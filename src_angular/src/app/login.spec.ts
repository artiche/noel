import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { provideNoopAnimations } from '@angular/platform-browser/animations';
import { routes } from './app.routes';
import { Login } from './login';
import { sessionInterceptor } from './session.interceptor';
import { SessionService } from './session';

vi.mock('canvas-confetti', () => ({
  default: Object.assign(vi.fn(), { reset: vi.fn() }),
}));

describe('Connexion et déconnexion', () => {
  let http: HttpTestingController;
  let harness: RouterTestingHarness;

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter(routes),
        provideHttpClient(withInterceptors([sessionInterceptor])),
        provideHttpClientTesting(),
        provideNoopAnimations(),
      ],
    });
    http = TestBed.inject(HttpTestingController);
    harness = await RouterTestingHarness.create();
  });

  afterEach(() => http.verify());

  async function submitLogin(): Promise<void> {
    await harness.navigateByUrl('/connexion', Login);
    const root = harness.routeNativeElement as HTMLElement;
    const login = root.querySelector<HTMLInputElement>('input[formControlName=login]')!;
    const password = root.querySelector<HTMLInputElement>('input[formControlName=password]')!;
    login.value = 'alice';
    login.dispatchEvent(new Event('input'));
    password.value = 'secret';
    password.dispatchEvent(new Event('input'));
    root.querySelector('form')!.dispatchEvent(new Event('submit'));
    harness.detectChanges();
  }

  it('affiche une erreur sur des identifiants invalides sans redirection', async () => {
    await submitLogin();
    http.expectOne('/api/login').flush(
      { error: { code: 'invalid_credentials' } }, { status: 401, statusText: 'Unauthorized' },
    );
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('Identifiant ou mot de passe incorrect');
    expect(TestBed.inject(Router).url).toBe('/connexion');
  });

  it('ne présente pas une erreur de configuration comme un mot de passe invalide', async () => {
    await submitLogin();
    http.expectOne('/api/login').flush(
      { error: { code: 'missing_token' } }, { status: 401, statusText: 'Unauthorized' },
    );
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('Vérifiez la configuration de l’API');
    expect(harness.routeNativeElement?.textContent).not.toContain('Identifiant ou mot de passe incorrect');
  });

  it('se connecte puis se déconnecte via l’API', async () => {
    await submitLogin();
    http.expectOne('/api/login').flush({ data: { authenticated: true } });
    http.expectOne('/api/session').flush({ data: { authenticated: true, isAdmin: true } });
    await harness.fixture.whenStable();
    http.expectOne(`/api/draw/${new Date().getFullYear()}`).flush({
      data: { year: new Date().getFullYear(), assignmentsByGiver: {}, lines: [] },
    });
    harness.detectChanges();
    const button = Array.from(harness.routeNativeElement!.querySelectorAll('button'))
      .find((element) => element.textContent?.includes('Déconnexion'))!;
    button.click();
    const request = http.expectOne('/api/logout');
    expect(request.request.method).toBe('POST');
    expect(request.request.withCredentials).toBe(true);
    request.flush({ data: { authenticated: false } });
    await harness.fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/connexion');
    expect(TestBed.inject(SessionService).session()).toBeNull();
  });

  it('demande un nouveau mot de passe avec le login saisi', async () => {
    await harness.navigateByUrl('/connexion', Login);
    const root = harness.routeNativeElement as HTMLElement;
    const login = root.querySelector<HTMLInputElement>('input[formControlName=login]')!;
    login.value = 'alice';
    login.dispatchEvent(new Event('input'));
    const forgotButton = root.querySelector<HTMLButtonElement>('[data-testid=forgot-password-button]')!;
    forgotButton.click();
    const request = http.expectOne('/api/password/forgot');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ login: 'alice' });
    request.flush({ data: { passwordResetRequested: true } });
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('Un email de réinitialisation a été envoyé');
    expect(TestBed.inject(Router).url).toBe('/connexion');
  });

  it('empêche la demande de nouveau mot de passe sans login', async () => {
    await harness.navigateByUrl('/connexion', Login);
    const root = harness.routeNativeElement as HTMLElement;
    const forgotButton = root.querySelector<HTMLButtonElement>('[data-testid=forgot-password-button]')!;
    forgotButton.click();
    harness.detectChanges();
    http.expectNone('/api/password/forgot');
    expect(harness.routeNativeElement?.textContent).toContain('Renseignez votre identifiant');
  });

  it('reste sur la page en cas d’échec de déconnexion', async () => {
    await harness.navigateByUrl('/tirages/2021');
    http.expectOne('/api/session').flush({ data: { authenticated: true, isAdmin: false } });
    http.expectOne('/api/draw/2021').flush({
      data: { year: 2021, assignmentsByGiver: {}, lines: [] },
    });
    harness.detectChanges();
    const button = Array.from(harness.routeNativeElement!.querySelectorAll('button'))
      .find((element) => element.textContent?.includes('Déconnexion'))!;
    button.click();
    http.expectOne('/api/logout').flush(
      { error: { code: 'internal_error' } }, { status: 500, statusText: 'Error' },
    );
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('La déconnexion a échoué');
    expect(TestBed.inject(Router).url).toBe('/tirages/2021');
    expect(button.disabled).toBe(false);
  });

  it.each([true, false])('récupère le rôle %s après connexion', async (isAdmin) => {
    await submitLogin();
    http.expectOne('/api/login').flush({ data: { authenticated: true } });
    const request = http.expectOne('/api/session');
    expect(request.request.withCredentials).toBe(true);
    request.flush({ data: { authenticated: true, isAdmin } });
    await harness.fixture.whenStable();
    expect(TestBed.inject(SessionService).session()).toEqual({ authenticated: true, isAdmin });
    http.expectOne(`/api/draw/${new Date().getFullYear()}`).flush({
      data: { year: new Date().getFullYear(), assignmentsByGiver: {}, lines: [] },
    });
  });

  it.each([true, false])('restaure le rôle %s à l’ouverture directe', async (isAdmin) => {
    await harness.navigateByUrl('/tirages/2021');
    http.expectOne('/api/session').flush({ data: { authenticated: true, isAdmin } });
    http.expectOne('/api/draw/2021').flush({ data: { year: 2021, assignmentsByGiver: {}, lines: [] } });
    expect(TestBed.inject(SessionService).session()).toEqual({ authenticated: true, isAdmin });
  });

  it('signale une erreur de chargement de session et permet de réessayer', async () => {
    await harness.navigateByUrl('/tirages/2021');
    http.expectOne('/api/session').flush({}, { status: 500, statusText: 'Error' });
    http.expectOne('/api/draw/2021').flush({ data: { year: 2021, assignmentsByGiver: {}, lines: [] } });
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('Impossible de charger la session');
    expect(TestBed.inject(SessionService).session()).toBeNull();
    const retry = Array.from(harness.routeNativeElement!.querySelectorAll('button'))
      .find((button) => button.textContent?.includes('Réessayer'))!;
    retry.click();
    http.expectOne('/api/session').flush({ data: { authenticated: true, isAdmin: true } });
    expect(TestBed.inject(SessionService).session()?.isAdmin).toBe(true);
  });

  it('efface la session et redirige à son expiration', async () => {
    await harness.navigateByUrl('/tirages/2021');
    http.expectOne('/api/session').flush({ data: { authenticated: true, isAdmin: true } });
    http.expectOne('/api/draw/2021').flush(
      { error: { code: 'token_expired' } }, { status: 401, statusText: 'Unauthorized' },
    );
    await harness.fixture.whenStable();
    expect(TestBed.inject(SessionService).session()).toBeNull();
    expect(TestBed.inject(Router).url).toBe('/connexion?session=expiree');
  });

  it('ne restaure pas une session après sa suppression si une réponse arrive tardivement', () => {
    const session = TestBed.inject(SessionService);
    session.load().subscribe();
    const pending = http.expectOne('/api/session');
    session.clear();
    pending.flush({ data: { authenticated: true, isAdmin: true } });
    expect(session.session()).toBeNull();
  });
});
