import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { provideNoopAnimations } from '@angular/platform-browser/animations';
import { MatDialog } from '@angular/material/dialog';
import { routes } from './app.routes';
import { Draws } from './draws';
import { sessionInterceptor } from './session.interceptor';
import { SessionService } from './session';

const confettiMock = vi.hoisted(() => Object.assign(vi.fn(), { reset: vi.fn() }));
vi.mock('canvas-confetti', () => ({ default: confettiMock }));

describe('Consultation des tirages', () => {
  let http: HttpTestingController;
  let harness: RouterTestingHarness;
  const currentYear = new Date().getFullYear();

  beforeEach(async () => {
    confettiMock.mockClear();
    confettiMock.reset.mockClear();
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
    TestBed.inject(SessionService).load().subscribe();
    http.expectOne('/api/session').flush({ data: { authenticated: true, isAdmin: false, login: 'alice', email: 'alice@example.com', participant: null, participantKey: null } });
  });

  afterEach(() => {
    http.verify();
    vi.unstubAllGlobals();
  });

  it('affiche le tirage le plus récent et ses lignes', async () => {
    await harness.navigateByUrl('/tirages', Draws);
    http.expectOne(`/api/draw/${currentYear}`).flush(
      { error: { code: 'draw_not_found' } }, { status: 404, statusText: 'Not Found' },
    );
    http.expectOne(`/api/draw/${currentYear - 1}`).flush({
      data: { year: currentYear - 1, assignmentsByGiver: { Alice: 'Bob' }, lines: ['Alice offre un cadeau à Bob'], assignments: [{ giver: 'Alice', recipient: 'Bob', giverKey: 'alice', recipientKey: 'bob' }] },
    });
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain(`Tirage ${currentYear - 1}`);
    expect(harness.routeNativeElement?.textContent).toContain('Alice offre un cadeau à Bob');
    expect(confettiMock).toHaveBeenCalledTimes(1);
  });

  it('met en évidence les deux rôles lorsque la session arrive après le tirage', async () => {
    const session = TestBed.inject(SessionService);
    session.clear();
    await harness.navigateByUrl('/tirages/2021', Draws);
    const pendingSession = http.expectOne('/api/session');
    const assignments = [
      { giver: 'BÉATRICE', recipient: 'Alpha offre un cadeau à Beta', giverKey: 'beatrice', recipientKey: 'alpha offre un cadeau a beta' },
      { giver: 'Bob', recipient: 'Beatrice', giverKey: 'bob', recipientKey: 'beatrice' },
      { giver: 'Beatrice 2', recipient: 'Carol', giverKey: 'beatrice 2', recipientKey: 'carol' },
      { giver: 'Œ', recipient: 'Oda', giverKey: 'œ', recipientKey: 'oda' },
      { giver: 'oe', recipient: 'Xavier', giverKey: 'oe', recipientKey: 'xavier' },
      { giver: 'Béatrice', recipient: 'Beatrice', giverKey: 'beatrice', recipientKey: 'beatrice' },
    ];
    http.expectOne('/api/draw/2021').flush({
      data: { year: 2021, assignmentsByGiver: {}, lines: [], assignments },
    });
    harness.detectChanges();
    const rows = () => Array.from(harness.routeNativeElement!.querySelectorAll('tr.mat-mdc-row'));
    expect(rows()).toHaveLength(6);
    expect(rows().filter((row) => row.classList.contains('giver') || row.classList.contains('recipient'))).toHaveLength(0);
    pendingSession.flush({ data: {
      authenticated: true, isAdmin: false, login: 'beatrice', email: 'b@example.com',
      participant: 'Béatrice', participantKey: 'beatrice',
    } });
    harness.detectChanges();
    http.expectOne(`/api/draw/${currentYear}`).flush({
      data: { year: currentYear, assignmentsByGiver: {}, lines: [], assignments: [] },
    });
    harness.detectChanges();
    expect(rows().map((row) => row.classList.contains('giver'))).toEqual([true, false, false, false, false, true]);
    expect(rows().map((row) => row.classList.contains('recipient'))).toEqual([false, true, false, false, false, true]);
    expect(Array.from(rows()[0].querySelectorAll('strong')).map((element) => element.textContent))
      .toEqual(['BÉATRICE', 'Alpha offre un cadeau à Beta']);
    expect(rows()[0].querySelector('.wishlist-link')).toBeNull();
    expect(rows()[1].querySelector('.wishlist-link')).toBeNull();
    expect(rows()[2].querySelector('.wishlist-link')).toBeNull();
    const cell = rows()[0].querySelector('td')!;
    expect(parseFloat(getComputedStyle(cell).fontSize)).toBeGreaterThanOrEqual(17.6);
    expect(getComputedStyle(cell).backgroundColor).toBe('rgb(228, 240, 226)');
    expect(getComputedStyle(rows()[1].querySelector('td')!).backgroundColor).toBe('rgb(255, 240, 214)');
    vi.stubGlobal('innerWidth', 320);
    expect(parseFloat(getComputedStyle(cell).fontSize)).toBeGreaterThanOrEqual(17.6);
    expect(getComputedStyle(cell).overflowWrap).toBe('anywhere');

    session.load().subscribe();
    http.expectOne('/api/session').flush({ data: {
      authenticated: true, isAdmin: false, login: 'œ', email: 'o@example.com',
      participant: 'Œ', participantKey: 'œ',
    } });
    harness.detectChanges();
    http.expectOne(`/api/draw/${currentYear}`).flush({
      data: { year: currentYear, assignmentsByGiver: {}, lines: [], assignments: [] },
    });
    harness.detectChanges();
    expect(rows().map((row) => row.classList.contains('giver'))).toEqual([false, false, false, true, false, false]);
    expect(rows().map((row) => row.classList.contains('recipient'))).toEqual([false, false, false, false, false, false]);
    session.clear();
    harness.detectChanges();
    expect(rows().filter((row) => row.classList.contains('giver') || row.classList.contains('recipient'))).toHaveLength(0);
  });

  it('affiche le lien uniquement pour le dernier tirage, même antérieur à l’année en cours', async () => {
    TestBed.inject(SessionService).load().subscribe();
    http.expectOne('/api/session').flush({ data: {
      authenticated: true, isAdmin: false, login: 'alice', email: 'alice@example.com',
      participant: 'Alice', participantKey: 'alice',
    } });
    const assignment = { giver: 'Alice', recipient: 'Bob', giverKey: 'alice', recipientKey: 'bob' };
    const draw = (year: number) => ({ data: { year, assignmentsByGiver: {}, lines: [], assignments: [assignment] } });

    await harness.navigateByUrl('/tirages', Draws);
    http.expectOne(`/api/draw/${currentYear}`).flush(
      { error: { code: 'draw_not_found' } }, { status: 404, statusText: 'Not Found' },
    );
    http.expectOne(`/api/draw/${currentYear - 1}`).flush(draw(currentYear - 1));
    harness.detectChanges();
    const link = () => harness.routeNativeElement!.querySelector<HTMLAnchorElement>('.wishlist-link');
    expect(link()).not.toBeNull();
    expect(TestBed.inject(Router).parseUrl(link()!.getAttribute('href')!).queryParams['target']).toBe('bob');

    await harness.navigateByUrl(`/tirages/${currentYear - 2}`, Draws);
    http.expectOne(`/api/draw/${currentYear - 2}`).flush(draw(currentYear - 2));
    harness.detectChanges();
    expect(link()).toBeNull();
    http.expectOne(`/api/draw/${currentYear}`).flush(
      { error: { code: 'draw_not_found' } }, { status: 404, statusText: 'Not Found' },
    );
    http.expectOne(`/api/draw/${currentYear - 1}`).flush(draw(currentYear - 1));
    harness.detectChanges();
    expect(link()).toBeNull();

    await harness.navigateByUrl(`/tirages/${currentYear - 1}`, Draws);
    http.expectOne(`/api/draw/${currentYear - 1}`).flush(draw(currentYear - 1));
    harness.detectChanges();
    expect(link()).toBeNull();
    http.expectOne(`/api/draw/${currentYear}`).flush(
      { error: { code: 'draw_not_found' } }, { status: 404, statusText: 'Not Found' },
    );
    harness.detectChanges();
    expect(link()).not.toBeNull();
    http.expectNone(`/api/draw/${currentYear - 1}`);
  });

  it('signale une erreur de vérification et permet de réessayer', async () => {
    TestBed.inject(SessionService).load().subscribe();
    http.expectOne('/api/session').flush({ data: {
      authenticated: true, isAdmin: false, login: 'alice', email: 'alice@example.com',
      participant: 'Alice', participantKey: 'alice',
    } });
    await harness.navigateByUrl(`/tirages/${currentYear - 1}`, Draws);
    http.expectOne(`/api/draw/${currentYear - 1}`).flush({
      data: { year: currentYear - 1, assignmentsByGiver: {}, lines: [],
        assignments: [{ giver: 'Alice', recipient: 'Bob', giverKey: 'alice', recipientKey: 'bob' }] },
    });
    harness.detectChanges();
    http.expectOne(`/api/draw/${currentYear}`).flush(
      { error: { code: 'internal_error' } }, { status: 500, statusText: 'Error' },
    );
    harness.detectChanges();
    const root = harness.routeNativeElement!;
    expect(root.querySelector('.wishlist-link')).toBeNull();
    expect(root.textContent).toContain('Impossible de vérifier le dernier tirage');
    Array.from(root.querySelectorAll('button')).find((button) => button.textContent?.includes('Réessayer'))!.click();
    harness.detectChanges();
    http.expectOne(`/api/draw/${currentYear}`).flush(
      { error: { code: 'draw_not_found' } }, { status: 404, statusText: 'Not Found' },
    );
    harness.detectChanges();
    expect(root.querySelector('.wishlist-link')).not.toBeNull();
    expect(root.textContent).not.toContain('Impossible de vérifier le dernier tirage');
  });

  it('annule la vérification en changeant de tirage', async () => {
    TestBed.inject(SessionService).load().subscribe();
    http.expectOne('/api/session').flush({ data: {
      authenticated: true, isAdmin: false, login: 'alice', email: 'alice@example.com',
      participant: 'Alice', participantKey: 'alice',
    } });
    const assignment = { giver: 'Alice', recipient: 'Bob', giverKey: 'alice', recipientKey: 'bob' };
    const draw = (year: number) => ({ data: { year, assignmentsByGiver: {}, lines: [], assignments: [assignment] } });
    await harness.navigateByUrl(`/tirages/${currentYear - 1}`, Draws);
    http.expectOne(`/api/draw/${currentYear - 1}`).flush(draw(currentYear - 1));
    harness.detectChanges();
    const pending = http.expectOne(`/api/draw/${currentYear}`);
    await harness.navigateByUrl(`/tirages/${currentYear}`, Draws);
    harness.detectChanges();
    expect(pending.cancelled).toBe(true);
    http.expectOne(`/api/draw/${currentYear}`).flush(draw(currentYear));
    harness.detectChanges();
    expect(harness.routeNativeElement!.querySelector('.wishlist-link')).not.toBeNull();
    http.expectNone(`/api/draw/${currentYear}`);
  });

  it('ne montre pas le lien d’administration à un utilisateur non administrateur', async () => {
    await harness.navigateByUrl('/tirages/2021', Draws);
    http.expectOne('/api/draw/2021').flush({ data: { year: 2021, assignmentsByGiver: {}, lines: [], assignments: [] } });
    harness.detectChanges();
    expect(harness.routeNativeElement?.querySelector('a[href="/administration"]')).toBeNull();
  });

  it('modifie le compte depuis le menu utilisateur', async () => {
    await harness.navigateByUrl('/tirages/2021', Draws);
    http.expectOne('/api/draw/2021').flush({ data: { year: 2021, assignmentsByGiver: {}, lines: [], assignments: [] } });
    harness.detectChanges();
    const trigger = harness.routeNativeElement!.querySelector<HTMLButtonElement>('[aria-label="Menu utilisateur"]')!;
    expect(trigger.textContent).toContain('alice');
    trigger.click();
    harness.detectChanges();
    Array.from(document.querySelectorAll<HTMLButtonElement>('.mat-mdc-menu-item'))
      .find((item) => item.textContent?.includes('Modifier'))!.click();
    harness.detectChanges();
    const root = harness.routeNativeElement as HTMLElement;
    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!;
    expect(dialog.getAttribute('aria-label')).toBe('Modifier mon compte');
    expect(root.querySelector('form')).toBeNull();
    const email = dialog.querySelector<HTMLInputElement>('input[formControlName=email]')!;
    expect(email.value).toBe('alice@example.com');
    email.value = 'new@example.com';
    email.dispatchEvent(new Event('input'));
    const password = dialog.querySelector<HTMLInputElement>('input[formControlName=currentPassword]')!;
    password.value = 'secret';
    password.dispatchEvent(new Event('input'));
    dialog.querySelector('form')!.dispatchEvent(new Event('submit'));
    const update = http.expectOne('/api/account');
    expect(update.request.body).toEqual({ currentPassword: 'secret', email: 'new@example.com' });
    harness.detectChanges();
    expect(dialog.querySelector<HTMLButtonElement>('button[type=button]')!.disabled).toBe(true);
    expect(TestBed.inject(MatDialog).openDialogs[0].disableClose).toBe(true);
    update.flush({ data: { updated: true } });
    expect(TestBed.inject(MatDialog).openDialogs[0].disableClose).toBe(true);
    http.expectOne('/api/session').flush({
      data: { authenticated: true, isAdmin: false, login: 'alice', email: 'new@example.com', participant: null, participantKey: null },
    });
    harness.detectChanges();
    await harness.fixture.whenStable();
    expect(root.textContent).toContain('Votre compte a été modifié');
    expect(document.querySelector('[role="dialog"]')).toBeNull();
  });

  it('affiche une erreur si le mot de passe actuel est incorrect', async () => {
    await harness.navigateByUrl('/tirages/2021', Draws);
    http.expectOne('/api/draw/2021').flush({ data: { year: 2021, assignmentsByGiver: {}, lines: [], assignments: [] } });
    harness.detectChanges();
    harness.routeNativeElement!.querySelector<HTMLButtonElement>('[aria-label="Menu utilisateur"]')!.click();
    harness.detectChanges();
    Array.from(document.querySelectorAll<HTMLButtonElement>('.mat-mdc-menu-item'))
      .find((item) => item.textContent?.includes('Modifier'))!.click();
    harness.detectChanges();
    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!;
    const password = dialog.querySelector<HTMLInputElement>('input[formControlName=currentPassword]')!;
    password.value = 'wrong';
    password.dispatchEvent(new Event('input'));
    const newPassword = dialog.querySelector<HTMLInputElement>('input[formControlName=newPassword]')!;
    newPassword.value = 'new';
    newPassword.dispatchEvent(new Event('input'));
    dialog.querySelector('form')!.dispatchEvent(new Event('submit'));
    http.expectOne('/api/account').flush(
      { error: { code: 'invalid_current_password' } }, { status: 403, statusText: 'Forbidden' },
    );
    harness.detectChanges();
    expect(dialog.textContent).toContain('Le mot de passe actuel est incorrect');
    expect(TestBed.inject(MatDialog).openDialogs[0].disableClose).toBe(false);
    expect(TestBed.inject(SessionService).session()?.login).toBe('alice');
    dialog.querySelector<HTMLButtonElement>('button[type=button]')!.click();
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(document.querySelector('[role="dialog"]')).toBeNull();
    expect(harness.routeNativeElement?.textContent).not.toContain('Le mot de passe actuel est incorrect');
  });

  it('signale sur la page si le compte est modifié mais que la session ne se recharge pas', async () => {
    await harness.navigateByUrl('/tirages/2021', Draws);
    http.expectOne('/api/draw/2021').flush({ data: { year: 2021, assignmentsByGiver: {}, lines: [], assignments: [] } });
    harness.detectChanges();
    harness.routeNativeElement!.querySelector<HTMLButtonElement>('[aria-label="Menu utilisateur"]')!.click();
    harness.detectChanges();
    Array.from(document.querySelectorAll<HTMLButtonElement>('.mat-mdc-menu-item'))
      .find((item) => item.textContent?.includes('Modifier'))!.click();
    harness.detectChanges();
    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!;
    const password = dialog.querySelector<HTMLInputElement>('input[formControlName=currentPassword]')!;
    password.value = 'secret';
    password.dispatchEvent(new Event('input'));
    const newPassword = dialog.querySelector<HTMLInputElement>('input[formControlName=newPassword]')!;
    newPassword.value = 'new';
    newPassword.dispatchEvent(new Event('input'));
    dialog.querySelector('form')!.dispatchEvent(new Event('submit'));
    http.expectOne('/api/account').flush({ data: { updated: true } });
    http.expectOne('/api/session').flush(
      { error: { code: 'internal_error' } }, { status: 500, statusText: 'Error' },
    );
    harness.detectChanges();
    await harness.fixture.whenStable();
    expect(document.querySelector('[role="dialog"]')).toBeNull();
    expect(harness.routeNativeElement?.textContent).toContain('Le compte a été modifié, mais la session');
  });

  it('annule la modification et efface les mots de passe avant une réouverture', async () => {
    await harness.navigateByUrl('/tirages/2021', Draws);
    http.expectOne('/api/draw/2021').flush({ data: { year: 2021, assignmentsByGiver: {}, lines: [], assignments: [] } });
    harness.detectChanges();
    const open = () => {
      harness.routeNativeElement!.querySelector<HTMLButtonElement>('[aria-label="Menu utilisateur"]')!.click();
      harness.detectChanges();
      Array.from(document.querySelectorAll<HTMLButtonElement>('.mat-mdc-menu-item'))
        .find((item) => item.textContent?.includes('Modifier'))!.click();
      harness.detectChanges();
      return document.querySelector<HTMLElement>('[role="dialog"]')!;
    };
    const dialog = open();
    const password = dialog.querySelector<HTMLInputElement>('input[formControlName=currentPassword]')!;
    password.value = 'secret';
    password.dispatchEvent(new Event('input'));
    dialog.querySelector<HTMLButtonElement>('button[type=button]')!.click();
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(document.querySelector('[role="dialog"]')).toBeNull();
    http.expectNone('/api/account');
    const reopened = open();
    expect(reopened.querySelector<HTMLInputElement>('input[formControlName=currentPassword]')!.value).toBe('');
    await harness.navigateByUrl('/connexion');
    await harness.fixture.whenStable();
    expect(document.querySelector('[role="dialog"]')).toBeNull();
  });

  it('signale une année choisie absente sans chercher une autre année', async () => {
    await harness.navigateByUrl('/tirages/2021', Draws);
    http.expectOne('/api/draw/2021').flush(
      { error: { code: 'draw_not_found' } }, { status: 404, statusText: 'Not Found' },
    );
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('Aucun tirage disponible pour 2021');
    http.expectNone('/api/draw/2020');
    expect(confettiMock).not.toHaveBeenCalled();
  });

  it('propose les années depuis 2021 sans année future', async () => {
    await harness.navigateByUrl('/tirages/2021', Draws);
    http.expectOne('/api/draw/2021').flush({
      data: { year: 2021, assignmentsByGiver: {}, lines: [], assignments: [] },
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
    expect(confettiMock).not.toHaveBeenCalled();
  });

  it('annule la recherche précédente lors du changement d’année', async () => {
    await harness.navigateByUrl('/tirages', Draws);
    const pending = http.expectOne(`/api/draw/${currentYear}`);
    await harness.navigateByUrl('/tirages/2021', Draws);
    expect(pending.cancelled).toBe(true);
    http.expectOne('/api/draw/2021').flush({
      data: { year: 2021, assignmentsByGiver: { Alice: 'Bob' }, lines: ['Alice offre un cadeau à Bob'], assignments: [{ giver: 'Alice', recipient: 'Bob', giverKey: 'alice', recipientKey: 'bob' }] },
    });
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('Tirage 2021');
    expect(confettiMock).toHaveBeenCalledTimes(1);
  });

  it('relance les confettis uniquement après le chargement de chaque tirage', async () => {
    await harness.navigateByUrl('/tirages/2021', Draws);
    http.expectOne('/api/draw/2021').flush({
      data: { year: 2021, assignmentsByGiver: { Alice: 'Bob' }, lines: ['Alice offre un cadeau à Bob'], assignments: [{ giver: 'Alice', recipient: 'Bob', giverKey: 'alice', recipientKey: 'bob' }] },
    });
    expect(confettiMock).toHaveBeenCalledTimes(1);
    await harness.navigateByUrl('/tirages/2022', Draws);
    expect(confettiMock).toHaveBeenCalledTimes(1);
    http.expectOne('/api/draw/2022').flush({
      data: { year: 2022, assignmentsByGiver: { Bob: 'Alice' }, lines: ['Bob offre un cadeau à Alice'], assignments: [{ giver: 'Bob', recipient: 'Alice', giverKey: 'bob', recipientKey: 'alice' }] },
    });
    expect(confettiMock).toHaveBeenCalledTimes(2);
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('Bob offre un cadeau à Alice');
  });

  it('affiche immédiatement le résultat sans confettis si les animations sont réduites', async () => {
    const preference = {
      matches: true,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    };
    vi.stubGlobal('matchMedia', vi.fn(() => preference));
    await harness.navigateByUrl('/tirages/2021', Draws);
    http.expectOne('/api/draw/2021').flush({
      data: { year: 2021, assignmentsByGiver: { Alice: 'Bob' }, lines: ['Alice offre un cadeau à Bob'], assignments: [{ giver: 'Alice', recipient: 'Bob', giverKey: 'alice', recipientKey: 'bob' }] },
    });
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('Alice offre un cadeau à Bob');
    expect(confettiMock).not.toHaveBeenCalled();
  });

  it('arrête les confettis si la préférence change et retire son écouteur en quittant la page', async () => {
    let changeHandler: ((event: MediaQueryListEvent) => void) | undefined;
    const preference = {
      matches: false,
      addEventListener: vi.fn((_name: string, handler: (event: MediaQueryListEvent) => void) => {
        changeHandler = handler;
      }),
      removeEventListener: vi.fn(),
    };
    vi.stubGlobal('matchMedia', vi.fn(() => preference));
    await harness.navigateByUrl('/tirages/2021', Draws);
    http.expectOne('/api/draw/2021').flush({
      data: { year: 2021, assignmentsByGiver: { Alice: 'Bob' }, lines: ['Alice offre un cadeau à Bob'], assignments: [{ giver: 'Alice', recipient: 'Bob', giverKey: 'alice', recipientKey: 'bob' }] },
    });
    expect(confettiMock).toHaveBeenCalledTimes(1);
    confettiMock.reset.mockClear();
    changeHandler?.({ matches: true } as MediaQueryListEvent);
    expect(confettiMock.reset).toHaveBeenCalledTimes(1);
    await harness.navigateByUrl('/tirages/2022', Draws);
    http.expectOne('/api/draw/2022').flush({
      data: { year: 2022, assignmentsByGiver: { Bob: 'Alice' }, lines: ['Bob offre un cadeau à Alice'], assignments: [{ giver: 'Bob', recipient: 'Alice', giverKey: 'bob', recipientKey: 'alice' }] },
    });
    expect(confettiMock).toHaveBeenCalledTimes(1);
    await harness.navigateByUrl('/connexion');
    expect(preference.removeEventListener).toHaveBeenCalledWith('change', changeHandler);
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
