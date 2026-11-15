import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideNoopAnimations } from '@angular/platform-browser/animations';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { Admin } from './admin';
import { routes } from './app.routes';
import { sessionInterceptor } from './session.interceptor';

describe('Administration', () => {
  let http: HttpTestingController;
  let harness: RouterTestingHarness;
  const session = { authenticated: true, isAdmin: true, login: 'admin', email: 'admin@example.com', participant: null, participantKey: null };
  const data = {
    users: [{ login: 'admin', email: 'admin@example.com', isAdmin: true }],
    participants: [
      { name: 'Béatrice', login: null, suggestedLogin: 'beatrice', forbiddenRecipients: [] },
      { name: 'Bob', login: 'bob', suggestedLogin: 'bob', forbiddenRecipients: ['Béatrice'] },
    ],
  };

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [provideRouter(routes), provideHttpClient(withInterceptors([sessionInterceptor])),
        provideHttpClientTesting(), provideNoopAnimations()],
    });
    http = TestBed.inject(HttpTestingController);
    harness = await RouterTestingHarness.create();
  });

  afterEach(() => http.verify());

  async function open(): Promise<HTMLElement> {
    await harness.navigateByUrl('/administration', Admin);
    http.expectOne('/api/session').flush({ data: session });
    http.expectOne('/api/admin/users').flush({ data });
    harness.detectChanges();
    return harness.routeNativeElement as HTMLElement;
  }

  function button(root: ParentNode, label: string): HTMLButtonElement {
    return Array.from(root.querySelectorAll<HTMLButtonElement>('button'))
      .find((element) => element.textContent?.trim() === label)!;
  }

  function enter(root: ParentNode, name: string, value: string): void {
    const input = root.querySelector<HTMLInputElement>(`input[formControlName=${name}]`)!;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  it('refuse l’accès direct aux non-administrateurs', async () => {
    await harness.navigateByUrl('/administration', Admin);
    http.expectOne('/api/session').flush({ data: { ...session, isAdmin: false } });
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('réservée aux administrateurs');
    http.expectNone('/api/admin/users');
  });

  it('préremplit le login lors de la création du compte d’un participant', async () => {
    const root = await open();
    const row = Array.from(root.querySelectorAll('.entry'))
      .find((entry) => entry.textContent?.includes('Béatrice'))!;
    button(row, 'Créer son compte').click();
    harness.detectChanges();
    const form = root.querySelector<HTMLFormElement>('form')!;
    expect(form.querySelector<HTMLInputElement>('input[formControlName=login]')!.value).toBe('beatrice');
    enter(form, 'email', 'bea@example.com');
    form.dispatchEvent(new Event('submit'));
    const create = http.expectOne('/api/admin/users');
    expect(create.request.body).toEqual({ login: 'beatrice', email: 'bea@example.com', isAdmin: false, participant: 'Béatrice' });
    create.flush({ data: { updated: true } });
    http.expectOne('/api/admin/users').flush({ data });
    harness.detectChanges();
    expect(root.textContent).toContain('Compte créé.');
  });

  it('signale un compte créé même si l’email échoue', async () => {
    const root = await open();
    button(root, 'Créer un compte').click();
    harness.detectChanges();
    const form = root.querySelector<HTMLFormElement>('form')!;
    enter(form, 'login', 'eve');
    enter(form, 'email', 'eve@example.com');
    const checkbox = Array.from(form.querySelectorAll('mat-checkbox'))
      .find((item) => item.textContent?.includes('Envoyer un email'))!;
    checkbox.querySelector<HTMLInputElement>('input')!.click();
    form.dispatchEvent(new Event('submit'));
    http.expectOne('/api/admin/users').flush({ data: { updated: true } });
    const reset = http.expectOne('/api/admin/users/reset');
    expect(reset.request.body).toEqual({ login: 'eve' });
    reset.flush({ error: { code: 'mail_send_failed' } }, { status: 500, statusText: 'Error' });
    http.expectOne('/api/admin/users').flush({ data });
    harness.detectChanges();
    expect(root.textContent).toContain('Le compte a été créé, mais l’email');
  });

  it('modifie la graphie et les interdictions du participant', async () => {
    const root = await open();
    const row = Array.from(root.querySelectorAll('.entry'))
      .find((entry) => entry.textContent?.includes('Sans compte'))!;
    button(row, 'Modifier').click();
    harness.detectChanges();
    const form = root.querySelector<HTMLFormElement>('form')!;
    enter(form, 'name', 'BEATRICE');
    const checkbox = Array.from(form.querySelectorAll('mat-checkbox'))
      .find((item) => item.textContent?.includes('Bob'))!;
    checkbox.querySelector<HTMLInputElement>('input')!.click();
    form.dispatchEvent(new Event('submit'));
    const update = http.expectOne('/api/admin/participants');
    expect(update.request.method).toBe('PATCH');
    expect(update.request.body).toEqual({ name: 'Béatrice', newName: 'BEATRICE', forbiddenRecipients: ['Bob'] });
    update.flush({ data: { updated: true } });
    http.expectOne('/api/admin/users').flush({ data });
  });

  it('signale une suppression partielle si le compte ne peut pas être supprimé', async () => {
    const root = await open();
    const row = Array.from(root.querySelectorAll('.entry'))
      .find((entry) => entry.textContent?.includes('Compte : bob'))!;
    button(row, 'Supprimer les deux').click();
    harness.detectChanges();
    button(root, 'Confirmer').click();
    const participant = http.expectOne('/api/admin/participants');
    expect(participant.request.body).toEqual({ name: 'Bob' });
    participant.flush({ data: { updated: true } });
    const account = http.expectOne('/api/admin/users');
    expect(account.request.body).toEqual({ login: 'bob' });
    account.flush({ error: { code: 'internal_error' } }, { status: 500, statusText: 'Error' });
    http.expectOne('/api/admin/users').flush({ data });
    harness.detectChanges();
    expect(root.textContent).toContain('Le participant a été supprimé, mais pas le compte');
  });
});
