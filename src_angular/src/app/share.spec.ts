import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideNoopAnimations } from '@angular/platform-browser/animations';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { routes } from './app.routes';
import { Api } from './api';
import { sessionInterceptor } from './session.interceptor';
import { Share } from './share';

describe('Confirmation des partages', () => {
  let http: HttpTestingController;
  let harness: RouterTestingHarness;
  const first = 'a'.repeat(64);
  const second = 'b'.repeat(64);
  const revision = 'c'.repeat(64);
  const session = { authenticated: true, isAdmin: false, login: 'alice', email: 'alice@example.com',
    participant: 'Alice', participantKey: 'alice' };
  const draft = { title: 'Un livre', url: 'https://example.test/livre', text: 'Texte reçu',
    image: 'data:image/png;base64,aGVsbG8=', source: 'android', expiresAt: 2000000000 };
  const wishlist = { participant: 'Alice', ops: [{ insert: 'Déjà présent\n' }], revision, giver: null, recipient: null };
  const storageKey = `noel.pending-shares:${new URL(document.baseURI).pathname}`;

  beforeEach(async () => {
    sessionStorage.removeItem(storageKey);
    TestBed.configureTestingModule({
      providers: [provideRouter(routes), provideHttpClient(withInterceptors([sessionInterceptor])),
        provideHttpClientTesting(), provideNoopAnimations()],
    });
    http = TestBed.inject(HttpTestingController);
    harness = await RouterTestingHarness.create();
  });

  afterEach(() => {
    http.verify();
    sessionStorage.removeItem(storageKey);
    vi.restoreAllMocks();
  });

  function button(root: ParentNode, label: string): HTMLButtonElement {
    const result = Array.from(root.querySelectorAll('button')).find((item) => item.textContent?.includes(label));
    if (!result) throw new Error(`Missing button: ${label}`);
    return result;
  }

  function edit(root: HTMLElement, field: string, value: string): void {
    const element = root.querySelector<HTMLInputElement | HTMLTextAreaElement>(`[formControlName="${field}"]`)!;
    element.value = value;
    element.dispatchEvent(new Event('input'));
    harness.detectChanges();
  }

  async function load(id = first): Promise<HTMLElement> {
    await harness.navigateByUrl(`/partage#draft=${id}`, Share);
    http.expectOne('/api/session').flush({ data: session });
    const request = http.expectOne('/api/share-drafts/open');
    expect(request.request.body).toEqual({ draftId: id });
    request.flush({ data: draft });
    harness.detectChanges();
    return harness.routeNativeElement as HTMLElement;
  }

  async function loadUntitled(): Promise<HTMLElement> {
    await harness.navigateByUrl(`/partage#draft=${first}`, Share);
    http.expectOne('/api/session').flush({ data: session });
    http.expectOne('/api/share-drafts/open').flush({ data: { ...draft, title: '' } });
    harness.detectChanges();
    return harness.routeNativeElement as HTMLElement;
  }

  it('propose automatiquement le titre OpenGraph, affiche sa source et le conserve pour la reconnexion', async () => {
    const root = await loadUntitled();
    expect(root.textContent).toContain('Vous pouvez continuer sans attendre');
    expect(button(root, 'Valider et ajouter').disabled).toBe(false);
    const request = http.expectOne('/api/share-drafts/metadata');
    expect(request.request.body).toEqual({ draftId: first });
    request.flush({ data: { status: 'found', title: 'Titre distant', source: 'opengraph' } });
    harness.detectChanges();
    expect(root.querySelector<HTMLInputElement>('[formControlName="title"]')?.value).toBe('Titre distant');
    expect(root.textContent).toContain('OpenGraph');
    expect(sessionStorage.getItem(storageKey)).toContain('Titre distant');
    await harness.navigateByUrl('/connexion?returnShare=1');
    await harness.navigateByUrl('/partage', Share);
    http.expectOne('/api/session').flush({ data: session });
    http.expectOne('/api/share-drafts/open').flush({ data: { ...draft, title: '' } });
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('OpenGraph');
    expect(harness.routeNativeElement?.querySelector<HTMLInputElement>('[formControlName="title"]')?.value).toBe('Titre distant');
    http.expectNone('/api/share-drafts/metadata');
  });

  it('ne remplace pas un titre ou une URL modifiée pendant la recherche', async () => {
    const root = await loadUntitled();
    const request = http.expectOne('/api/share-drafts/metadata');
    edit(root, 'title', 'Titre personnel');
    request.flush({ data: { status: 'found', title: 'Titre distant', source: 'html' } });
    harness.detectChanges();
    expect(root.querySelector<HTMLInputElement>('[formControlName="title"]')?.value).toBe('Titre personnel');
    expect(root.textContent).not.toContain('Suggestion de titre');
  });

  it('ignore une suggestion pour une URL remplacée avant la réponse', async () => {
    const root = await loadUntitled();
    const request = http.expectOne('/api/share-drafts/metadata');
    edit(root, 'url', 'https://example.test/autre');
    request.flush({ data: { status: 'found', title: 'Ancien titre', source: 'html' } });
    harness.detectChanges();
    expect(root.querySelector<HTMLInputElement>('[formControlName="title"]')?.value).toBe('');
    expect(root.textContent).not.toContain('Suggestion de titre');
  });

  it('indique la source HTML et retire l’attribution lorsque le titre est modifié', async () => {
    const root = await loadUntitled();
    http.expectOne('/api/share-drafts/metadata').flush({ data: { status: 'found', title: 'Livre', source: 'html' } });
    harness.detectChanges();
    expect(root.textContent).toContain('titre de la page');
    edit(root, 'title', 'Titre personnel');
    expect(root.textContent).not.toContain('Suggestion de titre');
  });

  it('signale un préremplissage indisponible sans interrompre le formulaire, même sur 401', async () => {
    const root = await loadUntitled();
    http.expectOne('/api/share-drafts/metadata').flush({ error: { code: 'invalid_token' } },
      { status: 401, statusText: 'Unauthorized' });
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(TestBed.inject(Router).url).toContain('/partage');
    expect(root.textContent).toContain('Préremplissage du titre indisponible');
    expect(button(root, 'Valider et ajouter').disabled).toBe(false);
  });

  it('permet de confirmer sans attendre et ignore un titre arrivé pendant la confirmation', async () => {
    const root = await loadUntitled();
    const pending = http.expectOne('/api/share-drafts/metadata');
    button(root, 'Valider et ajouter').click();
    http.expectOne('/api/wishlist').flush({ data: wishlist });
    const confirmation = http.expectOne('/api/share-drafts/confirm');
    expect(confirmation.request.body.title).toBe('');
    pending.flush({ data: { status: 'found', title: 'Trop tard', source: 'html' } });
    expect(root.querySelector<HTMLInputElement>('[formControlName="title"]')?.value).toBe('');
    confirmation.flush({ data: { revision, alreadyConfirmed: false } });
    harness.detectChanges();
    expect(root.textContent).toContain('ajouté à votre wishlist');
  });

  it('ignore une suggestion pour un brouillon qui n’est plus sélectionné', async () => {
    await loadUntitled();
    const pending = http.expectOne('/api/share-drafts/metadata');
    await harness.navigateByUrl(`/partage#draft=${second}`, Share);
    http.expectOne('/api/share-drafts/open').flush({ data: { ...draft, title: 'Second' } });
    harness.detectChanges();
    expect(pending.cancelled).toBe(true);
    expect(harness.routeNativeElement?.querySelector<HTMLInputElement>('[formControlName="title"]')?.value).toBe('Second');
  });

  it('retire le fragment, préremplit le formulaire et garde seulement les textes localement', async () => {
    const root = await load();
    expect(window.location.hash).toBe('');
    expect(root.querySelector<HTMLImageElement>('img')?.src).toBe(draft.image);
    expect(root.querySelector<HTMLInputElement>('[formControlName="url"]')?.value).toBe(draft.url);
    edit(root, 'comment', 'Une idée');
    const stored = sessionStorage.getItem(storageKey)!;
    expect(stored).toContain(first);
    expect(stored).toContain('Une idée');
    expect(stored).not.toContain(draft.image);
  });

  it('conserve plusieurs partages et les modifications lors du changement de sélection', async () => {
    const root = await load();
    edit(root, 'comment', 'Premier commentaire');
    await harness.navigateByUrl(`/partage#draft=${second}`, Share);
    http.expectOne('/api/share-drafts/open').flush({ data: { ...draft, title: 'Second' } });
    harness.detectChanges();
    expect(root.querySelector<HTMLInputElement>('[formControlName="title"]')?.value).toBe('Second');
    button(root, 'Partage 1').click();
    http.expectOne('/api/share-drafts/open').flush({ data: draft });
    harness.detectChanges();
    expect(root.querySelector<HTMLTextAreaElement>('[formControlName="comment"]')?.value).toBe('Premier commentaire');
    expect(sessionStorage.getItem(storageKey)).toContain(second);
  });

  it('bloque la connexion tant que le stockage du lien échoue', async () => {
    const failed = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('Quota exceeded', 'QuotaExceededError');
    });
    await harness.navigateByUrl(`/partage#draft=${first}`, Share);
    harness.detectChanges();
    const root = harness.routeNativeElement as HTMLElement;
    expect(root.textContent).toContain('Impossible de conserver le partage');
    expect(window.location.hash).toBe('');
    http.expectNone('/api/session');
    failed.mockRestore();
    button(root, 'Réessayer le stockage').click();
    http.expectOne('/api/session').flush({ data: session });
    http.expectOne('/api/share-drafts/open').flush({ data: draft });
    harness.detectChanges();
    expect(root.querySelector('[formControlName="comment"]')).not.toBeNull();
  });

  it('ne quitte pas après un 401 si les dernières modifications ne peuvent pas être conservées', async () => {
    const root = await load();
    const failed = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('Quota exceeded', 'QuotaExceededError');
    });
    edit(root, 'comment', 'Ne pas perdre');
    TestBed.inject(Api).session().subscribe({ error: () => undefined });
    http.expectOne('/api/session').flush({ error: { code: 'invalid_token' } },
      { status: 401, statusText: 'Unauthorized' });
    await harness.fixture.whenStable();
    harness.detectChanges();
    expect(TestBed.inject(Router).url).toContain('/partage');
    expect(root.textContent).toContain('ne peut pas être conservé pour la reconnexion');
    expect(root.querySelector<HTMLTextAreaElement>('[formControlName="comment"]')?.value).toBe('Ne pas perdre');
    failed.mockRestore();
    button(root, 'Réessayer le stockage').click();
    http.expectOne('/api/session').flush({ error: { code: 'invalid_token' } },
      { status: 401, statusText: 'Unauthorized' });
    await harness.fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/connexion?session=expiree&returnShare=1');
    expect(sessionStorage.getItem(storageKey)).toContain('Ne pas perdre');
  });

  it('préserve un stockage invalide et attend sa réparation avant d’ouvrir le partage', async () => {
    sessionStorage.setItem(storageKey, 'données invalides');
    await harness.navigateByUrl(`/partage#draft=${first}`, Share);
    harness.detectChanges();
    const root = harness.routeNativeElement as HTMLElement;
    expect(root.textContent).toContain('Impossible de lire le stockage');
    expect(sessionStorage.getItem(storageKey)).toBe('données invalides');
    http.expectNone('/api/session');
    sessionStorage.removeItem(storageKey);
    button(root, 'Réessayer le stockage').click();
    http.expectOne('/api/session').flush({ data: session });
    http.expectOne('/api/share-drafts/open').flush({ data: draft });
    expect(sessionStorage.getItem(storageKey)).toContain(first);
  });

  it('retrouve les modifications après expiration de session et reconnexion', async () => {
    const root = await load();
    edit(root, 'comment', 'À conserver');
    button(root, 'Valider et ajouter').click();
    http.expectOne('/api/wishlist').flush({ error: { code: 'invalid_token' } },
      { status: 401, statusText: 'Unauthorized' });
    await harness.fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/connexion?session=expiree&returnShare=1');
    const login = harness.routeNativeElement as HTMLElement;
    edit(login, 'login', 'alice');
    edit(login, 'password', 'secret');
    login.querySelector('form')!.dispatchEvent(new Event('submit'));
    http.expectOne('/api/login').flush({ data: { authenticated: true } });
    http.expectOne('/api/session').flush({ data: session });
    await harness.fixture.whenStable();
    http.expectOne('/api/session').flush({ data: session });
    http.expectOne('/api/share-drafts/open').flush({ data: draft });
    harness.detectChanges();
    expect(TestBed.inject(Router).url).toBe('/partage');
    expect(harness.routeNativeElement?.querySelector<HTMLTextAreaElement>('[formControlName="comment"]')?.value).toBe('À conserver');
  });

  it('conserve le fragment avant de rediriger une session absente vers la connexion', async () => {
    await harness.navigateByUrl(`/partage#draft=${first}`, Share);
    http.expectOne('/api/session').flush({ error: { code: 'missing_token' } },
      { status: 401, statusText: 'Unauthorized' });
    await harness.fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/connexion?session=expiree&returnShare=1');
    expect(sessionStorage.getItem(storageKey)).toContain(first);
    expect(window.location.href).not.toContain(first);
  });

  it('refuse un fragment mal formé sans ouvrir de brouillon', async () => {
    await harness.navigateByUrl('/partage#draft=not-a-secret', Share);
    harness.detectChanges();
    expect(window.location.hash).toBe('');
    expect(harness.routeNativeElement?.textContent).toContain('lien de partage est invalide');
    http.expectNone('/api/session');
    expect(sessionStorage.getItem(storageKey)).toBeNull();
  });

  it('ne lie pas un brouillon à un compte sans participant', async () => {
    await harness.navigateByUrl(`/partage#draft=${first}`, Share);
    http.expectOne('/api/session').flush({ data: { ...session, participant: null, participantKey: null } });
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('aucun participant');
    http.expectNone('/api/share-drafts/open');
    expect(sessionStorage.getItem(storageKey)).toContain(first);
  });

  it('affiche le refus du mauvais compte sans effacer le brouillon', async () => {
    await harness.navigateByUrl(`/partage#draft=${first}`, Share);
    http.expectOne('/api/session').flush({ data: session });
    http.expectOne('/api/share-drafts/open').flush({ error: { code: 'share_forbidden' } },
      { status: 403, statusText: 'Forbidden' });
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('autre compte');
    expect(sessionStorage.getItem(storageKey)).toContain(first);
  });

  it('charge la révision uniquement à la validation et empêche la double soumission', async () => {
    const root = await load();
    edit(root, 'comment', 'À lire');
    button(root, 'Valider et ajouter').click();
    button(root, 'Valider et ajouter').click();
    const get = http.expectOne('/api/wishlist');
    get.flush({ data: wishlist });
    const confirm = http.expectOne('/api/share-drafts/confirm');
    expect(confirm.request.body).toEqual({
      draftId: first, revision, title: 'Un livre', url: draft.url,
      text: 'Texte reçu\nÀ lire', keepImage: true,
    });
    confirm.flush({ data: { revision, alreadyConfirmed: false } });
    harness.detectChanges();
    expect(root.textContent).toContain('ajouté à votre wishlist');
    expect(sessionStorage.getItem(storageKey)).not.toContain(first);
  });

  it('garde les modifications et impose une revue après conflit de révision', async () => {
    const root = await load();
    edit(root, 'comment', 'À garder');
    button(root, 'Valider et ajouter').click();
    http.expectOne('/api/wishlist').flush({ data: wishlist });
    http.expectOne('/api/share-drafts/confirm').flush({ error: { code: 'wishlist_changed' } },
      { status: 409, statusText: 'Conflict' });
    http.expectOne('/api/wishlist').flush({ data: { ...wishlist, ops: [{ insert: 'Modification concurrente\n' }] } });
    harness.detectChanges();
    expect(root.textContent).toContain('Modification concurrente');
    expect(button(root, 'Valider et ajouter').disabled).toBe(true);
    expect(root.querySelector<HTMLTextAreaElement>('[formControlName="comment"]')?.value).toBe('À garder');
    button(root, 'J’ai vérifié').click();
    harness.detectChanges();
    expect(button(root, 'Valider et ajouter').disabled).toBe(false);
  });

  it('conserve la revue obligatoire après avoir quitté puis rouvert la page', async () => {
    const root = await load();
    button(root, 'Valider et ajouter').click();
    http.expectOne('/api/wishlist').flush({ data: wishlist });
    http.expectOne('/api/share-drafts/confirm').flush({ error: { code: 'wishlist_changed' } },
      { status: 409, statusText: 'Conflict' });
    http.expectOne('/api/wishlist').flush({ data: wishlist });
    await harness.navigateByUrl('/connexion');
    await harness.navigateByUrl('/partage', Share);
    http.expectOne('/api/session').flush({ data: session });
    http.expectOne('/api/share-drafts/open').flush({ data: draft });
    http.expectOne('/api/wishlist').flush({ data: wishlist });
    harness.detectChanges();
    const reopened = harness.routeNativeElement as HTMLElement;
    expect(button(reopened, 'Valider et ajouter').disabled).toBe(true);
    expect(reopened.textContent).toContain('Wishlist actualisée');
  });

  it('refuse une URL dangereuse et permet de retirer une image du partage', async () => {
    const root = await load();
    edit(root, 'url', 'javascript:alert(1)');
    button(root, 'Valider et ajouter').click();
    http.expectNone('/api/wishlist');
    harness.detectChanges();
    expect(root.textContent).toContain('Vérifiez l’URL HTTP(S)');
    edit(root, 'url', draft.url);
    root.querySelector<HTMLInputElement>('mat-checkbox input')!.click();
    harness.detectChanges();
    button(root, 'Valider et ajouter').click();
    http.expectOne('/api/wishlist').flush({ data: wishlist });
    const confirm = http.expectOne('/api/share-drafts/confirm');
    expect(confirm.request.body.keepImage).toBe(false);
    confirm.flush({ data: { revision, alreadyConfirmed: false } });
  });

  it('retrouve une confirmation déjà enregistrée après perte de réponse', async () => {
    const root = await load();
    button(root, 'Valider et ajouter').click();
    http.expectOne('/api/wishlist').flush({ data: wishlist });
    http.expectOne('/api/share-drafts/confirm').error(new ProgressEvent('error'));
    harness.detectChanges();
    expect(root.textContent).toContain('a peut-être réussi');
    await harness.navigateByUrl('/connexion');
    await harness.navigateByUrl('/partage', Share);
    http.expectOne('/api/session').flush({ data: session });
    http.expectOne('/api/share-drafts/open').flush({ error: { code: 'share_not_found' } },
      { status: 404, statusText: 'Not Found' });
    http.expectOne('/api/share-drafts/status').flush({ data: { confirmed: true } });
    harness.detectChanges();
    expect(harness.routeNativeElement?.textContent).toContain('déjà été enregistré');
    expect(sessionStorage.getItem(storageKey)).not.toContain(first);
  });

  it('demande confirmation avant d’annuler le brouillon', async () => {
    const root = await load();
    button(root, 'Annuler le partage').click();
    http.expectNone('/api/share-drafts/cancel');
    harness.detectChanges();
    button(root, 'Confirmer l’annulation').click();
    const cancel = http.expectOne('/api/share-drafts/cancel');
    expect(cancel.request.body).toEqual({ draftId: first });
    cancel.flush({ data: { cancelled: true } });
    harness.detectChanges();
    expect(root.textContent).toContain('brouillon a été annulé');
    expect(sessionStorage.getItem(storageKey)).not.toContain(first);
  });

  it('demande confirmation avant de quitter des modifications locales', async () => {
    const root = await load();
    edit(root, 'comment', 'À conserver');
    expect(window.dispatchEvent(new Event('beforeunload', { cancelable: true }))).toBe(false);
    const navigation = TestBed.inject(Router).navigateByUrl('/connexion');
    await vi.waitFor(() => expect(document.querySelector('[role="dialog"]')).not.toBeNull());
    button(document, 'Rester').click();
    expect(await navigation).toBe(false);
    expect(root.querySelector<HTMLTextAreaElement>('[formControlName="comment"]')?.value).toBe('À conserver');
  });
});
