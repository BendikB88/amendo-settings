// 1.4.43: faktura i WooCommerce sin egen kasse for butikker uten frontend.
// amendo_faktura_for_bruker() (1.4.27) brukes i
// woocommerce_available_payment_gateways: Tellix-bedrift (godkjent eller
// org.nr i Tellix) og gruppe med faktura → fakturaen vises, «Ikke faktura» →
// fjernes, ellers som gatewayen sier. Kassen og den EKTE betal-for-ordre-
// handlingen (WC_Form_Handler::pay_action), regelen for ordrens kunde, og
// ingen endring med Frontend-URL eller med B2B av. Fakturagatewayen er
// stubbet i mu/v1443-test.php med samme is_available() som 1.36.19; Tellix er
// mu/tellix-stub.php. Se includes/moduler/b2b-faktura-kasse.php.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { B, REPO, get, sjekk, ferdig } from '../lib/felles.mjs';

const SECRET = 'faktura-kasse-1443';
const FAK = 'amendo-invoice';
const opt = o => get('/amendo-test/v1/opt', o);
const klargjor = body => get('/amendo-test/v1/v1414-klargjor', body);
const bruker = (epost, meta) => get('/amendo-test/v1/bedrift-bruker', { epost, meta });
const gw = (epost, betal = null) => get('/amendo-test/v1/v1443-gatewayer', { epost, betal });
const ordre = epost => get('/amendo-test/v1/v1443-ordre', { epost });
const betal = (epost, o, metode) => get('/amendo-test/v1/v1443-betal', { epost, ordre: o.id, key: o.key, metode });
const meta = async epost => (await fetch(B + '/amendo-test/v1/v1427-meta&epost=' + encodeURIComponent(epost))).json();
const kobl = async epost => fetch(B + '/amendo-settings/v1/rabatt/kunde/' + (await meta(epost)).id, { headers: { 'X-Amendo-Secret': SECRET } });
const har = (r, id = FAK) => (r.tilgjengelige || []).includes(id);
const E = n => `${n}@fk1443.no`;

await get('/amendo-test/v1/setup');
const GRUPPER = [{ id: 14, name: 'Bedriftskunder', discountPercent: 10 }];
const KUNDER = [
  { id: 'T1', uuid: 'U1', groupId: 14, email: E('gruppe'), name: 'Gruppe Fjorten' },
  { id: 'T2', uuid: 'U2', groupId: 0, email: E('bedrift'), name: 'Bedrift AS' },
  { raa: { customerId: 'T3', customerUuid: 'U3', customerGroupId: 0, name: 'Orgnr AS', email: E('orgnr'), phoneNumber: '', organizationNumber: '923 207 074', isActive: true, isDeleted: false } },
  { id: 'T4', uuid: 'U4', groupId: 0, email: E('privat'), name: 'Privat Person' },
  { id: 'T5', uuid: 'U5', groupId: 14, email: E('sperret'), name: 'Sperret AS' },
];
const FAKTURA = { enabled: 'yes', enabled_customer_group_14: 'yes' };
await opt({ amendo_kasse_secret: SECRET, amendo_frontend_url: '', amendo_test_faktura_stub: '1', woocommerce_bacs_settings: { enabled: 'yes', title: 'Bankoverføring' } });
await klargjor({
  moduler: ['b2b'], tellix: '1', grupper: GRUPPER, kunder: KUNDER, helsebygget: null, store_groups: null, kundegrupper: null, sov: null, kast: null, api_feil: null, side: null,
  kundegrupper_ny: { 14: { gjelder: 'nettbutikk', b2b: 1, levering: 0 } }, faktura: FAKTURA, liste_slett: true, nullstill: true,
});
await bruker(E('gruppe'), {});
await bruker(E('bedrift'), { _b2b_status: 'godkjent', billing_org_number: '988000002', billing_company: 'Bedrift AS' });
await bruker(E('orgnr'), {});
await bruker(E('privat'), {});
await bruker(E('sperret'), { _b2b_status: 'godkjent', billing_org_number: '988000005', _amendo_ingen_faktura: '1' });
await bruker(E('ukoblet'), { _b2b_status: 'godkjent', billing_org_number: '988000006' });
// Koblingen til Tellix (og vår kopi av kundelista) som når kunden logger inn.
for (const n of ['gruppe', 'bedrift', 'orgnr', 'privat', 'sperret']) await kobl(E(n));

// ── Kassen ──────────────────────────────────────────────────────────────────
console.log('Kassen (Frontend-URL tom)');
let r = await gw(E('gruppe'));
sjekk('gruppe med faktura: fakturaen (gatewayen gjør det selv også)', har(r) && har(r, 'bacs'), r);
sjekk('stubben er med i WooCommerce', (r.alle || []).includes(FAK), r.alle);
r = await gw(E('bedrift'));
sjekk('godkjent Tellix-bedrift uten gruppe: fakturaen legges til', har(r) && har(r, 'bacs'), r);
sjekk('… i gatewayenes rekkefølge', JSON.stringify(r.tilgjengelige) === JSON.stringify((r.alle || []).filter(id => r.tilgjengelige.includes(id))), r);
r = await gw(E('orgnr'));
sjekk('org.nr i Tellix (ikke godkjent her): fakturaen', har(r), r);
r = await gw(E('privat'));
sjekk('privat Tellix-kunde: ingen faktura (som gatewayen)', !har(r) && har(r, 'bacs'), r);
r = await gw(E('sperret'));
sjekk('«Ikke faktura»: fjernet, selv om gruppen har faktura', !har(r) && har(r, 'bacs'), r);
r = await gw(E('ukoblet'));
sjekk('godkjent, men ikke koblet til Tellix: som gatewayen (ingen)', !har(r), r);
r = await gw(null);
sjekk('ikke logget inn: som gatewayen (ingen)', !har(r) && har(r, 'bacs'), r);

// ── Betal for ordre ─────────────────────────────────────────────────────────
console.log('\nBetal for ordre');
const oB = await ordre(E('bedrift'));
r = await gw(E('bedrift'), oB.id);
sjekk('egen ordre: fakturaen vises', har(r), r);
let b = await betal(E('bedrift'), oB, FAK);
sjekk('den ekte pay_action godtar fakturaen og kjører gatewayen', b.redirect === `https://takk.example/${oB.id}` && b.metode === FAK && b.status === 'on-hold' && b.feil.length === 0, b);
const oS = await ordre(E('sperret'));
r = await gw(E('sperret'), oS.id);
sjekk('sperret, egen ordre: ingen faktura', !har(r) && har(r, 'bacs'), r);
b = await betal(E('sperret'), oS, FAK);
sjekk('… og pay_action avviser fakturaen (ingen betaling, ordren venter)', b.redirect === null && b.feil.length === 1 && b.metode !== FAK && b.status === 'pending', b);
b = await betal(E('sperret'), oS, 'bacs');
sjekk('… men bankoverføring går', b.redirect !== null && b.metode === 'bacs' && b.feil.length === 0, b);
const oP = await ordre(E('privat'));
r = await gw(E('bedrift'), oP.id);
sjekk('en annen kundes ordre: regelen gjelder ikke (gatewayen: ingen for gruppe 0)', !har(r), r);
const oG = await ordre(E('privat'));
r = await gw(E('sperret'), oG.id);
sjekk('sperret på en annens ordre: som gatewayen (gruppe 14 = faktura)', har(r), r);
const oGjest = await ordre(null);
r = await gw(null, oGjest.id);
sjekk('gjesteordre, ikke innlogget: som gatewayen', !har(r) && har(r, 'bacs'), r);

// ── Gatewayens egne innstillinger ───────────────────────────────────────────
console.log('\nGatewayens innstillinger');
await klargjor({ faktura: { ...FAKTURA, enabled: 'no' } });
r = await gw(E('bedrift'));
sjekk('gatewayen slått av: fakturaen legges ikke til', !har(r), r);
await klargjor({ faktura: { ...FAKTURA, enabled_customer_logged_in: 'yes' } });
r = await gw(E('privat'));
sjekk('«alle innloggede»: privat får den som før (vi tar ikke bort noe)', har(r), r);
r = await gw(E('sperret'));
sjekk('«alle innloggede»: sperret får den ikke', !har(r), r);
await klargjor({ faktura: { ...FAKTURA, enabled_customer_all: 'yes' } });
r = await gw(null);
sjekk('«alle», ikke innlogget: som gatewayen (vises)', har(r), r);
await klargjor({ faktura: FAKTURA });

// ── Ingen endring ───────────────────────────────────────────────────────────
console.log('\nIngen endring med Frontend-URL eller B2B av');
await opt({ amendo_frontend_url: 'https://front.example.no' });
r = await gw(E('bedrift'));
sjekk('Frontend-URL satt: bedrift uten gruppe som gatewayen (ingen)', !har(r), r);
r = await gw(E('sperret'));
sjekk('Frontend-URL satt: sperret som gatewayen (gruppe 14 = faktura)', har(r), r);
b = await betal(E('bedrift'), await ordre(E('bedrift')), FAK);
sjekk('Frontend-URL satt: pay_action som før (avvist for gruppe 0)', b.redirect === null && b.feil.length === 1, b);
await opt({ amendo_frontend_url: '' });
await klargjor({ moduler: [] });
r = await gw(E('bedrift'));
sjekk('B2B av: bedrift som gatewayen (ingen)', !har(r), r);
r = await gw(E('sperret'));
sjekk('B2B av: sperret som gatewayen (faktura)', har(r), r);
await klargjor({ moduler: ['b2b'] });
r = await gw(E('bedrift'));
sjekk('B2B på igjen: bedriften får fakturaen', har(r), r);

const kilde = readFileSync(resolve(REPO, 'includes/moduler/b2b-faktura-kasse.php'), 'utf8');
const kode = kilde.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
sjekk('ingen butikknavn, logging eller Tellix-kall i kassen', !/Haugli|Aanerud|Garçon|Garcon|emmk/i.test(kilde) && !/error_log|wc_get_logger|->log\(|call_API|wp_remote_/.test(kode));

await opt({ amendo_test_faktura_stub: null, woocommerce_bacs_settings: null, amendo_frontend_url: null });
ferdig();
