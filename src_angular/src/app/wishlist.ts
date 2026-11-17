import { DOCUMENT } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { AfterViewInit, Component, DestroyRef, ElementRef, inject, signal, TemplateRef, ViewChild } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatToolbarModule } from '@angular/material/toolbar';
import { ActivatedRoute, RouterLink } from '@angular/router';
import Quill, { Delta } from 'quill';
import { catchError, EMPTY, finalize, map, Observable, switchMap } from 'rxjs';
import { Api, InvalidNotificationResponseError, Wishlist as WishlistData } from './api';
import { SessionService } from './session';

@Component({
  selector: 'app-wishlist',
  imports: [MatButtonModule, MatCardModule, MatDialogModule, MatToolbarModule, RouterLink],
  templateUrl: './wishlist.html',
  styleUrl: './wishlist.scss',
})
export class Wishlist implements AfterViewInit {
  @ViewChild('editor', { static: true }) private editorElement!: ElementRef<HTMLElement>;
  @ViewChild('recipientEditor', { static: true }) private recipientElement!: ElementRef<HTMLElement>;
  @ViewChild('leaveTemplate', { static: true }) private leaveTemplate!: TemplateRef<unknown>;
  private readonly api = inject(Api);
  private readonly dialog = inject(MatDialog);
  private readonly session = inject(SessionService);
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);
  private readonly document = inject(DOCUMENT);
  private editor!: Quill;
  private recipientEditor!: Quill;
  private savedContents = '';
  private revision = '';
  protected readonly own = signal<WishlistData | null>(null);
  protected readonly recipientTarget = signal(this.route.snapshot.queryParamMap.get('target') ?? '');
  protected readonly recipientName = signal('');
  protected readonly recipientYear = signal<number | null>(null);
  protected readonly recipientEmpty = signal(false);
  protected readonly giverChanged = signal(false);
  protected readonly loading = signal(true);
  protected readonly recipientLoading = signal(false);
  protected readonly busy = signal(false);
  protected readonly dirty = signal(false);
  protected readonly error = signal('');
  protected readonly recipientError = signal('');
  protected readonly message = signal('');

  ngAfterViewInit(): void {
    this.editor = new Quill(this.editorElement.nativeElement, {
      theme: 'snow',
      placeholder: 'Écrivez votre wishlist…',
      formats: ['bold', 'italic', 'underline', 'strike', 'header', 'list', 'link', 'image'],
      modules: { toolbar: [
        [{ header: [1, 2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['link', 'clean'],
      ] },
    });
    this.editor.root.setAttribute('aria-label', 'Modifier ma wishlist');
    this.editor.enable(false);
    this.recipientEditor = new Quill(this.recipientElement.nativeElement, {
      theme: 'snow', readOnly: true, modules: { toolbar: false },
    });
    this.recipientEditor.root.setAttribute('aria-label', 'Wishlist du destinataire en lecture seule');
    this.editor.on('text-change', () => {
      this.dirty.set(JSON.stringify(this.editor.getContents().ops) !== this.savedContents);
    });
    const beforeUnload = (event: BeforeUnloadEvent) => {
      if (this.dirty()) {
        event.preventDefault();
        event.returnValue = '';
      }
    };
    this.document.defaultView?.addEventListener('beforeunload', beforeUnload);
    this.destroyRef.onDestroy(() => this.document.defaultView?.removeEventListener('beforeunload', beforeUnload));

    this.session.load().pipe(
      switchMap((session) => {
        if (session.participant === null) {
          this.error.set('Cet écran est réservé aux comptes associés à un participant.');
          this.loading.set(false);
          return EMPTY;
        }
        return this.api.wishlist();
      }),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe({
      next: (data) => {
        this.own.set(data);
        this.revision = data.revision;
        this.editor.setContents(new Delta(data.ops), 'silent');
        this.savedContents = JSON.stringify(this.editor.getContents().ops);
        this.dirty.set(false);
        this.editor.enable(true);
        this.loading.set(false);
        this.watchRecipient();
      },
      error: (error: unknown) => {
        this.loading.set(false);
        this.error.set(this.errorMessage(error));
      },
    });
  }

  private watchRecipient(): void {
    this.route.queryParamMap.pipe(
      switchMap((params) => {
        const target = params.get('target');
        this.recipientTarget.set(target ?? '');
        this.recipientError.set('');
        this.recipientName.set('');
        this.recipientYear.set(null);
        this.recipientEmpty.set(false);
        this.recipientEditor.setContents(new Delta(), 'silent');
        if (target === null || target === '') {
          this.recipientLoading.set(false);
          return EMPTY;
        }
        this.recipientLoading.set(true);
        return this.api.recipientWishlist(target).pipe(catchError((error: unknown) => {
          this.recipientLoading.set(false);
          this.recipientError.set(this.errorMessage(error));
          return EMPTY;
        }));
      }),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe((data) => {
      this.recipientEditor.setContents(new Delta(data.ops), 'silent');
      this.recipientName.set(data.participant);
      this.recipientYear.set(data.year);
      this.recipientEmpty.set(data.ops.every((op) => typeof op.insert === 'string' && op.insert.trim() === ''));
      this.recipientLoading.set(false);
    });
  }

  canDeactivate(): boolean | Observable<boolean> {
    if (!this.dirty()) return true;
    const dialog = this.dialog.open<unknown, unknown, boolean>(this.leaveTemplate, {
      ariaLabel: 'Modifications non enregistrées', ariaModal: true, width: '36rem', maxWidth: '95vw',
      autoFocus: 'first-tabbable',
    });
    return dialog.afterClosed().pipe(
      map((confirmed) => confirmed === true),
      finalize(() => dialog.close()),
    );
  }

  protected save(): void {
    if (this.busy() || !this.own()) return;
    this.error.set('');
    this.message.set('');
    this.busy.set(true);
    this.editor.enable(false);
    const ops = this.editor.getContents().ops;
    this.api.saveWishlist(ops, this.revision).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: ({ revision }) => {
        this.revision = revision;
        this.savedContents = JSON.stringify(ops);
        this.dirty.set(false);
        this.busy.set(false);
        this.editor.enable(true);
        this.message.set('Wishlist enregistrée.');
      },
      error: (error: unknown) => {
        this.busy.set(false);
        this.editor.enable(true);
        this.error.set(this.errorMessage(error));
      },
    });
  }

  protected notify(): void {
    const giver = this.own()?.giver;
    if (this.busy() || this.dirty() || this.giverChanged() || !giver) return;
    this.busy.set(true);
    this.error.set('');
    this.message.set('');
    this.api.notifyGiver(giver.key).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (name) => {
        this.busy.set(false);
        this.message.set(`L’e-mail a été envoyé à ${name}.`);
      },
      error: (error: unknown) => {
        this.busy.set(false);
        if (error instanceof HttpErrorResponse && error.error?.error?.code === 'giver_changed') {
          this.giverChanged.set(true);
          return;
        }
        if (error instanceof InvalidNotificationResponseError) {
          this.error.set('L’envoi a peut-être réussi, mais son destinataire n’a pas pu être confirmé. Vérifiez avant de réessayer.');
        } else {
          this.error.set(error instanceof HttpErrorResponse && error.error?.error?.code === 'giver_account_missing'
            ? `${giver.name} ne dispose pas d’un compte avec une adresse e-mail utilisable.`
            : this.errorMessage(error));
        }
      },
    });
  }

  protected addImageFile(event: Event): void {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    if (!['image/png', 'image/jpeg', 'image/gif', 'image/webp'].includes(file.type) || file.size > 5_000_000) {
      this.error.set('Choisissez une image PNG, JPEG, GIF ou WebP de 5 Mo maximum.');
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      if (typeof reader.result === 'string') this.insertImage(reader.result);
    };
    reader.onerror = () => this.error.set('Impossible de lire cette image.');
    reader.readAsDataURL(file);
  }

  protected addImageUrl(input: HTMLInputElement): void {
    try {
      const url = new URL(input.value);
      if (url.protocol !== 'https:' || url.username || url.password) throw new Error('Invalid URL');
      this.insertImage(url.href);
      input.value = '';
    } catch {
      this.error.set('Renseignez une URL d’image HTTPS valide.');
    }
  }

  private insertImage(url: string): void {
    if (!this.own() || this.busy()) return;
    const index = this.editor.getSelection()?.index ?? Math.max(0, this.editor.getLength() - 1);
    this.editor.insertEmbed(index, 'image', url, 'user');
    this.error.set('');
    this.message.set('');
  }

  private errorMessage(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      if (error.status === 413) {
        return 'La taille maximale est de 5 Mo par image et 20 Mo d’images par wishlist.';
      }
      switch (error.error?.error?.code) {
        case 'wishlist_forbidden': case 'participant_required':
          return 'Cette wishlist n’est pas accessible pour le dernier tirage.';
        case 'draw_assignment_missing':
          return 'Aucune attribution ne vous concerne dans le dernier tirage.';
        case 'wishlist_changed':
          return 'La wishlist a été modifiée ailleurs. Copiez vos changements et rechargez la page avant de réessayer.';
        case 'wishlist_too_large': case 'image_too_large':
          return 'La taille maximale est de 5 Mo par image et 20 Mo d’images par wishlist.';
        case 'invalid_image':
          return 'Une image est invalide ou utilise un format non pris en charge.';
        case 'invalid_wishlist':
          return 'La wishlist contient un format ou un lien non pris en charge.';
        case 'mail_send_failed': case 'wishlist_config_missing': case 'smtp_config_missing':
          return 'Impossible d’envoyer l’e-mail ou d’accéder au service. Réessayez plus tard.';
      }
    }
    return 'Une erreur est survenue. Vérifiez votre connexion ou réessayez plus tard.';
  }
}
