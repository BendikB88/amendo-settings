// 1.4.14 punkt 1–3: kundegrupper fra Tellix (via amendo-core), Helsebygget-
// gruppene og rutene rabatt/kunde og rabatt/telefon. Mot stubben i
// mu/tellix-stub.php. Se includes/moduler/b2b-tellix.php.
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
await klargjor({ moduler: ['b2b'], tellix: '1', grupper: GRUPPER, kunder: KUNDER, helsebygget: HELSEBYGGET, sov: null, kast: null, api_feil: null, side: null, store_groups: null, liste_slett: true, nullstill: true });
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

let html = await side('admin');
sjekk('feltet forhåndsutfylt med BARE gruppe-ID-ene (4–7), uten sats', /name="helsebygget_grupper\[verdi\]" value="4:, 5:, 6:, 7:"/.test(html), html.match(/name="helsebygget_grupper\[verdi\]" value="[^"]*"/)?.[0]);
let l = await lagre({ helsebygget_grupper: { finnes: '1', verdi: '4:, 5:, 6:, 7:' } });
sjekk('lagring av forslaget uten satser: avvist, ingenting lagret', l.helsebygget === null, l.helsebygget);
html = await side('admin');
sjekk('… fanen sier hvorfor, og beholder det som ble skrevet', html.includes('amendo-helsebygget-avvist') && /value="4:, 5:, 6:, 7:"/.test(html));
l = await lagre({ helsebygget_grupper: { finnes: '1', verdi: '4:40, 5:40, 6:, 7:40' } });
sjekk('én gruppe uten sats: hele lagringen avvist', l.helsebygget === null, l.helsebygget);
l = await lagre({ helsebygget_grupper: { finnes: '1', verdi: '4:40, 5:40, 6:40, 7:40, 19:50, 8:150' } });
sjekk('alle med sats: lagret {gruppe_id: prosent}, kappet til 100', JSON.stringify(JSON.parse(l.helsebygget)) === JSON.stringify({ 4: 40, 5: 40, 6: 40, 7: 40, 8: 100, 19: 50 }), l.helsebygget);
html = await side('admin');
sjekk('lagret liste vises med satsene (et tidligere avslag er glemt)', /name="helsebygget_grupper\[verdi\]" value="4:40, 5:40, 6:40, 7:40, 8:100, 19:50"/.test(html) && !html.includes('amendo-helsebygget-avvist'));
l = await lagre({ helsebygget_grupper: { finnes: '1', verdi: '4:40, x:9' } });
sjekk('noe som ikke kan tolkes: avvist, lista uendret', JSON.parse(l.helsebygget)['19'] === 50, l.helsebygget);
html = await side('admin');
sjekk('… og avslaget vises én gang', html.includes('amendo-helsebygget-avvist') && html.includes('x:9'));
await klargjor({ helsebygget: { 4: 0, 5: 0, 6: 0, 7: 0 } });
html = await side('admin');
sjekk('lista fra garcon.no ({"4":0,…}): advarsel om 0 %', html.includes('amendo-helsebygget-null') && html.includes('Gruppe 4, 5, 6, 7 har 0 %'));
await klargjor({ helsebygget: { 4: 40, 5: 40, 6: 40, 7: 40, 8: 100, 19: 50 } });
html = await side('shop_manager');
sjekk('butikkadministrator ser ikke feltet', !html.includes('helsebygget_grupper[verdi]') && html.includes('id="b2b-tellix"'));
l = await lagre({ helsebygget_grupper: { finnes: '1', verdi: '14:99' } }, 'shop_manager');
sjekk('… og kan ikke lagre det', JSON.stringify(JSON.parse(l.helsebygget)) === JSON.stringify({ 4: 40, 5: 40, 6: 40, 7: 40, 8: 100, 19: 50 }), l.helsebygget);
l = await lagre({ butikk_navn: 'Test' });
sjekk('lagring uten feltet: urørt', JSON.parse(l.helsebygget)['19'] === 50, l.helsebygget);
r = await kunde('nav@example.com');
sjekk('etter lagring: gruppe 4 er Helsebygget igjen', r.json?.helsebygget?.prosent === 40 && r.json?.nettbutikk === null, r.json);

// ── To kilder for Helsebygget ───────────────────────────────────────────────
console.log('\nTo kilder: Helsebygget-lista vs. amendo_kundegruppe_config');
const KG = { 14: { navn: 'Bedriftskunder', faktura: 1, b2b: 1, helsebygget: 0, helsebygget_rabatt: 0 }, 4: { navn: 'NAV', faktura: 0, b2b: 0, helsebygget: 1, helsebygget_rabatt: 30 } };
await klargjor({ kundegrupper: KG });
const settings = async () => (await fetch(B + '/amendo-settings/v1/settings')).json();
let kg = (await settings()).kundegruppe_config;
sjekk('lista lagret: Helsebygget og sats fra lista (4 → 40, ikke 30)', kg['4']?.helsebygget === 1 && kg['4']?.helsebygget_rabatt === 40, kg);
sjekk('… grupper bare i lista kommer med (19 → 50)', kg['19']?.helsebygget === 1 && kg['19']?.helsebygget_rabatt === 50 && kg['19']?.faktura === 0, kg['19']);
sjekk('… og grupper som ikke står i lista, har ikke Helsebygget', kg['14']?.helsebygget === 0 && kg['14']?.faktura === 1, kg['14']);
html = await side('admin');
const kgDel = html.slice(html.indexOf('id="b2b-kundegrupper"'), html.indexOf('id="b2b-tellix"'));
sjekk('admin: merknad om at lista gjelder', kgDel.includes('amendo-helsebygget-merknad'));
sjekk('admin: Helsebygget-kolonnene skrivebeskyttet (disabled, ikke sendt)', !/\[helsebygget\]"|\[helsebygget_rabatt\]"/.test(kgDel) && (kgDel.match(/kundegruppe-laast/g) || []).length >= 4, (kgDel.match(/kundegruppe-laast/g) || []).length);
l = await lagre({ kundegruppe_config: { finnes: '1', rader: [{ id: '14', faktura: '1', b2b: '1', helsebygget: '1', helsebygget_rabatt: '99' }, { id: '4', helsebygget: '' }] } });
sjekk('lagring: Helsebygget-feltene fra skjemaet ignoreres, det lagrede står', l.kundegrupper?.['14']?.helsebygget === 0 && l.kundegrupper?.['4']?.helsebygget === 1 && l.kundegrupper?.['4']?.helsebygget_rabatt === 30, l.kundegrupper);
await klargjor({ kundegrupper: {} });
kg = (await settings()).kundegruppe_config;
sjekk('tom tabell forblir {} selv med lista (frontenden bytter ikke kilde)', kg && Object.keys(kg).length === 0, kg);
await klargjor({ helsebygget: null, store_groups: { 4: 'helsebygget' }, kundegrupper: KG });
kg = (await settings()).kundegruppe_config;
sjekk('uten lagret liste (bare amendo-store-groups): som før — 4 → 30, ingen 19', kg['4']?.helsebygget_rabatt === 30 && !('19' in kg), kg);
html = await side('admin');
sjekk('… og ingen merknad, kolonnene kan redigeres', !html.includes('amendo-helsebygget-merknad') && /kundegruppe_config\[rader\]\[\d+\]\[helsebygget\]/.test(html));
await klargjor({ helsebygget: { 4: 40, 5: 40, 6: 40, 7: 40, 8: 100, 19: 50 }, store_groups: null, kundegrupper: null });

// ── Admin: kundegruppene skrivebeskyttet ────────────────────────────────────
console.log('\nAdmin: Kundegrupper fra Tellix');
const tellixDel = h => h.slice(h.indexOf('id="b2b-tellix"'), h.indexOf('id="b2b-helsebygget"') > 0 ? h.indexOf('id="b2b-helsebygget"') : undefined);
html = tellixDel(await side('admin'));
sjekk('gruppene med navn og sats', html.includes('Bedriftskunder') && html.includes('10 %') && html.includes('NAV Lillestrøm'));
sjekk('kategorisatsen med kategorinavn', html.includes('Ringer: 15 %'));
sjekk('inaktiv gruppe vises ikke', !html.includes('Faktura 25'));
sjekk('Helsebygget-gruppene merket', html.includes('Bare Helsebygget (40 %)') && html.includes('Bare Helsebygget (50 %)'));
sjekk('skrivebeskyttet: ingen felter', !/<input|<select|<textarea/.test(html));

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
