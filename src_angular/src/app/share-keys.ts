import { DatePipe, DOCUMENT } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, inject, signal, TemplateRef, ViewChild } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatDialog, MatDialogModule } from '@angular/material/dialog';
import { MatToolbarModule } from '@angular/material/toolbar';
import { RouterLink } from '@angular/router';
import { finalize, map, Observable, switchMap } from 'rxjs';
import { Api, ShareToken } from './api';
import { SessionService } from './session';

@Component({
  selector: 'app-share-keys',
  imports: [DatePipe, MatButtonModule, MatCardModule, MatDialogModule, MatToolbarModule, RouterLink],
  templateUrl: './share-keys.html',
  styleUrl: './share-keys.scss',
})
export class ShareKeys {
  @ViewChild('leaveTemplate', { static: true }) private leaveTemplate!: TemplateRef<unknown>;
  private readonly api = inject(Api);
  private readonly session = inject(SessionService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly document = inject(DOCUMENT);
  private readonly dialog = inject(MatDialog);
  protected readonly user = this.session.session;
  protected readonly tokens = signal<ShareToken[]>([]);
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly listError = signal('');
  protected readonly message = signal('');
  protected readonly newKey = signal<{ id: string; token: string } | null>(null);
  protected readonly confirmingId = signal<string | null>(null);

  constructor() {
    const beforeUnload = (event: BeforeUnloadEvent) => {
      if (this.newKey() || this.busy()) {
        event.preventDefault();
        event.returnValue = '';
      }
    };
    this.document.defaultView?.addEventListener('beforeunload', beforeUnload);
    this.destroyRef.onDestroy(() => {
      this.newKey.set(null);
      this.document.defaultView?.removeEventListener('beforeunload', beforeUnload);
    });
    this.session.load().pipe(
      switchMap(() => this.api.shareTokens()),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe({
      next: (tokens) => {
        this.tokens.set(tokens);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.listError.set('Impossible de charger les clés. Réessayez.');
      },
    });
  }

  protected refresh(): void {
    if (this.loading() || this.busy()) return;
    this.loading.set(true);
    this.listError.set('');
    const request = this.user() === null
      ? this.session.load().pipe(switchMap(() => this.api.shareTokens()))
      : this.api.shareTokens();
    request.pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (tokens) => {
        this.tokens.set(tokens);
        this.loading.set(false);
        const key = this.newKey();
        if (key && !tokens.some((token) => token.id === key.id)) {
          this.newKey.set(null);
          this.error.set('La clé créée n’est plus active. Créez une nouvelle clé si nécessaire.');
        } else {
          this.error.set('');
        }
      },
      error: () => {
        this.loading.set(false);
        this.listError.set('Impossible de charger les clés. Réessayez.');
      },
    });
  }

  protected create(): void {
    if (this.busy() || this.loading() || this.listError() || this.newKey() || !this.user()?.participant) return;
    this.busy.set(true);
    this.error.set('');
    this.message.set('');
    this.api.createShareToken().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (key) => {
        this.newKey.set(key);
        this.busy.set(false);
        this.refresh();
      },
      error: (error: unknown) => {
        this.busy.set(false);
        if (error instanceof HttpErrorResponse && error.status === 429) {
          this.error.set('La limite de dix clés est atteinte. Révoquez une clé avant de réessayer.');
        } else {
          this.listError.set('Actualisez la liste pour vérifier les clés.');
          this.error.set('La création a peut-être réussi, mais la clé ne peut pas être récupérée. Actualisez la liste et révoquez toute clé inconnue avant de réessayer.');
        }
      },
    });
  }

  protected revoke(id: string): void {
    if (this.busy() || this.loading() || this.listError() || this.newKey() || this.confirmingId() !== id) return;
    this.busy.set(true);
    this.error.set('');
    this.message.set('');
    this.api.revokeShareToken(id).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: () => {
        this.tokens.update((tokens) => tokens.filter((token) => token.id !== id));
        this.confirmingId.set(null);
        this.busy.set(false);
        this.message.set('La clé a été révoquée.');
      },
      error: () => {
        this.busy.set(false);
        this.confirmingId.set(null);
        this.listError.set('Actualisez la liste pour vérifier les clés.');
        this.error.set('La révocation a peut-être réussi. Actualisez la liste avant de réessayer.');
      },
    });
  }

  canDeactivate(): boolean | Observable<boolean> {
    if (this.busy()) {
      this.error.set('Attendez la fin de l’opération avant de quitter.');
      return false;
    }
    if (!this.newKey()) return true;
    const dialog = this.dialog.open<unknown, unknown, boolean>(this.leaveTemplate, {
      ariaLabel: 'Clé non enregistrée', ariaModal: true, width: '36rem', maxWidth: '95vw',
      autoFocus: 'first-tabbable',
    });
    return dialog.afterClosed().pipe(map((confirmed) => confirmed === true), finalize(() => dialog.close()));
  }
}
