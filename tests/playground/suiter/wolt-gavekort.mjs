// 1.4.23: modulene wolt og gavekort. Ingen ekte kall: Wolt-tillegget og Wolts
// API, og WooCommerce Gift Cards, er stubber i mu/v1423-test.php.
// Se includes/moduler/wolt.php og includes/moduler/gavekort.php.
import { B, get, sjekk, ferdig } from '../lib/felles.mjs';

const SECRET = 'hemmelig-1423';
const sett = o => get('/amendo-test/v1/v1423-sett', o);
const tilstand = () => get('/amendo-test/v1/v1423-tilstand');
const inspiser = () => get('/amendo-test/v1/v1423-inspiser');
const lagre = post => get('/amendo-test/v1/v1423-lagre', { post });
const side = async () => (await fetch(B + '/amendo-test/v1/v1423-side')).text();
const ordre = body => get('/amendo-test/v1/v1423-ordre', body);
const ordreLes = async id => (await fetch(B + '/amendo-test/v1/v1423-ordre-les&id=' + id)).json();
const status = body => get('/amendo-test/v1/v1423-ordre-status', body);
const post = async (rute, body, hemmelighet = SECRET) => {
  const headers = { 'content-type': 'application/json' };
  if (hemmelighet !== null) headers['X-Amendo-Secret'] = hemmelighet;
  const r = await fetch(B + '/amendo-settings/v1/' + rute, { method: 'POST', headers, body: JSON.stringify(body) });
  const tekst = await r.text();
  let json; try { json = JSON.parse(tekst); } catch { json = tekst; }
  return { status: r.status, json, h: r.headers };
};
const wolt = (body, h) => post('wolt-tilbud', body, h);
const sjekkKort = (kode, h) => post('gavekort/sjekk', { kode }, h);
const losInn = (body, h) => post('gavekort/los-inn', body, h);

// Datoer i butikkens tidssone (Europe/Oslo).
const osloDato = (dager) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Oslo' }).format(new Date(Date.now() + dager * 864e5));
const osloTilUtc = (dato, tid, sekunder = 0) => {
  const [y, m, d] = dato.split('-').map(Number), [hh, mm] = tid.split(':').map(Number);
  const gjett = Date.UTC(y, m - 1, d, hh, mm, sekunder);
  const del = new Intl.DateTimeFormat('en-US', { timeZone: 'Europe/Oslo', timeZoneName: 'shortOffset' }).formatToParts(new Date(gjett)).find(p => p.type === 'timeZoneName').value;
  const off = Number((del.match(/GMT([+-]\d+)/) || [0, 0])[1]);
  return new Date(gjett - off * 36e5).toISOString().replace('.000Z', '+00:00');
};

await sett({ amendo_test_stille: '1' }); // ingen cron ved siden av testene, se mu/v1423-test.php
await get('/amendo-test/v1/setup');
await sett({ timezone_string: 'Europe/Oslo', amendo_kasse_secret: SECRET, amendo_moduler: null, amendo_test_abbdw: null, amendo_test_wcgc: null, amendo_test_gk_ip: '1000' });

// ── Av ──────────────────────────────────────────────────────────────────────
console.log('Modulene av');
let t = await inspiser();
sjekk('ingen wolt-tilbud eller gavekort-ruter', !t.ruter.some(r => /wolt|gavekort/.test(r)), t.ruter);
let r = await wolt({});
sjekk('wolt-tilbud: 404 når modulen er av', r.status === 404, r.status);
r = await sjekkKort('AAAA-BBBB-CCCC-DDDD');
sjekk('gavekort/sjekk: 404 når modulen er av', r.status === 404, r.status);
let html = await side();
sjekk('ingen Wolt-fane', !html.includes('data-tab="wolt"'));

// ── Wolt ────────────────────────────────────────────────────────────────────
console.log('\nWolt-tilbud');
const UTSALG = { env: 'staging', api_key: 'GLOBAL-KEY', outlets: [
  { slug: 'oslo', label: 'Aanerud Oslo', venue_id: 'VENUE-OSLO', active: 1, merchant_key: 'MK-OSLO' },
  { slug: 'lillestrom', label: 'Aanerud Lillestrøm', venue_id: 'VENUE-LS', active: 1 },
  { slug: 'stengt', label: 'Aanerud Stengt', venue_id: 'VENUE-X', active: 0 },
] };
await sett({
  amendo_moduler: ['wolt'],
  amendo_avdelinger: [{ navn: 'Oslo' }, { navn: 'Lillestrøm' }, { navn: 'Stengt' }],
  amendo_wolt_utsalg: { Oslo: { utsalg: 'oslo', postnumre: '0150-0199, 0250', poststeder: 'Oslo' }, 'Lillestrøm': { utsalg: 'lillestrom', postnumre: '', poststeder: 'Lillestrøm, Strømmen' }, Stengt: { utsalg: 'stengt', postnumre: '9000', poststeder: '' } },
  amendo_test_wolt: { kode: 201, svar: { id: 'PROMISE-1', is_binding: true, price: { amount: 13625, currency: 'NOK' } } },
  amendo_test_wolt_kall: null,
  amendo_levering_min_timer: null, amendo_levering_luke_minutter: null,
});
const OM_EN_UKE = osloDato(7);
const GYLDIG = { adresse: 'Storgata 1', postnummer: '0155', poststed: 'Oslo', dato: OM_EN_UKE, tid: '12:00', telefon: '+4799999999', navn: 'Kari' };

r = await wolt(GYLDIG, null);
sjekk('uten X-Amendo-Secret: 401', r.status === 401, r.status);
r = await wolt(GYLDIG, 'feil');
sjekk('feil secret: 401', r.status === 401, r.status);
await sett({ amendo_kasse_secret: null });
r = await wolt(GYLDIG, '');
sjekk('secret ikke satt i admin: 503 (fail-closed)', r.status === 503, r.status);
await sett({ amendo_kasse_secret: SECRET });

r = await wolt(GYLDIG);
sjekk('uten Wolt-tillegget: 503', r.status === 503 && r.json.ok === false, r);
await sett({ amendo_test_abbdw: UTSALG });

for (const [navn, endring] of [['uten adresse', { adresse: ' ' }], ['postnummer med tre sifre', { postnummer: '155' }], ['uten poststed', { poststed: '' }], ['dato som ikke finnes', { dato: '2026-02-31' }], ['tid 25:00', { tid: '25:00' }], ['dato i feil form', { dato: '31.10.2026' }]]) {
  r = await wolt({ ...GYLDIG, ...endring });
  sjekk(`ugyldig (${navn}): 400 { ok:false, feil:'ugyldig' }`, r.status === 400 && JSON.stringify(r.json) === '{"ok":false,"feil":"ugyldig"}', r);
}
sjekk('ingen kall til Wolt for ugyldige', (await tilstand()).wolt_kall.length === 0);

r = await wolt({ ...GYLDIG, dato: osloDato(0), tid: '00:00' });
sjekk('for tidlig: { ok, leverer:false, feil:"for_tidlig", min_timer:4 }', r.status === 200 && JSON.stringify(r.json) === '{"ok":true,"leverer":false,"feil":"for_tidlig","min_timer":4}', r.json);
await sett({ amendo_levering_min_timer: '6' });
r = await wolt({ ...GYLDIG, dato: osloDato(0), tid: '00:00' });
sjekk('min_timer fra levering.min_timer (6)', r.json.min_timer === 6, r.json);
await sett({ amendo_levering_min_timer: null });

r = await wolt(GYLDIG);
const forventetTid = osloTilUtc(OM_EN_UKE, '12:07', 30);
sjekk('gyldig: 200 med nøyaktig kontraktens felter', r.status === 200 && JSON.stringify(Object.keys(r.json)) === '["ok","leverer","utsalg","utsalg_navn","pris","promise_id","tidspunkt"]', r.json);
sjekk('… utsalg og navn fra postnummeret (0155 i 0150-0199)', r.json.utsalg === 'oslo' && r.json.utsalg_navn === 'Aanerud Oslo', r.json);
sjekk('… pris i øre inkl. mva, rett fra Wolt', JSON.stringify(r.json.pris) === '{"amount":13625,"currency":"NOK"}' && r.json.promise_id === 'PROMISE-1', r.json);
sjekk('… tidspunkt: midten av kvarteret, i UTC', r.json.tidspunkt === forventetTid, [r.json.tidspunkt, forventetTid]);
let k = (await tilstand()).wolt_kall.at(-1);
sjekk('Wolt: shipment-promises på utsalgets venue, testmiljøet', k?.url === 'https://daas-public-api.development.dev.woltapi.com/v1/venues/VENUE-OSLO/shipment-promises', k?.url);
sjekk('Wolt: utsalgets merchant_key', k?.auth === 'Bearer MK-OSLO', k?.auth);
sjekk('Wolt: samme felter som tillegget sender', JSON.stringify(k?.body) === JSON.stringify({ street: 'Storgata 1', city: 'Oslo', post_code: '0155', min_preparation_time_minutes: 30, scheduled_dropoff_time: forventetTid }), k?.body);

r = await wolt({ ...GYLDIG, postnummer: '2000', poststed: 'lillestrøm' });
k = (await tilstand()).wolt_kall.at(-1);
sjekk('poststed (uten store bokstaver) når postnummeret ikke treffer', r.json.utsalg === 'lillestrom' && r.json.utsalg_navn === 'Aanerud Lillestrøm', r.json);
sjekk('… uten egen nøkkel på utsalget: den globale', k?.auth === 'Bearer GLOBAL-KEY' && k?.url.includes('/VENUE-LS/'), k);
r = await wolt({ ...GYLDIG, postnummer: '0250', poststed: 'Lillestrøm' });
sjekk('postnummer går foran poststed', r.json.utsalg === 'oslo', r.json);

await sett({ amendo_levering_luke_minutter: '30' });
r = await wolt(GYLDIG);
sjekk('luke_minutter 30: midten er 12:15', r.json.tidspunkt === osloTilUtc(OM_EN_UKE, '12:15'), r.json.tidspunkt);
await sett({ amendo_levering_luke_minutter: null });

const foer = (await tilstand()).wolt_kall.length;
r = await wolt({ ...GYLDIG, postnummer: '9999', poststed: 'Tromsø' });
sjekk('ingen avdeling dekker adressen: leverer:false med DROPOFF_OUTSIDE_OF_DELIVERY_AREA', r.status === 200 && r.json.ok === true && r.json.leverer === false && r.json.melding.includes('DROPOFF_OUTSIDE_OF_DELIVERY_AREA'), r.json);
r = await wolt({ ...GYLDIG, postnummer: '9000', poststed: 'Tromsø' });
sjekk('utsalg som er av i tillegget: leverer ikke', r.json.leverer === false && r.json.utsalg === 'stengt', r.json);
sjekk('… og ingen kall til Wolt for dem', (await tilstand()).wolt_kall.length === foer);

const RAA = { error_code: 'DROPOFF_OUTSIDE_OF_DELIVERY_AREA', reason: 'Dropoff location is outside of delivery area' };
await sett({ amendo_test_wolt: { kode: 422, svar: RAA } });
r = await wolt(GYLDIG);
sjekk('Wolt sier nei: { ok, leverer:false, utsalg, utsalg_navn, melding (rå Wolt-JSON) }', r.status === 200 && JSON.stringify(Object.keys(r.json)) === '["ok","leverer","utsalg","utsalg_navn","melding"]' && r.json.utsalg === 'oslo' && JSON.parse(r.json.melding).error_code === RAA.error_code, r.json);
await sett({ amendo_test_wolt: { nede: true } });
r = await wolt(GYLDIG);
sjekk('Wolt svarer ikke: 502 ok:false', r.status === 502 && r.json.ok === false, r);

await sett({ amendo_test_abbdw: { ...UTSALG, env: 'production' }, amendo_test_wolt: { kode: 201, svar: { id: 'P2', price: { amount: 9900, currency: 'NOK' } } } });
await wolt(GYLDIG);
k = (await tilstand()).wolt_kall.at(-1);
sjekk('env production: produksjons-API-et', k?.url.startsWith('https://daas-public-api.wolt.com/'), k?.url);

console.log('\nWolt etter betaling');
const ids = await get('/amendo-test/v1/v1423-produkter');
let o = await ordre({ varer: [{ id: ids.vare }], meta: { _ab_delivery_method: 'wolt_venter' } });
await status({ id: o.id, status: 'on-hold' });
sjekk('ubetalt (on-hold): fortsatt wolt_venter', (await ordreLes(o.id)).levering === 'wolt_venter');
await status({ id: o.id, betal: true });
sjekk('betalt: wolt (tillegget bestiller)', (await ordreLes(o.id)).levering === 'wolt');
o = await ordre({ varer: [{ id: ids.vare }], meta: { _ab_delivery_method: 'henting' } });
await status({ id: o.id, betal: true });
sjekk('annen leveringsmåte røres ikke', (await ordreLes(o.id)).levering === 'henting');

console.log('\nWolt i admin');
await sett({ amendo_test_abbdw: UTSALG });
html = await side();
sjekk('fanen Wolt med én rad per avdeling', html.includes('data-tab="wolt"') && (html.match(/class="wolt-rad"/g) || []).length === 3);
sjekk('utsalgene fra tillegget i valget, det som er av merket', html.includes('>Aanerud Oslo</option>') && html.includes('Aanerud Stengt (av)') && /<option value="oslo"\s+selected/.test(html));
sjekk('postnumrene står', html.includes('value="0150-0199, 0250"'));
let lag = await lagre({ amendo_endret: { wolt: '1' }, wolt_utsalg: { finnes: '1', rader: [{ avdeling: 'Oslo', utsalg: 'oslo', postnumre: '0150-0159', poststeder: 'Oslo' }, { avdeling: 'Lillestrøm', utsalg: '', postnumre: '', poststeder: '' }] } });
sjekk('lagret per avdeling; avdelinger som ikke var med, beholdes', lag.amendo_wolt_utsalg?.Oslo?.postnumre === '0150-0159' && lag.amendo_wolt_utsalg?.['Lillestrøm']?.utsalg === '' && lag.amendo_wolt_utsalg?.Stengt?.utsalg === 'stengt', lag.amendo_wolt_utsalg);
lag = await lagre({ amendo_endret: { wolt: '' }, wolt_utsalg: { finnes: '1', rader: [{ avdeling: 'Oslo', utsalg: '', postnumre: '', poststeder: '' }] } });
sjekk('ikke endret i fanen: lagres ikke', lag.amendo_wolt_utsalg?.Oslo?.utsalg === 'oslo', lag.amendo_wolt_utsalg?.Oslo);
await sett({ amendo_test_abbdw: null });
html = await side();
sjekk('uten tillegget: advarsel i fanen', html.includes('id="wolt-mangler"'));

// ── Gavekort ────────────────────────────────────────────────────────────────
console.log('\nGavekort: sjekk');
const OM_ET_AAR = Math.floor(Date.now() / 1000) + 365 * 86400;
const KORT = {
  1: { code: 'AAAA-BBBB-CCCC-DDDD', remaining: 500, active: 'on', expire: OM_ET_AAR },
  2: { code: 'BRUK-TOPP-0000-0000', remaining: 0, active: 'on', expire: 0 },
  3: { code: 'UTLO-PT00-0000-0000', remaining: 100, active: 'on', expire: Math.floor(Date.now() / 1000) - 86400 },
  4: { code: 'AV00-0000-0000-0000', remaining: 100, active: 'off', expire: 0 },
  5: { code: 'KONT-O000-0000-0000', remaining: 100, active: 'on', expire: 0, redeemed_by: 3 },
  6: { code: 'UTEN-DATO-0000-0000', remaining: 250.5, active: 'on', expire: 0 },
};
const ALLE_KODER = ['AAAA-BBBB-CCCC-DDDD', 'aaaa bbbb cccc dddd', 'FINN-ESIK-KE00-0000', ...Object.values(KORT).map(k => k.code)];
await sett({ amendo_moduler: ['gavekort'], amendo_test_wcgc: null, amendo_test_gc: KORT, amendo_test_gc_aktivitet: null, amendo_test_gc_last: null, brems_nullstill: ALLE_KODER });
r = await sjekkKort('AAAA-BBBB-CCCC-DDDD');
sjekk('uten WooCommerce Gift Cards: 503', r.status === 503, r.status);
await sett({ amendo_test_wcgc: '1' });
r = await sjekkKort('AAAA-BBBB-CCCC-DDDD', null);
sjekk('uten X-Amendo-Secret: 401', r.status === 401, r.status);
r = await sjekkKort('AAAA-BBBB-CCCC-DDDD', 'feil');
sjekk('feil secret: 401', r.status === 401, r.status);

r = await sjekkKort('AAAA-BBBB-CCCC-DDDD');
const iAar = new Date(OM_ET_AAR * 1000);
sjekk('gyldig: { gyldig, saldo, valuta, utloper }', r.status === 200 && JSON.stringify(Object.keys(r.json)) === '["gyldig","saldo","valuta","utloper"]' && r.json.gyldig === true && r.json.saldo === 500 && r.json.valuta === 'NOK' && /^\d{4}-\d{2}-\d{2}$/.test(r.json.utloper), r.json);
sjekk('… utloper er datoen kortet går ut', Math.abs(new Date(r.json.utloper) - iAar) < 2 * 864e5, [r.json.utloper, iAar.toISOString()]);
r = await sjekkKort('aaaa bbbb cccc dddd');
sjekk('små bokstaver og mellomrom: samme kort', r.json.gyldig === true && r.json.saldo === 500, r.json);
r = await sjekkKort('aaaabbbbccccdddd');
sjekk('… og uten bindestreker', r.json.gyldig === true && r.json.saldo === 500, r.json);
r = await sjekkKort('UTEN-DATO-0000-0000');
sjekk('uten utløpsdato: utloper null, saldo med desimaler', r.json.gyldig === true && r.json.utloper === null && r.json.saldo === 250.5, r.json);

const ugyldige = {};
for (const kode of ['FINN-ESIK-KE00-0000', 'BRUK-TOPP-0000-0000', 'UTLO-PT00-0000-0000', 'AV00-0000-0000-0000', 'KONT-O000-0000-0000']) ugyldige[kode] = await sjekkKort(kode);
const fasit = '{"gyldig":false,"saldo":0,"valuta":"NOK","utloper":null}';
sjekk('ukjent kode: { gyldig:false, saldo:0, valuta, utloper:null }', ugyldige['FINN-ESIK-KE00-0000'].status === 200 && JSON.stringify(ugyldige['FINN-ESIK-KE00-0000'].json) === fasit, ugyldige['FINN-ESIK-KE00-0000'].json);
sjekk('brukt opp, utløpt, deaktivert og koblet til konto: NØYAKTIG samme svar som ukjent', Object.values(ugyldige).every(u => u.status === 200 && JSON.stringify(u.json) === fasit), Object.fromEntries(Object.entries(ugyldige).map(([k, u]) => [k, u.json])));
r = await post('gavekort/sjekk', {});
sjekk('uten kode: 400', r.status === 400, r.status);

console.log('\nGavekort: brems');
await sett({ brems_nullstill: ALLE_KODER });
const svar = [];
for (let i = 0; i < 6; i++) svar.push(await sjekkKort('FINN-ESIK-KE00-0000'));
sjekk('per kode: fem forsøk, så 429 med Retry-After', svar.slice(0, 5).every(s => s.status === 200) && svar[5].status === 429 && Number(svar[5].h.get('retry-after')) > 0, svar.map(s => s.status));
r = await sjekkKort('AAAA-BBBB-CCCC-DDDD');
sjekk('… en annen kode slipper fortsatt gjennom', r.status === 200 && r.json.gyldig === true, r.status);
await sett({ brems_nullstill: ALLE_KODER, amendo_test_gk_ip: '3' });
const ip = [];
for (const kode of ['AAAA-BBBB-CCCC-DDDD', 'UTEN-DATO-0000-0000', 'FINN-ESIK-KE00-0000', 'BRUK-TOPP-0000-0000']) ip.push((await sjekkKort(kode)).status);
sjekk('per IP: grensen gjelder alle kodene samlet', JSON.stringify(ip) === '[200,200,200,429]', ip);
await sett({ brems_nullstill: ALLE_KODER, amendo_test_gk_ip: '1000' });

console.log('\nGavekort: innløsning');
o = await ordre({ varer: [{ id: ids.vare, antall: 3 }] });
sjekk('ordre på 300 (3 × 100 inkl. mva)', o.total === 300, o);
const rest = Math.round((o.total - 200) * 100) / 100;
r = await losInn({ order_id: o.id, kode: 'aaaa-bbbb-cccc-dddd', belop: 200 }, null);
sjekk('uten X-Amendo-Secret: 401', r.status === 401, r.status);
r = await losInn({ order_id: o.id, kode: 'AAAA-BBBB-CCCC-DDDD', belop: 200 });
sjekk('trekker: { ok, trukket 200, saldo 300, ordre_total = totalen − 200 }', r.status === 200 && JSON.stringify(r.json) === JSON.stringify({ ok: true, trukket: 200, saldo: 300, ordre_total: rest }), r.json);
let ol = await ordreLes(o.id);
sjekk('ordren: gavekortlinje på 200, trukket, totalen redusert, låsen fjernet', ol.total === rest && ol.gavekort.length === 1 && ol.gavekort[0].belop === 200 && ol.gavekort[0].trukket === true && !ol.las, ol);
let st = await tilstand();
sjekk('kortet: 300 igjen, én «used» på 200 for ordren', st.gc['1'].remaining === 300 && st.aktivitet.length === 1 && st.aktivitet[0].type === 'used' && st.aktivitet[0].belop === 200 && st.aktivitet[0].ordre === o.id, [st.gc['1'], st.aktivitet]);

r = await losInn({ order_id: o.id, kode: 'AAAA-BBBB-CCCC-DDDD', belop: 50 });
sjekk('samme kort to ganger på ordren: 409 allerede', r.status === 409 && r.json.feil === 'allerede', r);
r = await losInn({ order_id: o.id, kode: 'UTEN-DATO-0000-0000', belop: Math.min(250, rest + 50) });
sjekk('mer enn det som gjenstår på ordren: 409 for_mye', r.status === 409 && r.json.feil === 'for_mye', r);
r = await losInn({ order_id: o.id, kode: 'BRUK-TOPP-0000-0000', belop: 10 });
sjekk('brukt opp: 409 ikke_gyldig', r.status === 409 && r.json.feil === 'ikke_gyldig', r);
r = await losInn({ order_id: 99999999, kode: 'UTEN-DATO-0000-0000', belop: 10 });
sjekk('ukjent ordre: 404', r.status === 404, r);
for (const belop of [0, -5, 'abc', null]) {
  r = await losInn({ order_id: o.id, kode: 'UTEN-DATO-0000-0000', belop });
  sjekk(`ugyldig beløp (${JSON.stringify(belop)}): 400`, r.status === 400 && r.json.feil === 'ugyldig', r);
}
let o2 = await ordre({ varer: [{ id: ids.vare, antall: 5 }] });
r = await losInn({ order_id: o2.id, kode: 'UTEN-DATO-0000-0000', belop: 300 });
sjekk('mer enn saldoen: 409 for_mye', r.status === 409 && r.json.feil === 'for_mye', r);
await sett({ amendo_test_gc_last: '1' });
r = await losInn({ order_id: o2.id, kode: 'UTEN-DATO-0000-0000', belop: 100 });
sjekk('kortet er låst av en annen bestilling: 409 opptatt', r.status === 409 && r.json.feil === 'opptatt', r);
await sett({ amendo_test_gc_last: null });
ol = await ordreLes(o2.id);
st = await tilstand();
sjekk('… ingenting trukket og ordren urørt', st.gc['6'].remaining === 250.5 && ol.gavekort.length === 0 && ol.total === o2.total, [st.gc['6'], ol]);

// Bremsen teller hvert forsøk med koden (fem per vindu); nullstill før de neste.
await sett({ brems_nullstill: ALLE_KODER });
r = await losInn({ order_id: o2.id, kode: 'UTEN-DATO-0000-0000', belop: 250.5 });
sjekk('hele saldoen: trukket 250.5, saldo 0', r.json.ok === true && r.json.trukket === 250.5 && r.json.saldo === 0 && r.json.ordre_total === Math.round((o2.total - 250.5) * 100) / 100, r.json);
r = await sjekkKort('UTEN-DATO-0000-0000');
sjekk('… brukt opp: sjekk sier som ukjent', JSON.stringify(r.json) === fasit, r.json);
await status({ id: o2.id, betal: true });
st = await tilstand();
sjekk('betalt etterpå: ikke trukket på nytt', st.gc['6'].remaining === 0 && st.aktivitet.filter(a => a.kort === 6).length === 1, st.aktivitet);

console.log('\nGavekort: tilbakeføring');
await status({ id: o.id, status: 'cancelled' });
st = await tilstand();
sjekk('kansellert: 200 tilbake på kortet (pluginens maybe_credit_giftcards)', st.gc['1'].remaining === 500 && st.aktivitet.some(a => a.type === 'refunded' && a.kort === 1 && a.belop === 200 && a.ordre === o.id), [st.gc['1'], st.aktivitet]);
await status({ id: o.id, status: 'cancelled' });
sjekk('… bare én gang', (await tilstand()).gc['1'].remaining === 500);
r = await losInn({ order_id: o.id, kode: 'AAAA-BBBB-CCCC-DDDD', belop: 10 });
sjekk('kansellert ordre kan ikke få gavekort: 409 ikke_ubetalt', r.status === 409 && r.json.feil === 'ikke_ubetalt', r);
let o3 = await ordre({ varer: [{ id: ids.vare }] });
await losInn({ order_id: o3.id, kode: 'AAAA-BBBB-CCCC-DDDD', belop: 60 });
await status({ id: o3.id, status: 'failed' });
sjekk('feilet betaling: tilbake på kortet', (await tilstand()).gc['1'].remaining === 500);

console.log('\nGavekort: kjøp');
await sett({ brems_nullstill: ALLE_KODER });
r = await get('/amendo-test/v1/v1423-rest-ordre', { line_items: [
  { product_id: ids.gavekort, quantity: 1, meta_data: [
    { key: 'wc_gc_giftcard_to', value: 'mottaker@example.com' }, { key: 'wc_gc_giftcard_from', value: 'Kari' },
    { key: 'wc_gc_giftcard_message', value: 'Gratulerer!' }, { key: 'wc_gc_giftcard_delivery', value: '1798761600' },
    { key: 'wc_gc_giftcard_amount', value: '5000' }, { key: 'wc_gc_giftcard_code', value: 'HACK-0000-0000-0000' }, { key: 'wc_gc_giftcards', value: ['1'] },
  ] },
  { product_id: ids.vare, quantity: 1, meta_data: [{ key: 'wc_gc_giftcard_amount', value: '5000' }] },
] });
const [gkLinje, vareLinje] = r.linjer || [];
sjekk('REST-ordre laget', r.status === 201 && r.linjer?.length === 2, r);
sjekk('wc_gc_giftcard_amount = varens pris (500), ikke det som ble sendt (5000)', Number(gkLinje?.meta.wc_gc_giftcard_amount) === 500, gkLinje?.meta);
sjekk('mottaker, avsender, hilsen og leveringsdato står som sendt', gkLinje?.meta.wc_gc_giftcard_to === 'mottaker@example.com' && gkLinje?.meta.wc_gc_giftcard_from === 'Kari' && gkLinje?.meta.wc_gc_giftcard_message === 'Gratulerer!' && gkLinje?.meta.wc_gc_giftcard_delivery === '1798761600', gkLinje?.meta);
sjekk('pluginens egne nøkler (kode, kort) fjernet', !('wc_gc_giftcard_code' in (gkLinje?.meta || {})) && !('wc_gc_giftcards' in (gkLinje?.meta || {})), gkLinje?.meta);
sjekk('vanlige varer røres ikke', vareLinje?.meta.wc_gc_giftcard_amount === '5000', vareLinje?.meta);

// Rydd
await sett({ amendo_moduler: null, amendo_avdelinger: null, amendo_wolt_utsalg: null, amendo_test_abbdw: null, amendo_test_wolt: null, amendo_test_wolt_kall: null, amendo_test_wcgc: null, amendo_test_gc: null, amendo_test_gc_aktivitet: null, amendo_test_gk_ip: null, amendo_kasse_secret: null, timezone_string: null, brems_nullstill: ALLE_KODER });
ferdig();
