import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideNoopAnimations } from '@angular/platform-browser/animations';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { routes } from './app.routes';
import { sessionInterceptor } from './session.interceptor';
import { ShareKeys } from './share-keys';

describe('Clés de partage', () => {
  let http: HttpTestingController;
  let harness: RouterTestingHarness;
  const session = { authenticated: true, isAdmin: false, login: 'alice', email: 'alice@example.com',
    participant: 'Alice', participantKey: 'alice' };
  const id = 'a'.repeat(32);
  const secret = 'b'.repeat(64);

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [provideRouter(routes), provideHttpClient(withInterceptors([sessionInterceptor])),
        provideHttpClientTesting(), provideNoopAnimations()],
    });
    http = TestBed.inject(HttpTestingController);
    harness = await RouterTestingHarness.create();
  });

  afterEach(() => http.verify());

  async function load(participant: string | null = 'Alice'): Promise<HTMLElement> {
    await harness.navigateByUrl('/cles-partage', ShareKeys);
    http.expectOne('/api/session').flush({ data: {
      ...session, participant, participantKey: participant === null ? null : 'alice',
    } });
    http.expectOne('/api/share-tokens').flush({ data: { tokens: [] } });
    harness.detectChanges();
    return harness.routeNativeElement as HTMLElement;
  }

  function button(root: HTMLElement, label: string): HTMLButtonElement {
    return Array.from(root.querySelectorAll('button')).find((item) => item.textContent?.includes(label))!;
  }

  it('affiche le secret une seule fois et protège sa perte accidentelle', async () => {
    const root = await load();
    const storage = vi.spyOn(Storage.prototype, 'setItem');
    button(root, 'Créer une clé').click();
    const creation = http.expectOne('/api/share-tokens');
    expect(creation.request.method).toBe('POST');
    expect(creation.request.withCredentials).toBe(true);
    creation.flush({ data: { id, token: secret } });
    http.expectOne('/api/share-tokens').flush({ data: { tokens: [{ id, createdAt: 1_700_000_000 }] } });
    harness.detectChanges();
    expect(root.querySelector<HTMLTextAreaElement>('#share-secret')?.value).toBe(secret);
    expect(button(root, 'Créer une clé').disabled).toBe(true);
    expect(storage).not.toHaveBeenCalled();
    expect(window.dispatchEvent(new Event('beforeunload', { cancelable: true }))).toBe(false);

    const router = TestBed.inject(Router);
    const cancelled = router.navigateByUrl('/connexion');
    await vi.waitFor(() => expect(document.querySelector('[role="dialog"]')).not.toBeNull());
    button(document.querySelector<HTMLElement>('[role="dialog"]')!, 'Rester').click();
    expect(await cancelled).toBe(false);
    expect(root.querySelector<HTMLTextAreaElement>('#share-secret')?.value).toBe(secret);
    button(root, 'J’ai enregistré ma clé').click();
    harness.detectChanges();
    expect(root.querySelector('#share-secret')).toBeNull();
    expect(root.textContent).not.toContain(secret);
    expect(window.dispatchEvent(new Event('beforeunload', { cancelable: true }))).toBe(true);
    storage.mockRestore();
  });

  it('demande confirmation et révoque une clé sans exposer son secret', async () => {
    const root = await load();
    button(root, 'Actualiser la liste').click();
    http.expectOne('/api/share-tokens').flush({ data: { tokens: [{ id, createdAt: null }] } });
    harness.detectChanges();
    expect(root.textContent).toContain('Date de création inconnue');
    button(root, 'Révoquer').click();
    harness.detectChanges();
    expect(root.textContent).toContain('Révoquer cette clé ?');
    button(root, 'Confirmer').click();
    const request = http.expectOne('/api/share-tokens/revoke');
    expect(request.request.body).toEqual({ id });
    request.flush({ data: { revoked: true } });
    harness.detectChanges();
    expect(root.textContent).toContain('La clé a été révoquée');
    expect(root.textContent).not.toContain(id);
  });

  it('permet à un ancien participant de révoquer mais pas de créer', async () => {
    const root = await load(null);
    expect(button(root, 'Créer une clé').disabled).toBe(true);
    button(root, 'Actualiser la liste').click();
    http.expectOne('/api/share-tokens').flush({ data: { tokens: [{ id, createdAt: null }] } });
    harness.detectChanges();
    button(root, 'Révoquer').click();
    harness.detectChanges();
    button(root, 'Confirmer').click();
    http.expectOne('/api/share-tokens/revoke').flush({ data: { revoked: true } });
    harness.detectChanges();
    expect(root.textContent).toContain('La clé a été révoquée');
  });

  it('signale une création incertaine sans inventer de clé', async () => {
    const root = await load();
    button(root, 'Créer une clé').click();
    http.expectOne('/api/share-tokens').flush({ error: { code: 'internal_error' } },
      { status: 500, statusText: 'Error' });
    harness.detectChanges();
    expect(root.textContent).toContain('La création a peut-être réussi');
    expect(root.querySelector('#share-secret')).toBeNull();
    expect(root.textContent).not.toContain(secret);
    expect(button(root, 'Créer une clé').disabled).toBe(true);
    button(root, 'Actualiser la liste').click();
    http.expectOne('/api/share-tokens').flush({ data: { tokens: [{ id, createdAt: null }] } });
    harness.detectChanges();
    expect(root.textContent).toContain(id);
    expect(button(root, 'Créer une clé').disabled).toBe(false);
    expect(root.textContent).not.toContain('La création a peut-être réussi');
  });

  it('permet de réessayer après une erreur de chargement de session', async () => {
    await harness.navigateByUrl('/cles-partage', ShareKeys);
    http.expectOne('/api/session').flush({ error: { code: 'internal_error' } },
      { status: 500, statusText: 'Error' });
    harness.detectChanges();
    const root = harness.routeNativeElement as HTMLElement;
    expect(root.textContent).toContain('Impossible de charger les clés');
    button(root, 'Actualiser la liste').click();
    http.expectOne('/api/session').flush({ data: session });
    http.expectOne('/api/share-tokens').flush({ data: { tokens: [] } });
    harness.detectChanges();
    expect(button(root, 'Créer une clé').disabled).toBe(false);
  });
});
