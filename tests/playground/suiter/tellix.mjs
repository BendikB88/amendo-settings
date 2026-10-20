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
];
const KUNDER = [
  { id: 'T1', uuid: 'U1', groupId: 14, email: 'bedrift@example.com', phone: '+4741128080' },
  { id: 'T2', uuid: 'U2', groupId: 4, email: 'nav@example.com', phone: '+4790000001' },
  { id: 'T5', uuid: 'U5', groupId: 14, email: 'annen@tellix.no', phone: '+4790000005' },
  { id: 'T3', uuid: 'U3', groupId: 3, email: 'faktura@example.com', phone: '' },
];
const HELSEBYGGET = { 4: 40, 5: 40, 6: 40, 7: 40, 19: 50 };

await opt({ amendo_kasse_secret: SECRET });
await klargjor({ moduler: ['b2b'], tellix: '1', grupper: GRUPPER, kunder: KUNDER, helsebygget: HELSEBYGGET, sov: null, kast: null, store_groups: null, nullstill: true });
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
sjekk('telefonen sendt renset (+47XXXXXXXX) sammen med e-posten', JSON.stringify(s.args.at(-1)) === '["bedrift@example.com","+4741128080"]', s.args);

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
sjekk('… og feltene tømmes ikke', m.meta._tellixGroupId === '14' && m.meta._tellixCustomerId === 'T9', m.meta);

r = await kunde('faktura@example.com');
sjekk('inaktiv gruppe i Tellix: ingen rabatt, ingen navn', r.json?.gruppe_id === 3 && r.json?.gruppe_navn === null && r.json?.nettbutikk === null && r.json?.helsebygget === null, r.json);

s = await tilstand();
sjekk('get_tellix_customer_groups kalt én gang for alle oppslagene over (transient)', s.navnkall === 1, s.navnkall);

r = await rute('rabatt/kunde/999999');
sjekk('ukjent WordPress-bruker: 200 med null', r.status === 200 && r.json?.gruppe_id === null && r.json?.nettbutikk === null, r.json);

await klargjor({ kast: '1' });
r = await kunde('bedrift@example.com');
m = await brukermeta('bedrift@example.com');
sjekk('Tellix kaster: 200 med lagret gruppe, feltene urørt', r.status === 200 && r.json?.gruppe_id === 14 && m.meta._tellixCustomerId === 'T1', [r.status, r.json, m.meta]);
await klargjor({ kast: null });

// ── rabatt/telefon ──────────────────────────────────────────────────────────
console.log('\nrabatt/telefon');
r = await telefon('411 28 080');
sjekk('200 med renset nummer', r.status === 200 && r.json?.telefon === '+4741128080', r.json);
sjekk('gruppen fra Tellix', r.json?.gruppe_id === 14 && r.json?.nettbutikk?.prosent === 10, r.json);
s = await tilstand();
sjekk('slått opp i Tellix på nummeret alene (ikke via WordPress-brukere)', JSON.stringify(s.args.at(-1)) === '["","+4741128080"]', s.args.at(-1));
r = await telefon('+47 900 00 001');
sjekk('Helsebygget-gruppe på telefon: helsebygget-satsen, ingen nettbutikk', r.json?.gruppe_id === 4 && r.json?.helsebygget?.prosent === 40 && r.json?.nettbutikk === null, r.json);
r = await telefon('0047 99999999');
sjekk('ukjent nummer: gruppe null', r.status === 200 && r.json?.gruppe_id === null && r.json?.telefon === '+4799999999', r.json);
r = await telefon('abc');
sjekk('ugyldig nummer: 400', r.status === 400, r.status);

console.log('\nGrense per nummer (10 per 15 min)');
await klargjor({ nullstill: true });
let koder = [];
for (let i = 0; i < 11; i++) koder.push((await telefon('41128080')).status);
sjekk('10 × 200, så 429', koder.slice(0, 10).every(k => k === 200) && koder[10] === 429, koder);
r = await telefon('41128080');
sjekk('429 har Retry-After og JSON', r.status === 429 && Number(r.h.get('retry-after')) > 0 && r.json?.code === 'amendo_rabatt_rate_limit', [r.status, r.h.get('retry-after')]);
r = await telefon('90000001');
sjekk('et annet nummer har egen kvote', r.status === 200, r.status);

// ── Treg Tellix ─────────────────────────────────────────────────────────────
console.log('\nTreg Tellix (> 2 s): mellomlagring i 10 min');
await klargjor({ nullstill: true, sov: '2.3' });
r = await telefon('90000005');
s = await tilstand();
sjekk('første kall går til Tellix og markerer treg', r.json?.gruppe_id === 14 && s.kall === 1 && s.treg, s);
await klargjor({ sov: null });
r = await telefon('90000005');
s = await tilstand();
sjekk('samme oppslag igjen: fra mellomlageret, ikke Tellix', r.json?.gruppe_id === 14 && s.kall === 1, s);
await klargjor({ nullstill: true });
r = await telefon('90000005');
s = await tilstand();
sjekk('rask Tellix: ingen mellomlagring', r.json?.gruppe_id === 14 && s.kall === 1 && !s.treg, s);
r = await telefon('90000005');
s = await tilstand();
sjekk('… og neste kall går til Tellix igjen', s.kall === 2, s);

// ── Helsebygget-gruppene ────────────────────────────────────────────────────
console.log('\nHelsebygget-gruppene');
await klargjor({ helsebygget: null, store_groups: null });
r = await kunde('nav@example.com');
sjekk('ikke satt opp: 503 (ingen rabatt kan gis feil)', r.status === 503 && r.json?.code === 'amendo_helsebygget_ikke_satt', [r.status, r.json]);
r = await telefon('90000001');
sjekk('… også rabatt/telefon', r.status === 503, r.status);

await klargjor({ store_groups: { 1: '0', 2: '0', 3: '0', 4: 'helsebygget', 5: 'helsebygget', 6: 'helsebygget', 7: 'helsebygget', 14: '0' } });
r = await kunde('nav@example.com');
sjekk('utledet av amendo-store-groups («helsebygget»): gruppe 4 får bare Helsebygget', r.status === 200 && r.json?.gruppe_id === 4 && r.json?.helsebygget?.prosent === 40 && r.json?.nettbutikk === null, [r.status, r.json]);
r = await kunde('bedrift@example.com');
sjekk('… og gruppe 14 («0») får nettbutikk-satsen', r.json?.gruppe_id === 14 && r.json?.nettbutikk?.prosent === 10 && r.json?.helsebygget === null, r.json);

let html = await side('admin');
sjekk('admin ser feltet, forhåndsutfylt fra amendo-store-groups', html.includes('name="helsebygget_grupper[verdi]"') && /name="helsebygget_grupper\[verdi\]" value="4:40, 5:40, 6:40, 7:40"/.test(html));
let l = await lagre({ helsebygget_grupper: { finnes: '1', verdi: '4:40, 5:40, 6:40, 7:40, 19:50, x:9, 0:10, 8:150' } });
sjekk('admin lagrer {gruppe_id: prosent}, renset og kappet til 100', JSON.stringify(JSON.parse(l.helsebygget)) === JSON.stringify({ 4: 40, 5: 40, 6: 40, 7: 40, 8: 100, 19: 50 }), l.helsebygget);
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
sjekk('amendo-tellix-loggen har ingen e-post eller telefonnummer', !/@|\+47|4112|9000/.test(logg.tekst || ''), logg.tekst);
sjekk('… men har tidsmålingen', /search_customers tok \d/.test(logg.tekst || ''), logg.tekst);

await klargjor({ moduler: null, tellix: null, nullstill: true });
ferdig();
