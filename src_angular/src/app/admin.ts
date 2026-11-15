import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatToolbarModule } from '@angular/material/toolbar';
import { Router, RouterLink } from '@angular/router';
import { Api, AdminData, AdminUser, Participant } from './api';
import { SessionService } from './session';

type DeleteChoice = { login?: string; name?: string };

@Component({
  selector: 'app-admin',
  imports: [ReactiveFormsModule, MatButtonModule, MatCardModule, MatCheckboxModule,
    MatFormFieldModule, MatInputModule, MatToolbarModule, RouterLink],
  templateUrl: './admin.html',
  styleUrl: './admin.scss',
})
export class Admin {
  private readonly api = inject(Api);
  private readonly session = inject(SessionService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);
  protected readonly user = this.session.session;
  protected readonly loading = signal(true);
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly message = signal('');
  protected readonly data = signal<AdminData | null>(null);
  protected readonly accountMode = signal<'create' | 'edit' | null>(null);
  protected readonly linkedParticipant = signal<string | null>(null);
  protected readonly editingParticipant = signal<string | null>(null);
  protected readonly participantFormOpen = signal(false);
  protected readonly selectedRecipients = signal<string[]>([]);
  protected readonly deleteChoice = signal<DeleteChoice | null>(null);
  protected readonly accountForm = this.fb.nonNullable.group({
    login: ['', Validators.required],
    email: ['', [Validators.required, Validators.email]],
    isAdmin: false,
    password: '',
    sendReset: false,
  });
  protected readonly participantForm = this.fb.nonNullable.group({
    name: ['', Validators.required],
  });

  constructor() {
    this.session.load().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (session) => {
        if (session.isAdmin) this.loadData();
        else this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Impossible de vérifier vos droits. Réessayez plus tard.');
      },
    });
  }

  protected loadData(): void {
    this.loading.set(true);
    this.api.adminData().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (data) => {
        this.data.set(data);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.loading.set(false);
        this.error.set(this.errorMessage(error));
      },
    });
  }

  protected newAccount(participant?: Participant): void {
    this.clearFeedback();
    this.accountForm.reset({
      login: participant?.suggestedLogin ?? '', email: '', isAdmin: false, password: '', sendReset: false,
    });
    this.linkedParticipant.set(participant?.name ?? null);
    this.accountMode.set('create');
  }

  protected editAccount(user: AdminUser): void {
    this.clearFeedback();
    this.accountForm.reset({ login: user.login, email: user.email, isAdmin: user.isAdmin, password: '', sendReset: false });
    this.linkedParticipant.set(null);
    this.accountMode.set('edit');
  }

  protected saveAccount(): void {
    if (this.busy() || this.accountForm.invalid) {
      this.accountForm.markAllAsTouched();
      return;
    }
    const mode = this.accountMode();
    if (mode === null) return;
    this.clearFeedback();
    this.busy.set(true);
    const { login, email, isAdmin, password, sendReset } = this.accountForm.getRawValue();
    const participant = this.linkedParticipant();
    const request = mode === 'create'
      ? this.api.createUser({ login, email, isAdmin, ...(participant === null ? {} : { participant }) })
      : this.api.editUser({ login, email, isAdmin, ...(password === '' ? {} : { password }) });
    request.pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: () => {
        this.accountForm.controls.password.reset();
        this.accountMode.set(null);
        if (mode === 'create' && sendReset) {
          this.api.adminReset(login).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
            next: () => this.finish('Compte créé et email de réinitialisation envoyé.'),
            error: () => {
              this.busy.set(false);
              this.error.set('Le compte a été créé, mais l’email n’a pas pu être envoyé. Vous pouvez réessayer depuis la liste.');
              this.loadData();
            },
          });
        } else if (mode === 'edit' && login === this.user()?.login) {
          this.session.load().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
            next: (session) => {
              this.busy.set(false);
              if (!session.isAdmin) void this.router.navigate(['/tirages']);
              else this.finish('Compte modifié.');
            },
            error: () => {
              this.busy.set(false);
              this.error.set('Le compte a été modifié, mais la session n’a pas pu être rechargée.');
            },
          });
        } else {
          this.finish(mode === 'create' ? 'Compte créé.' : 'Compte modifié.');
        }
      },
      error: (error: unknown) => this.fail(error),
    });
  }

  protected sendReset(login: string): void {
    if (this.busy()) return;
    this.clearFeedback();
    this.busy.set(true);
    this.api.adminReset(login).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: () => this.finish('Email de réinitialisation envoyé.'),
      error: (error: unknown) => {
        this.busy.set(false);
        this.error.set(`L’email de réinitialisation n’a pas pu être envoyé. ${this.errorMessage(error)}`);
      },
    });
  }

  protected newParticipant(): void {
    this.clearFeedback();
    this.editingParticipant.set(null);
    this.selectedRecipients.set([]);
    this.participantForm.reset({ name: '' });
    this.participantFormOpen.set(true);
  }

  protected editParticipant(participant: Participant): void {
    this.clearFeedback();
    this.editingParticipant.set(participant.name);
    this.selectedRecipients.set([...participant.forbiddenRecipients]);
    this.participantForm.reset({ name: participant.name });
    this.participantFormOpen.set(true);
  }

  protected toggleRecipient(name: string, checked: boolean): void {
    this.selectedRecipients.update((current) =>
      checked ? [...current, name] : current.filter((item) => item !== name));
  }

  protected saveParticipant(): void {
    if (this.busy() || this.participantForm.invalid) {
      this.participantForm.markAllAsTouched();
      return;
    }
    this.clearFeedback();
    this.busy.set(true);
    const name = this.participantForm.controls.name.value;
    const oldName = this.editingParticipant();
    const request = oldName === null ? this.api.createParticipant(name)
      : this.api.editParticipant(oldName, name, this.selectedRecipients());
    request.pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: () => {
        this.participantFormOpen.set(false);
        this.finish(oldName === null ? 'Participant créé.' : 'Participant modifié.');
      },
      error: (error: unknown) => this.fail(error),
    });
  }

  protected confirmDelete(choice: DeleteChoice): void {
    this.clearFeedback();
    this.deleteChoice.set(choice);
  }

  protected deleteSelected(): void {
    const choice = this.deleteChoice();
    if (choice === null || this.busy()) return;
    this.busy.set(true);
    this.clearFeedback();
    const deleteUser = () => {
      if (choice.login === undefined) {
        this.deleteChoice.set(null);
        this.finish('Participant supprimé.');
        return;
      }
      this.api.deleteUser(choice.login).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
        next: () => {
          this.deleteChoice.set(null);
          if (choice.login === this.user()?.login) {
            this.session.clear();
            void this.router.navigate(['/connexion']);
          } else {
            this.finish(choice.name ? 'Participant et compte supprimés.' : 'Compte supprimé.');
          }
        },
        error: (error: unknown) => {
          this.deleteChoice.set(null);
          this.busy.set(false);
          this.error.set(choice.name
            ? `Le participant a été supprimé, mais pas le compte. ${this.errorMessage(error)}`
            : this.errorMessage(error));
          this.loadData();
        },
      });
    };
    if (choice.name === undefined) {
      deleteUser();
    } else {
      this.api.deleteParticipant(choice.name).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
        next: deleteUser,
        error: (error: unknown) => this.fail(error),
      });
    }
  }

  private finish(message: string): void {
    this.busy.set(false);
    this.message.set(message);
    this.loadData();
  }

  private fail(error: unknown): void {
    this.busy.set(false);
    this.error.set(this.errorMessage(error));
  }

  private clearFeedback(): void {
    this.error.set('');
    this.message.set('');
  }

  private errorMessage(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
      switch (error.error?.error?.code) {
        case 'last_admin': return 'Le dernier administrateur ne peut pas être supprimé ou rétrogradé.';
        case 'login_exists': return 'Cet identifiant existe déjà.';
        case 'participant_exists': return 'Ce participant existe déjà.';
        case 'identity_mismatch': return 'Le nom et le login doivent correspondre sans tenir compte des accents ni de la casse.';
        case 'forbidden': return 'Vous ne disposez plus des droits administrateur.';
      }
    }
    return 'Opération impossible. Vérifiez les informations et réessayez.';
  }
}
