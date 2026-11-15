import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Api, Draw, FIRST_YEAR } from './api';

describe('API des tirages', () => {
  let api: Api;
  let http: HttpTestingController;
  const draw: Draw = {
    year: 2022,
    assignmentsByGiver: { Alice: 'Bob' },
    lines: ['Alice offre un cadeau à Bob'],
    assignments: [{ giver: 'Alice', recipient: 'Bob', giverKey: 'alice', recipientKey: 'bob' }],
  };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(Api);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('envoie les identifiants sans exposer de jeton', () => {
    api.login('alice', 'secret').subscribe();
    const request = http.expectOne('/api/login');
    expect(request.request.method).toBe('POST');
    expect(request.request.withCredentials).toBe(true);
    expect(request.request.body).toEqual({ login: 'alice', password: 'secret' });
    request.flush({ data: { authenticated: true } });
  });

  it('demande un reset de mot de passe avec le login', () => {
    api.requestPasswordReset('alice').subscribe();
    const request = http.expectOne('/api/password/forgot');
    expect(request.request.method).toBe('POST');
    expect(request.request.withCredentials).toBe(true);
    expect(request.request.body).toEqual({ login: 'alice' });
    request.flush({ data: { passwordResetRequested: true } });
  });

  it('refuse une réponse de session sans rôle booléen', () => {
    let failed = false;
    api.session().subscribe({ error: () => failed = true });
    http.expectOne('/api/session').flush({ data: { authenticated: true, isAdmin: 'true' } });
    expect(failed).toBe(true);
  });

  it('refuse une session sans clé normalisée cohérente', () => {
    let failed = false;
    api.session().subscribe({ error: () => failed = true });
    http.expectOne('/api/session').flush({
      data: { authenticated: true, isAdmin: false, login: 'alice', email: 'alice@example.com',
        participant: 'Alice', participantKey: null },
    });
    expect(failed).toBe(true);
  });

  it('refuse un tirage sans attribution structurée valide', () => {
    let failed = false;
    api.draw(2022).subscribe({ error: () => failed = true });
    http.expectOne('/api/draw/2022').flush({
      data: { ...draw, assignments: [{ giver: 'Alice', recipient: 'Bob', giverKey: 'alice' }] },
    });
    expect(failed).toBe(true);
  });

  it('recherche le tirage le plus récent uniquement après un draw_not_found', () => {
    let result: Draw | null | undefined;
    api.latestDraw(2024).subscribe((value) => result = value);
    http.expectOne('/api/draw/2024').flush(
      { error: { code: 'draw_not_found' } }, { status: 404, statusText: 'Not Found' },
    );
    http.expectOne('/api/draw/2023').flush(
      { error: { code: 'draw_not_found' } }, { status: 404, statusText: 'Not Found' },
    );
    http.expectOne('/api/draw/2022').flush({ data: draw });
    expect(result).toEqual(draw);
  });

  it('s’arrête à 2021 si aucun tirage n’existe', () => {
    let result: Draw | null | undefined;
    api.latestDraw(FIRST_YEAR).subscribe((value) => result = value);
    http.expectOne('/api/draw/2021').flush(
      { error: { code: 'draw_not_found' } }, { status: 404, statusText: 'Not Found' },
    );
    expect(result).toBeNull();
    http.expectNone('/api/draw/2020');
  });

  it.each([
    { status: 404, body: { error: { code: 'not_found' } } },
    { status: 401, body: { error: { code: 'missing_token' } } },
    { status: 500, body: { error: { code: 'internal_error' } } },
  ])('ne masque pas une erreur API $status', ({ status, body }) => {
    let receivedError = false;
    api.latestDraw(2024).subscribe({ error: () => receivedError = true });
    http.expectOne('/api/draw/2024').flush(body, { status, statusText: 'Error' });
    expect(receivedError).toBe(true);
    http.expectNone('/api/draw/2023');
  });
});
