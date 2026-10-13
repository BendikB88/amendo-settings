// /adyen-metoder, /adyen-betal og /adyen-detaljer (1.4.11) — kortbetaling uten
// sesjon — mot stubber av Amendo Gateway (mu/betaling-stub.php).
import { sjekk, ferdig, get, B } from '../lib/felles.mjs';

const SECRET = 'kasse-hemmelig-1411';
const post = async (rute, body, secret = SECRET) => {
  const headers = { 'content-type': 'application/json' };
  if (secret !== null) headers['X-Amendo-Secret'] = secret;
  const r = await fetch(B + '/amendo-settings/v1/' + rute, { method: 'POST', headers, body: JSON.stringify(body) });
  const tekst = await r.text();
  let json = null; try { json = JSON.parse(tekst); } catch {}
  return { status: r.status, json, tekst, type: r.headers.get('content-type') || '' };
};
const opt = o => get('/amendo-test/v1/opt', o);
const tilstand = () => get('/amendo-test/v1/betaling-tilstand');
const ordre = (body = {}) => get('/amendo-test/v1/kortordre', { ...body, _: Date.now() });
// ⚠ `&id=`, ikke `?id=`: B slutter på `/?rest_route=`, så et nytt `?` blir en
// del av rutenavnet, og WordPress svarer rest_no_route.
const status = async id => (await fetch(`${B}/amendo-test/v1/kortordre-status&id=${id}`)).json();
const erJson = r => r.json !== null && r.type.includes('application/json');
const kort = { type: 'scheme', encryptedCardNumber: 'adyenjs_0_1_25$KORT', encryptedExpiryMonth: 'adyenjs$MND', encryptedExpiryYear: 'adyenjs$AAR', encryptedSecurityCode: 'adyenjs$CVC' };
const nettleser = { acceptHeader: '*/*', colorDepth: 24, language: 'nb-NO', javaEnabled: false, screenHeight: 900, screenWidth: 1440, userAgent: 'Test', timeZoneOffset: -120 };
const betal = (id, ekstra = {}) => ({ ordre_id: id, paymentMethod: kort, browserInfo: nettleser, origin: 'https://butikk.test', returnUrl: `https://butikk.test/kasse/takk?ordre=${id}`, shopperIP: '203.0.113.5', ...ekstra });

await get('/amendo-test/v1/setup');
await opt({
  amendo_kasse_secret: null, stub_betaling_av: null, stub_adyen_live: '0', stub_kort_id: 'bindestrek',
  'woocommerce_amendo-adyen-card_settings': { client_key: 'test_KORT', cc_caption: 'EMMK NETTBUTIKK' },
  stub_kort_svar: 'authorised', stub_detaljer_svar: 'authorised', stub_provisjon: 0, stub_balance_account: '',
});

console.log('Tilgang (fail-closed)');
let r = await post('adyen-metoder', {});
sjekk('uten satt secret: 503 JSON', r.status === 503 && erJson(r), r);
await opt({ amendo_kasse_secret: SECRET });
for (const rute of ['adyen-metoder', 'adyen-betal', 'adyen-detaljer']) {
  r = await post(rute, {}, null);
  sjekk(`${rute} uten header: 403 JSON`, r.status === 403 && erJson(r), r.status);
  r = await post(rute, {}, 'feil');
  sjekk(`${rute} feil secret: 403`, r.status === 403, r.status);
}

console.log('\nadyen-metoder');
await opt({ stub_metoder_kall: 0 });
r = await post('adyen-metoder', { countryCode: 'NO', currency: 'NOK' });
sjekk('200', r.status === 200, r.json);
sjekk('bare kort (scheme), ikke Vipps', JSON.stringify(r.json?.paymentMethods?.paymentMethods?.map(m => m.type)) === '["scheme"]', r.json?.paymentMethods);
sjekk('clientKey og environment', r.json?.clientKey === 'test_KORT' && r.json?.environment === 'test', r.json);
let t = await tilstand();
sjekk('merchantAccount fra kortgatewayen', t.adyen_kall?.[2]?.merchantAccount === 'AmendoECOM_STUB', t.adyen_kall);
await opt({ stub_adyen_kall: null });
r = await post('adyen-metoder', { countryCode: 'NO', currency: 'NOK' });
t = await tilstand();
sjekk('andre kall fra cache: 200 uten nytt Adyen-kall', r.status === 200 && t.adyen_kall === null, t.adyen_kall);
r = await post('adyen-metoder', { countryCode: 'NORGE' });
sjekk('ugyldig land → 400', r.status === 400, r.json);
await opt({ stub_adyen_live: '1' });
r = await post('adyen-metoder', { countryCode: 'SE' });
sjekk('test-nøkkel i live-modus → 500 med melding', r.status === 500 && /live-modus/.test(r.json?.error), r.json);
await opt({ stub_adyen_live: '0', stub_betaling_av: '1' });
r = await post('adyen-metoder', { countryCode: 'DK' });
sjekk('uten Amendo Gateway → 503 JSON', r.status === 503 && erJson(r), r);
await opt({ stub_betaling_av: null, stub_kort_svar: 'wp_error' });
r = await post('adyen-metoder', { countryCode: 'FI' });
sjekk('Adyen-feil → 502 JSON', r.status === 502 && erJson(r), r);
await opt({ stub_kort_svar: 'authorised' });

console.log('\nadyen-betal: autorisert');
let { id } = await ordre();
r = await post('adyen-betal', betal(id));
sjekk('200 Authorised', r.status === 200 && r.json?.resultCode === 'Authorised' && r.json?.pspReference === 'PSP_OK', r.json);
t = await tilstand();
const p = t.adyen_kall?.[2] || {};
sjekk('beløpet fra ORDREN, i øre: 23530', p.amount?.value === 23530 && p.amount?.currency === 'NOK', p.amount);
sjekk('reference = <host>/<ordre-id> (samme som sesjonsflyten/webhooken)', p.reference === `shop.test/${id}`, p.reference);
sjekk('3DS native, origin, returnUrl, shopperIP', p.authenticationData?.threeDSRequestData?.nativeThreeDS === 'preferred' && p.origin === 'https://butikk.test' && p.returnUrl?.includes(`ordre=${id}`) && p.shopperIP === '203.0.113.5', p);
sjekk('shopperStatement fra cc_caption', p.shopperStatement === 'EMMK NETTBUTIKK', p.shopperStatement);
sjekk('ingen splits uten provisjon', !('splits' in p), p.splits);
let s = await status(id);
sjekk('ordren er betalt (processing)', s.status === 'processing' && s.transaksjon === 'PSP_OK' && s.psp === 'PSP_OK', s);
sjekk('betalingsmåte amendo-adyen-card', s.metode === 'amendo-adyen-card', s.metode);
r = await post('adyen-betal', betal(id));
sjekk('betalt ordre kan ikke betales igjen → 409', r.status === 409, r.json);

console.log('\nadyen-betal: beløpet kan ikke komme fra nettleseren');
({ id } = await ordre());
r = await post('adyen-betal', betal(id, { amount: { value: 100, currency: 'NOK' }, reference: 'annen/1' }));
t = await tilstand();
sjekk('amount og reference fra kroppen ignoreres', t.adyen_kall?.[2]?.amount?.value === 23530 && t.adyen_kall?.[2]?.reference === `shop.test/${id}`, t.adyen_kall?.[2]);

console.log('\nadyen-betal: validering');
({ id } = await ordre());
r = await post('adyen-betal', betal(id, { paymentMethod: { type: 'vipps' } }));
sjekk('annen type enn scheme → 400', r.status === 400, r.json);
r = await post('adyen-betal', betal(id, { paymentMethod: { type: 'scheme', storedPaymentMethodId: 'X' } }));
sjekk('kort uten krypterte data → 400', r.status === 400, r.json);
r = await post('adyen-betal', betal(id, { paymentMethod: { ...kort, ekstra: 'x' } }));
t = await tilstand();
sjekk('ukjente felter i paymentMethod kastes', !('ekstra' in (t.adyen_kall?.[2]?.paymentMethod || {})), t.adyen_kall?.[2]?.paymentMethod);
({ id } = await ordre());
r = await post('adyen-betal', betal(id, { returnUrl: 'javascript:alert(1)' }));
sjekk('ugyldig returnUrl → 400', r.status === 400, r.json);
r = await post('adyen-betal', betal(id, { origin: 'http://ondsted.test' }));
sjekk('http-origin (ikke localhost) → 400', r.status === 400, r.json);
const uten = await ordre({ headless: false });
r = await post('adyen-betal', betal(uten.id));
sjekk('ordre uten _nextjs_kasse → 404', r.status === 404, r.json);
r = await post('adyen-betal', betal(99999999));
sjekk('ukjent ordre → 404 (samme svar)', r.status === 404 && r.json?.error === 'Fant ikke ordren', r.json);
const avbrutt = await ordre({ status: 'cancelled' });
r = await post('adyen-betal', betal(avbrutt.id));
sjekk('avbrutt ordre → 409', r.status === 409, r.json);

console.log('\nadyen-betal: avvist, 3DS, feil');
await opt({ stub_kort_svar: 'refused' });
({ id } = await ordre());
r = await post('adyen-betal', betal(id));
sjekk('Refused → 200 med resultCode og refusalReason', r.status === 200 && r.json?.resultCode === 'Refused' && r.json?.refusalReason === 'Refused', r.json);
s = await status(id);
sjekk('ordren står fortsatt pending, med notat', s.status === 'pending' && s.notater.some(n => n.includes('Refused')), s);
await opt({ stub_kort_svar: 'authorised' });
r = await post('adyen-betal', betal(id));
sjekk('nytt kort på samme ordre etter avvisning → Authorised', r.json?.resultCode === 'Authorised', r.json);
await opt({ stub_kort_svar: 'challenge' });
({ id } = await ordre());
r = await post('adyen-betal', betal(id));
sjekk('ChallengeShopper → action videre til kassen', r.json?.resultCode === 'ChallengeShopper' && r.json?.action?.type === 'threeDS2', r.json);
sjekk('… ordren er IKKE betalt ennå', (await status(id)).status === 'pending');
const tredsOrdre = id;
await opt({ stub_kort_svar: 'feil_belop' });
({ id } = await ordre());
r = await post('adyen-betal', betal(id));
s = await status(id);
sjekk('Authorised med annet beløp → IKKE markert betalt, notat', s.status === 'pending' && s.notater.some(n => n.includes('avviker')), s);
for (const [svar, navn] of [['wp_error', 'WP_Error fra gatewayen'], ['kaster', 'unntak fra gatewayen']]) {
  await opt({ stub_kort_svar: svar });
  ({ id } = await ordre());
  r = await post('adyen-betal', betal(id));
  sjekk(`${navn} → ${svar === 'kaster' ? 500 : 502} JSON`, r.status === (svar === 'kaster' ? 500 : 502) && erJson(r) && !!r.json?.error, r);
}
await opt({ stub_kort_svar: 'authorised' });

console.log('\nSplitt (samme regel som gatewayens egen /payments)');
await opt({ stub_provisjon: 2.49, stub_balance_account: 'BA_STUB' });
({ id } = await ordre());
await post('adyen-betal', betal(id));
t = await tilstand();
const sp = t.adyen_kall?.[2]?.splits || [];
sjekk('to splitter som summerer til 23530', sp.length === 2 && sp[0].amount.value + sp[1].amount.value === 23530, sp);
sjekk('provisjon 2,49 % = 586 øre, butikk på BalanceAccount', sp[1].type === 'Commission' && sp[1].amount.value === 586 && sp[0].type === 'BalanceAccount' && sp[0].account === 'BA_STUB', sp);
await opt({ stub_provisjon: 0, stub_balance_account: '' });

console.log('\nadyen-detaljer (3D Secure)');
await opt({ stub_kort_ordre: tredsOrdre, stub_detaljer_svar: 'authorised' });
r = await post('adyen-detaljer', { ordre_id: tredsOrdre, details: { threeDSResult: 'eyJ0cmFuc1N0YXR1cyI6IlkifQ==', ukjent: 'x' } });
sjekk('Authorised', r.status === 200 && r.json?.resultCode === 'Authorised', r.json);
t = await tilstand();
sjekk('bare kjente detaljfelter sendt', JSON.stringify(Object.keys(t.adyen_kall?.[2]?.details || {})) === '["threeDSResult"]', t.adyen_kall?.[2]);
s = await status(tredsOrdre);
sjekk('ordren betalt etter 3DS', s.status === 'processing' && s.transaksjon === 'PSP_DET', s);
r = await post('adyen-detaljer', { ordre_id: tredsOrdre, details: { redirectResult: 'abc' } });
sjekk('betalt ordre: detaljer godtas (webhooken kan ha vært først)', r.status === 200, r.json);
({ id } = await ordre());
await opt({ stub_kort_ordre: id, stub_detaljer_svar: 'feil_ref' });
r = await post('adyen-detaljer', { ordre_id: id, details: { redirectResult: 'abc' } });
s = await status(id);
sjekk('detaljer for en ANNEN ordre → ikke betalt, bekreftet=false', r.json?.bekreftet === false && s.status === 'pending', { r: r.json, s });
await opt({ stub_detaljer_svar: 'refused' });
r = await post('adyen-detaljer', { ordre_id: id, details: { redirectResult: 'abc' } });
sjekk('Refused etter 3DS', r.json?.resultCode === 'Refused' && (await status(id)).status === 'pending', r.json);
r = await post('adyen-detaljer', { ordre_id: id, details: {} });
sjekk('uten detaljer → 400', r.status === 400, r.json);
await opt({ stub_detaljer_svar: 'wp_error' });
r = await post('adyen-detaljer', { ordre_id: id, details: { redirectResult: 'abc' } });
sjekk('Adyen-feil → 502 JSON', r.status === 502 && erJson(r), r);

await opt({ amendo_kasse_secret: null, stub_kort_svar: null, stub_detaljer_svar: null });
ferdig();
