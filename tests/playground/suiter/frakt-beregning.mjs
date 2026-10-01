// /frakt: priser, MVA, gratis-frakt-grense, skjuling av betalt frakt, avviste varer.
import { sjekk, ferdig, get, frakt, med, rate, klargjorFrakt } from '../lib/felles.mjs';

const ids = await klargjorFrakt();

console.log('Under grensen (2 × 250 = 500, NO)');
let r = await frakt({ varer: [{ product_id: ids.brod, antall: 2 }], land: 'no', postnummer: '0150', sted: 'Oslo' }, med);
sjekk('200', r.status === 200, r.json);
const fr = rate(r, 'flat_rate');
sjekk('flat_rate id = flat_rate:instance', fr?.id === `flat_rate:${ids.fr}`, fr);
sjekk('flat_rate 99 eks / 24.75 mva / 123.75 inkl', fr?.pris_eks_mva === 99 && fr?.mva === 24.75 && fr?.pris_inkl_mva === 123.75, fr);
sjekk('navn fra WC', fr?.navn === 'Hjemlevering', fr);
sjekk('meta uten «Items»/produktnavn', fr && !JSON.stringify(fr.meta).includes('Brød'), fr?.meta);
sjekk('meta er objekt', fr && typeof fr.meta === 'object' && !Array.isArray(fr.meta), fr?.meta);
sjekk('local_pickup med', !!rate(r, 'local_pickup'), r.json.alternativer);
sjekk('ingen free_shipping under grensen', !rate(r, 'free_shipping'), r.json.alternativer);
sjekk('grense 1000, gjenstår 500', r.json.gratis_frakt_grense === 1000 && r.json.gjenstaar_til_gratis === 500, r.json);
sjekk('valuta NOK', r.json.valuta === 'NOK');
sjekk('ingen Set-Cookie', !r.h.get('set-cookie'), r.h.get('set-cookie'));
sjekk('unntatt: ingen X-RateLimit-Remaining', r.h.get('x-ratelimit-remaining') === null);
sjekk('nøkler i svaret', JSON.stringify(Object.keys(r.json)) === JSON.stringify(['valuta', 'alternativer', 'gratis_frakt_grense', 'gjenstaar_til_gratis', 'avviste_varer']), Object.keys(r.json));
sjekk('nøkler per alternativ', JSON.stringify(Object.keys(fr || {})) === JSON.stringify(['id', 'metode', 'navn', 'pris_eks_mva', 'mva', 'pris_inkl_mva', 'meta']), Object.keys(fr || {}));

console.log('\nOver grensen (4 × 250 = 1000)');
r = await frakt({ varer: [{ product_id: ids.brod, antall: 4 }], land: 'NO', postnummer: '0150' }, med);
sjekk('free_shipping med', !!rate(r, 'free_shipping'), r.json.alternativer);
sjekk('free_shipping 0 kr', rate(r, 'free_shipping')?.pris_inkl_mva === 0);
sjekk('flat_rate skjult (standard på)', !rate(r, 'flat_rate'), r.json.alternativer);
sjekk('local_pickup beholdt', !!rate(r, 'local_pickup'));
sjekk('gjenstår 0', r.json.gjenstaar_til_gratis === 0, r.json);

await get('/amendo-test/v1/opt', { amendo_frakt_skjul_betalt: '0' });
r = await frakt({ varer: [{ product_id: ids.brod, antall: 4 }], land: 'NO', postnummer: '0150' }, med);
sjekk('innstilling av: flat_rate vises sammen med gratis', !!rate(r, 'flat_rate') && !!rate(r, 'free_shipping'), r.json.alternativer);
await get('/amendo-test/v1/opt', { amendo_frakt_skjul_betalt: '1' });

console.log('\nSverige (ingen gratis frakt i sonen)');
r = await frakt({ varer: [{ product_id: ids.brod, antall: 10 }], land: 'SE', postnummer: '111 22' }, med);
sjekk('flat_rate 199 + 25 %', rate(r, 'flat_rate')?.pris_inkl_mva === 248.75, r.json);
sjekk('grense og gjenstår null', r.json.gratis_frakt_grense === null && r.json.gjenstaar_til_gratis === null, r.json);

console.log('\nVarer som avvises');
r = await frakt({ varer: [
  { product_id: ids.kake_id, variation_id: ids.v1_id, antall: 1 },
  { product_id: ids.kake_id, antall: 1 },
  { product_id: ids.kake_id, variation_id: ids.v2_id, antall: 1 },
  { product_id: ids.utsolgt, antall: 1 },
  { product_id: ids.kladd, antall: 1 },
  { product_id: ids.brod, variation_id: ids.v1_id, antall: 1 },
  { product_id: 999999, antall: 1 },
  { product_id: String(ids.brod), antall: '2' },
], land: 'NO', postnummer: '0150' }, med);
const grunn = Object.fromEntries((r.json.avviste_varer || []).map(a => [a.indeks, a.grunn]));
sjekk('«hvilken som helst»-variant godtatt', !(0 in grunn), r.json.avviste_varer);
sjekk('variabelt produkt uten variant', grunn[1] === 'mangler_variant', grunn);
sjekk('avslått variant', grunn[2] === 'finnes_ikke', grunn);
sjekk('utsolgt', grunn[3] === 'ikke_paa_lager', grunn);
sjekk('kladd', grunn[4] === 'finnes_ikke', grunn);
sjekk('variant med feil forelder', grunn[5] === 'finnes_ikke', grunn);
sjekk('finnes ikke', grunn[6] === 'finnes_ikke', grunn);
sjekk('tall som strenger godtatt', !(7 in grunn), grunn);
sjekk('500 + 2×250 = 1000 → gratis', !!rate(r, 'free_shipping') && r.json.gjenstaar_til_gratis === 0, r.json);
sjekk('avvist-element har bare id-er og grunn', JSON.stringify(Object.keys(r.json.avviste_varer?.[0] || {})) === '["indeks","product_id","variation_id","grunn"]');

ferdig();
