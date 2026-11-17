import { animate, style, transition, trigger } from '@angular/animations';
import { DOCUMENT } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, effect, inject, signal, TemplateRef, ViewChild } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatDialog, MatDialogRef } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatTableModule } from '@angular/material/table';
import { MatToolbarModule } from '@angular/material/toolbar';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { catchError, map, of, switchMap } from 'rxjs';
import confetti from 'canvas-confetti';
import { Api, Draw, DrawAssignment, FIRST_YEAR, isMissingDraw } from './api';
import { SessionService } from './session';

type DrawState =
  | { kind: 'loading' }
  | { kind: 'draw'; draw: Draw; latest: boolean }
  | { kind: 'empty'; year?: number }
  | { kind: 'invalid' }
  | { kind: 'error'; message: string };

@Component({
  selector: 'app-draws',
  imports: [
    MatButtonModule, MatCardModule, MatIconModule, MatMenuModule, MatProgressSpinnerModule,
    MatTableModule, MatToolbarModule, MatFormFieldModule, MatInputModule, ReactiveFormsModule, RouterLink,
  ],
  templateUrl: './draws.html',
  styleUrl: './draws.scss',
  animations: [
    trigger('reveal', [
      transition(':enter', [
        style({ opacity: 0, transform: 'translateY(12px)' }),
        animate('700ms cubic-bezier(0.22, 1, 0.36, 1)', style({ opacity: 1, transform: 'none' })),
      ]),
    ]),
  ],
})
export class Draws {
  @ViewChild('accountTemplate', { static: true }) private accountTemplate!: TemplateRef<unknown>;
  private readonly api = inject(Api);
  private readonly dialog = inject(MatDialog);
  private accountDialog?: MatDialogRef<unknown>;
  private readonly session = inject(SessionService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);
  private readonly formBuilder = inject(FormBuilder);
  protected readonly user = this.session.session;
  protected readonly accountOpen = signal(false);
  protected readonly accountBusy = signal(false);
  protected readonly accountError = signal('');
  protected readonly accountMessage = signal('');
  protected readonly accountForm = this.formBuilder.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    currentPassword: ['', Validators.required],
    newPassword: [''],
  });
  private readonly motionPreference = inject(DOCUMENT).defaultView?.matchMedia?.('(prefers-reduced-motion: reduce)');
  private readonly currentYear = new Date().getFullYear();
  protected readonly years = Array.from(
    { length: Math.max(0, this.currentYear - FIRST_YEAR + 1) },
    (_, index) => this.currentYear - index,
  );
  protected readonly columns = ['assignment'];
  protected readonly state = signal<DrawState>({ kind: 'loading' });
  protected readonly logoutBusy = signal(false);
  protected readonly logoutError = signal('');
  protected readonly sessionError = signal('');
  protected readonly reducedMotion = signal(this.motionPreference?.matches ?? false);
  protected readonly showWishlistLink = signal(false);
  protected readonly latestDrawError = signal('');
  private readonly latestDrawRetry = signal(0);

  constructor() {
    if (this.session.session() === null) this.loadSession();
    effect((onCleanup) => {
      const current = this.state();
      const participantKey = this.user()?.participantKey;
      this.latestDrawRetry();
      this.showWishlistLink.set(false);
      this.latestDrawError.set('');
      if (current.kind !== 'draw' || !participantKey ||
          !current.draw.assignments.some((assignment) => assignment.giverKey === participantKey)) return;
      if (current.latest || current.draw.year === this.currentYear) {
        this.showWishlistLink.set(true);
        return;
      }
      const request = this.api.latestDraw(this.currentYear, current.draw.year + 1).subscribe({
        next: (newer) => this.showWishlistLink.set(newer === null),
        error: () => this.latestDrawError.set('Impossible de vérifier le dernier tirage.'),
      });
      onCleanup(() => request.unsubscribe());
    });
    const motionChanged = (event: MediaQueryListEvent) => {
      this.reducedMotion.set(event.matches);
      if (event.matches) confetti.reset();
    };
    this.motionPreference?.addEventListener('change', motionChanged);
    this.destroyRef.onDestroy(() => {
      this.accountDialog?.close();
      this.motionPreference?.removeEventListener('change', motionChanged);
      confetti.reset();
    });
    this.route.paramMap.pipe(
      switchMap((params) => {
        confetti.reset();
        this.state.set({ kind: 'loading' });
        const selected = params.get('year');
        if (selected !== null && (!/^\d{4}$/.test(selected) ||
            Number(selected) < FIRST_YEAR || Number(selected) > this.currentYear)) {
          return of<DrawState>({ kind: 'invalid' });
        }
        const year = selected === null ? null : Number(selected);
        const request = year === null ? this.api.latestDraw(this.currentYear) : this.api.draw(year);
        return request.pipe(
          map((draw): DrawState => draw ? { kind: 'draw', draw, latest: year === null } : { kind: 'empty' }),
          catchError((error: unknown) => of<DrawState>(
            year !== null && isMissingDraw(error)
              ? { kind: 'empty', year }
              : { kind: 'error', message: this.errorMessage(error) },
          )),
        );
      }),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe((state) => {
      this.state.set(state);
      if (state.kind === 'draw' && !this.reducedMotion()) {
        void confetti({
          particleCount: 70,
          spread: 65,
          origin: { y: 0.65 },
          colors: ['#D4AF37', '#9B2226', '#1B4332'],
          disableForReducedMotion: true,
        });
      }
    });
  }

  protected logout(): void {
    if (this.logoutBusy()) return;
    this.logoutBusy.set(true);
    this.logoutError.set('');
    this.api.logout().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: () => {
        this.session.clear();
        void this.router.navigate(['/connexion']);
      },
      error: () => {
        this.logoutBusy.set(false);
        this.logoutError.set('La déconnexion a échoué. Veuillez réessayer.');
      },
    });
  }

  protected isGiver(assignment: DrawAssignment): boolean {
    const participantKey = this.user()?.participantKey;
    return participantKey != null && assignment.giverKey === participantKey;
  }

  protected retryLatestDraw(): void {
    this.latestDrawRetry.update((attempt) => attempt + 1);
  }

  protected isRecipient(assignment: DrawAssignment): boolean {
    const participantKey = this.user()?.participantKey;
    return participantKey != null && assignment.recipientKey === participantKey;
  }

  protected openAccount(): void {
    const user = this.user();
    if (!user || this.accountDialog) return;
    this.accountForm.reset({ email: user.email, currentPassword: '', newPassword: '' });
    this.accountError.set('');
    this.accountMessage.set('');
    this.accountOpen.set(true);
    const dialog = this.dialog.open(this.accountTemplate, {
      ariaLabel: 'Modifier mon compte', ariaModal: true, width: '36rem', maxWidth: '95vw',
      restoreFocus: '[aria-label="Menu utilisateur"]',
    });
    this.accountDialog = dialog;
    dialog.afterClosed().pipe(takeUntilDestroyed(this.destroyRef)).subscribe(() => {
      if (this.accountDialog !== dialog) return;
      this.accountDialog = undefined;
      if (this.accountOpen()) this.accountError.set('');
      this.accountOpen.set(false);
      this.accountForm.controls.currentPassword.reset();
      this.accountForm.controls.newPassword.reset();
    });
  }

  protected cancelAccount(): void {
    if (!this.accountBusy()) this.accountDialog?.close();
  }

  protected updateAccount(): void {
    if (this.accountForm.invalid || this.accountBusy()) {
      this.accountForm.markAllAsTouched();
      return;
    }
    const user = this.user();
    if (!user) return;
    const { email, currentPassword, newPassword } = this.accountForm.getRawValue();
    const changedEmail = email !== user.email ? email : undefined;
    if (changedEmail === undefined && newPassword === '') {
      this.accountError.set('Modifiez l’email ou renseignez un nouveau mot de passe.');
      return;
    }
    this.accountBusy.set(true);
    if (this.accountDialog) this.accountDialog.disableClose = true;
    this.accountError.set('');
    this.accountMessage.set('');
    this.api.updateAccount(currentPassword, changedEmail, newPassword || undefined)
      .pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
        next: () => {
          this.accountForm.controls.currentPassword.reset();
          this.accountForm.controls.newPassword.reset();
          this.session.load().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
            next: () => {
              this.accountBusy.set(false);
              this.accountOpen.set(false);
              this.accountDialog?.close();
              this.accountMessage.set('Votre compte a été modifié.');
            },
            error: () => {
              this.accountBusy.set(false);
              this.accountOpen.set(false);
              this.accountDialog?.close();
              this.accountError.set('Le compte a été modifié, mais la session n’a pas pu être rechargée.');
            },
          });
        },
        error: (error: unknown) => {
          this.accountBusy.set(false);
          if (this.accountDialog) this.accountDialog.disableClose = false;
          this.accountError.set(error instanceof HttpErrorResponse && error.status === 403 &&
            error.error?.error?.code === 'invalid_current_password'
            ? 'Le mot de passe actuel est incorrect.'
            : 'Impossible de modifier le compte. Veuillez réessayer.');
        },
      });
  }

  protected loadSession(): void {
    this.sessionError.set('');
    this.session.load().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      error: (error: unknown) => {
        if (!(error instanceof HttpErrorResponse && error.status === 401)) {
          this.sessionError.set('Impossible de charger la session. Veuillez réessayer.');
        }
      },
    });
  }

  private errorMessage(error: unknown): string {
    if (error instanceof HttpErrorResponse && error.status === 401) {
      return 'Session expirée. Redirection vers la connexion…';
    }
    return 'Impossible de charger le tirage. Vérifiez votre connexion ou réessayez plus tard.';
  }
}
