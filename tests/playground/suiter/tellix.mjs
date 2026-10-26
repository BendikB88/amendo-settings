// 1.4.14 punkt 1–3: kundegrupper fra Tellix (via amendo-core) og rutene
// rabatt/kunde og rabatt/telefon. Mot stubben i mu/tellix-stub.php. Se
// includes/moduler/b2b-tellix.php. Tabellen i B2B → Kundegrupper og hvor
// rabatten gjelder (1.4.17): suiten `kundegrupper`.
//
// ⚠ Moduler og stubben lastes når pluginen lastes: tilstanden settes i ett
// kall og sjekkes i det neste.
import { B, get, sjekk, ferdig } from '../lib/felles.mjs';

const SECRET = 'rabatt-hemmelig-1414';
const klargjor = body => get('/amendo-test/v1/v1414-klargjor', body);
const opt = o => get('/amendo-test/v1/opt', o);
const bruker = (epost, meta) => get('/amendo-test/v1/bedrift-bruker', { epost, meta });
const brukermeta = async epost => (await fetch(B + '/amendo-test/v1/v1414-brukermeta&epost=' + encodeURIComponent(epost))).json();
const tilstand = () => get('/amendo-test/v1/v1414-tilstand');
const lagre = (post, rolle = 'admin') => get('/amendo-test/v1/v1414-lagre', { post, rolle });
const side = async (rolle = 'admin') => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=' + rolle)).text();
const rute = async (sti, secret = SECRET) => {
  const headers = secret === null ? {} : { 'X-Amendo-Secret': secret };
  const r = await fetch(B + '/amendo-settings/v1/' + sti, { headers });
  const tekst = await r.text();
  let json = null; try { json = JSON.parse(tekst); } catch {}
  return { status: r.status, json, tekst, h: r.headers };
};
const kunde = async (epost, secret) => rute('rabatt/kunde/' + (await brukermeta(epost)).id, secret);
const telefon = (nr, secret) => rute('rabatt/telefon&nr=' + encodeURIComponent(nr), secret);

await get('/amendo-test/v1/setup');
const kat = await get('/amendo-test/v1/kategorirad-oppsett');

const GRUPPER = [
  { id: 14, name: 'Bedriftskunder', discountPercent: 10, categories: { [kat.ringer]: 15 } },
  { id: 4, name: 'NAV Lillestrøm', discountPercent: 40, categories: {} },
  { id: 19, name: 'Personalrabatt 50', discountPercent: 50 },
  { id: 3, name: 'Faktura 25', discountPercent: 25, active: false },
  { id: 7, name: 'NAV SFJ', discountPercent: 40 },
  { id: 5, name: 'NAV Arbeidslivssenter', discountPercent: 40 },
  { id: 6, name: 'NAV Arbeidsrådgivning', discountPercent: 40 },
  { id: 1, name: 'Bedriftsrabatt 10 %', discountPercent: 10 },
];
const KUNDER = [
  { id: 'T1', uuid: 'U1', groupId: 14, email: 'Bedrift@Example.com', phone: '+4741128080', name: 'Kari  Nordmann Hansen' },
  { id: 'T2', uuid: 'U2', groupId: 4, email: 'nav@example.com', phone: '90000001', name: 'Ola' },
  { id: 'T5', uuid: 'U5', groupId: 14, email: '4790000005@dummy.com', phone: '+47 900 00 005', name: 'Per Persen' },
  { id: 'T3', uuid: 'U3', groupId: 3, email: 'faktura@example.com', phone: '' },
  { id: 'T6', uuid: 'U6', groupId: null, email: 'nullgruppe@example.com', phone: '' },
  { id: 'T7', uuid: 'U7', groupId: 14, email: 'inaktiv@example.com', phone: '+4793000007', aktiv: false },
  { id: 'T8', uuid: 'U8', groupId: 14, email: 'slettet@example.com', phone: '+4793000008', slettet: true },
];
const HELSEBYGGET = { 4: 40, 5: 40, 6: 40, 7: 40, 19: 50 };

await opt({ amendo_kasse_secret: SECRET });
await klargjor({ moduler: ['b2b'], tellix: '1', grupper: GRUPPER, kunder: KUNDER, helsebygget: HELSEBYGGET, sov: null, kast: null, api_feil: null, side: null, store_groups: null, kundegrupper: null, kundegrupper_ny: null, faktura: null, liste_slett: true, nullstill: true });
// NAV-ansatt som amendo-core aldri oppdaterte: står med 0 og en gammel kunde-ID.
await bruker('bedrift@example.com', { billing_phone: '411 28 080', _tellixGroupId: '0', _tellixCustomerId: 'GAMMEL' });
await bruker('nav@example.com', { billing_phone: '90000001' });
await bruker('otp@example.com', { _amendo_otp_match: '+47 900 00 005' });
await bruker('ukjent@example.com', { _tellixGroupId: '14', _tellixCustomerId: 'T9' });
await bruker('faktura@example.com', {});

// ── Tilgang ─────────────────────────────────────────────────────────────────
console.log('Tilgang (fail-closed)');
let r = await kunde('bedrift@example.com', null);
sjekk('rabatt/kunde uten header: avvist', r.status === 401 || r.status === 403, r.status);
r = await kunde('bedrift@example.com', 'feil');
sjekk('rabatt/kunde feil secret: avvist', r.status === 401 || r.status === 403, r.status);
r = await telefon('41128080', null);
sjekk('rabatt/telefon uten header: avvist', r.status === 401 || r.status === 403, r.status);
await opt({ amendo_kasse_secret: '' });
r = await kunde('bedrift@example.com', '');
sjekk('tom secret i WordPress: avvist også med tom header', r.status === 401 || r.status === 403, r.status);
await opt({ amendo_kasse_secret: SECRET });

// ── rabatt/kunde ────────────────────────────────────────────────────────────
console.log('\nrabatt/kunde');
r = await kunde('bedrift@example.com');
sjekk('200', r.status === 200, r.tekst);
sjekk('gruppe 14 fra Tellix (ikke den lagrede 0)', r.json?.gruppe_id === 14 && r.json?.gruppe_navn === 'Bedriftskunder', r.json);
sjekk('nettbutikk: gruppens sats og kategorisatsen', r.json?.nettbutikk?.prosent === 10 && r.json?.nettbutikk?.kategorier?.[String(kat.ringer)] === 15, r.json?.nettbutikk);
sjekk('helsebygget null for vanlig gruppe', r.json?.helsebygget === null);
sjekk('nøyaktig de fire feltene', JSON.stringify(Object.keys(r.json || {}).sort()) === '["gruppe_id","gruppe_navn","helsebygget","nettbutikk"]', Object.keys(r.json || {}));
let m = await brukermeta('bedrift@example.com');
sjekk('_tellixGroupId, _tellixCustomerId og _tellixCustomerUuId oppdatert', m.meta._tellixGroupId === '14' && m.meta._tellixCustomerId === 'T1' && m.meta._tellixCustomerUuId === 'U1', m.meta);
let s = await tilstand();
sjekk('amendo-core sin search_customers (8-timers transient) brukes ikke', s.kall === 0, s.kall);
sjekk('egen kopi av kundelista hentet fra Tellix (customer/list)', s.api_kall >= 2 && s.liste?.antall === 5, s);

r = await kunde('nav@example.com');
sjekk('Helsebygget-gruppe: ingen rabatt i nettbutikken', r.json?.gruppe_id === 4 && r.json?.nettbutikk === null, r.json);
sjekk('… men Helsebygget-satsen', r.json?.helsebygget?.prosent === 40, r.json);

r = await kunde('otp@example.com');
sjekk('funnet på _amendo_otp_match når e-posten ikke er i Tellix', r.json?.gruppe_id === 14, r.json);
m = await brukermeta('otp@example.com');
sjekk('… og gruppen skrevet til brukeren', m.meta._tellixGroupId === '14' && m.meta._tellixCustomerId === 'T5', m.meta);

r = await kunde('ukjent@example.com');
m = await brukermeta('ukjent@example.com');
sjekk('ikke i Tellix: den lagrede gruppen brukes', r.json?.gruppe_id === 14, r.json);
sjekk('… og feltene tømmes ikke (koblet til T9, som Tellix ikke har)', m.meta._tellixGroupId === '14' && m.meta._tellixCustomerId === 'T9', m.meta);

r = await kunde('faktura@example.com');
sjekk('inaktiv gruppe i Tellix: ingen rabatt, ingen navn', r.json?.gruppe_id === 3 && r.json?.gruppe_navn === null && r.json?.nettbutikk === null && r.json?.helsebygget === null, r.json);

s = await tilstand();
sjekk('get_tellix_customer_groups kalt én gang for alle oppslagene over (transient)', s.navnkall === 1, s.navnkall);

r = await rute('rabatt/kunde/999999');
sjekk('ukjent WordPress-bruker: 200 med null', r.status === 200 && r.json?.gruppe_id === null && r.json?.nettbutikk === null, r.json);

await klargjor({ liste_slett: true, api_feil: '1' });
r = await kunde('bedrift@example.com');
m = await brukermeta('bedrift@example.com');
sjekk('Tellix svarer feil og ingen kopi: 200 med lagret gruppe, feltene urørt', r.status === 200 && r.json?.gruppe_id === 14 && m.meta._tellixCustomerId === 'T1', [r.status, r.json, m.meta]);
await klargjor({ api_feil: null, liste_slett: true });

// ── rabatt/telefon ──────────────────────────────────────────────────────────
console.log('\nrabatt/telefon');
r = await telefon('411 28 080');
sjekk('200 med renset nummer', r.status === 200 && r.json?.telefon === '+4741128080', r.json);
sjekk('gruppen fra Tellix', r.json?.gruppe_id === 14 && r.json?.nettbutikk?.prosent === 10, r.json);
s = await tilstand();
sjekk('slått opp i Tellix-lista, ikke via search_customers', s.kall === 0, s.kall);
r = await telefon('+47 900 00 001');
sjekk('Helsebygget-gruppe på telefon: helsebygget-satsen, ingen nettbutikk', r.json?.gruppe_id === 4 && r.json?.helsebygget?.prosent === 40 && r.json?.nettbutikk === null, r.json);
r = await telefon('0047 99999999');
sjekk('ukjent nummer: gruppe null', r.status === 200 && r.json?.gruppe_id === null && r.json?.telefon === '+4799999999', r.json);
r = await telefon('abc');
sjekk('ugyldig nummer: 400', r.status === 400, r.status);

console.log('\nrabatt/telefon: navn og e-post fra Tellix (1.4.16)');
r = await telefon('41128080');
sjekk('navnet delt på siste mellomrom: «Kari Nordmann» + «Hansen»', r.json?.fornavn === 'Kari Nordmann' && r.json?.etternavn === 'Hansen', r.json);
sjekk('e-posten fra Tellix (små bokstaver)', r.json?.epost === 'bedrift@example.com', r.json?.epost);
sjekk('de gamle feltene er som før', ['gruppe_id', 'gruppe_navn', 'nettbutikk', 'helsebygget', 'telefon'].every(k => k in r.json), Object.keys(r.json || {}));
r = await telefon('90000001');
sjekk('ett ord: bare fornavn, etternavn null', r.json?.fornavn === 'Ola' && r.json?.etternavn === null, r.json);
r = await telefon('90000005');
sjekk('e-post @dummy.com (amendo-core sin plassholder): null', r.json?.epost === null && r.json?.fornavn === 'Per', r.json);
r = await telefon('99999999');
sjekk('ukjent nummer: navn og e-post null', r.json?.fornavn === null && r.json?.etternavn === null && r.json?.epost === null, r.json);

console.log('\nReserve når Tellix ikke finner kunden (1.4.15)');
await bruker('manuell@example.com', { _tellixGroupId: '1' });
await bruker('nullgruppe@example.com', { _tellixGroupId: '14' });
await bruker('ingen@example.com', { _tellixGroupId: '0' });
r = await kunde('manuell@example.com');
sjekk('ikke i Tellix, _tellixGroupId = 1 (satt for hånd): gruppe 1 med nettbutikk-satsen', r.json?.gruppe_id === 1 && r.json?.nettbutikk?.prosent === 10, r.json);
r = await kunde('nullgruppe@example.com');
m = await brukermeta('nullgruppe@example.com');
sjekk('i Tellix uten gruppe: den lagrede gruppen (14) brukes', r.json?.gruppe_id === 14 && r.json?.nettbutikk?.prosent === 10, r.json);
sjekk('… og 14 overskrives ikke med 0 (kunde-ID-en oppdateres)', m.meta._tellixGroupId === '14' && m.meta._tellixCustomerId === 'T6', m.meta);
r = await kunde('nullgruppe@example.com');
m = await brukermeta('nullgruppe@example.com');
sjekk('neste gang er kunden KOBLET (T6): Tellix er sannheten, gruppe 0 skrives', r.json?.gruppe_id === null && m.meta._tellixGroupId === '0', [r.json, m.meta]);
r = await kunde('ingen@example.com');
sjekk('_tellixGroupId = 0 og ikke i Tellix: null', r.status === 200 && r.json?.gruppe_id === null && r.json?.nettbutikk === null, r.json);

console.log('\nReserve når Tellix ikke kjenner nummeret (1.4.15)');
// 41128080-saken fra garcon.no, med et nummer Tellix ikke har: én bruker med
// gruppe 4, én uten gruppe — samme nummer i to former.
await bruker('tlf-a@example.com', { billing_phone: '450 76 655', _tellixGroupId: '4' });
await bruker('tlf-b@example.com', { _amendo_otp_match: '+4745076655' });
r = await telefon('45076655');
sjekk('to brukere, én med gruppe (4): gruppe 4 og Helsebygget-satsen', r.json?.gruppe_id === 4 && r.json?.helsebygget?.prosent === 40 && r.json?.nettbutikk === null, r.json);
await bruker('tlf-c@example.com', { billing_phone: '46000001', _tellixGroupId: '4' });
await bruker('tlf-d@example.com', { _amendo_otp_match: '46000001', _tellixGroupId: '14' });
r = await telefon('46000001');
sjekk('to brukere med ulike grupper: null', r.status === 200 && r.json?.gruppe_id === null, r.json);
await bruker('tlf-e@example.com', { billing_phone: '46000002', _tellixGroupId: '14' });
await bruker('tlf-f@example.com', { billing_phone: '+47 460 00 002', _tellixGroupId: '14' });
r = await telefon('46000002');
sjekk('to brukere med samme gruppe: den gruppen', r.json?.gruppe_id === 14, r.json);
await bruker('tlf-g@example.com', { billing_phone: '46000103', _tellixGroupId: '4' });
await bruker('tlf-i@example.com', { billing_phone: '93000007', _tellixGroupId: '0' });
r = await telefon('46000003');
sjekk('nummer som bare ligner (samme to siste sifre): ikke treff', r.json?.gruppe_id === null, r.json);
await bruker('tlf-h@example.com', { billing_phone: '41128080', _tellixGroupId: '4' });
r = await telefon('41128080');
sjekk('Tellix kjenner nummeret med gruppe: Tellix vinner over WordPress', r.json?.gruppe_id === 14, r.json);
r = await telefon('93000007');
sjekk('inaktiv Tellix-kunde finnes ikke (som i amendo-core)', r.json?.gruppe_id === null && r.json?.fornavn === null, r.json);
r = await telefon('93000008');
sjekk('slettet Tellix-kunde finnes ikke', r.json?.gruppe_id === null, r.json);

console.log('\nGrense per nummer (10 per 15 min)');
await klargjor({ nullstill: true });
let koder = [];
for (let i = 0; i < 11; i++) koder.push((await telefon('41128080')).status);
sjekk('10 × 200, så 429', koder.slice(0, 10).every(k => k === 200) && koder[10] === 429, koder);
r = await telefon('41128080');
sjekk('429 har Retry-After og JSON', r.status === 429 && Number(r.h.get('retry-after')) > 0 && r.json?.code === 'amendo_rabatt_rate_limit', [r.status, r.h.get('retry-after')]);
r = await telefon('90000001');
sjekk('et annet nummer har egen kvote', r.status === 200, r.status);

// ── Egen kopi av kundelista (1.4.16) ────────────────────────────────────────
console.log('\nEgen kopi av kundelista: alle sider, aldri eldre enn 5 min');
await klargjor({ liste_slett: true, side: '2', nullstill: true });
r = await telefon('90000005');
s = await tilstand();
sjekk('alle sider hentet, 2 og 2, til tom side (7 kunder: offset 0, 2, 4, 6, 7)', JSON.stringify(s.offsets) === '[0,2,4,6,7]' && s.api_kall === 5, s.offsets);
sjekk('bare aktive, ikke slettede kunder i kopien (5 av 7)', s.liste?.antall === 5, s.liste);
sjekk('kunden er funnet i kopien', r.json?.gruppe_id === 14, r.json);
r = await telefon('90000001');
s = await tilstand();
sjekk('neste oppslag: fra kopien, ikke Tellix', r.json?.gruppe_id === 4 && s.api_kall === 5, s.api_kall);
// Playground bruker 10–25 s per kall: kopien settes «fersk» med margin.
await klargjor({ liste_alder: -60 });
r = await telefon('98000000');
s = await tilstand();
sjekk('ukjent nummer, kopien yngre enn 30 s: ingen ny henting', r.json?.gruppe_id === null && s.api_kall === 5, s.api_kall);

console.log('\nNy kunde i Tellix: funnet med en gang');
await klargjor({ kunder: [...KUNDER, { id: 'T9', uuid: 'U9', groupId: 1, email: 'ny@example.com', phone: '+4798000001', name: 'Ny Kunde' }], liste_alder: 40, las_alder: 40 });
let før = (await tilstand()).api_kall;
r = await telefon('98000001');
s = await tilstand();
sjekk('ikke i kopien, kopien eldre enn 30 s: hentet på nytt og funnet', r.json?.gruppe_id === 1 && r.json?.fornavn === 'Ny' && s.api_kall > før, [r.json, s.api_kall, før]);

console.log('\nKunden flyttet i Tellix');
const flyttet = [...KUNDER, { id: 'T9', uuid: 'U9', groupId: 4, email: 'ny@example.com', phone: '+4798000001', name: 'Ny Kunde' }];
await klargjor({ kunder: flyttet, liste_alder: 100, las_alder: 100 });
r = await telefon('98000001');
sjekk('kopien er 100 s (under 5 min): gammel gruppe til den er hentet på nytt', r.json?.gruppe_id === 1, r.json);
await klargjor({ liste_alder: 301, las_alder: 301 });
r = await telefon('98000001');
s = await tilstand();
sjekk('kopien eldre enn 5 min: hentet på nytt, ny gruppe (4)', r.json?.gruppe_id === 4 && r.json?.helsebygget?.prosent === 40 && s.liste?.alder < 60, [r.json, s.liste]);

console.log('\nMaks én full henting per 30 s for hele butikken');
// Låsen satt fram i tid: den er garantert tatt mens kallet går.
await klargjor({ liste_alder: 400, las_alder: -60 });
før = (await tilstand()).api_kall;
r = await telefon('98000001');
s = await tilstand();
sjekk('låsen tatt (under 30 s): ingen henting, svar fra den gamle kopien', s.api_kall === før && r.json?.gruppe_id === 4, [s.api_kall, før, r.json]);

console.log('\nTellix feiler midt i lista');
await klargjor({ kunder: [...flyttet, { id: 'T10', uuid: 'U10', groupId: 14, email: 'x@example.com', phone: '+4798000002', name: 'X' }], liste_alder: 400, las_alder: 400, api_feil: '2' });
const antallFør = (await tilstand()).liste?.antall;
r = await telefon('98000001');
s = await tilstand();
sjekk('feil på side 2: den forrige kopien står hel', s.liste?.antall === antallFør && r.json?.gruppe_id === 4, [s.liste, antallFør]);
await klargjor({ api_feil: null, kunder: KUNDER, side: null, liste_slett: true });

// ── Helsebygget-gruppene ────────────────────────────────────────────────────
console.log('\nHelsebygget-gruppene');
await klargjor({ helsebygget: null, store_groups: null });
r = await kunde('nav@example.com');
sjekk('ikke satt opp: 503 (ingen rabatt kan gis feil)', r.status === 503 && r.json?.code === 'amendo_helsebygget_ikke_satt', [r.status, r.json]);
r = await telefon('90000001');
sjekk('… også rabatt/telefon', r.status === 503, r.status);

await klargjor({ store_groups: { 1: '0', 2: '0', 3: '0', 4: 'helsebygget', 5: 'helsebygget', 6: 'helsebygget', 7: 'helsebygget', 14: '0' } });
r = await kunde('nav@example.com');
sjekk('amendo-store-groups alene setter ikke opp lista: fortsatt 503 (1.4.15)', r.status === 503, [r.status, r.json]);

// Lista lagret igjen: rabattrutene svarer. Helsebygget-lista, satsene og
// admin-tabellen (1.4.17): suiten `kundegrupper`.
await klargjor({ helsebygget: HELSEBYGGET, store_groups: null });
r = await kunde('nav@example.com');
sjekk('lista lagret: gruppe 4 er Helsebygget igjen', r.status === 200 && r.json?.helsebygget?.prosent === 40 && r.json?.nettbutikk === null, r.json);

// ── E-post uten hensyn til store/små bokstaver og mellomrom (1.4.17) ────────
console.log('\nE-post: store og små bokstaver og mellomrom');
// Kk@eidel.no-saken fra garcon.no: Tellix og WordPress skrev e-posten ulikt,
// og kunden ble ikke koblet. Ingen telefon, så bare e-posten kan gi treffet.
await klargjor({ kunder: [...KUNDER,
  { id: 'T20', uuid: 'U20', groupId: 14, email: 'Kk@eidel.no', phone: '', name: 'K K' },
  { id: 'T21', uuid: 'U21', groupId: 1, email: ' Ola.NORDMANN@Eidel.no ', phone: '', name: 'Ola N' },
  { id: 'T22', uuid: 'U22', groupId: 14, email: 'kari @eidel.no', phone: '', name: 'Kari' },
], liste_slett: true });
await bruker('kk@eidel.no', {});
await bruker('Ola.Nordmann@eidel.no', {});
await bruker('kari@eidel.no', {});
await bruker('kk2@eidel.no', {});
r = await kunde('kk@eidel.no');
m = await brukermeta('kk@eidel.no');
sjekk('Tellix «Kk@eidel.no», WordPress «kk@eidel.no»: koblet, gruppe 14', r.json?.gruppe_id === 14 && m.meta._tellixCustomerId === 'T20' && m.meta._tellixGroupId === '14', [r.json, m.meta]);
r = await kunde('Ola.Nordmann@eidel.no');
sjekk('store bokstaver i WordPress, mellomrom og hardt mellomrom i Tellix: gruppe 1', r.json?.gruppe_id === 1, r.json);
r = await kunde('kari@eidel.no');
sjekk('mellomrom inne i e-posten i Tellix: gruppe 14', r.json?.gruppe_id === 14, r.json);
r = await kunde('kk2@eidel.no');
sjekk('en annen e-post som ligner: ikke treff', r.status === 200 && r.json?.gruppe_id === null, r.json);
await klargjor({ kunder: KUNDER, liste_slett: true });

// ── Gruppefeltet: customerGroupId og groupId (1.4.19) ───────────────────────
console.log('\nGruppefeltet: customerGroupId (Tellix) og groupId (amendo-core)');
// garcon.no, 1.4.17: bruker 3492 (testbedrift@amendo.no), koblet til
// Tellix-kunde 2255 med gruppe 1, fikk gruppe_id null og _tellixGroupId = 0.
// Kunde 2255 er et ekte svar fra v2/customer/list (customerGroupId).
const TELLIX_2255 = {
  customerId: 2255, name: 'Testbedrift AS', email: 'testbedrift@amendo.no', phoneNumber: '',
  customerGroupId: 1, customerUuid: '6f1c2a3e-2255-4b7a-9c1d-0a1b2c3d4e5f',
  address: 'Testveien 1', zip: '0150', location: 'Oslo', country: 'NO',
  isActive: true, isDeleted: false,
};
await klargjor({ kunder: [...KUNDER,
  { raa: TELLIX_2255 },
  { id: '2256', uuid: 'U2256', groupId: 1, email: 'core@amendo.no', phone: '', form: 'core' },
  { id: '2257', uuid: 'U2257', groupId: 4, email: 'uten@amendo.no', phone: '', form: 'uten' },
  { id: '2258', uuid: 'U2258', groupId: null, email: 'tom@amendo.no', phone: '' },
  { id: '2259', uuid: 'U2259', groupId: 0, email: 'null@amendo.no', phone: '' },
  { id: '2260', uuid: 'U2260', groupId: '', email: 'coretom@amendo.no', phone: '', form: 'core' },
  { id: '2261', uuid: 'U2261', groupId: 4, email: 'kontakt-uten@amendo.no', phone: '', form: 'uten' },
], liste_slett: true });
await bruker('testbedrift@amendo.no', { _tellixCustomerId: '2255', _tellixGroupId: '0' });
await bruker('core@amendo.no', { _tellixCustomerId: '2256', _tellixGroupId: '0' });
await bruker('uten@amendo.no', { _tellixCustomerId: '2257', _tellixGroupId: '1' });
await bruker('tom@amendo.no', { _tellixCustomerId: '2258', _tellixGroupId: '1' });
await bruker('null@amendo.no', { _tellixCustomerId: '2259', _tellixGroupId: '1' });
await bruker('coretom@amendo.no', { _tellixCustomerId: '2260', _tellixGroupId: '1' });
await bruker('kontakt-uten@amendo.no', { _tellixGroupId: '1' });
r = await kunde('testbedrift@amendo.no');
m = await brukermeta('testbedrift@amendo.no');
sjekk('ekte v2/customer/list (customerGroupId 1), koblet: gruppe 1 og _tellixGroupId 1', r.json?.gruppe_id === 1 && m.meta._tellixGroupId === '1' && m.meta._tellixCustomerUuId === TELLIX_2255.customerUuid, [r.json, m.meta]);
r = await kunde('core@amendo.no');
m = await brukermeta('core@amendo.no');
sjekk('amendo-core sin form (groupId 1), koblet: gruppe 1', r.json?.gruppe_id === 1 && m.meta._tellixGroupId === '1', [r.json, m.meta]);
r = await kunde('uten@amendo.no');
m = await brukermeta('uten@amendo.no');
sjekk('gruppefeltet mangler, koblet: den lagrede gruppen 1 gjelder og står', r.json?.gruppe_id === 1 && m.meta._tellixGroupId === '1', [r.json, m.meta]);
r = await kunde('kontakt-uten@amendo.no');
m = await brukermeta('kontakt-uten@amendo.no');
sjekk('gruppefeltet mangler, treff på e-post: 1 står, kunden kobles', r.json?.gruppe_id === 1 && m.meta._tellixGroupId === '1' && m.meta._tellixCustomerId === '2261', [r.json, m.meta]);
r = await kunde('tom@amendo.no');
m = await brukermeta('tom@amendo.no');
sjekk('customerGroupId: null (eksplisitt tom), koblet: 0 (Tellix er sannheten)', r.json?.gruppe_id === null && m.meta._tellixGroupId === '0', [r.json, m.meta]);
r = await kunde('null@amendo.no');
m = await brukermeta('null@amendo.no');
sjekk('customerGroupId: 0, koblet: 0', r.json?.gruppe_id === null && m.meta._tellixGroupId === '0', [r.json, m.meta]);
r = await kunde('coretom@amendo.no');
m = await brukermeta('coretom@amendo.no');
sjekk('groupId: "" (eksplisitt tom), koblet: 0', r.json?.gruppe_id === null && m.meta._tellixGroupId === '0', [r.json, m.meta]);

// Kopi lagret av 1.4.17 (uten format): 0 kan bety «feltet manglet». Den
// skriver aldri over en gruppe over 0, men en gruppe over 0 i kopien gjelder.
await bruker('gammel-kopi@amendo.no', { _tellixCustomerId: '2255', _tellixGroupId: '1' });
await bruker('gammel-kopi4@amendo.no', { _tellixCustomerId: '2262', _tellixGroupId: '0' });
await get('/amendo-test/v1/v1419-liste', { kunder: [
  { id: '2255', uuid: 'U-gammel', gruppe_id: 0, navn: 'Testbedrift AS', epost: 'testbedrift@amendo.no', telefon: '' },
  { id: '2262', uuid: 'U2262', gruppe_id: 4, navn: 'NAV', epost: 'gammel-kopi4@amendo.no', telefon: '' },
] });
r = await kunde('gammel-kopi@amendo.no');
m = await brukermeta('gammel-kopi@amendo.no');
sjekk('kopi fra 1.4.17 med gruppe_id 0: _tellixGroupId 1 står', r.json?.gruppe_id === 1 && m.meta._tellixGroupId === '1', [r.json, m.meta]);
r = await kunde('gammel-kopi4@amendo.no');
m = await brukermeta('gammel-kopi4@amendo.no');
sjekk('kopi fra 1.4.17 med gruppe_id 4: gruppe 4', r.json?.gruppe_id === 4 && m.meta._tellixGroupId === '4', [r.json, m.meta]);
await get('/amendo-test/v1/v1419-liste', { format: 2, kunder: [
  { id: '2255', uuid: 'U-ny', gruppe_id: 0, navn: 'Testbedrift AS', epost: 'testbedrift@amendo.no', telefon: '' },
] });
r = await kunde('gammel-kopi@amendo.no');
m = await brukermeta('gammel-kopi@amendo.no');
sjekk('kopi fra 1.4.19 (format 2) med gruppe_id 0: Tellix er sannheten, 0', r.json?.gruppe_id === null && m.meta._tellixGroupId === '0', [r.json, m.meta]);
await klargjor({ kunder: KUNDER, liste_slett: true });

// ── Samme telefonnummer på flere Tellix-kunder (1.4.20) ─────────────────────
console.log('\nSamme telefonnummer på flere Tellix-kunder');
// Garçon-økta: amendo_tellix_let() tok den første kunden med nummeret, uten
// å se på gruppen. Kunden uten gruppe står FØRST i lista i hvert tilfelle.
// Gruppe 4 og 19 er Bare Helsebygget (HELSEBYGGET), 14 er Nettbutikken.
await klargjor({ kunder: [...KUNDER,
  { id: 'D1a', uuid: 'UD1a', groupId: 0, email: '', phone: '+4793001001', name: 'Uten Gruppe' },
  { id: 'D1b', uuid: 'UD1b', groupId: 4, email: '', phone: '93 00 10 01', name: 'Nav Ansatt' },
  { id: 'D2a', uuid: 'UD2a', groupId: 14, email: 'd2a@eidel.no', phone: '+4793001002', name: 'Bedrift Ansatt' },
  { id: 'D2b', uuid: 'UD2b', groupId: 4, email: '', phone: '+4793001002', name: 'Nav Ansatt' },
  { id: 'D3a', uuid: 'UD3a', groupId: 4, email: '', phone: '+4793001003', name: 'A' },
  { id: 'D3b', uuid: 'UD3b', groupId: 19, email: '', phone: '+4793001003', name: 'B' },
  { id: 'D4a', uuid: 'UD4a', groupId: 0, email: '', phone: '+4793001004', name: 'C' },
  { id: 'D4b', uuid: 'UD4b', groupId: null, email: '', phone: '+4793001004', name: 'D' },
  { id: 'D5a', uuid: 'UD5a', groupId: 0, email: '', phone: '+4793001005', name: 'E' },
  { id: 'D5b', uuid: 'UD5b', groupId: 14, email: '', phone: '+4793001005', name: 'F' },
  { id: 'D5c', uuid: 'UD5c', groupId: 14, email: '', phone: '+4793001005', name: 'G' },
], liste_slett: true });
r = await telefon('93001001');
sjekk('gruppe 0 og 4 på nummeret: gruppe 4 (Helsebygget), ikke den første', r.json?.gruppe_id === 4 && r.json?.helsebygget?.prosent === 40 && r.json?.fornavn === 'Nav', r.json);
r = await telefon('93001002');
sjekk('gruppe 14 og 4: rabatt/telefon foretrekker Helsebygget (4)', r.json?.gruppe_id === 4 && r.json?.helsebygget?.prosent === 40, r.json);
// Reserven fra WordPress skal ikke slå inn når Tellix har flere kunder.
await bruker('d3-wp@eidel.no', { billing_phone: '93001003', _tellixGroupId: '14' });
r = await telefon('93001003');
sjekk('gruppe 4 og 19 (begge Helsebygget): ingen gruppe, ingen navn, ikke WordPress-reserven', r.status === 200 && r.json?.gruppe_id === null && r.json?.fornavn === null && r.json?.epost === null, r.json);
r = await telefon('93001004');
sjekk('ingen av kundene har gruppe: ingen gruppe og ingen navn', r.status === 200 && r.json?.gruppe_id === null && r.json?.fornavn === null, r.json);
r = await telefon('93001005');
sjekk('0, 14 og 14: gruppe 14', r.json?.gruppe_id === 14, r.json);

// rabatt/kunde: Helsebygget foretrekkes ikke. Ulike grupper = ingen gruppe og
// ingen kobling; e-posten kan avgjøre; ID-koblingen er som før.
await bruker('d2-tvetydig@eidel.no', { billing_phone: '93001002' });
r = await kunde('d2-tvetydig@eidel.no');
m = await brukermeta('d2-tvetydig@eidel.no');
sjekk('rabatt/kunde, gruppe 14 og 4 på nummeret: ingen gruppe, ikke koblet', r.json?.gruppe_id === null && !m.meta._tellixCustomerId && !m.meta._tellixGroupId, [r.json, m.meta]);
await bruker('d2-lagret@eidel.no', { billing_phone: '93001002', _tellixGroupId: '19' });
r = await kunde('d2-lagret@eidel.no');
m = await brukermeta('d2-lagret@eidel.no');
sjekk('… med lagret gruppe 19: den står (som når Tellix ikke finner kunden)', r.json?.gruppe_id === 19 && m.meta._tellixGroupId === '19', [r.json, m.meta]);
await bruker('d2a@eidel.no', { billing_phone: '93001002' });
r = await kunde('d2a@eidel.no');
m = await brukermeta('d2a@eidel.no');
sjekk('… e-posten avgjør: gruppe 14, koblet til D2a', r.json?.gruppe_id === 14 && m.meta._tellixCustomerId === 'D2a', [r.json, m.meta]);
await bruker('d2-koblet@eidel.no', { billing_phone: '93001002', _tellixCustomerId: 'D2b' });
r = await kunde('d2-koblet@eidel.no');
sjekk('… koblet på Tellix-ID (D2b): gruppe 4, som før', r.json?.gruppe_id === 4, r.json);
await bruker('d1@eidel.no', { billing_phone: '93001001' });
r = await kunde('d1@eidel.no');
m = await brukermeta('d1@eidel.no');
sjekk('rabatt/kunde, gruppe 0 og 4: gruppe 4, koblet til D1b', r.json?.gruppe_id === 4 && m.meta._tellixCustomerId === 'D1b', [r.json, m.meta]);
await klargjor({ kunder: KUNDER, liste_slett: true });

let html;
console.log('\nAdvarsel: Tellix-lista kan være avkortet (1.4.15)');
await klargjor({ sync_size: '2500', core_cache: 2500, tellix_cache: null });
html = await side('admin');
sjekk('transienten tellix_345customers (amendo-core 3.0.1 på garcon.no) leses', html.includes('amendo-tellix-avkortet') && html.includes('2500 kunder, og grensen er 2500'));
await klargjor({ core_cache: null, tellix_cache: 2500 });
html = await side('admin');
sjekk('2500 kunder, grense 2500 (garcon.no): advarsel med tallene', html.includes('amendo-tellix-avkortet') && html.includes('hev AMENDO_CUSTOMER_SYNC_SIZE') && html.includes('2500 kunder, og grensen er 2500'));
await klargjor({ tellix_cache: 2400 });
html = await side('admin');
sjekk('2400 av 2500 (inaktive tatt ut etter hentingen): fortsatt advarsel', html.includes('amendo-tellix-avkortet'));
await klargjor({ sync_size: '10000', tellix_cache: 2922 });
html = await side('admin');
sjekk('grense hevet til 10000, 2922 kunder: ingen advarsel', !html.includes('amendo-tellix-avkortet'));
await klargjor({ sync_size: null, tellix_cache: 500 });
html = await side('admin');
sjekk('uten konstanten: amendo-core sin standard (500) gjelder', html.includes('amendo-tellix-avkortet') && html.includes('grensen er 500'));
await klargjor({ tellix_cache: null });
html = await side('admin');
sjekk('lista ikke lastet: ingen advarsel (og ingen kall til Tellix)', !html.includes('amendo-tellix-avkortet'));

// ── Uten amendo-core ────────────────────────────────────────────────────────
console.log('\nUten amendo-core');
await klargjor({ tellix: '0' });
r = await kunde('bedrift@example.com');
sjekk('ingen rabatt: alt null', r.status === 200 && r.json?.gruppe_id === null && r.json?.nettbutikk === null && r.json?.helsebygget === null, r.json);
r = await telefon('41128080');
sjekk('rabatt/telefon: ingen rabatt', r.status === 200 && r.json?.gruppe_id === null, r.json);
m = await brukermeta('bedrift@example.com');
sjekk('lagrede felt urørt', m.meta._tellixGroupId === '14', m.meta);
html = await side('admin');
sjekk('advarsel i B2B-fanen', html.includes('amendo-tellix-mangler'));

// ── B2B av ──────────────────────────────────────────────────────────────────
console.log('\nB2B av');
await klargjor({ moduler: [], tellix: '1' });
r = await kunde('bedrift@example.com');
sjekk('rabatt/kunde finnes ikke (404)', r.status === 404, r.status);
r = await telefon('41128080');
sjekk('rabatt/telefon finnes ikke (404)', r.status === 404, r.status);

// ── Logg ────────────────────────────────────────────────────────────────────
console.log('\nLogg');
const logg = await get('/amendo-test/v1/v1414-logg');
sjekk('amendo-tellix-loggen har ingen e-post, telefonnummer eller navn', !/@|\+47|4112|9000|Nordmann|Persen/.test(logg.tekst || ''), logg.tekst);
sjekk('… men har antall og tid for hentingen', /kundelista hentet: \d+ kunder, \d+ sider, [\d.]+ s/.test(logg.tekst || ''), logg.tekst);

await klargjor({ moduler: null, tellix: null, nullstill: true });
ferdig();
