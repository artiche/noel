import { DatePipe, DOCUMENT } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, inject, signal, TemplateRef, ViewChild } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatToolbarModule } from '@angular/material/toolbar';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { finalize, map, Observable, Subscription, switchMap } from 'rxjs';
import { Api, SharedDraft } from './api';
import { SessionService } from './session';
import { PendingShare, ShareEdits, ShareQueue, ShareStorage } from './share-storage';

@Component({
  selector: 'app-share',
  imports: [DatePipe, ReactiveFormsModule, MatButtonModule, MatCardModule, MatCheckboxModule,
    MatDialogModule, MatFormFieldModule, MatInputModule, MatToolbarModule, RouterLink],
  templateUrl: './share.html',
  styleUrl: './share.scss',
})
export class Share {
  @ViewChild('leaveTemplate', { static: true }) private leaveTemplate!: TemplateRef<unknown>;
  private readonly api = inject(Api);
  private readonly session = inject(SessionService);
  private readonly storage = inject(ShareStorage);
  private readonly document = inject(DOCUMENT);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly dialog = inject(MatDialog);
  private readonly destroyRef = inject(DestroyRef);
  private readonly formBuilder = inject(FormBuilder);
  private storageRead = false;
  private initialized = false;
  private initialEdits = '';
  private metadataRequest: Subscription | null = null;
  protected readonly user = this.session.session;
  protected readonly queue = signal<ShareQueue>({ activeId: null, items: [] });
  protected readonly draft = signal<SharedDraft | null>(null);
  protected readonly loading = signal(false);
  protected readonly busy = signal(false);
  protected readonly dirty = signal(false);
  protected readonly unavailable = signal(false);
  protected readonly reviewRequired = signal(false);
  protected readonly reviewWishlist = signal<string | null>(null);
  protected readonly confirmCancel = signal(false);
  protected readonly confirmLocalRemoval = signal(false);
  protected readonly storageError = signal('');
  protected readonly fragmentError = signal('');
  protected readonly error = signal('');
  protected readonly message = signal('');
  protected readonly metadataLoading = signal(false);
  protected readonly metadataNotice = signal('');
  protected readonly metadataSource = signal<'opengraph' | 'html' | null>(null);
  protected readonly form = this.formBuilder.nonNullable.group({
    title: [''],
    url: [''],
    text: [''],
    comment: [''],
    keepImage: [true],
  });

  constructor() {
    try {
      this.queue.set(this.storage.read());
      this.storageRead = true;
    } catch {
      this.storageError.set('Impossible de lire le stockage de cet onglet. Ne le fermez pas et réessayez.');
    }

    this.form.valueChanges.pipe(takeUntilDestroyed(this.destroyRef)).subscribe(() => {
      const id = this.queue().activeId;
      if (!id || !this.draft()) return;
      const edits = this.form.getRawValue();
      this.dirty.set(JSON.stringify(edits) !== this.initialEdits);
      this.queue.update((queue) => ({
        ...queue, items: queue.items.map((item) => {
          if (item.id !== id) return item;
          const metadata = item.metadata && item.metadata.title === edits.title && item.metadata.url === edits.url
            ? item.metadata : null;
          this.metadataSource.set(metadata?.source ?? null);
          return { ...item, edits, metadata };
        }),
      }));
      this.persist();
    });

    const beforeUnload = (event: BeforeUnloadEvent) => {
      if (this.dirty() || this.busy() || this.storageError()) {
        event.preventDefault();
        event.returnValue = '';
      }
    };
    this.document.defaultView?.addEventListener('beforeunload', beforeUnload);
    this.destroyRef.onDestroy(() => this.document.defaultView?.removeEventListener('beforeunload', beforeUnload));
    this.destroyRef.onDestroy(() => this.metadataRequest?.unsubscribe());

    this.route.fragment.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((fragment) => this.receiveFragment(fragment));
    this.initialized = true;
    if (!this.storageError() && this.queue().activeId) this.loadSession();
  }

  private receiveFragment(fragment: string | null): void {
    if (fragment === null) return;
    const view = this.document.defaultView;
    if (!view) {
      this.storageError.set('Impossible de masquer le lien de partage dans cet environnement.');
      return;
    }
    try {
      view.history.replaceState(view.history.state, '', view.location.pathname + view.location.search);
    } catch {
      this.storageError.set('Impossible de masquer le lien de partage. Ne le communiquez pas.');
      return;
    }
    const match = /^draft=([a-f0-9]{64})$/.exec(fragment);
    if (!match) {
      this.fragmentError.set('Le lien de partage est invalide.');
      return;
    }
    this.fragmentError.set('');
    const id = match[1];
    this.queue.update((queue) => ({
      activeId: this.busy() || this.loading() ? queue.activeId : id,
      items: queue.items.some((item) => item.id === id)
        ? queue.items : [...queue.items, { id, edits: null, reviewRequired: false }],
    }));
    this.persist();
    if (this.initialized && !this.storageError() && !this.busy() && !this.loading()) {
      if (this.user()) this.loadActive();
      else this.loadSession();
    } else if (this.initialized && (this.busy() || this.loading())) {
      this.message.set('Un autre partage est en attente.');
    }
  }

  protected retryStorage(): void {
    try {
      if (!this.storageRead) {
        const saved = this.storage.read();
        const current = this.queue();
        const items: PendingShare[] = [...saved.items];
        for (const item of current.items) {
          const index = items.findIndex((entry) => entry.id === item.id);
          if (index < 0) items.push(item);
          else if (item.edits !== null) items[index] = item;
        }
        this.queue.set({ activeId: current.activeId ?? saved.activeId, items });
        this.storageRead = true;
      }
      this.persist();
      if (!this.storageError() && this.queue().activeId && !this.user() && !this.loading()) this.loadSession();
    } catch {
      this.storageError.set('Impossible de lire le stockage de cet onglet. Ne le fermez pas et réessayez.');
    }
  }

  private persist(): void {
    if (!this.storageRead) return;
    try {
      this.storage.write(this.queue());
      this.storageError.set('');
    } catch {
      this.storageError.set('Impossible de conserver le partage dans cet onglet. Ne le fermez pas et réessayez.');
    }
  }

  protected loadSession(): void {
    if (this.loading() || this.busy() || this.storageError() || !this.queue().activeId) return;
    this.loading.set(true);
    this.error.set('');
    this.session.load().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (session) => {
        this.loading.set(false);
        if (session.participant === null) {
          this.error.set('Ce compte n’est associé à aucun participant. Le partage reste en attente.');
          return;
        }
        this.loadActive();
      },
      error: (error: unknown) => {
        this.loading.set(false);
        if (!(error instanceof HttpErrorResponse && error.status === 401)) {
          this.error.set('Impossible de charger la session. Le partage reste en attente.');
        }
      },
    });
  }

  protected select(id: string): void {
    if (this.busy() || this.loading() || this.storageError() || id === this.queue().activeId) return;
    this.queue.update((queue) => ({ ...queue, activeId: id }));
    this.clearMetadata();
    this.persist();
    this.draft.set(null);
    this.dirty.set(false);
    this.reviewRequired.set(this.queue().items.find((item) => item.id === id)?.reviewRequired ?? false);
    this.reviewWishlist.set(null);
    this.confirmCancel.set(false);
    this.confirmLocalRemoval.set(false);
    this.message.set('');
    if (this.user()?.participant) this.loadActive();
    else this.loadSession();
  }

  protected loadActive(): void {
    const id = this.queue().activeId;
    if (!id || this.loading() || this.busy() || this.storageError()) return;
    this.loading.set(true);
    this.error.set('');
    this.unavailable.set(false);
    this.api.openSharedDraft(id).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (draft) => {
        const initial: ShareEdits = { title: draft.title, url: draft.url, text: draft.text, comment: '', keepImage: true };
        this.initialEdits = JSON.stringify(initial);
        const item = this.queue().items.find((entry) => entry.id === id);
        const edits = item?.edits ?? initial;
        this.draft.set(draft);
        this.form.setValue(edits, { emitEvent: false });
        this.form.markAsPristine();
        this.dirty.set(JSON.stringify(edits) !== this.initialEdits);
        this.loading.set(false);
        this.clearMetadata();
        if (item?.metadata && item.metadata.title === edits.title && item.metadata.url === edits.url) {
          this.metadataSource.set(item.metadata.source);
        } else if (item?.edits === null && draft.title === '' && draft.url !== '') {
          this.fetchMetadata(id, draft);
        }
        this.reviewRequired.set(this.queue().items.find((item) => item.id === id)?.reviewRequired ?? false);
        if (this.reviewRequired()) this.loadReview();
      },
      error: (error: unknown) => {
        if (error instanceof HttpErrorResponse && error.status === 404) {
          this.checkConfirmation(id);
          return;
        }
        this.loading.set(false);
        this.draft.set(null);
        this.unavailable.set(error instanceof HttpErrorResponse && (error.status === 403 || error.status === 410));
        if (!(error instanceof HttpErrorResponse && error.status === 401)) this.error.set(this.errorMessage(error));
      },
    });
  }

  private clearMetadata(): void {
    this.metadataRequest?.unsubscribe();
    this.metadataRequest = null;
    this.metadataLoading.set(false);
    this.metadataNotice.set('');
    this.metadataSource.set(null);
  }

  private fetchMetadata(id: string, draft: SharedDraft): void {
    this.metadataLoading.set(true);
    this.metadataRequest = this.api.sharedDraftMetadata(id).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (result) => {
        this.metadataLoading.set(false);
        if (this.queue().activeId !== id || this.draft() !== draft || this.form.controls.url.value !== draft.url) return;
        if (result.status === 'found') {
          if (this.form.controls.title.pristine && this.form.controls.title.value === '' &&
              this.form.controls.url.value === draft.url && !this.busy()) {
            this.form.controls.title.setValue(result.title, { emitEvent: false });
            const edits = this.form.getRawValue();
            this.dirty.set(JSON.stringify(edits) !== this.initialEdits);
            this.queue.update((queue) => ({
              ...queue, items: queue.items.map((item) => item.id === id
                ? { ...item, edits, metadata: { title: result.title, url: draft.url, source: result.source } } : item),
            }));
            this.persist();
            this.metadataSource.set(result.source);
          }
        } else if (result.status === 'none') {
          this.metadataNotice.set('Aucun titre trouvé ; vous pouvez le renseigner manuellement.');
        } else if (result.status === 'unavailable') {
          this.metadataNotice.set('Préremplissage du titre indisponible ; vous pouvez poursuivre manuellement.');
        }
      },
      error: () => {
        this.metadataLoading.set(false);
        if (this.queue().activeId === id && this.draft() === draft && this.form.controls.url.value === draft.url) {
          this.metadataNotice.set('Préremplissage du titre indisponible ; vous pouvez poursuivre manuellement.');
        }
      },
    });
  }

  private checkConfirmation(id: string): void {
    this.api.sharedDraftStatus(id).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (confirmed) => {
        this.loading.set(false);
        if (confirmed) {
          this.complete(id, 'Ce partage a déjà été enregistré dans votre wishlist.');
        } else {
          this.draft.set(null);
          this.unavailable.set(true);
          this.error.set('Ce brouillon est introuvable. Il n’a pas pu être identifié comme enregistré.');
        }
      },
      error: (error: unknown) => {
        this.loading.set(false);
        this.draft.set(null);
        if (!(error instanceof HttpErrorResponse && error.status === 401)) {
          this.error.set('Impossible de vérifier si le partage a été enregistré. Réessayez.');
        }
      },
    });
  }

  protected submit(): void {
    const id = this.queue().activeId;
    const draft = this.draft();
    if (!id || !draft || this.busy() || this.loading() || this.storageError() || this.reviewRequired()) return;
    const edits = this.form.getRawValue();
    const combined = [edits.text.trim(), edits.comment.trim()].filter((value) => value !== '').join('\n');
    const encoder = new TextEncoder();
    if ([edits.title, edits.url, edits.text, edits.comment].some((value) => value.includes('\0')) ||
        encoder.encode(edits.title).length > 4096 || encoder.encode(edits.url).length > 8192 ||
        encoder.encode(combined).length > 65536 || !this.validUrl(edits.url) ||
        (edits.url.trim() === '' && combined === '' && !(draft.image && edits.keepImage))) {
      this.error.set('Vérifiez l’URL HTTP(S), le contenu et les limites : titre 4 096 octets, URL 8 192 octets, texte et commentaire 65 536 octets.');
      return;
    }
    this.busy.set(true);
    this.error.set('');
    this.message.set('');
    let confirmationAttempted = false;
    this.api.wishlist().pipe(
      switchMap((wishlist) => {
        if (typeof wishlist.revision !== 'string' || !/^[a-f0-9]{64}$/.test(wishlist.revision)) {
          throw new Error('Invalid wishlist revision');
        }
        confirmationAttempted = true;
        return this.api.confirmSharedDraft(id, wishlist.revision, {
          title: edits.title, url: edits.url, text: combined, keepImage: edits.keepImage,
        });
      }),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe({
      next: ({ alreadyConfirmed }) => {
        this.busy.set(false);
        this.complete(id, alreadyConfirmed
          ? 'Ce partage avait déjà été enregistré dans votre wishlist.'
          : 'Le partage a été ajouté à votre wishlist.');
      },
      error: (error: unknown) => {
        this.busy.set(false);
        if (error instanceof HttpErrorResponse && error.status === 409 && error.error?.error?.code === 'wishlist_changed') {
          this.reviewRequired.set(true);
          this.queue.update((queue) => ({
            ...queue, items: queue.items.map((item) => item.id === id ? { ...item, reviewRequired: true } : item),
          }));
          this.persist();
          this.reviewWishlist.set(null);
          this.message.set('Votre wishlist a changé. Vérifiez le partage et votre wishlist avant de confirmer à nouveau.');
          this.loadReview();
        } else if (!(error instanceof HttpErrorResponse && error.status === 401)) {
          if (!confirmationAttempted) {
            this.error.set('Impossible de charger la wishlist et sa révision. Votre partage est conservé ; réessayez.');
          } else if (error instanceof HttpErrorResponse && error.status === 413) {
            this.error.set('La wishlist dépasse la limite de 20 Mo d’images ou le partage dépasse une limite autorisée.');
          } else if (error instanceof HttpErrorResponse && error.status === 400) {
            this.error.set('Le serveur a refusé le contenu du partage. Vérifiez les champs avant de réessayer.');
          } else if (error instanceof HttpErrorResponse && (error.status === 403 || error.status === 404 || error.status === 410)) {
            this.draft.set(null);
            this.unavailable.set(true);
            this.error.set(this.errorMessage(error));
          } else {
            this.error.set('L’enregistrement a peut-être réussi. Votre partage est conservé ; réessayez pour vérifier.');
          }
        }
      },
    });
  }

  protected loadReview(): void {
    if (!this.reviewRequired() || this.loading() || this.busy() || this.storageError()) return;
    this.loading.set(true);
    this.error.set('');
    this.api.wishlist().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (wishlist) => {
        this.loading.set(false);
        if (!Array.isArray(wishlist.ops) || !wishlist.ops.every((op) =>
          op !== null && typeof op === 'object' &&
          (typeof op.insert === 'string' || (op.insert !== null && typeof op.insert === 'object' &&
            'image' in op.insert && typeof op.insert['image'] === 'string')))) {
          this.error.set('Impossible de lire la wishlist actualisée. Réessayez.');
          return;
        }
        const preview = wishlist.ops.map((op) =>
          typeof op.insert === 'string' ? op.insert : '[image]').join('');
        this.reviewWishlist.set(preview.length > 16000
          ? `${preview.slice(0, 16000)}\n[Aperçu tronqué : consultez la wishlist complète.]`
          : preview);
      },
      error: (error: unknown) => {
        this.loading.set(false);
        if (!(error instanceof HttpErrorResponse && error.status === 401)) {
          this.error.set('Impossible de recharger la wishlist. Réessayez avant de confirmer.');
        }
      },
    });
  }

  protected acknowledgeReview(): void {
    const id = this.queue().activeId;
    if (!id || this.reviewWishlist() === null || this.loading() || this.storageError()) return;
    this.queue.update((queue) => ({
      ...queue, items: queue.items.map((item) => item.id === id ? { ...item, reviewRequired: false } : item),
    }));
    this.persist();
    if (!this.storageError()) this.reviewRequired.set(false);
  }

  protected cancel(): void {
    const id = this.queue().activeId;
    if (!id || this.busy() || this.loading() || !this.confirmCancel()) return;
    this.busy.set(true);
    this.error.set('');
    this.api.cancelSharedDraft(id).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: () => {
        this.busy.set(false);
        this.complete(id, 'Le brouillon a été annulé.');
      },
      error: (error: unknown) => {
        this.busy.set(false);
        this.confirmCancel.set(false);
        if (!(error instanceof HttpErrorResponse && error.status === 401)) {
          this.error.set('Impossible de confirmer l’annulation. Le partage reste sur cet appareil ; réessayez.');
        }
      },
    });
  }

  protected removeLocal(): void {
    const id = this.queue().activeId;
    if (!id || !this.confirmLocalRemoval() || this.busy() || this.loading()) return;
    this.complete(id, 'Le partage a été retiré de cet onglet ; cela ne supprime pas le brouillon sur le serveur.');
  }

  private complete(id: string, message: string): void {
    this.clearMetadata();
    this.queue.update((queue) => {
      const items = queue.items.filter((item) => item.id !== id);
      return { activeId: queue.activeId === id ? (items[0]?.id ?? null) : queue.activeId, items };
    });
    this.persist();
    this.draft.set(null);
    this.dirty.set(false);
    this.unavailable.set(false);
    this.reviewRequired.set(false);
    this.reviewWishlist.set(null);
    this.confirmCancel.set(false);
    this.confirmLocalRemoval.set(false);
    this.error.set('');
    this.message.set(message);
  }

  canDeactivate(nextUrl: string): boolean | Observable<boolean> {
    const next = this.router.parseUrl(nextUrl);
    const segments = next.root.children['primary']?.segments ?? [];
    if (segments.length === 1 && segments[0].path === 'connexion' && next.queryParams['returnShare'] === '1') {
      if (!this.storageError()) return true;
      this.error.set('Le partage ne peut pas être conservé pour la reconnexion. Réessayez le stockage.');
      return false;
    }
    if (this.busy()) {
      this.error.set('Attendez la fin de l’opération avant de quitter.');
      return false;
    }
    if (!this.dirty() && !this.storageError()) return true;
    const dialog = this.dialog.open<unknown, unknown, boolean>(this.leaveTemplate, {
      ariaLabel: 'Partage en attente', ariaModal: true, width: '36rem', maxWidth: '95vw',
      autoFocus: 'first-tabbable',
    });
    return dialog.afterClosed().pipe(map((confirmed) => confirmed === true), finalize(() => dialog.close()));
  }

  private validUrl(value: string): boolean {
    if (value.trim() === '') return true;
    try {
      const url = new URL(value.trim());
      return (url.protocol === 'https:' || url.protocol === 'http:') && !url.username && !url.password;
    } catch {
      return false;
    }
  }

  private errorMessage(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      switch (error.error?.error?.code) {
        case 'share_forbidden': return 'Ce partage appartient à un autre compte. Connectez-vous avec son propriétaire.';
        case 'participant_required': return 'Ce compte n’est associé à aucun participant.';
        case 'share_expired': return 'Ce brouillon a expiré. Il reste sur cet onglet jusqu’à son retrait.';
        case 'share_not_found': return 'Ce brouillon est introuvable. Il reste sur cet onglet jusqu’à son retrait.';
      }
    }
    return 'Impossible de charger le partage. Il reste en attente ; réessayez.';
  }
}
