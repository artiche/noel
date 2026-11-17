import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideNoopAnimations } from '@angular/platform-browser/animations';
import { MatDialog } from '@angular/material/dialog';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import Quill from 'quill';
import { routes } from './app.routes';
import { sessionInterceptor } from './session.interceptor';
import { Wishlist } from './wishlist';
import { Wishlist as WishlistData } from './api';

describe('Wishlists', () => {
  let http: HttpTestingController;
  let harness: RouterTestingHarness;
  const session = { authenticated: true, isAdmin: false, login: 'bob', email: 'bob@example.com',
    participant: 'Bob', participantKey: 'bob' };
  const wishlist: WishlistData = { participant: 'Bob', ops: [{ insert: '\n' }], revision: 'revision',
    giver: { name: 'Élodie', key: 'elodie' },
    recipient: { name: 'Béatrice', key: 'beatrice', year: 2026 } };

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [provideRouter(routes), provideHttpClient(withInterceptors([sessionInterceptor])),
        provideHttpClientTesting(), provideNoopAnimations()],
    });
    http = TestBed.inject(HttpTestingController);
    harness = await RouterTestingHarness.create();
  });

  afterEach(() => http.verify());

  async function load(url = '/wishlist', data = wishlist): Promise<HTMLElement> {
    await harness.navigateByUrl(url, Wishlist);
    http.expectOne('/api/session').flush({ data: session });
    http.expectOne('/api/wishlist').flush({ data });
    harness.detectChanges();
    return harness.routeNativeElement as HTMLElement;
  }

  function button(root: HTMLElement, label: string): HTMLButtonElement {
    return Array.from(root.querySelectorAll('button')).find((item) => item.textContent?.includes(label))!;
  }

  it('ne propose pas la wishlist du destinataire sur la page personnelle', async () => {
    const root = await load();
    expect(button(root, 'Envoyer ma wishlist').textContent).toContain('Élodie');
    const cards = root.querySelectorAll('mat-card');
    expect(getComputedStyle(cards[0]).display).not.toBe('none');
    expect(getComputedStyle(cards[1]).display).toBe('none');
    expect(root.textContent).not.toContain('Consulter sa wishlist');
    http.expectNone((req) => req.url === '/api/wishlist/recipient');
  });

  it('enregistre la wishlist et informe le donneur uniquement après sauvegarde', async () => {
    const root = await load();
    const editorElement = root.querySelector('.ql-container')!;
    const editor = Quill.find(editorElement);
    expect(editor instanceof Quill).toBe(true);
    if (!(editor instanceof Quill)) throw new Error('Editor not found');
    editor.insertText(0, 'Un livre', 'user');
    harness.detectChanges();
    expect(root.textContent).toContain('Modifications non enregistrées');
    expect(button(root, 'Envoyer ma wishlist').disabled).toBe(true);
    button(root, 'Enregistrer').click();
    const request = http.expectOne('/api/wishlist');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ ops: [{ insert: 'Un livre\n' }], revision: 'revision' });
    request.flush({ data: { revision: 'next' } });
    harness.detectChanges();
    expect(root.textContent).toContain('Wishlist enregistrée');
    button(root, 'Envoyer ma wishlist').click();
    const notification = http.expectOne('/api/wishlist/notify');
    expect(notification.request.body).toEqual({ expectedGiver: 'elodie' });
    notification.flush({ data: { sent: true, giver: 'ÉLODIE' } });
    harness.detectChanges();
    expect(root.textContent).toContain('L’e-mail a été envoyé à ÉLODIE.');
  });

  it('ouvre le lien ciblé en lecture seule et affiche les erreurs d’autorisation', async () => {
    const root = await load('/wishlist?target=beatrice');
    const request = http.expectOne((req) => req.url === '/api/wishlist/recipient');
    expect(request.request.params.get('target')).toBe('beatrice');
    expect(getComputedStyle(root.querySelectorAll('mat-card')[0]).display).toBe('none');
    expect(getComputedStyle(root.querySelectorAll('mat-card')[1]).display).not.toBe('none');
    request.flush({ data: { participant: 'Béatrice', year: 2026, ops: [{ insert: 'Un cadeau\n' }] } });
    harness.detectChanges();
    expect(root.textContent).toContain('Wishlist de Béatrice');
    expect(root.textContent).toContain('Un cadeau');
    expect(getComputedStyle(root.querySelectorAll('mat-card')[1]).display).not.toBe('none');
    expect(root.textContent).not.toContain('Consulter sa wishlist');
    const readonly = root.querySelector('[aria-label="Wishlist du destinataire en lecture seule"]');
    expect(readonly?.getAttribute('contenteditable')).toBe('false');
    await harness.navigateByUrl('/wishlist', Wishlist);
    harness.detectChanges();
    expect(getComputedStyle(root.querySelectorAll('mat-card')[0]).display).not.toBe('none');
    expect(getComputedStyle(root.querySelectorAll('mat-card')[1]).display).toBe('none');
  });

  it('bascule de la wishlist personnelle vers celle du destinataire sans perdre les modifications', async () => {
    const root = await load();
    const editor = Quill.find(root.querySelector('.ql-container')!);
    if (!(editor instanceof Quill)) throw new Error('Editor not found');
    editor.insertText(0, 'Un livre', 'user');
    await harness.navigateByUrl('/wishlist?target=beatrice', Wishlist);
    const request = http.expectOne((req) => req.url === '/api/wishlist/recipient');
    request.flush({ error: { code: 'wishlist_forbidden' } }, { status: 403, statusText: 'Forbidden' });
    harness.detectChanges();
    const cards = root.querySelectorAll('mat-card');
    expect(getComputedStyle(cards[0]).display).toBe('none');
    expect(getComputedStyle(cards[1]).display).not.toBe('none');
    expect(cards[1].textContent).toContain('n’est pas accessible pour le dernier tirage');
    await harness.navigateByUrl('/wishlist', Wishlist);
    harness.detectChanges();
    expect(getComputedStyle(cards[0]).display).not.toBe('none');
    expect(getComputedStyle(cards[1]).display).toBe('none');
    expect(editor.getText()).toContain('Un livre');
    expect(root.textContent).toContain('Modifications non enregistrées');
  });

  it('demande confirmation dans une modale avant de quitter une wishlist modifiée', async () => {
    const root = await load();
    expect(window.dispatchEvent(new Event('beforeunload', { cancelable: true }))).toBe(true);
    const editor = Quill.find(root.querySelector('.ql-container')!);
    if (!(editor instanceof Quill)) throw new Error('Editor not found');
    editor.insertText(0, 'Un livre', 'user');
    harness.detectChanges();
    expect(window.dispatchEvent(new Event('beforeunload', { cancelable: true }))).toBe(false);
    const router = TestBed.inject(Router);
    const cancelled = router.navigateByUrl('/connexion');
    await vi.waitFor(() => expect(document.querySelector('[role="dialog"]')).not.toBeNull());
    let dialog = document.querySelector<HTMLElement>('[role="dialog"]')!;
    expect(dialog.getAttribute('aria-label')).toBe('Modifications non enregistrées');
    button(dialog, 'Rester').click();
    expect(await cancelled).toBe(false);
    harness.detectChanges();
    expect(router.url).toBe('/wishlist');
    expect(editor.getText()).toContain('Un livre');
    const confirmed = router.navigateByUrl('/connexion');
    await vi.waitFor(() => expect(document.querySelector('[role="dialog"]')).not.toBeNull());
    dialog = document.querySelector<HTMLElement>('[role="dialog"]')!;
    button(dialog, 'Quitter').click();
    expect(await confirmed).toBe(true);
    expect(router.url).toBe('/connexion');
    await harness.fixture.whenStable();
    expect(document.querySelector('[role="dialog"]')).toBeNull();
    expect(window.dispatchEvent(new Event('beforeunload', { cancelable: true }))).toBe(true);
  });

  it('quitte sans confirmation si la wishlist est inchangée', async () => {
    await load();
    expect(await TestBed.inject(Router).navigateByUrl('/connexion')).toBe(true);
    expect(document.querySelector('[role="dialog"]')).toBeNull();
  });

  it('ferme une confirmation remplacée par une nouvelle navigation', async () => {
    const root = await load();
    const editor = Quill.find(root.querySelector('.ql-container')!);
    if (!(editor instanceof Quill)) throw new Error('Editor not found');
    editor.insertText(0, 'Un livre', 'user');
    const router = TestBed.inject(Router);
    const first = router.navigateByUrl('/connexion');
    await vi.waitFor(() => expect(TestBed.inject(MatDialog).openDialogs).toHaveLength(1));
    const second = router.navigateByUrl('/tirages');
    expect(await first).toBe(false);
    await vi.waitFor(() => expect(TestBed.inject(MatDialog).openDialogs).toHaveLength(1));
    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!;
    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', keyCode: 27, bubbles: true }));
    expect(await second).toBe(false);
    await harness.fixture.whenStable();
    expect(document.querySelector('[role="dialog"]')).toBeNull();
    expect(router.url).toBe('/wishlist');
    expect(editor.getText()).toContain('Un livre');
  });

  it('indique que le donneur ne possède pas de compte', async () => {
    const root = await load();
    button(root, 'Envoyer ma wishlist').click();
    http.expectOne('/api/wishlist/notify').flush(
      { error: { code: 'giver_account_missing' } }, { status: 404, statusText: 'Not Found' },
    );
    harness.detectChanges();
    expect(root.textContent).toContain('Élodie ne dispose pas d’un compte');
  });

  it('désactive l’envoi sans attribution tout en permettant la modification', async () => {
    const root = await load('/wishlist', { ...wishlist, giver: null });
    expect(button(root, 'Envoyer ma wishlist').disabled).toBe(true);
    expect(root.textContent).toContain('Aucun donneur disponible pour le dernier tirage.');
    const editor = Quill.find(root.querySelector('.ql-container')!);
    if (!(editor instanceof Quill)) throw new Error('Editor not found');
    editor.insertText(0, 'Un livre', 'user');
    harness.detectChanges();
    expect(button(root, 'Enregistrer').disabled).toBe(false);
    http.expectNone('/api/wishlist/notify');
  });

  it('bloque les envois si le donneur a changé, même après sauvegarde', async () => {
    const root = await load();
    button(root, 'Envoyer ma wishlist').click();
    http.expectOne('/api/wishlist/notify').flush(
      { error: { code: 'giver_changed' } }, { status: 409, statusText: 'Conflict' },
    );
    harness.detectChanges();
    expect(root.textContent).toContain('Votre attribution a changé. Rechargez la page');
    expect(button(root, 'Envoyer ma wishlist').disabled).toBe(true);
    const editor = Quill.find(root.querySelector('.ql-container')!);
    if (!(editor instanceof Quill)) throw new Error('Editor not found');
    editor.insertText(0, 'Un livre', 'user');
    harness.detectChanges();
    button(root, 'Enregistrer').click();
    http.expectOne('/api/wishlist').flush({ data: { revision: 'next' } });
    harness.detectChanges();
    expect(root.textContent).toContain('Votre attribution a changé. Rechargez la page');
    expect(button(root, 'Envoyer ma wishlist').disabled).toBe(true);
    http.expectNone('/api/wishlist/notify');
  });

  it('signale une confirmation incomplète sans prétendre connaître le destinataire', async () => {
    const root = await load();
    button(root, 'Envoyer ma wishlist').click();
    http.expectOne('/api/wishlist/notify').flush({ data: { sent: true } });
    harness.detectChanges();
    expect(root.textContent).toContain('L’envoi a peut-être réussi');
    expect(root.textContent).not.toContain('L’e-mail a été envoyé');
  });

  it('ne donne pas accès à l’écran à un compte sans participant', async () => {
    await harness.navigateByUrl('/wishlist', Wishlist);
    http.expectOne('/api/session').flush({ data: { ...session, participant: null, participantKey: null } });
    http.expectNone('/api/wishlist');
    harness.detectChanges();
    const root = harness.routeNativeElement as HTMLElement;
    expect(root.textContent).toContain('réservé aux comptes associés à un participant');
    expect(getComputedStyle(root.querySelector('mat-card')!).display).toBe('none');
  });

  it('garde la cible du lien après expiration de session', async () => {
    await harness.navigateByUrl('/wishlist?target=beatrice', Wishlist);
    http.expectOne('/api/session').flush(
      { error: { code: 'missing_token' } }, { status: 401, statusText: 'Unauthorized' },
    );
    await harness.fixture.whenStable();
    const url = TestBed.inject(Router).parseUrl(TestBed.inject(Router).url);
    expect(url.root.children['primary'].segments[0].path).toBe('connexion');
    expect(url.queryParams['target']).toBe('beatrice');
    expect(url.queryParams['returnWishlist']).toBe('1');
    const root = harness.routeNativeElement as HTMLElement;
    for (const [name, value] of [['login', 'bob'], ['password', 'secret']]) {
      const input = root.querySelector<HTMLInputElement>(`input[formControlName=${name}]`)!;
      input.value = value;
      input.dispatchEvent(new Event('input'));
    }
    root.querySelector('form')!.dispatchEvent(new Event('submit'));
    http.expectOne('/api/login').flush({ data: { authenticated: true } });
    http.expectOne('/api/session').flush({ data: session });
    await harness.fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/wishlist?target=beatrice');
    http.expectOne('/api/session').flush({ data: session });
    http.expectOne('/api/wishlist').flush({ data: wishlist });
    const request = http.expectOne((req) => req.url === '/api/wishlist/recipient');
    expect(request.request.params.get('target')).toBe('beatrice');
    request.flush({ error: { code: 'wishlist_forbidden' } }, { status: 403, statusText: 'Forbidden' });
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('n’est pas accessible pour le dernier tirage');
  });
});
