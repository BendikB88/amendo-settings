// 1.4.47: gratis_frakt_grense fra Holio Shipping i /frakt, mot stubben i mu/v1447-test.php (som Holio 1.4.2).
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { sjekk, ferdig, get, frakt, med, rate, klargjorFrakt, REPO } from '../lib/felles.mjs';

const ids = await klargjorFrakt();
await get('/amendo-test/v1/opt', { amendo_test_holio: '1', woocommerce_tax_display_cart: 'incl' });
const soner = await get('/amendo-test/v1/v1447-soner');
sjekk('sonene er laget', !!soner?.ha && !!soner?.hb, soner);

const BRING_HENT = { id: 11, product_name: 'Pakke til hentested', is_enabled: true, is_free_shipping: true, free_shipping_threshold: 3500, pris: 149 };
const BRING_POST = { id: 12, product_name: 'Pakke i postkassen', is_enabled: true, is_free_shipping: true, free_shipping_threshold: 3500, pris: 99 };
const PN_AV      = { id: 21, product_name: 'PostNord (av)', is_enabled: false, is_free_shipping: true, free_shipping_threshold: 2800, pris: 89 };
const PN_AV_TEKST = { id: 22, product_name: 'PostNord 2 (av)', is_enabled: 'false', is_free_shipping: true, free_shipping_threshold: 2800, pris: 89 };
const STANDARD = [BRING_HENT, BRING_POST, PN_AV, PN_AV_TEKST];

const holio = (valg, ekstra = {}) => get('/amendo-test/v1/v1447-holio', { valg, modus: '', tom_mellomlager: true, nullstill_teller: true, ...ekstra });
const tilstand = () => get('/amendo-test/v1/v1447-tilstand');
// DK = bare Holio, FI = Holio + WooCommerce free_shipping 3000 + flat_rate, NO = Norge-sonen uten Holio.
const kurv = (antall, land = 'DK', ekstra = {}) => frakt({ varer: [{ product_id: ids.brod, antall }], land, postnummer: land === 'NO' ? '0150' : '1000', ...ekstra }, med);
const holioRater = r => (r.json.alternativer || []).filter(a => a.metode === 'holio_shipping');
const medValg = (r, id) => holioRater(r).find(a => a.meta?.holio_option_id === String(id));

console.log('Under grensen: 2 × 250 = 500 kr (Bring 3500 på begge, PostNord 2800 deaktivert)');
await holio(STANDARD);
let r = await kurv(2);
sjekk('200', r.status === 200, r.json);
sjekk('gratis_frakt_grense = 3500 (ikke 2800 fra det deaktiverte PostNord-valget)', r.json.gratis_frakt_grense === 3500, r.json);
sjekk('gjenstår 3000', r.json.gjenstaar_til_gratis === 3000, r.json);
sjekk('to Holio-rater, ingen fra PostNord', holioRater(r).length === 2 && !medValg(r, 21) && !medValg(r, 22), holioRater(r));
if (!holioRater(r).length) { console.log('  STOPP: ingen Holio-rater i DK-sonen, oppsettet virker ikke', JSON.stringify(soner)); ferdig(); }
sjekk('hentested koster 149 + mva', medValg(r, 11)?.pris_inkl_mva === 186.25, medValg(r, 11));
sjekk('grensen per alternativ: 3500 på begge Bring-valgene', medValg(r, 11)?.gratis_frakt_grense === 3500 && medValg(r, 12)?.gratis_frakt_grense === 3500, holioRater(r));
sjekk('nøkler i svaret uendret', JSON.stringify(Object.keys(r.json)) === JSON.stringify(['valuta', 'alternativer', 'gratis_frakt_grense', 'gjenstaar_til_gratis', 'avviste_varer']), Object.keys(r.json));
sjekk('nøkler per alternativ: gratis_frakt_grense sist', JSON.stringify(Object.keys(medValg(r, 11) || {})) === JSON.stringify(['id', 'metode', 'navn', 'pris_eks_mva', 'mva', 'pris_inkl_mva', 'meta', 'gratis_frakt_grense']), Object.keys(medValg(r, 11) || {}));
let t = await tilstand();
sjekk('ett kall mot Holio', t.kall === 1, t);
sjekk('mellomlageret har bare Bring-valgene', JSON.stringify(t.grenser) === JSON.stringify({ 11: 3500, 12: 3500 }), t.grenser);
sjekk('mellomlageret gjelder i 10 minutter', t.sekunder_igjen > 540 && t.sekunder_igjen <= 600, t);

console.log('\nOver grensen: 28 × 250 = 7000 kr');
r = await kurv(28);
sjekk('Bring-valgene er 0 kr', medValg(r, 11)?.pris_inkl_mva === 0 && medValg(r, 12)?.pris_inkl_mva === 0, holioRater(r));
sjekk('grense 3500, gjenstår 0', r.json.gratis_frakt_grense === 3500 && r.json.gjenstaar_til_gratis === 0, r.json);
t = await tilstand();
sjekk('mellomlageret brukt: fortsatt ett kall', t.kall === 1, t);

console.log('\nAkkurat på grensen og like under (14 × 250 = 3500, 13 × 250 = 3250)');
r = await kurv(14);
sjekk('3500: gjenstår 0 og 0 kr', r.json.gjenstaar_til_gratis === 0 && medValg(r, 11)?.pris_inkl_mva === 0, r.json);
r = await kurv(13);
sjekk('3250: gjenstår 250', r.json.gjenstaar_til_gratis === 250, r.json);

console.log('\nDeaktivert valg med lavere grense som likevel har en rate (Holios backend svarer med alt)');
await holio([BRING_HENT, BRING_POST, { ...PN_AV, tving_rate: true }]);
r = await kurv(2);
sjekk('grensen er fortsatt 3500', r.json.gratis_frakt_grense === 3500, r.json);
sjekk('PostNord-raten har ingen grense', medValg(r, 21) && medValg(r, 21).gratis_frakt_grense === null, medValg(r, 21));

console.log('\nAktivt valg med lavere grense uten rate (gjelder ikke denne kurven)');
await holio([...STANDARD, { id: 13, product_name: 'Ekspress', is_enabled: true, is_free_shipping: true, free_shipping_threshold: 2000, rate: false }]);
r = await kurv(2);
sjekk('grensen er 3500, ikke 2000', r.json.gratis_frakt_grense === 3500, r.json);

console.log('\nUlike grenser: postkassen 3000, hentested 3500');
await holio([BRING_HENT, { ...BRING_POST, free_shipping_threshold: '3000.00' }, PN_AV]);
r = await kurv(2);
sjekk('laveste vinner: 3000, gjenstår 2500', r.json.gratis_frakt_grense === 3000 && r.json.gjenstaar_til_gratis === 2500, r.json);
sjekk('per alternativ: 3500 og 3000', medValg(r, 11)?.gratis_frakt_grense === 3500 && medValg(r, 12)?.gratis_frakt_grense === 3000, holioRater(r));

console.log('\nValg uten gratis frakt');
await holio([{ ...BRING_HENT, is_free_shipping: false }, { ...BRING_POST, free_shipping_threshold: null }, { id: 14, product_name: 'Null', is_enabled: true, is_free_shipping: true, free_shipping_threshold: 0 }]);
r = await kurv(2);
sjekk('is_free_shipping av, grense tom og 0 = ingen grense (null)', r.json.gratis_frakt_grense === null && r.json.gjenstaar_til_gratis === null, r.json);
sjekk('alternativene har null', holioRater(r).length === 3 && holioRater(r).every(a => a.gratis_frakt_grense === null), holioRater(r));
t = await tilstand();
sjekk('tom liste mellomlagres bare 1 minutt', t.mellomlager && t.sekunder_igjen <= 60, t);

console.log('\nRate uten holio_option_id');
await holio([{ ...BRING_HENT, uten_meta: true }]);
r = await kurv(2);
sjekk('ingen grense', r.json.gratis_frakt_grense === null, r.json);

console.log('\nUten mva i kurven (woocommerce_tax_display_cart = excl): 14 × 200 = 2800');
await holio(STANDARD);
await get('/amendo-test/v1/opt', { woocommerce_tax_display_cart: 'excl' });
r = await kurv(14);
sjekk('gjenstår 700, som Holio regner', r.json.gratis_frakt_grense === 3500 && r.json.gjenstaar_til_gratis === 700, r.json);
sjekk('Holio tar fortsatt betalt', medValg(r, 11)?.pris_eks_mva === 149, medValg(r, 11));
await get('/amendo-test/v1/opt', { woocommerce_tax_display_cart: 'incl' });

console.log('\nKupong 10 %: 14 × 250 = 3500 − 350 = 3150');
r = await kurv(14, 'DK', { kupong: 'holio10' });
sjekk('kupongen er gyldig', r.json.kupong?.gyldig === true, r.json.kupong);
sjekk('gjenstår 350 etter rabatten', r.json.gjenstaar_til_gratis === 350, r.json);
sjekk('Holio tar betalt under grensen etter rabatt', medValg(r, 11)?.pris_eks_mva === 149, medValg(r, 11));

console.log('\nSammen med WooCommerce free_shipping (3000) i samme sone');
r = await kurv(2, 'FI');
sjekk('WooCommerce sin 3000 er lavest', r.json.gratis_frakt_grense === 3000 && r.json.gjenstaar_til_gratis === 2500, r.json);
sjekk('Holio-ratene har 3500, flat_rate null', medValg(r, 11)?.gratis_frakt_grense === 3500 && rate(r, 'flat_rate')?.gratis_frakt_grense === null, r.json.alternativer);
await holio([{ ...BRING_HENT, free_shipping_threshold: 2500 }, BRING_POST]);
r = await kurv(2, 'FI');
sjekk('Holio sin 2500 er lavest, gjenstår 2000', r.json.gratis_frakt_grense === 2500 && r.json.gjenstaar_til_gratis === 2000, r.json);
r = await kurv(13, 'FI');
sjekk('over WooCommerce-grensen: free_shipping med grense 3000', rate(r, 'free_shipping')?.gratis_frakt_grense === 3000, r.json.alternativer);
sjekk('«Skjul betalt frakt» som før: betalte Holio-rater skjules når free_shipping finnes', !holioRater(r).length && !rate(r, 'flat_rate'), r.json.alternativer);

console.log('\nUten Holio-rater (vanlig Norge-sone): ingen kall');
await holio(STANDARD);
r = await kurv(2, 'NO');
t = await tilstand();
sjekk('grensen er WooCommerce sin 1000 som før', r.json.gratis_frakt_grense === 1000 && r.json.gjenstaar_til_gratis === 500, r.json);
sjekk('free_shipping-grensen per alternativ = null under grensen (raten finnes ikke), flat_rate null', rate(r, 'flat_rate')?.gratis_frakt_grense === null, r.json.alternativer);
sjekk('Holio ble ikke spurt', t.kall === 0, t);

console.log('\nMellomlageret');
r = await kurv(2);
await holio([{ ...BRING_HENT, free_shipping_threshold: 1500 }, BRING_POST], { tom_mellomlager: false, nullstill_teller: true });
r = await kurv(2);
t = await tilstand();
sjekk('endring i Holio vises ikke før mellomlageret går ut (3500 står)', r.json.gratis_frakt_grense === 3500 && t.kall === 0, { svar: r.json.gratis_frakt_grense, t });
await holio([{ ...BRING_HENT, free_shipping_threshold: 1500 }, BRING_POST]);
r = await kurv(2);
sjekk('etter utløp: 1500', r.json.gratis_frakt_grense === 1500, r.json);

console.log('\nHolio feiler');
await holio(STANDARD, { modus: 'kast' });
r = await kurv(2);
sjekk('unntak: 200 og grense null', r.status === 200 && r.json.gratis_frakt_grense === null, r.json);
sjekk('ratene vises fortsatt', holioRater(r).length === 2, r.json.alternativer);
t = await tilstand();
sjekk('unntak mellomlagres ikke', !t.mellomlager, t);
await holio(STANDARD, { modus: 'tom' });
r = await kurv(2);
sjekk('tom liste: grense null', r.json.gratis_frakt_grense === null, r.json);

console.log('\nUten Holio (stubben av: ingen klasser og ingen Holio-rater)');
await holio(STANDARD);
await get('/amendo-test/v1/opt', { amendo_test_holio: null });
r = await kurv(2);
sjekk('uten Holio: 200 og grense null', r.status === 200 && r.json.gratis_frakt_grense === null, r.json);
await get('/amendo-test/v1/opt', { amendo_test_holio: '1' });

console.log('\nKildekoden');
const kilde = readFileSync(resolve(REPO, 'includes/frakt-holio.php'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
const kalt = [...kilde.matchAll(/Holio_\w+(?:::|\(\)\)?->)\s*(\w+)/g)].map(m => m[1]);
sjekk('bare get_shop_id og find_enabled_by_shop_id kalles i Holio', kalt.length > 0 && kalt.every(k => ['get_shop_id', 'find_enabled_by_shop_id'].includes(k)), kalt);
sjekk('ingen butikknavn', !/playlot|garcon|garçon|aanerud|haugli|emmk/i.test(readFileSync(resolve(REPO, 'includes/frakt-holio.php'), 'utf8')));
sjekk('ingen logging', !/error_log|wc_get_logger|->log\(/.test(kilde));

await get('/amendo-test/v1/opt', { amendo_test_holio: null, woocommerce_tax_display_cart: 'incl' });
ferdig();
