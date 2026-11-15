import { animate, style, transition, trigger } from '@angular/animations';
import { DOCUMENT } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatTableModule } from '@angular/material/table';
import { MatToolbarModule } from '@angular/material/toolbar';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { catchError, map, of, switchMap } from 'rxjs';
import confetti from 'canvas-confetti';
import { Api, Draw, FIRST_YEAR, isMissingDraw } from './api';
import { SessionService } from './session';

type DrawState =
  | { kind: 'loading' }
  | { kind: 'draw'; draw: Draw }
  | { kind: 'empty'; year?: number }
  | { kind: 'invalid' }
  | { kind: 'error'; message: string };

@Component({
  selector: 'app-draws',
  imports: [
    MatButtonModule, MatCardModule, MatIconModule, MatMenuModule, MatProgressSpinnerModule,
    MatTableModule, MatToolbarModule, RouterLink,
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
  private readonly api = inject(Api);
  private readonly session = inject(SessionService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);
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

  constructor() {
    if (this.session.session() === null) this.loadSession();
    const motionChanged = (event: MediaQueryListEvent) => {
      this.reducedMotion.set(event.matches);
      if (event.matches) confetti.reset();
    };
    this.motionPreference?.addEventListener('change', motionChanged);
    this.destroyRef.onDestroy(() => {
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
          map((draw): DrawState => draw ? { kind: 'draw', draw } : { kind: 'empty' }),
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
