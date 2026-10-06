// /bedrift-sok: oppslag på org.nummer, tilgang, rate limit og at svaret ikke
// lekker personopplysninger.
import { sjekk, ferdig, get, B, SECRET } from '../lib/felles.mjs';

const KONTO = '923207074';   // godkjent: billing_org_number
const SOKNAD = '998877665';  // søknad: _orgnr_soknad
const BEGGE = '111222333';   // har begge — skal regnes som konto
const UKJENT = '555444333';

const sok = async (orgnr, headers = {}) => {
  const r = await fetch(`${B}/amendo-settings/v1/bedrift-sok&org_nr=${encodeURIComponent(orgnr)}`, { headers });
  const tekst = await r.text();
  let json; try { json = JSON.parse(tekst); } catch { json = tekst; }
  return { status: r.status, h: r.headers, json, tekst };
};
const med = { 'X-Amendo-Secret': SECRET };

// ── Oppsett ────────────────────────────────────────────────────────────────
await get('/amendo-test/v1/opt', { amendo_kasse_secret: SECRET, amendo_test_bedrift_rl: null });
await get('/amendo-test/v1/bedrift-bruker', { epost: 'konto@example.com', meta: { billing_org_number: KONTO } });
await get('/amendo-test/v1/bedrift-bruker', { epost: 'soknad@example.com', meta: { _orgnr_soknad: SOKNAD, _b2b_status: 'venter' } });
await get('/amendo-test/v1/bedrift-bruker', { epost: 'begge@example.com', meta: { billing_org_number: BEGGE, _orgnr_soknad: BEGGE } });
await get('/amendo-test/v1/bedrift-nullstill');

console.log('Oppslag');
let r = await sok(KONTO, med);
sjekk('godkjent bedrift: finnes + type konto', r.status === 200 && r.json.finnes === true && r.json.type === 'konto', r.json);
r = await sok(SOKNAD, med);
sjekk('søknad: finnes + type soknad', r.status === 200 && r.json.finnes === true && r.json.type === 'soknad', r.json);
r = await sok(BEGGE, med);
sjekk('både konto og søknad: regnes som konto', r.json.type === 'konto', r.json);
r = await sok(UKJENT, med);
sjekk('ukjent org.nr: finnes false, ingen type', r.status === 200 && r.json.finnes === false && r.json.type === undefined, r.json);
r = await sok('923 207 074', med);
sjekk('mellomrom i nummeret tolereres', r.json.finnes === true && r.json.type === 'konto', r.json);

console.log('\nSvaret lekker ingenting');
r = await sok(KONTO, med);
const felter = Object.keys(r.json).sort().join(',');
sjekk('bare finnes + type i svaret', felter === 'finnes,type', felter);
sjekk('ingen e-post noe sted i kroppen', !/example\.com|@/.test(r.tekst), r.tekst);
sjekk('ingen bruker-ID i kroppen', !/\bid\b|user/i.test(r.tekst), r.tekst);

console.log('\nValidering');
for (const [navn, verdi] of [['for få siffer', '12345'], ['for mange siffer', '1234567890'], ['bokstaver', 'abcdefghi'], ['tom', '']]) {
  r = await sok(verdi, med);
  sjekk(`${navn}: 400`, r.status === 400, [r.status, r.json]);
}

console.log('\nTilgang');
r = await sok(KONTO);
sjekk('uten secret: 401/403', r.status === 401 || r.status === 403, r.status);
r = await sok(KONTO, { 'X-Amendo-Secret': 'feil' });
sjekk('feil secret: 401/403', r.status === 401 || r.status === 403, r.status);
// ⚠ Her skiller ruten seg fra /adyen-session, som slipper alle gjennom når
// secreten mangler. Et åpent «er denne bedriften kunde?» ville vært et register.
await get('/amendo-test/v1/opt', { amendo_kasse_secret: '' });
r = await sok(KONTO, med);
sjekk('TOM secret gir IKKE åpen rute: 401/403', r.status === 401 || r.status === 403, r.status);
r = await sok(KONTO, { 'X-Amendo-Secret': '' });
sjekk('tom secret + tom header: 401/403', r.status === 401 || r.status === 403, r.status);
await get('/amendo-test/v1/opt', { amendo_kasse_secret: SECRET });

console.log('\nRate limit');
await get('/amendo-test/v1/opt', { amendo_test_bedrift_rl: '3' });
await get('/amendo-test/v1/bedrift-nullstill');
const koder = [];
for (let i = 1; i <= 3; i++) koder.push((await sok(UKJENT, med)).status);
sjekk('grense 3: tre kall slipper gjennom', koder.join() === '200,200,200', koder);
r = await sok(UKJENT, med);
sjekk('fjerde kall: 429 med Retry-After', r.status === 429 && Number(r.h.get('retry-after')) > 0 && Number(r.h.get('retry-after')) <= 60, [r.status, r.h.get('retry-after')]);
// ⚠ Rate limit FØR validering: ellers kan et ugyldig nummer brukes til å måle
// om kvoten er brukt opp.
r = await sok('123', med);
sjekk('ugyldig nummer over kvoten: 429, ikke 400', r.status === 429, r.status);
await get('/amendo-test/v1/opt', { amendo_test_bedrift_rl: null });
await get('/amendo-test/v1/bedrift-nullstill');
r = await sok(UKJENT, med);
sjekk('standardgrensen slipper gjennom etter nullstilling', r.status === 200, r.status);

ferdig();
