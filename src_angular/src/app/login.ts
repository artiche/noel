import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatIconModule } from '@angular/material/icon';
import { ActivatedRoute, Router } from '@angular/router';
import { switchMap } from 'rxjs';
import { Api } from './api';
import { SessionService } from './session';

@Component({
  selector: 'app-login',
  imports: [ReactiveFormsModule, MatButtonModule, MatCardModule, MatFormFieldModule, MatInputModule, MatIconModule],
  templateUrl: './login.html',
  styleUrl: './login.scss',
})
export class Login {
  private readonly api = inject(Api);
  private readonly session = inject(SessionService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly formBuilder = inject(FormBuilder);
  protected readonly busy = signal(false);
  protected readonly forgotBusy = signal(false);
  protected readonly error = signal('');
  protected readonly forgotMessage = signal('');
  protected readonly forgotError = signal('');
  protected readonly sessionExpired = this.route.snapshot.queryParamMap.get('session') === 'expiree';
  protected readonly form = this.formBuilder.nonNullable.group({
    login: ['', Validators.required],
    password: ['', Validators.required],
  });

  protected submit(): void {
    if (this.form.invalid || this.busy()) {
      this.form.markAllAsTouched();
      return;
    }
    this.error.set('');
    this.busy.set(true);
    const { login, password } = this.form.getRawValue();
    this.api.login(login, password).pipe(
      switchMap(() => this.session.load()),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe({
      next: () => {
        this.form.controls.password.reset();
        if (this.route.snapshot.queryParamMap.get('returnWishlist') === '1') {
          const target = this.route.snapshot.queryParamMap.get('target');
          void this.router.navigate(['/wishlist'], { queryParams: target === null ? {} : { target } });
        } else {
          void this.router.navigate(['/tirages']);
        }
      },
      error: (error: unknown) => {
        this.busy.set(false);
        this.error.set(error instanceof HttpErrorResponse && error.status === 401 &&
          error.error?.error?.code === 'invalid_credentials'
          ? 'Identifiant ou mot de passe incorrect.'
          : 'Connexion impossible. Vérifiez la configuration de l’API et votre connexion, ou réessayez plus tard.');
      },
    });
  }

  protected requestPasswordReset(): void {
    if (this.busy() || this.forgotBusy()) {
      return;
    }
    const login = this.form.controls.login.value.trim();
    if (login === '') {
      this.form.controls.login.markAsTouched();
      this.forgotError.set('Renseignez votre identifiant avant de demander un nouveau mot de passe.');
      this.forgotMessage.set('');
      return;
    }

    this.forgotBusy.set(true);
    this.forgotError.set('');
    this.forgotMessage.set('');
    this.api.requestPasswordReset(login).subscribe({
      next: () => {
        this.forgotBusy.set(false);
        this.forgotMessage.set('Un email de réinitialisation a été envoyé si ce compte existe.');
      },
      error: (error: unknown) => {
        this.forgotBusy.set(false);
        this.forgotError.set(error instanceof HttpErrorResponse && error.status === 404 &&
          error.error?.error?.code === 'user_not_found'
          ? 'Aucun compte trouvé pour cet identifiant.'
          : error instanceof HttpErrorResponse && error.status === 422 &&
            error.error?.error?.code === 'invalid_user_email'
            ? 'Cet utilisateur est associé à un email invalide.'
            : 'Impossible d’envoyer l’email de réinitialisation. Réessayez plus tard.');
      },
    });
  }
}
