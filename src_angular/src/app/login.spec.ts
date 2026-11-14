import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { routes } from './app.routes';
import { Login } from './login';
import { sessionInterceptor } from './session.interceptor';

describe('Connexion et déconnexion', () => {
  let http: HttpTestingController;
  let harness: RouterTestingHarness;

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter(routes),
        provideHttpClient(withInterceptors([sessionInterceptor])),
        provideHttpClientTesting(),
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
  });

  it('reste sur la page en cas d’échec de déconnexion', async () => {
    await harness.navigateByUrl('/tirages/2021');
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
});
