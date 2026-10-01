// /frakt: validering av input, og at ingenting lagres (sesjon, cookie, persistent kurv, ekte kurv).
import { sjekk, ferdig, get, frakt, med, klargjorFrakt } from '../lib/felles.mjs';

const ids = await klargjorFrakt();
const foer = await get('/amendo-test/v1/tilstand');

console.log('Validering');
const ugyldig = [
  ['ikke JSON', 'tull'],
  ['varer mangler', { land: 'NO', postnummer: '0150' }],
  ['tom liste', { varer: [], land: 'NO', postnummer: '0150' }],
  ['objekt i stedet for liste', { varer: { a: { product_id: ids.brod, antall: 1 } }, land: 'NO', postnummer: '0150' }],
  ['101 linjer', { varer: Array.from({ length: 101 }, () => ({ product_id: ids.brod, antall: 1 })), land: 'NO', postnummer: '0150' }],
  ['antall 0', { varer: [{ product_id: ids.brod, antall: 0 }], land: 'NO', postnummer: '0150' }],
  ['antall 10000', { varer: [{ product_id: ids.brod, antall: 10000 }], land: 'NO', postnummer: '0150' }],
  ['antall 1.5', { varer: [{ product_id: ids.brod, antall: 1.5 }], land: 'NO', postnummer: '0150' }],
  ['negativ variation_id', { varer: [{ product_id: ids.brod, variation_id: -1, antall: 1 }], land: 'NO', postnummer: '0150' }],
  ['land mangler', { varer: [{ product_id: ids.brod, antall: 1 }], postnummer: '0150' }],
  ['land XX', { varer: [{ product_id: ids.brod, antall: 1 }], land: 'XX', postnummer: '0150' }],
  ['postnummer mangler', { varer: [{ product_id: ids.brod, antall: 1 }], land: 'NO' }],
  ['postnummer for langt', { varer: [{ product_id: ids.brod, antall: 1 }], land: 'NO', postnummer: 'x'.repeat(30) }],
];
let r;
for (const [navn, body] of ugyldig) {
  r = await frakt(body, med);
  // Ugyldig JSON avvises av WordPress-kjernen (rest_invalid_json) før callbacken.
  sjekk(`400: ${navn}`, r.status === 400 && (r.json.code === 'amendo_frakt_ugyldig' || (navn === 'ikke JSON' && r.json.code === 'rest_invalid_json')), [r.status, r.json]);
}
r = await frakt({ varer: Array.from({ length: 100 }, () => ({ product_id: ids.brod, antall: 1 })), land: 'NO', postnummer: '0150' }, med);
sjekk('100 linjer godtas', r.status === 200, r.status);

console.log('\nIngen lagring');
r = await frakt({ varer: [{ product_id: ids.brod, antall: 1 }], land: 'NO', postnummer: '0150' }, { ...med, Cookie: 'wp_woocommerce_session_abc=123%7C%7C9999999999%7C%7C9999999999%7C%7Cdeadbeef; woocommerce_items_in_cart=1' });
sjekk('med kunde-cookie: 200 og ingen Set-Cookie', r.status === 200 && !r.h.get('set-cookie'), [r.status, r.h.get('set-cookie')]);
const etter = await get('/amendo-test/v1/tilstand');
sjekk('ingen nye rader i woocommerce_sessions', etter.sesjoner === foer.sesjoner, [foer, etter]);
sjekk('ingen persistent kurv i usermeta', etter.persistent === foer.persistent, [foer, etter]);

console.log('\nMidt i en forespørsel med ekte innlogget kunde og kurv');
const intern = await get('/amendo-test/v1/intern');
for (const k of ['res_ok', 'samme_sesjon', 'samme_kunde', 'samme_kurv', 'kunde_postnr_urort', 'kurv_urort', 'bruker_tilbake', 'persistent_urort', 'sesjon_uten_amendo']) sjekk(k, intern[k] === true, intern);
const kr = intern.shutdown_kroker || [];
sjekk('bare den ekte kundens/kurvens shutdown-kroker (1 + 1)', kr.filter(k => k === 'WC_Customer::save').length === 1 && kr.filter(k => k === 'WC_Cart_Session::maybe_set_cart_cookies').length === 1, kr);

ferdig();
