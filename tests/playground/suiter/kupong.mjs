// /frakt med kupong (1.4.9): validering via WooCommerce sin apply_coupon(),
// rabatt i svaret, gratis-frakt-grensen MED rabatten, og at kupongen krever
// X-Amendo-Kasse-Secret.
import { sjekk, ferdig, get, frakt, med, rate, klargjorFrakt } from '../lib/felles.mjs';

const ids = await klargjorFrakt();
await get('/amendo-test/v1/kuponger', { fs_requires: 'min_amount' });

const kurv = (antall, ekstra = {}) => ({ varer: [{ product_id: ids.brod, antall }], land: 'NO', postnummer: '0150', ...ekstra });

console.log('Tilgang');
let r = await frakt(kurv(2, { kupong: 'ti' }));
sjekk('kupong uten secret → 403', r.status === 403, r.json);
r = await frakt(kurv(2), {});
sjekk('uten kupong og secret → 200 som før', r.status === 200, r.json);
sjekk('… og ingen kupong-nøkler', !('kupong' in r.json) && !('varer_inkl_mva' in r.json), Object.keys(r.json));

console.log('\nGyldig kupong (10 %, 2 × 250 = 500)');
r = await frakt(kurv(2, { kupong: 'ti' }), med);
sjekk('200', r.status === 200, r.json);
sjekk('gyldig', r.json.kupong?.gyldig === true, r.json.kupong);
sjekk('rabatt 50 inkl / 40 eks', r.json.kupong?.rabatt_inkl_mva === 50 && r.json.kupong?.rabatt_eks_mva === 40, r.json.kupong);
sjekk('varer_inkl_mva 500 (før rabatt)', r.json.varer_inkl_mva === 500, r.json);
sjekk('ingen melding', r.json.kupong?.melding === null, r.json.kupong);
sjekk('kode normalisert («TI» → «ti»)', (await frakt(kurv(2, { kupong: ' TI ' }), med)).json.kupong?.kode === 'ti');

console.log('\nGratis-frakt-grensen regnes MED rabatten (1000, ignore_discounts=no)');
r = await frakt(kurv(4, { kupong: 'ti' }), med);
sjekk('4 × 250 − 10 % = 900: ingen gratis frakt', !rate(r, 'free_shipping'), r.json.alternativer);
sjekk('gjenstår 100', r.json.gjenstaar_til_gratis === 100, r.json);
r = await frakt(kurv(4), med);
sjekk('samme kurv uten kupong: gratis frakt (kupongen lekket ikke)', !!rate(r, 'free_shipping') && r.json.gjenstaar_til_gratis === 0, r.json);

console.log('\nUgyldige kuponger');
for (const [kode, hva] of [['utlopt', 'utløpt'], ['min2000', 'under minstebeløp'], ['oppbrukt', 'bruksgrense nådd'], ['finnes-ikke-xyz', 'finnes ikke']]) {
  r = await frakt(kurv(2, { kupong: kode }), med);
  sjekk(`${hva}: gyldig=false, rabatt 0, melding`, r.json.kupong?.gyldig === false && r.json.kupong?.rabatt_inkl_mva === 0 && !!r.json.kupong?.melding, r.json.kupong);
}
r = await frakt(kurv(2, { kupong: 'utlopt' }), med);
sjekk('melding uten HTML', !/<[a-z]/i.test(r.json.kupong?.melding || ''), r.json.kupong?.melding);

console.log('\nGrense per kunde og e-postbegrensning');
r = await frakt(kurv(2, { kupong: 'perkunde', epost: 'brukt@example.com' }), med);
sjekk('perkunde, brukt e-post → ugyldig', r.json.kupong?.gyldig === false, r.json.kupong);
r = await frakt(kurv(2, { kupong: 'perkunde', epost: 'ny@example.com' }), med);
sjekk('perkunde, ny e-post → gyldig', r.json.kupong?.gyldig === true, r.json.kupong);
r = await frakt(kurv(2, { kupong: 'bare-kari', epost: 'ola@example.com' }), med);
sjekk('bare-kari, annen e-post → ugyldig', r.json.kupong?.gyldig === false, r.json.kupong);
r = await frakt(kurv(2, { kupong: 'bare-kari', epost: 'kari@example.com' }), med);
sjekk('bare-kari, riktig e-post → gyldig', r.json.kupong?.gyldig === true, r.json.kupong);

console.log('\nKupong med gratis frakt');
await get('/amendo-test/v1/kuponger', { fs_requires: 'either' });
r = await frakt(kurv(1, { kupong: 'fraktfri' }), med);
sjekk('gratis_frakt=true', r.json.kupong?.gratis_frakt === true, r.json.kupong);
sjekk('free_shipping tilbys under beløpsgrensen', !!rate(r, 'free_shipping'), r.json.alternativer);
r = await frakt(kurv(1), med);
sjekk('uten kupongen: ingen free_shipping', !rate(r, 'free_shipping'), r.json.alternativer);
await get('/amendo-test/v1/kuponger', { fs_requires: 'min_amount' });

console.log('\nValidering');
r = await frakt(kurv(2, { kupong: 'x'.repeat(60) }), med);
sjekk('for lang kode → 400', r.status === 400, r.json);
r = await frakt(kurv(2, { kupong: 123 }), med);
sjekk('tall som kode → 400', r.status === 400, r.json);

ferdig();
