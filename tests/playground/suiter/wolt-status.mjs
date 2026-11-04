// 1.4.30: statusmeldinger fra Wolt Drive (webhook), varsel når Wolt avviser
// eller ikke leverer, kolonnen Wolt i ordrelisten og «Kanseller hos Wolt».
// Ingen ekte kall: Wolt-tillegget og Wolts API er stubber i mu/v1423-test.php,
// e-post fanges i mu/v1429-test.php, testrutene er i mu/v1430-test.php.
// Se includes/moduler/wolt-status.php.
import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { B, REPO, get, sjekk, ferdig } from '../lib/felles.mjs';

const SEKRET = 'wolt-webhook-1430';
const sett = o => get('/amendo-test/v1/v1423-sett', o);
const lagre = post => get('/amendo-test/v1/v1423-lagre', { post });
const side = async () => (await fetch(B + '/amendo-test/v1/v1423-side')).text();
const ordre = body => get('/amendo-test/v1/v1430-ordre', body);
const les = async (id, svar = '') => get('/amendo-test/v1/v1430-les', { id, svar });
const kanseller = body => get('/amendo-test/v1/v1430-kanseller', body);
// Bare Wolt-varslene: WooCommerce sender sin egen «Ny ordre»-e-post når testordren lages.
const tilstand = async () => { const t = await get('/amendo-test/v1/v1430-tilstand'); t.alle_epost = t.epost || []; t.epost = t.alle_epost.filter(e => /Wolt/.test(e.emne || '')); return t; };
const logg = async () => (await get('/amendo-test/v1/v1430-logg')).tekst || '';

const b64u = s => Buffer.from(s).toString('base64url');
const jwt = (innhold, hemmelighet = SEKRET, alg = 'HS256') => {
  const h = b64u(JSON.stringify({ alg, typ: 'JWT' })), p = b64u(JSON.stringify(innhold));
  return `${h}.${p}.${createHmac('sha256', hemmelighet).update(h + '.' + p).digest('base64url')}`;
};
const kl = n => new Date(Date.UTC(2026, 10, 5, 8, n)).toISOString();
const hendelse = (type, details, tid = kl(0)) => ({ type, dispatched_at: tid, details });
const webhook = async (body, raa = false) => {
  const r = await fetch(B + '/amendo-settings/v1/wolt/webhook', { method: 'POST', headers: { 'content-type': raa ? 'text/plain' : 'application/json' }, body: raa ? body : JSON.stringify(body) });
  const tekst = await r.text();
  let json; try { json = JSON.parse(tekst); } catch { json = tekst; }
  return { status: r.status, json };
};
const send = (type, details, tid, hemmelighet) => webhook({ token: jwt(hendelse(type, details, tid), hemmelighet) });
const antallEpost = async () => (await tilstand()).epost.length;

const UTSALG = { env: 'staging', api_key: 'GLOBAL-KEY', outlets: [
  { slug: 'oslo', label: 'Testbakeri Oslo', venue_id: 'VENUE-OSLO', active: 1, merchant_key: 'MK-OSLO' },
  { slug: 'nord', label: 'Testbakeri Nord', venue_id: 'VENUE-NORD', active: 1 },
] };
const REGLER = {
  Oslo: { utsalg: 'oslo', postnumre: '0150-0199', poststeder: 'Oslo', varsel: '' },
  Nord: { utsalg: 'nord', postnumre: '2050', poststeder: 'Jessheim', varsel: 'nord@butikk.example, nord2@butikk.example' },
};
const WOLT = { _ab_delivery_method: 'wolt', _ab_wolt_status: 'confirmed', _ab_delivery_date: '2026-11-05', _ab_delivery_window: '10:00-10:15' };

await sett({ amendo_test_stille: '1' });
await get('/amendo-test/v1/setup');
await sett({ timezone_string: 'Europe/Oslo', amendo_moduler: null, amendo_test_abbdw: null, amendo_wolt_webhook_secret: null, amendo_wolt_varsel_epost: null, amendo_test_epost_fang: '1', amendo_test_epost: null, amendo_test_epost_feil: null, amendo_test_wolt: null, amendo_test_wolt_kall: null });

// ── Av ──────────────────────────────────────────────────────────────────────
console.log('Modulen av');
let r = await send('order.received', { wolt_order_reference_id: 'X' });
sjekk('webhooken: 404 når modulen er av', r.status === 404, r.status);
let t = await tilstand();
sjekk('ingen Wolt-secret blant hemmelighetene, ingen funksjoner', !t.hemmeligheter.includes('wolt_webhook') && t.finnes === false, t.hemmeligheter);

await sett({ amendo_moduler: ['wolt'], amendo_test_abbdw: UTSALG, amendo_avdelinger: [{ navn: 'Oslo' }, { navn: 'Nord' }], amendo_wolt_utsalg: REGLER });

// ── Secreten ────────────────────────────────────────────────────────────────
console.log('\nSecreten');
r = await send('order.received', { wolt_order_reference_id: 'X' });
sjekk('ingen secret: 503 (fail-closed), også med en signert melding', r.status === 503 && r.json.feil === 'ikke_satt_opp', r);
await lagre({ amendo_ny_wolt_webhook_secret: '1' });
t = await tilstand();
sjekk('«Generer ny» lager 64 hex-tegn', /^[0-9a-f]{64}$/.test(t.secret), t.secret.length);
let html = await side();
sjekk('vises én gang i fanen Wolt', html.includes('data-hemmelighet="wolt_webhook"') && html.includes(t.secret), html.length);
html = await side();
sjekk('neste gang bare «Satt, 64 tegn», aldri verdien', html.includes('<strong>Satt</strong>, 64 tegn') && !html.includes(t.secret));
sjekk('fanen viser webhook-URL-en og «Varsel til» per avdeling', html.includes('wolt/webhook</code>') && html.includes('name="wolt_utsalg[rader][1][varsel]" value="nord@butikk.example, nord2@butikk.example"') && html.includes('name="wolt_utsalg[varsel]"'));
await sett({ amendo_wolt_webhook_secret: SEKRET });

console.log('\nSignaturen');
r = await webhook({});
sjekk('uten token: 400', r.status === 400, r);
r = await send('order.received', { wolt_order_reference_id: 'X' }, kl(0), 'en-annen-secret');
sjekk('signert med en annen secret: 401', r.status === 401 && r.json.feil === 'signatur', r);
const [h0, , s0] = jwt(hendelse('order.received', { wolt_order_reference_id: 'X' })).split('.');
r = await webhook({ token: `${h0}.${b64u(JSON.stringify(hendelse('order.rejected', { wolt_order_reference_id: 'X' })))}.${s0}` });
sjekk('endret innhold med gammel signatur: 401', r.status === 401, r);
const ingen = `${b64u('{"alg":"none","typ":"JWT"}')}.${b64u(JSON.stringify(hendelse('order.rejected', {})))}.`;
r = await webhook({ token: ingen });
sjekk('alg «none»: avvist', r.status === 400 || r.status === 401, r);
r = await webhook(jwt(hendelse('order.received', { wolt_order_reference_id: 'UKJENT' })), true);
sjekk('rå JWT som body godtas også; ukjent ordre = 200 funnet:false', r.status === 200 && r.json.funnet === false, r);

// ── Statusendringer ─────────────────────────────────────────────────────────
console.log('\nStatusendringer');
const A = await ordre({ fornavn: 'Kari', etternavn: 'Kunde', telefon: '+4799990001', meta: { ...WOLT, _ab_pickup_store: 'oslo' } });
r = await send('order.received', { id: 'EV-1', wolt_order_reference_id: 'REF-A', merchant_order_reference_id: 'woo-' + A.id }, kl(1));
let o = await les(A.id);
sjekk('funnet på merchant_order_reference_id (woo-<id>), referansen lagret', r.json.status === 'received' && o.meta._ab_wolt_order_ref === 'REF-A', [r, o.meta]);
sjekk('_ab_wolt_status oppdatert og ordrenotat', o.meta._ab_wolt_status === 'received' && o.notater.some(n => n.includes('Mottatt av Wolt (received)')), o.notater);
const notaterFør = o.notater.length;
r = await send('order.dropoff_eta_updated', { wolt_order_reference_id: 'REF-A', dropoff: { eta: { min: '2026-11-05T09:00:00Z', max: '2026-11-05T09:10:00Z' } } }, kl(2));
o = await les(A.id);
sjekk('ETA: tiden lagres, statusen og notatene står', o.meta._amendo_wolt_eta_levering === '2026-11-05 09:10:00' && o.meta._ab_wolt_status === 'received' && o.notater.length === notaterFør, [o.meta, o.notater.length]);
r = await send('order.picked_up', { wolt_order_reference_id: 'REF-A' }, kl(3));
r = await send('order.delivered', { wolt_order_reference_id: 'REF-A' }, kl(4));
o = await les(A.id);
sjekk('funnet på wolt_order_reference_id; hentet → levert', o.meta._ab_wolt_status === 'delivered' && o.notater.some(n => n.includes('(picked_up)')) && o.notater.some(n => n.includes('Levert (delivered)')), o.notater);
r = await send('order.dropoff_started', { wolt_order_reference_id: 'REF-A' }, kl(5));
o = await les(A.id);
sjekk('sluttstatus overskrives ikke av en senere ikke-endelig melding', o.meta._ab_wolt_status === 'delivered' && o.notater.at(-1).includes('ikke endret'), o.notater.at(-1));
t = await tilstand();
sjekk('levert: ingen e-post', t.epost.length === 0, t.epost.map(e => e.emne));

// ── Avvist: varselet ────────────────────────────────────────────────────────
console.log('\nAvvist av Wolt');
const Bo = await ordre({ fornavn: 'Ola', etternavn: 'Nordmann', firma: 'Firma AS', telefon: '+4799990002', meta: { ...WOLT, _ab_wolt_order_ref: 'REF-B', _ab_pickup_store: 'nord', _ab_employee_name: 'Per Ansatt', _ab_employee_phone: '+4799990003' } });
r = await send('order.rejected', { id: 'EV-2', wolt_order_reference_id: 'REF-B', purchase_rejected_reason: 'No couriers available' }, kl(10));
o = await les(Bo.id);
t = await tilstand();
const e = t.epost.at(-1) || { til: [], emne: '', html: '' };
sjekk('status rejected, årsaken lagret, ordrestatusen urørt', r.json.varslet === true && o.meta._ab_wolt_status === 'rejected' && o.meta._amendo_wolt_arsak === 'No couriers available' && o.status === 'processing', [r, o.meta, o.status]);
sjekk('notat med status og årsak, og hvem som fikk varsel', o.notater.some(n => n.includes('(rejected). Årsak: No couriers available') && n.includes('nord@butikk.example')), o.notater);
sjekk('e-post til avdelingens adresser (begge)', t.epost.length === 1 && e.til.join() === 'nord@butikk.example,nord2@butikk.example', e.til);
sjekk('emnet har ordrenummeret', e.emne.includes('#' + Bo.nr) && /avvist/i.test(e.emne), e.emne);
sjekk('innholdet: ordre, kunde, telefon, mottaker, leveringstid, utsalg, årsak', ['Ordre: #' + Bo.nr, 'Kunde: Ola Nordmann (Firma AS)', 'Telefon: +4799990002', 'Mottaker: Per Ansatt +4799990003', 'Levering: 2026-11-05 10:00-10:15', 'Utsalg: Testbakeri Nord', 'Årsak: No couriers available'].every(s => e.html.includes(s)), e.html);
sjekk('lenke til ordren', e.html.includes('Åpne ordren: ' + Bo.url), [e.html, Bo.url]);
sjekk('ordrelisten: kolonnen Wolt etter Status, rødt merke med årsaken', o.kolonner.join() === 'cb,order_number,order_status,amendo_wolt,order_total' && o.merke.includes('status-failed') && o.merke.includes('Avvist av Wolt') && o.merke.includes('title="No couriers available"'), [o.kolonner, o.merke]);
sjekk('ordresiden: årsaken, og ingen kanselleringsknapp etter avvisning', o.boks.includes('Årsak: No couriers available') && !o.boks.includes('Kanseller hos Wolt'), o.boks);
r = await send('order.rejected', { id: 'EV-2', wolt_order_reference_id: 'REF-B', purchase_rejected_reason: 'No couriers available' }, kl(10));
o = await les(Bo.id);
sjekk('samme melding igjen (Wolt prøver på nytt): ingenting skjer', r.json.duplikat === true && (await antallEpost()) === 1 && o.notater.filter(n => n.includes('(rejected)')).length === 1, r);
r = await send('order.picked_up', { wolt_order_reference_id: 'REF-B' }, kl(9));
o = await les(Bo.id);
sjekk('eldre melding etter avvisningen: statusen står', o.meta._ab_wolt_status === 'rejected', o.meta);

console.log('\nMottakere');
const C = await ordre({ fornavn: 'Kari', telefon: '+4799990004', meta: { ...WOLT, _ab_wolt_delivery_id: 'DEL-C', _ab_pickup_store: 'oslo' } });
r = await send('order.not_delivered', { id: 'DEL-C', not_delivered_details: { outcome: 'returned', reason: 'Mottakeren svarte ikke' } }, kl(11));
t = await tilstand();
o = await les(C.id);
sjekk('funnet på details.id mot _ab_wolt_delivery_id; ikke levert = varsel', o.meta._ab_wolt_status === 'not_delivered' && o.meta._amendo_wolt_arsak === 'returned – Mottakeren svarte ikke', o.meta);
sjekk('avdelingen uten egen adresse og ingen felles: admin-e-posten', t.epost.at(-1)?.til.join() === t.admin && /leverte ikke/i.test(t.epost.at(-1)?.emne), t.epost.at(-1));
lag: {
  const l = await lagre({ amendo_endret: { wolt: '1' }, wolt_utsalg: { finnes: '1', varsel: 'felles@butikk.example, ikke-en-adresse', rader: [{ avdeling: 'Oslo', utsalg: 'oslo', postnumre: '0150-0199', poststeder: 'Oslo', varsel: '' }, { avdeling: 'Nord', utsalg: 'nord', postnumre: '2050', poststeder: 'Jessheim', varsel: 'nord@butikk.example; tull' }] } });
  t = await tilstand();
  sjekk('lagring: felles adresse og per avdeling, ugyldige fjernes', t.felles === 'felles@butikk.example' && l.amendo_wolt_utsalg?.Nord?.varsel === 'nord@butikk.example', [t.felles, l.amendo_wolt_utsalg]);
}
const C2 = await ordre({ meta: { ...WOLT, _ab_wolt_order_ref: 'REF-C2', _ab_pickup_store: 'oslo' } });
await send('order.rejected', { wolt_order_reference_id: 'REF-C2', purchase_rejected_reason: 'GENERIC_VENUE_REQUESTED' }, kl(12));
t = await tilstand();
sjekk('avdelingen uten egen adresse: den felles', t.epost.at(-1)?.til.join() === 'felles@butikk.example', t.epost.at(-1)?.til);
sjekk('leveringstiden er dato + luke fra ordren',t.epost.at(-1)?.html.includes('Levering: 2026-11-05 10:00-10:15'));
await sett({ amendo_test_epost_feil: '1' });
const C3 = await ordre({ meta: { ...WOLT, _ab_wolt_order_ref: 'REF-C3', _ab_pickup_store: 'nord' } });
r = await send('order.rejected', { wolt_order_reference_id: 'REF-C3' }, kl(13));
o = await les(C3.id);
sjekk('e-posten feiler: notatet sier det, statusen er likevel oppdatert', o.meta._ab_wolt_status === 'rejected' && o.notater.some(n => n.includes('Varselet kunne ikke sendes')), o.notater);
await sett({ amendo_test_epost_feil: null });

console.log('\nFeil ordre');
const V = await ordre({ meta: { _ab_delivery_method: 'pickup' } });
r = await send('order.rejected', { wolt_order_reference_id: 'REF-V', merchant_order_reference_id: 'woo-' + V.id }, kl(14));
o = await les(V.id);
sjekk('merchant-referanse til en ordre som ikke er Wolt: ikke rørt', r.json.funnet === false && !o.notater.some(n => n.includes('Wolt')) && o.meta._ab_wolt_status === '' && o.meta._ab_wolt_order_ref === '' && o.merke === '–', [r, o]);
const W = await ordre({ meta: { ...WOLT, _ab_wolt_order_ref: 'REF-W-NY' } });
r = await send('order.rejected', { wolt_order_reference_id: 'REF-W-GAMMEL', merchant_order_reference_id: 'woo-' + W.id }, kl(15));
o = await les(W.id);
sjekk('merchant-referanse, men ordren har en annen Wolt-referanse (ny levering): ikke rørt', r.json.funnet === false && o.meta._ab_wolt_status === 'confirmed', [r, o.meta]);
const X = await ordre({ meta: { ...WOLT, _ab_wolt_delivery_id: 'DEL-X-NY' } });
r = await send('order.rejected', { id: 'DEL-X-GAMMEL', wolt_order_reference_id: 'REF-X', merchant_order_reference_id: 'woo-' + X.id }, kl(15));
o = await les(X.id);
sjekk('merchant-referanse, men ordren har en annen _ab_wolt_delivery_id: ikke rørt', r.json.funnet === false && o.meta._ab_wolt_status === 'confirmed' && o.meta._ab_wolt_order_ref === '', [r, o.meta]);
const Y = await ordre({ meta: { ...WOLT, _ab_delivery_method: 'wolt_venter' } });
r = await send('order.rejected', { wolt_order_reference_id: 'REF-Y', merchant_order_reference_id: 'woo-' + Y.id }, kl(15));
o = await les(Y.id);
sjekk('merchant-referanse til en ubetalt Wolt-ordre (wolt_venter): ikke rørt', r.json.funnet === false && o.meta._ab_wolt_status === 'confirmed', [r, o.meta]);
r = await send('order.location_updated', { tracking_id: 'x', courier_location: { lat: 1, lon: 2 } }, kl(16));
sjekk('posisjonsmeldinger ignoreres', r.status === 200 && r.json.ignorert === true, r);

// ── Kanseller ───────────────────────────────────────────────────────────────
console.log('\nKanseller hos Wolt');
const D = await ordre({ meta: { ...WOLT, _ab_wolt_order_ref: 'REF-D', _ab_pickup_store: 'oslo' } });
o = await les(D.id);
sjekk('knappen på ordresiden når levering er bestilt', o.boks.includes('Kanseller hos Wolt') && o.boks.includes('action=amendo_wolt_kanseller') && o.boks.includes('_wpnonce='), o.boks);
r = await kanseller({ id: D.id, rolle: 'customer' });
sjekk('kunde: stoppet', r.stoppet === true, r);
r = await kanseller({ id: D.id, nonce: false });
sjekk('feil nonce: stoppet', r.stoppet === true, r);
await sett({ amendo_test_wolt: { kode: 200, svar: { status: 'REJECTED' } }, amendo_test_wolt_kall: null });
r = await kanseller({ id: D.id, arsak: 'Kunden ringte' });
t = await tilstand();
o = await les(D.id, 'ok');
const k = t.kall.at(-1) || {};
sjekk('PATCH /order/{ref}/status/cancel med årsaken og utsalgets nøkkel', t.kall.length === 1 && k.metode === 'PATCH' && k.url === 'https://daas-public-api.development.dev.woltapi.com/order/REF-D/status/cancel' && k.body?.reason === 'Kunden ringte' && k.auth === 'Bearer MK-OSLO', k);
sjekk('tilbake til ordren med svaret', /amendo_wolt=ok/.test(r.til || '') && o.boks_svar.includes('Leveringen er kansellert hos Wolt'), r);
sjekk('status rejected, hvem, notat — ordrestatusen urørt', o.meta._ab_wolt_status === 'rejected' && o.meta._amendo_wolt_kansellert_av !== '' && o.notater.some(n => n.includes('kansellert av') && n.includes('Kunden ringte')) && o.status === 'processing', [o.meta, o.status]);
const før = await antallEpost();
r = await send('order.rejected', { wolt_order_reference_id: 'REF-D', purchase_rejected_reason: 'Kunden ringte' }, kl(20));
sjekk('Wolts bekreftelse på vår egen kansellering: notat, men ingen e-post', r.json.varslet === false && (await antallEpost()) === før, r);
r = await kanseller({ id: D.id });
sjekk('allerede avsluttet: ingen nye kall', /amendo_wolt=avsluttet/.test(r.til || '') && (await tilstand()).kall.length === 1, r);
const E = await ordre({ meta: { ...WOLT, _ab_wolt_order_ref: 'REF-E', _ab_pickup_store: 'nord' } });
await sett({ amendo_test_wolt: { kode: 400, svar: { error_code: 'INVALID_ORDER_STATE_TRANSITION', details: 'Order is not in a cancellable state.' } } });
r = await kanseller({ id: E.id });
o = await les(E.id);
t = await tilstand();
sjekk('Wolt sier nei: notat med Wolts svar, status uendret; global nøkkel når utsalget ikke har egen', /amendo_wolt=wolt/.test(r.til || '') && o.meta._ab_wolt_status === 'confirmed' && o.notater.some(n => n.includes('not in a cancellable state')) && t.kall.at(-1).auth === 'Bearer GLOBAL-KEY', [r, o.meta, t.kall.at(-1)]);
await sett({ amendo_test_wolt: { nede: true } });
r = await kanseller({ id: E.id });
sjekk('Wolt nede: «kontakt»', /amendo_wolt=kontakt/.test(r.til || ''), r);
const F = await ordre({ meta: { ...WOLT, _ab_wolt_delivery_id: 'DEL-F' } });
o = await les(F.id);
r = await kanseller({ id: F.id });
sjekk('uten Wolt-referanse: ingen knapp, og ruten svarer mangler_ref', !o.boks.includes('Kanseller hos Wolt') && /amendo_wolt=mangler_ref/.test(r.til || ''), [o.boks, r]);
await sett({ amendo_test_wolt: null });

// ── Loggen ──────────────────────────────────────────────────────────────────
const l = await logg();
sjekk('loggen: hendelsene, men ingen kundedata', /Wolt-webhook: rejected, varsel sendt/.test(l) && /feil signatur/.test(l) && !/Nordmann|Kari|\+4799|@butikk\.example|@example\.com|No couriers/.test(l), l.slice(-800));
const kode = readFileSync(resolve(REPO, 'includes/moduler/wolt-status.php'), 'utf8');
sjekk('ingen butikknavn i koden', !/Aanerud|Garçon|Garcon|emmk|Jessheim/i.test(kode));

// ── Av igjen ────────────────────────────────────────────────────────────────
console.log('\nModulen av igjen');
await sett({ amendo_moduler: null });
r = await send('order.rejected', { wolt_order_reference_id: 'REF-B' }, kl(30));
t = await tilstand();
sjekk('webhooken 404, ingen hemmelighet, ingen funksjoner', r.status === 404 && !t.hemmeligheter.includes('wolt_webhook') && t.finnes === false, [r.status, t.hemmeligheter]);
r = await kanseller({ id: D.id });
sjekk('kanselleringen finnes ikke', r.finnes === false, r);

await sett({ amendo_test_abbdw: null, amendo_avdelinger: null, amendo_wolt_utsalg: null, amendo_wolt_webhook_secret: null, amendo_wolt_varsel_epost: null, amendo_test_epost_fang: null, amendo_test_epost: null, amendo_test_wolt: null, amendo_test_wolt_kall: null, timezone_string: null });
ferdig();
