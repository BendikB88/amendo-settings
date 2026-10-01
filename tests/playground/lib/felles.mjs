// Felles for suitene i tests/playground/suiter. Se CLAUDE.md.
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HER = dirname(fileURLToPath(import.meta.url));
export const REPO = resolve(HER, '../../..');
export const ADMIN_JS = resolve(REPO, 'assets/admin.js');
// dist/jquery.js. jQuery 4 eksporterer ikke understien, så den må finnes via «main».
export const JQUERY = createRequire(import.meta.url).resolve('jquery');

// kjor.mjs setter PG_URL; standard er porten serveren startes på.
export const URL_ = process.env.PG_URL || 'http://127.0.0.1:9411';
export const B = URL_ + '/?rest_route=';

let ok = 0, feil = 0;
export const sjekk = (navn, betingelse, info) => {
  if (betingelse) { ok++; console.log('  ✓', navn); }
  else { feil++; console.log('  ✗', navn, info !== undefined ? JSON.stringify(info) : ''); }
};
/** Siste linje er det kjor.mjs leser: «N ok, M feil». */
export const ferdig = () => {
  console.log(`\n${ok} ok, ${feil} feil`);
  process.exit(feil ? 1 : 0);
};

export const get = async (p, body) => (await fetch(B + p, { method: body ? 'POST' : 'GET', headers: { 'content-type': 'application/json' }, body: body && JSON.stringify(body) })).json();

export const frakt = async (body, headers = {}) => {
  const r = await fetch(B + '/amendo-settings/v1/frakt', { method: 'POST', headers: { 'content-type': 'application/json', ...headers }, body: typeof body === 'string' ? body : JSON.stringify(body) });
  const tekst = await r.text();
  let json; try { json = JSON.parse(tekst); } catch { json = tekst; }
  return { status: r.status, h: r.headers, json, tekst };
};

export const SECRET = 'hemmelig-test-123';
export const med = { 'X-Amendo-Kasse-Secret': SECRET };
export const rate = (res, m) => res.json.alternativer?.find(a => a.metode === m);

/** Butikk med produkter, MVA og fraktsoner (idempotent), kasse-secret satt og rate limit nullstilt. */
export async function klargjorFrakt() {
  const ids = await get('/amendo-test/v1/setup');
  await get('/amendo-test/v1/opt', { amendo_kasse_secret: SECRET, amendo_frakt_skjul_betalt: null, amendo_test_rl: null });
  await get('/amendo-test/v1/nullstill-rl');
  return ids;
}
