// 1.4.17: personlige PIN-koder til Helsebygget-admin. Ansatte i B2B →
// Helsebygget, POST helsebygget/admin-innlogging → token (12 t), POST
// helsebygget-produkter krever tokenet og logger hvem/når/hva, maks 5 feil
// forsøk per 15 min per IP, og alt avvist når kasse-secret er tom. Se
// includes/helsebygget.php og includes/moduler/b2b-helsebygget.php.
import { B, get, sjekk, ferdig } from '../lib/felles.mjs';

const SECRET = 'hb-hemmelig-1417';
const opt = o => get('/amendo-test/v1/opt', o);
const handling = (post, nonce = true) => get('/amendo-test/v1/v1417-handling', { post, nonce });
const tilstand = () => get('/amendo-test/v1/v1417-tilstand');
const side = async (q = '') => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=admin' + q)).text();
const kall = async (sti, { body, secret = SECRET, token, metode = 'POST' } = {}) => {
  const headers = { 'content-type': 'application/json' };
  if (secret !== null) headers['X-Amendo-Secret'] = secret;
  if (token) headers['X-Helsebygget-Token'] = token;
  const r = await fetch(B + '/amendo-settings/v1/' + sti, { method: metode, headers, body: body && JSON.stringify(body) });
  const tekst = await r.text();
  let json = null; try { json = JSON.parse(tekst); } catch {}
  return { status: r.status, json, tekst, h: r.headers };
};
const logginn = (pin, secret) => kall('helsebygget/admin-innlogging', { body: { pin }, secret });
const lagre = (ider, token, secret) => kall('helsebygget-produkter', { body: { aktive_ids: ider }, token, secret });
const nyPin = html => html.match(/class="amendo-pin">(\d{6})</)?.[1];

const ids = await get('/amendo-test/v1/setup');
await get('/amendo-test/v1/v1417-nullstill');
await opt({ amendo_kasse_secret: SECRET, amendo_moduler: JSON.stringify(['b2b']), amendo_helsebygget_produkter: JSON.stringify([ids.brod]) });

// ── Ansatte i fanen ─────────────────────────────────────────────────────────
console.log('Ansatte i B2B → Helsebygget');
let html = await side();
sjekk('underfanen Helsebygget med skjema og «Legg til ansatt»', html.includes('data-under-panel="helsebygget"') && html.includes('id="amendo-hb-ansatt"') && html.includes('name="ny_ansatt"'));
sjekk('ingen ansatte ennå', html.includes('Ingen ansatte ennå'));
let h = await handling({ ny_ansatt: '1', navn: 'Kari <b>Nordmann</b>' }, false);
sjekk('uten nonce: stoppes', h.stoppet && !h.til, h);
h = await handling({ ny_ansatt: '1', navn: '   ' });
sjekk('uten navn: ikke lagt til', /hb=mangler_navn/.test(h.til || '') && (await tilstand()).ansatte.length === 0, h);
h = await handling({ ny_ansatt: '1', navn: 'Kari <b>Nordmann</b>' });
sjekk('lagt til: tilbake til #b2b-helsebygget', /hb=lagt_til#b2b-helsebygget$/.test(h.til || ''), h);
html = await side('&hb=lagt_til');
const pinKari = nyPin(html);
sjekk('PIN-koden vises når den er laget (6 sifre)', /^\d{6}$/.test(pinKari || ''), html.match(/amendo-ny-pin[^]{0,300}/)?.[0]);
sjekk('navnet er renset for HTML', html.includes('Kari Nordmann') && !html.includes('<b>Nordmann'));
html = await side();
sjekk('… og bare den ene gangen', !nyPin(html) && html.includes('Kari Nordmann'));
let t = await tilstand();
sjekk('PIN lagres ikke i klartekst (bare HMAC)', !String(t.ansatte_raa).includes(pinKari) && /"pin":"[0-9a-f]{64}"/.test(String(t.ansatte_raa)), t.ansatte_raa);
const kari = t.ansatte[0];
await handling({ ny_ansatt: '1', navn: 'Ola' });
const pinOla = nyPin(await side());
sjekk('to ansatte har ulik PIN', pinOla && pinOla !== pinKari);
t = await tilstand();
const ola = t.ansatte.find(a => a.navn === 'Ola');

// ── Innlogging ──────────────────────────────────────────────────────────────
console.log('\nPOST helsebygget/admin-innlogging');
let r = await logginn(pinKari, null);
sjekk('uten X-Amendo-Secret: avvist', r.status === 401 || r.status === 403, r.status);
const feilPin = ['000000', '111111'].find(p => p !== pinKari && p !== pinOla);
r = await logginn(feilPin);
sjekk('feil PIN: 401', r.status === 401 && r.json?.code === 'amendo_helsebygget_feil_pin', [r.status, r.json]);
await get('/amendo-test/v1/v1417-nullstill-rl');
r = await logginn(pinKari);
sjekk('riktig PIN: navn og token', r.status === 200 && r.json?.navn === 'Kari Nordmann' && /^[\w-]+\.[\w-]+$/.test(r.json?.token || ''), r.json);
const tokenKari = r.json?.token;
const nyttig = JSON.parse(Buffer.from(tokenKari.split('.')[0].replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString());
const timer = (nyttig.exp - Date.now() / 1000) / 3600;
sjekk('tokenet varer i 12 timer', timer > 11.9 && timer <= 12.01, timer);
sjekk('tokenet har ikke PIN-en', !tokenKari.includes(pinKari) && !JSON.stringify(nyttig).includes(pinKari), nyttig);
r = await logginn(` ${pinOla.slice(0, 3)} ${pinOla.slice(3)} `);
sjekk('PIN med mellomrom: godtatt', r.status === 200 && r.json?.navn === 'Ola', r.json);
const tokenOla = r.json?.token;

// ── Lagring av sortimentet ──────────────────────────────────────────────────
console.log('\nPOST helsebygget-produkter krever token');
r = await lagre([ids.brod, ids.kake_id], null);
sjekk('uten token: 401', r.status === 401 && r.json?.code === 'amendo_helsebygget_token', [r.status, r.json]);
r = await lagre([ids.kake_id], 'tull.tull');
sjekk('ugyldig token: 401', r.status === 401, r.status);
const [del0, del1] = tokenKari.split('.');
const fusket = Buffer.from(JSON.stringify({ ...nyttig, a: ola.id })).toString('base64url') + '.' + del1;
r = await lagre([ids.kake_id], fusket);
sjekk('token med endret innhold (annen ansatt): 401', r.status === 401, r.status);
r = await lagre([ids.kake_id], tokenKari, null);
sjekk('gyldig token, men uten X-Amendo-Secret: avvist', r.status === 401 || r.status === 403, r.status);
t = await tilstand();
sjekk('… og ingenting er lagret eller logget', JSON.stringify(t.produkter) === JSON.stringify([ids.brod]) && t.logg.length === 0, t);
r = await lagre([ids.brod, ids.kake_id], tokenKari);
sjekk('gyldig token: lagret', r.status === 200 && r.json?.ok === true, [r.status, r.json]);
t = await tilstand();
sjekk('sortimentet er endret', JSON.stringify(t.produkter) === JSON.stringify([ids.brod, ids.kake_id]), t.produkter);
sjekk('logget: navn, tid og endring', t.logg.length === 1 && t.logg[0].navn === 'Kari Nordmann' && Math.abs(t.logg[0].tid - Date.now() / 1000) < 300 && JSON.stringify(t.logg[0].lagt_til) === JSON.stringify([ids.kake_id]) && t.logg[0].fjernet.length === 0, t.logg);
r = await lagre([ids.kake_id], tokenOla);
t = await tilstand();
sjekk('neste endring: nyeste først, med den andre ansatte', t.logg.length === 2 && t.logg[0].navn === 'Ola' && JSON.stringify(t.logg[0].fjernet) === JSON.stringify([ids.brod]), t.logg);
r = await kall('helsebygget-produkter', { metode: 'GET', secret: null });
sjekk('GET er åpen som før', r.status === 200 && Array.isArray(r.json?.produkter) && JSON.stringify(r.json?.aktive_ids) === JSON.stringify([ids.kake_id]), r.status);
html = await side();
const loggDel = html.slice(html.indexOf('id="b2b-helsebygget-logg"'));
sjekk('fanen viser loggen med navn og varenavn', (loggDel.match(/class="hb-logg"/g) || []).length === 2 && loggDel.includes('Ola') && loggDel.includes('Tok bort:') && loggDel.includes('La til:') && !loggDel.includes('vare #'));

const utlopt = (await get('/amendo-test/v1/v1417-utlopt-token', { id: kari.id, alder: 5 })).token;
r = await lagre([ids.brod], utlopt);
sjekk('utløpt token: 401', r.status === 401, r.status);

// ── Ny PIN og sletting ──────────────────────────────────────────────────────
console.log('\nNy PIN og sletting');
h = await handling({ ny_pin: kari.id });
const pinKari2 = nyPin(await side('&hb=ny_pin'));
sjekk('ny PIN laget og vist', /hb=ny_pin/.test(h.til || '') && /^\d{6}$/.test(pinKari2 || '') && pinKari2 !== pinKari, [h, pinKari2]);
r = await lagre([ids.brod], tokenKari);
sjekk('det gamle tokenet virker ikke lenger', r.status === 401, r.status);
await get('/amendo-test/v1/v1417-nullstill-rl');
r = await logginn(pinKari);
sjekk('den gamle PIN-en virker ikke', r.status === 401, r.status);
r = await logginn(pinKari2);
sjekk('den nye gjør det', r.status === 200 && r.json?.navn === 'Kari Nordmann', r.json);
h = await handling({ slett: ola.id });
r = await lagre([ids.brod], tokenOla);
sjekk('slettet ansatt: tokenet virker ikke', /hb=slettet/.test(h.til || '') && r.status === 401, [h, r.status]);
await get('/amendo-test/v1/v1417-nullstill-rl');
r = await logginn(pinOla);
sjekk('… og PIN-en heller ikke', r.status === 401, r.status);

// ── Grense for feil forsøk ──────────────────────────────────────────────────
console.log('\nMaks 5 feil forsøk per 15 min');
await get('/amendo-test/v1/v1417-nullstill-rl');
const koder = [];
koder.push((await logginn(pinKari2)).status);
for (let i = 0; i < 4; i++) koder.push((await logginn(feilPin)).status);
koder.push((await logginn(pinKari2)).status);
sjekk('riktige forsøk teller ikke: 4 feil, så riktig = 200', JSON.stringify(koder) === '[200,401,401,401,401,200]', koder);
koder.push((await logginn(feilPin)).status);
r = await logginn(pinKari2);
sjekk('femte feil: så sperret, også med riktig PIN (429)', koder.at(-1) === 401 && r.status === 429 && Number(r.h.get('retry-after')) > 0 && r.json?.code === 'amendo_helsebygget_sperret', [koder, r.status]);

// ── Loggen holder de siste 200 ──────────────────────────────────────────────
console.log('\nLoggen');
const fylt = await get('/amendo-test/v1/v1417-fyll-logg', { id: kari.id, antall: 205 });
sjekk('maks 200 i loggen', fylt.antall === 200, fylt);
t = await tilstand();
sjekk('… de nyeste', JSON.stringify(t.logg[0].lagt_til) === '[205]', t.logg[0]);

// ── Tom secret ──────────────────────────────────────────────────────────────
console.log('\nTom kasse-secret: alt avvist');
await get('/amendo-test/v1/v1417-nullstill-rl');
await opt({ amendo_kasse_secret: '' });
r = await logginn(pinKari2, '');
sjekk('innlogging avvist', r.status === 401 || r.status === 403, r.status);
r = await lagre([ids.brod], 'x.y', '');
sjekk('lagring avvist', r.status === 401 || r.status === 403, r.status);
html = await side();
sjekk('fanen sier at innloggingen er stengt', html.includes('amendo-hb-stengt'));

await get('/amendo-test/v1/v1417-nullstill');
await opt({ amendo_kasse_secret: null, amendo_moduler: null, amendo_helsebygget_produkter: null });
ferdig();
