// /frakt: rate limit per REMOTE_ADDR, unntak med X-Amendo-Kasse-Secret.
// Playground bruker 10–25 s per kall, så et 60-sekunders vindu rekker å gå ut
// mellom kall. Grensen senkes derfor med filteret (amendo_test_rl), og hver sak
// som skal sperres får sin egen kvote på 1.
import { sjekk, ferdig, get, frakt, med, SECRET, klargjorFrakt } from '../lib/felles.mjs';

const ids = await klargjorFrakt();
const enkel = { varer: [{ product_id: ids.brod, antall: 1 }], land: 'NO', postnummer: '0150' };

console.log('Rate limit');
await get('/amendo-test/v1/opt', { amendo_test_rl: '3' });
await get('/amendo-test/v1/nullstill-rl');
let sist, r;
const gjenstaar = [];
for (let i = 1; i <= 3; i++) { sist = await frakt(enkel); gjenstaar.push(sist.status + '/' + sist.h.get('x-ratelimit-remaining')); }
sjekk('grense 3 (via filteret): 3 kall ok, gjenstår 2/1/0', gjenstaar.join() === '200/2,200/1,200/0', gjenstaar);
r = await frakt(enkel);
sjekk('4. kall: 429 med Retry-After', r.status === 429 && Number(r.h.get('retry-after')) > 0 && Number(r.h.get('retry-after')) <= 60, [r.status, r.h.get('retry-after'), r.json]);
r = await frakt(enkel, { 'X-Amendo-Kasse-Secret': 'feil' });
sjekk('feil secret: fortsatt 429', r.status === 429, r.status);
r = await frakt(enkel, med);
sjekk('riktig secret: unntatt', r.status === 200, r.status);

const sperres = async (navn, secretOption, header, body = enkel) => {
  await get('/amendo-test/v1/opt', { amendo_kasse_secret: secretOption, amendo_test_rl: '1' });
  await get('/amendo-test/v1/nullstill-rl');
  await frakt({});
  const svar = await frakt(body, header);
  sjekk(navn, svar.status === 429, svar.status);
};
await sperres('X-Forwarded-For ignoreres: 429', SECRET, { 'X-Forwarded-For': '10.9.9.9' });
await sperres('rate limit før validering (429, ikke 400)', SECRET, {}, {});
await sperres('tom secret + tom header: 429', '', { 'X-Amendo-Kasse-Secret': '' });
await sperres('tom secret + hvilken som helst header: 429', '', med);
await sperres('ingen secret-option: 429', null, med);
await get('/amendo-test/v1/opt', { amendo_kasse_secret: SECRET, amendo_test_rl: '1' });
await get('/amendo-test/v1/nullstill-rl');
await frakt({});
r = await frakt({}, med);
sjekk('satt secret + riktig header forbi oppbrukt kvote: 400 (validering), ikke 429', r.status === 400, r.status);
await get('/amendo-test/v1/opt', { amendo_test_rl: null });
await get('/amendo-test/v1/nullstill-rl');
r = await frakt(enkel);
sjekk('standardgrense 30: første kall gjenstår 29', r.h.get('x-ratelimit-remaining') === '29', r.h.get('x-ratelimit-remaining'));

ferdig();
