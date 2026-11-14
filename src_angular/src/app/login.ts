import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { ActivatedRoute, Router } from '@angular/router';
import { Api } from './api';

@Component({
  selector: 'app-login',
  imports: [ReactiveFormsModule, MatButtonModule, MatCardModule, MatFormFieldModule, MatInputModule],
  templateUrl: './login.html',
  styleUrl: './login.scss',
})
export class Login {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly formBuilder = inject(FormBuilder);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
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
    this.api.login(login, password).subscribe({
      next: () => {
        this.form.controls.password.reset();
        void this.router.navigate(['/tirages']);
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
}
