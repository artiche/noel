import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { routes } from './app.routes';
import { Draws } from './draws';
import { sessionInterceptor } from './session.interceptor';

describe('Consultation des tirages', () => {
  let http: HttpTestingController;
  let harness: RouterTestingHarness;
  const currentYear = new Date().getFullYear();

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

  it('affiche le tirage le plus récent et ses lignes', async () => {
    await harness.navigateByUrl('/tirages', Draws);
    http.expectOne(`/api/draw/${currentYear}`).flush(
      { error: { code: 'draw_not_found' } }, { status: 404, statusText: 'Not Found' },
    );
    http.expectOne(`/api/draw/${currentYear - 1}`).flush({
      data: { year: currentYear - 1, assignmentsByGiver: { Alice: 'Bob' }, lines: ['Alice offre un cadeau à Bob'] },
    });
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain(`Tirage ${currentYear - 1}`);
    expect(harness.routeNativeElement?.textContent).toContain('Alice offre un cadeau à Bob');
  });

  it('signale une année choisie absente sans chercher une autre année', async () => {
    await harness.navigateByUrl('/tirages/2021', Draws);
    http.expectOne('/api/draw/2021').flush(
      { error: { code: 'draw_not_found' } }, { status: 404, statusText: 'Not Found' },
    );
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('Aucun tirage disponible pour 2021');
    http.expectNone('/api/draw/2020');
  });

  it('propose les années depuis 2021 sans année future', async () => {
    await harness.navigateByUrl('/tirages/2021', Draws);
    http.expectOne('/api/draw/2021').flush({
      data: { year: 2021, assignmentsByGiver: {}, lines: [] },
    });
    harness.detectChanges();
    const menuButton = Array.from(harness.routeNativeElement!.querySelectorAll('button'))
      .find((button) => button.textContent?.includes('Années'))!;
    menuButton.click();
    harness.detectChanges();
    const options = Array.from(document.querySelectorAll('.mat-mdc-menu-item'))
      .map((item) => item.textContent?.trim());
    expect(options[0]).toBe(String(currentYear));
    expect(options[options.length - 1]).toBe('2021');
    expect(options).toHaveLength(currentYear - 2021 + 1);
  });

  it('affiche une erreur réseau sans essayer une année antérieure', async () => {
    await harness.navigateByUrl('/tirages', Draws);
    http.expectOne(`/api/draw/${currentYear}`).flush(
      { error: { code: 'internal_error' } }, { status: 500, statusText: 'Error' },
    );
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('Impossible de charger le tirage');
    http.expectNone(`/api/draw/${currentYear - 1}`);
  });

  it('annule la recherche précédente lors du changement d’année', async () => {
    await harness.navigateByUrl('/tirages', Draws);
    const pending = http.expectOne(`/api/draw/${currentYear}`);
    await harness.navigateByUrl('/tirages/2021', Draws);
    expect(pending.cancelled).toBe(true);
    http.expectOne('/api/draw/2021').flush({
      data: { year: 2021, assignmentsByGiver: {}, lines: ['Alice offre un cadeau à Bob'] },
    });
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('Tirage 2021');
  });

  it.each(['/tirages/2020', `/tirages/${currentYear + 1}`, '/tirages/abc'])(
    'refuse %s sans requête API',
    async (url) => {
      await harness.navigateByUrl(url, Draws);
      harness.detectChanges();
      expect(harness.routeNativeElement?.textContent).toContain('Année invalide');
      http.expectNone((request) => request.url.includes('/draw/'));
    },
  );

  it('redirige vers la connexion quand la session est absente', async () => {
    await harness.navigateByUrl('/tirages/2021', Draws);
    http.expectOne('/api/draw/2021').flush(
      { error: { code: 'missing_token' } }, { status: 401, statusText: 'Unauthorized' },
    );
    await harness.fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/connexion?session=expiree');
  });
});
