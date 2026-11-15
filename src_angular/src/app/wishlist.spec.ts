import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideNoopAnimations } from '@angular/platform-browser/animations';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import Quill from 'quill';
import { routes } from './app.routes';
import { sessionInterceptor } from './session.interceptor';
import { Wishlist } from './wishlist';

describe('Wishlists', () => {
  let http: HttpTestingController;
  let harness: RouterTestingHarness;
  const session = { authenticated: true, isAdmin: false, login: 'bob', email: 'bob@example.com',
    participant: 'Bob', participantKey: 'bob' };
  const wishlist = { participant: 'Bob', ops: [{ insert: '\n' }], revision: 'revision',
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

  async function load(url = '/wishlist'): Promise<HTMLElement> {
    await harness.navigateByUrl(url, Wishlist);
    http.expectOne('/api/session').flush({ data: session });
    http.expectOne('/api/wishlist').flush({ data: wishlist });
    harness.detectChanges();
    return harness.routeNativeElement as HTMLElement;
  }

  function button(root: HTMLElement, label: string): HTMLButtonElement {
    return Array.from(root.querySelectorAll('button')).find((item) => item.textContent?.includes(label))!;
  }

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
    http.expectOne('/api/wishlist/notify').flush({ data: { sent: true } });
    harness.detectChanges();
    expect(root.textContent).toContain('L’e-mail a été envoyé');
  });

  it('ouvre le lien ciblé en lecture seule et affiche les erreurs d’autorisation', async () => {
    const root = await load('/wishlist?target=beatrice');
    const request = http.expectOne((req) => req.url === '/api/wishlist/recipient');
    expect(request.request.params.get('target')).toBe('beatrice');
    request.flush({ data: { participant: 'Béatrice', year: 2026, ops: [{ insert: 'Un cadeau\n' }] } });
    harness.detectChanges();
    expect(root.textContent).toContain('Wishlist de Béatrice');
    expect(root.textContent).toContain('Un cadeau');
    const readonly = root.querySelector('[aria-label="Wishlist du destinataire en lecture seule"]');
    expect(readonly?.getAttribute('contenteditable')).toBe('false');
  });

  it('indique que le donneur ne possède pas de compte', async () => {
    const root = await load();
    button(root, 'Envoyer ma wishlist').click();
    http.expectOne('/api/wishlist/notify').flush(
      { error: { code: 'giver_account_missing' } }, { status: 404, statusText: 'Not Found' },
    );
    harness.detectChanges();
    expect(root.textContent).toContain('ne dispose pas d’un compte');
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
