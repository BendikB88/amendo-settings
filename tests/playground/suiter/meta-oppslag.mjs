// 1.4.32: oppslag på ordre-meta. wc_get_orders() med meta_query/meta_key
// filtreres ikke i alle lagringsmåter (i 1.4.30 traff en Wolt-melding den
// nyeste ordren). amendo_ordrer_med_meta() i includes/ordrer.php går rett mot
// tabellen og sjekker treffet. Testrutene er i mu/v1432-test.php.
import { readFileSync, readdirSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { REPO, get, sjekk, ferdig } from '../lib/felles.mjs';

const N = '_amendo_test_1432';
await get('/amendo-test/v1/setup');
await get('/amendo-test/v1/v1423-sett', { amendo_test_stille: '1' });

const { ider, uten } = await get('/amendo-test/v1/v1432-ordrer', { nokkel: N, verdier: ['A-1432', 'B-1432', 'B-1432'] });
const diag = v => get('/amendo-test/v1/v1432-diagnose', { nokkel: N, verdi: v });

console.log('Lagringsmåten og wc_get_orders()');
let d = await diag('A-1432');
// Til informasjon, ikke en kontroll: hva WooCommerce gjør med meta-filtrene her.
console.log('  WooCommerce', d.wc, d.hpos ? '(HPOS)' : '(ordrer som innlegg)');
for (const k of ['meta_query', 'meta_key', 'egen_nokkel']) {
  const riktig = JSON.stringify(d[k]) === JSON.stringify([ider['A-1432']]);
  console.log(`  wc_get_orders med ${k}: ${riktig ? 'filtrert' : 'IKKE filtrert'} ${JSON.stringify(d[k])}`);
}

console.log('\namendo_ordrer_med_meta()');
sjekk('bare ordren med verdien, ikke den nyeste uten', JSON.stringify(d.hjelper) === JSON.stringify([ider['A-1432']]) && !d.hjelper.includes(uten), [d.hjelper, ider, uten]);
d = await diag('B-1432');
sjekk('flere treff: begge, nyeste først', d.hjelper.length === 2 && d.hjelper[0] === ider['B-1432'] && d.hjelper[0] > d.hjelper[1] && !d.hjelper.includes(uten) && !d.hjelper.includes(ider['A-1432']), d.hjelper);
d = await diag('finnes-ikke-1432');
sjekk('ingen treff: tom liste (ikke de nyeste ordrene)', JSON.stringify(d.hjelper) === '[]', d.hjelper);
d = await diag('');
sjekk('tom verdi: tom liste', JSON.stringify(d.hjelper) === '[]', d.hjelper);

console.log('\nKildekoden');
const filer = [];
const gå = dir => { for (const f of readdirSync(dir, { withFileTypes: true })) { const p = join(dir, f.name); if (f.isDirectory()) gå(p); else if (p.endsWith('.php')) filer.push(p); } };
gå(resolve(REPO, 'includes'));
filer.push(resolve(REPO, 'amendo-settings.php'));
const funn = [];
for (const f of filer) {
  // Bare koden: kommentarene forklarer nettopp hvorfor wc_get_orders() med meta_query ikke brukes.
  const kilde = readFileSync(f, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
  for (const m of kilde.matchAll(/wc_get_orders\s*\(([\s\S]*?)\);/g)) if (/meta_/.test(m[1])) funn.push(f.slice(REPO.length + 1));
}
sjekk('ingen wc_get_orders() med meta-filter i pluginen', funn.length === 0, funn);

ferdig();
