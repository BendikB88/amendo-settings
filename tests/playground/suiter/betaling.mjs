// /adyen-session og /gateway-redirect mot stubber av Amendo Gateway og Vipps (mu/betaling-stub.php).
import { sjekk, ferdig, get, B } from '../lib/felles.mjs';

const post = async (rute, body, headers = {}) => {
  const r = await fetch(B + '/amendo-settings/v1/' + rute, { method: 'POST', headers: { 'content-type': 'application/json', ...headers }, body: JSON.stringify(body) });
  const tekst = await r.text();
  let json = null; try { json = JSON.parse(tekst); } catch {}
  return { status: r.status, json, tekst, type: r.headers.get('content-type') || '' };
};
const opt = o => get('/amendo-test/v1/opt', o);
const tilstand = () => get('/amendo-test/v1/betaling-tilstand');
const nullstill = () => opt({ stub_betaling_logg: null, stub_vipps_saa: null, stub_adyen_kall: null });
const erJson = r => r.json !== null && r.type.includes('application/json');
const kort = { amount: 499, currency: 'NOK', countryCode: 'NO', reference: 'ordre-1' };

await get('/amendo-test/v1/setup');
await opt({
  amendo_kasse_secret: null,
  stub_betaling_av: null, stub_adyen_svar: 'ok', stub_adyen_live: '0', stub_kort_id: 'bindestrek',
  'woocommerce_amendo-adyen-card_settings': { client_key: 'test_BINDESTREK' },
  woocommerce_amendo_adyen_card_settings: { client_key: 'test_UNDERSTREK' },
});

console.log('adyen-session: client key');
let r = await post('adyen-session', kort);
sjekk('200 med sesjon', r.status === 200 && r.json?.sessionId === 'CS_STUB_1' && r.json?.sessionData === 'Ab02b4c0!stub', r);
sjekk('client key fra amendo-adyen-card (bindestrek)', r.json?.clientKey === 'test_BINDESTREK', r.json);
sjekk('environment test', r.json?.environment === 'test', r.json);
sjekk('samme nøkler som før', JSON.stringify(Object.keys(r.json || {})) === '["sessionId","sessionData","clientKey","environment"]', r.json);
await opt({ stub_kort_id: 'understrek' });
r = await post('adyen-session', kort);
sjekk('reserve: amendo_adyen_card (understrek)', r.status === 200 && r.json?.clientKey === 'test_UNDERSTREK', r.json);
await opt({ stub_kort_id: 'begge' });
r = await post('adyen-session', kort);
sjekk('begge finnes: bindestrek foretrekkes', r.json?.clientKey === 'test_BINDESTREK', r.json);
await opt({ stub_kort_id: 'bindestrek', 'woocommerce_amendo-adyen-card_settings': { client_key: '' } });
r = await post('adyen-session', kort);
sjekk('mangler client key: 500 JSON', r.status === 500 && erJson(r) && /client key/.test(r.json?.error), r);
await opt({ 'woocommerce_amendo-adyen-card_settings': { client_key: 'test_BINDESTREK' } });

console.log('\nadyen-session: test/live');
await nullstill();
await opt({ 'woocommerce_amendo-adyen-card_settings': { client_key: 'live_NOKKEL' }, stub_adyen_live: '0' });
r = await post('adyen-session', kort);
sjekk('live-nøkkel i testmodus: 500 med melding', r.status === 500 && r.json?.error === 'Amendo Gateway står i testmodus, men nøklene er live', r);
let t = await tilstand();
sjekk('… logget', t.logg.length === 1 && t.logg[0].includes('testmodus, men nøklene er live'), t.logg);
sjekk('… ingen sesjon opprettet hos Adyen', t.adyen_kall === null, t.adyen_kall);
await opt({ 'woocommerce_amendo-adyen-card_settings': { client_key: 'test_BINDESTREK' }, stub_adyen_live: '1' });
r = await post('adyen-session', kort);
sjekk('test-nøkkel i live-modus: 500 med melding', r.status === 500 && r.json?.error === 'Amendo Gateway står i live-modus, men nøklene er test', r);
await opt({ 'woocommerce_amendo-adyen-card_settings': { client_key: 'live_NOKKEL' }, stub_adyen_live: '1' });
r = await post('adyen-session', kort);
sjekk('live + live: 200, environment live', r.status === 200 && r.json?.environment === 'live' && r.json?.clientKey === 'live_NOKKEL', r.json);
await opt({ 'woocommerce_amendo-adyen-card_settings': { client_key: 'test_BINDESTREK' }, stub_adyen_live: '0' });

console.log('\nadyen-session: Adyen svarer tomt eller med feil');
for (const [svar, navn] of [['tomt_objekt', 'tomt objekt (var fatal før)'], ['null', 'null'], ['false', 'false'], ['tom_array', 'tom array'], ['kaster', 'unntak fra gatewayen']]) {
  await nullstill();
  await opt({ stub_adyen_svar: svar });
  r = await post('adyen-session', kort);
  sjekk(`${navn}: 502 JSON`, r.status === 502 && erJson(r) && r.json?.error === 'Kunne ikke opprette Adyen-sesjon', [r.status, r.tekst.slice(0, 200)]);
  t = await tilstand();
  sjekk(`${navn}: logget`, t.logg.length === 1, t.logg);
}
await opt({ stub_adyen_svar: 'ok' });

console.log('\nadyen-session: Klarna og validering');
r = await post('adyen-session', { ...kort, payment_method: 'klarna_paynow' });
t = await tilstand();
sjekk('Klarna via api_call: 200', r.status === 200 && r.json?.sessionId === 'CS_KLARNA_1', r.json);
sjekk('Klarna: AmendoPOS merchant account, beløp i øre', t.adyen_kall?.[0] === 'api_call' && t.adyen_kall?.[2]?.merchantAccount === 'AmendoPOS_STUB' && t.adyen_kall?.[2]?.amount?.value === 49900, t.adyen_kall);
await opt({ stub_adyen_svar: 'tom_array' });
r = await post('adyen-session', { ...kort, payment_method: 'klarna_paynow' });
sjekk('Klarna tomt svar: 502 JSON', r.status === 502 && erJson(r), r);
await opt({ stub_adyen_svar: 'ok' });
r = await post('adyen-session', { ...kort, amount: 0 });
sjekk('beløp 0: 400', r.status === 400 && r.json?.error === 'Ugyldig beløp', r);
await opt({ stub_betaling_av: '1' });
r = await post('adyen-session', kort);
sjekk('Amendo Gateway ikke aktiv: 503 JSON', r.status === 503 && r.json?.error === 'Amendo Gateway ikke aktivert', r);
await opt({ stub_betaling_av: null });

console.log('\ngateway-redirect');
const ordre = (await get('/amendo-test/v1/betaling-ordre')).id;
await nullstill();
r = await post('gateway-redirect', { order_id: ordre, gateway_id: 'vipps', return_url: 'https://emmk-frontend.vercel.app/kasse/takk' });
t = await tilstand();
sjekk('Vipps: 200 med redirect', r.status === 200 && r.json?.redirect === 'https://vipps.example/betal?tlf=%2B4799999999', r);
sjekk('Vipps så WC()->session', typeof t.vipps?.sesjon === 'string' && t.vipps.sesjon.length > 0, t.vipps);
sjekk('Vipps så kunden fra ordrens fakturaadresse', t.vipps?.telefon === '+4799999999' && t.vipps?.by === 'Oslo', t.vipps);
sjekk('… og leveringsadressen', t.vipps?.lev_by === 'Bergen', t.vipps);
sjekk('return_url overstyrt til frontenden med order_id', t.vipps?.retur === `https://emmk-frontend.vercel.app/kasse/takk?order_id=${ordre}`, t.vipps?.retur);

await nullstill();
r = await post('gateway-redirect', { order_id: ordre, gateway_id: 'krasj' });
t = await tilstand();
sjekk('fatal feil i gatewayen (TypeError): 500 JSON', r.status === 500 && erJson(r) && r.json?.error === 'Betalingen kunne ikke startes', [r.status, r.tekst.slice(0, 200)]);
sjekk('… detaljene logget, ikke i svaret', t.logg.length === 1 && t.logg[0].includes('TypeError') && !r.tekst.includes('TypeError'), t.logg);
r = await post('gateway-redirect', { order_id: ordre, gateway_id: 'avvist' });
sjekk('gateway uten redirect: 502 JSON', r.status === 502 && r.json?.error === 'Gateway returnerte ikke redirect', r);
r = await post('gateway-redirect', { order_id: 999999, gateway_id: 'vipps' });
sjekk('ukjent ordre: 404 JSON', r.status === 404 && r.json?.error === 'Fant ikke ordren', r);
r = await post('gateway-redirect', { order_id: ordre, gateway_id: 'finnes-ikke' });
sjekk('ukjent gateway: 404 JSON', r.status === 404 && erJson(r), r);
r = await post('gateway-redirect', {});
sjekk('mangler felter: 400 JSON', r.status === 400 && erJson(r), r);

console.log('\nTilgang (uendret: X-Amendo-Secret når amendo_kasse_secret er satt)');
await opt({ amendo_kasse_secret: 'betal-hemmelig' });
r = await post('gateway-redirect', { order_id: ordre, gateway_id: 'vipps' });
sjekk('uten header: avvist', r.status === 401 || r.status === 403, r.status);
r = await post('gateway-redirect', { order_id: ordre, gateway_id: 'vipps' }, { 'X-Amendo-Secret': 'betal-hemmelig' });
sjekk('med riktig X-Amendo-Secret: 200', r.status === 200, r);
r = await post('adyen-session', kort, { 'X-Amendo-Secret': 'feil' });
sjekk('adyen-session med feil secret: avvist', r.status === 401 || r.status === 403, r.status);
await opt({ amendo_kasse_secret: null });

ferdig();
