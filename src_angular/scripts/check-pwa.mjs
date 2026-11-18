import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const base = process.argv[2] ?? '/';
assert.match(base, /^\/(?:[a-zA-Z0-9_-][a-zA-Z0-9_.-]*\/)*$/, 'Le chemin de base doit commencer et finir par /.');
const output = resolve('dist/noel/browser');
const read = (file) => readFileSync(resolve(output, file));
const manifest = JSON.parse(read('manifest.webmanifest'));
const worker = JSON.parse(read('ngsw.json'));
const index = read('index.html').toString('utf8');
const manifestUrl = new URL(`${base}manifest.webmanifest`, 'https://example.test');

assert.ok(index.includes(`<base href="${base}">`), 'Le base href doit correspondre au chemin public.');
assert.match(index, /<link rel="manifest" href="manifest\.webmanifest">/);
assert.equal(new URL(manifest.id, manifestUrl).pathname, base);
assert.equal(new URL(manifest.scope, manifestUrl).pathname, base);
assert.equal(new URL(manifest.start_url, manifestUrl).pathname, base);
assert.equal(manifest.display, 'standalone');
assert.equal(new URL(manifest.share_target.action, manifestUrl).pathname, `${base}api/share-target`);
assert.equal(manifest.share_target.method, 'POST');
assert.equal(manifest.share_target.enctype, 'multipart/form-data');
assert.deepEqual(manifest.share_target.params, {
  title: 'title', text: 'text', url: 'url',
  files: [{ name: 'image', accept: ['image/png', 'image/jpeg', 'image/gif', 'image/webp'] }],
});

for (const size of [192, 512]) {
  const icon = manifest.icons.find((entry) => entry.sizes === `${size}x${size}` && entry.type === 'image/png');
  assert.ok(icon, `Icône ${size} manquante.`);
  const bytes = read(icon.src);
  assert.equal(bytes.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
  assert.equal(bytes.readUInt32BE(16), size);
  assert.equal(bytes.readUInt32BE(20), size);
  if (size === 512) assert.ok(icon.purpose.split(/\s+/).includes('maskable'));
}

assert.equal(worker.index, `${base}index.html`);
assert.deepEqual(worker.dataGroups, [], 'Les réponses API ne doivent pas être mises en cache.');
assert.ok(read('ngsw-worker.js').length > 0);
const assets = worker.assetGroups.flatMap((group) => group.urls);
assert.ok(assets.includes(`${base}index.html`));
assert.ok(assets.includes(`${base}manifest.webmanifest`));
assert.ok(assets.every((url) => !url.startsWith(`${base}api/`)));
for (const group of worker.assetGroups) {
  assert.deepEqual(group.patterns, [], 'Les groupes statiques ne doivent pas intercepter de réponses API.');
}
for (const [url, expected] of Object.entries(worker.hashTable)) {
  assert.ok(url.startsWith(base));
  assert.equal(createHash('sha1').update(read(url.slice(base.length))).digest('hex'), expected);
}

const isNavigation = (path) => {
  const relative = path.slice(base.length - 1);
  return worker.navigationUrls.some((rule) => rule.positive && new RegExp(rule.regex).test(relative)) &&
    !worker.navigationUrls.some((rule) => !rule.positive && new RegExp(rule.regex).test(relative));
};
assert.ok(isNavigation(`${base}partage`), 'La page de partage doit être une route Angular.');
assert.ok(isNavigation(`${base}connexion`), 'La page de connexion doit être une route Angular.');
assert.ok(!isNavigation(`${base}api`));
assert.ok(!isNavigation(`${base}api/wishlist`));
assert.ok(!isNavigation(`${base}api/share-target`));
console.log(`Configuration PWA vérifiée pour ${base}`);
