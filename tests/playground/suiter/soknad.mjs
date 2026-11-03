// 1.4.28: POST /b2b-soknad og `_`-feltene via wc/v3. WooCommerce 11 lagrer
// ikke `_`-nøkler fra wc/v3/customers (is_protected_meta), så garcon-b2b sender
// søknaden til amendo-settings, og `_tellixGroupId` (B2B/Helsebygget) og
// `_vipps_syntetisk_epost` (alle) slippes gjennom. Se includes/kundefelter.php
// og «POST /b2b-soknad» i includes/moduler/b2b.php.
import { B, get, sjekk, ferdig } from '../lib/felles.mjs';

const SECRET = 'soknad-hemmelig-1428';
const opt = o => get('/amendo-test/v1/opt', o);
const moduler = m => get('/amendo-test/v1/v1414-klargjor', { moduler: m });
const lag = brukere => get('/amendo-test/v1/v1428-klargjor', { brukere });
const meta = async (...eposter) => get('/amendo-test/v1/v1428-meta', { eposter });
const wc = (metode, sti, body) => get('/amendo-test/v1/v1428-wc', { metode, sti, body });
const side = async () => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=admin')).text();
const E = n => `${n}@s1428.no`;

const soknad = async (body, secret = SECRET) => {
  const r = await fetch(B + '/amendo-settings/v1/b2b-soknad', {
    method: 'POST',
    headers: { 'content-type': 'application/json', ...(secret === null ? {} : { 'X-Amendo-Secret': secret }) },
    body: JSON.stringify(body),
  });
  const tekst = await r.text();
  let json = null; try { json = JSON.parse(tekst); } catch {}
  return { status: r.status, json, tekst };
};
const bedriftSok = async orgnr => (await fetch(B + '/amendo-settings/v1/bedrift-sok&org_nr=' + orgnr, { headers: { 'X-Amendo-Secret': SECRET } })).json();
const avvist = r => (r.status === 401 || r.status === 403) && r.json?.ok !== true;

await get('/amendo-test/v1/setup');
await opt({ amendo_kasse_secret: SECRET });
await moduler(['b2b']);
const { ider } = await lag([
  { epost: E('ny'), meta: {} },
  { epost: E('igjen'), meta: {} },
  { epost: E('venter'), meta: { _b2b_status: 'venter', _orgnr_soknad: '922222222', _b2b_sokt: '2026-01-01T00:00:00+00:00' } },
  { epost: E('godkjent'), meta: { _b2b_status: 'godkjent', billing_org_number: '911111111' } },
  { epost: E('billing'), meta: { billing_org_number: '911111112' } },
  { epost: E('avvist'), meta: { _b2b_status: 'avvist', _b2b_avvist_orgnr: '911111113' } },
  { epost: E('butikk'), rolle: 'shop_manager', meta: {} },
  { epost: E('wc'), meta: { _b2b_status: 'venter', _orgnr_soknad: '944444444' } },
  { epost: E('vipps'), meta: {} },
]);
const id = n => ider[E(n)];

// ── POST /b2b-soknad: tilgang ───────────────────────────────────────────────
console.log('b2b-soknad: tilgang');
let r = await soknad({ bruker_id: id('ny'), org_nr: '911222333' }, null);
sjekk('uten X-Amendo-Secret: avvist', avvist(r), r.status);
r = await soknad({ bruker_id: id('ny'), org_nr: '911222333' }, 'feil');
sjekk('feil secret: avvist', avvist(r), r.status);
await opt({ amendo_kasse_secret: '' });
r = await soknad({ bruker_id: id('ny'), org_nr: '911222333' }, '');
sjekk('tom amendo_kasse_secret: avvist, også med tom header', avvist(r), r.status);
await opt({ amendo_kasse_secret: SECRET });
let m = await meta(E('ny'));
sjekk('… og ingenting er skrevet', m[E('ny')]._b2b_status === '' && m[E('ny')]._orgnr_soknad === '', m[E('ny')]);

// ── Validering ──────────────────────────────────────────────────────────────
console.log('\nb2b-soknad: validering');
r = await soknad({ bruker_id: id('ny'), org_nr: '12345' });
sjekk('org.nr som ikke er ni siffer: 400 ugyldig_orgnr', r.status === 400 && r.json?.code === 'amendo_b2b_soknad_ugyldig_orgnr', r.json);
r = await soknad({ bruker_id: id('ny'), org_nr: ['911222333'] });
sjekk('org.nr som liste: 400', r.status === 400, r.status);
r = await soknad({ bruker_id: 99999999, org_nr: '911222333' });
sjekk('ukjent bruker: 400 (ikke 404, som frontenden leser som «ruten finnes ikke»)', r.status === 400 && r.json?.code === 'amendo_b2b_soknad_ukjent_bruker', r.json);
r = await soknad({ org_nr: '911222333' });
sjekk('uten bruker_id: 400', r.status === 400 && r.json?.code === 'amendo_b2b_soknad_ukjent_bruker', r.json);

// ── Søknaden lagres ─────────────────────────────────────────────────────────
console.log('\nb2b-soknad: lagres');
r = await soknad({ bruker_id: id('ny'), org_nr: '911 222 333' });
sjekk('ny kunde: 200 { ok, status: venter, org_nr } med bare sifrene (det frontenden krever)', r.status === 200 && JSON.stringify(r.json) === '{"ok":true,"status":"venter","org_nr":"911222333"}', r.tekst);
m = (await meta(E('ny')))[E('ny')];
sjekk('_orgnr_soknad, _b2b_status = venter og _b2b_sokt (ISO-dato) står i databasen', m._orgnr_soknad === '911222333' && m._b2b_status === 'venter' && /^\d{4}-\d\d-\d\dT/.test(m._b2b_sokt), m);
sjekk('ingen tilgang: billing_org_number er ikke satt', m.billing_org_number === '', m);
const sokt = m._b2b_sokt;
sjekk('bedrift-sok svarer «søknad til behandling» for nummeret', JSON.stringify(await bedriftSok('911222333')) === '{"finnes":true,"type":"soknad"}');
const html = await side();
const soknader = html.slice(html.indexOf('id="b2b-soknader"'), html.indexOf('id="b2b-kunder"'));
sjekk('søknaden står i B2B → Søknader', soknader.includes(`data-bruker="${id('ny')}"`));
r = await soknad({ bruker_id: id('ny'), org_nr: '911222333' });
m = (await meta(E('ny')))[E('ny')];
sjekk('samme kall en gang til: 200, og _b2b_sokt flyttes ikke', r.status === 200 && r.json?.ok === true && m._b2b_sokt === sokt, [r.json, m._b2b_sokt, sokt]);
r = await soknad({ bruker_id: id('venter'), org_nr: '933333333' });
m = (await meta(E('venter')))[E('venter')];
sjekk('søknad som venter, nytt nummer: nummeret byttes, opprinnelig dato står', r.status === 200 && r.json?.org_nr === '933333333' && m._orgnr_soknad === '933333333' && m._b2b_sokt === '2026-01-01T00:00:00+00:00', [r.json, m]);

// ── 409 ─────────────────────────────────────────────────────────────────────
console.log('\nb2b-soknad: kan ikke søke');
for (const [n, kode, tekst] of [
  ['godkjent', 'amendo_b2b_soknad_godkjent', 'godkjent bedrift'],
  ['billing', 'amendo_b2b_soknad_godkjent', 'har billing_org_number (uten _b2b_status)'],
  ['avvist', 'amendo_b2b_soknad_avvist', 'avvist'],
  ['butikk', 'amendo_b2b_soknad_ikke_kunde', 'butikkbruker (shop_manager)'],
]) {
  const før = (await meta(E(n)))[E(n)];
  r = await soknad({ bruker_id: id(n), org_nr: '955555555' });
  const etter = (await meta(E(n)))[E(n)];
  sjekk(`${tekst}: 409 ${kode.replace('amendo_b2b_soknad_', '')}, ingenting endret`, r.status === 409 && r.json?.code === kode && r.json?.ok === false && JSON.stringify(før) === JSON.stringify(etter), [r.json, før, etter]);
}
const adminId = (await wc('GET', '/wp/v2/users/me')).data?.id;
r = await soknad({ bruker_id: adminId, org_nr: '955555555' });
sjekk('administrator: 409 ikke_kunde', r.status === 409 && r.json?.code === 'amendo_b2b_soknad_ikke_kunde', [adminId, r.json]);

// ── `_`-feltene via wc/v3 ──────────────────────────────────────────────────
console.log('\nwc/v3/customers: _tellixGroupId og _vipps_syntetisk_epost (B2B på)');
r = await wc('PUT', `/wc/v3/customers/${id('wc')}`, { meta_data: [
  { key: '_tellixGroupId', value: '14' },
  { key: '_vipps_syntetisk_epost', value: '1' },
  { key: '_b2b_status', value: 'godkjent' },
  { key: '_orgnr_soknad', value: '900000000' },
] });
m = (await meta(E('wc')))[E('wc')];
sjekk('PUT: _tellixGroupId og _vipps_syntetisk_epost lagres', r.status === 200 && m._tellixGroupId === '14' && m._vipps_syntetisk_epost === '1', [r.status, m]);
sjekk('PUT: _b2b_status og _orgnr_soknad lagres IKKE (søknaden går bare via b2b-soknad)', m._b2b_status === 'venter' && m._orgnr_soknad === '944444444', m);
r = await wc('GET', `/wc/v3/customers/${id('wc')}`);
const nokler = (r.data?.meta_data || []).map(x => x.key);
sjekk('GET: _tellixGroupId og _vipps_syntetisk_epost kommer med, søknadsnøklene ikke', nokler.includes('_tellixGroupId') && nokler.includes('_vipps_syntetisk_epost') && !nokler.includes('_b2b_status') && !nokler.includes('_orgnr_soknad'), nokler);
sjekk('GET: verdiene er de lagrede', r.data.meta_data.find(x => x.key === '_tellixGroupId')?.value === '14' && r.data.meta_data.find(x => x.key === '_vipps_syntetisk_epost')?.value === '1', r.data.meta_data);
const nyvipps = E('nyvipps' + Date.now());
r = await wc('POST', '/wc/v3/customers', { email: nyvipps, password: 'Pg-1428-test!', meta_data: [{ key: '_vipps_syntetisk_epost', value: '1' }, { key: '_tellixGroupId', value: '4' }] });
m = (await meta(nyvipps))[nyvipps];
sjekk('POST (ny kunde): begge feltene lagres', r.status === 201 && m?._vipps_syntetisk_epost === '1' && m._tellixGroupId === '4', [r.status, m]);
let b = await get('/amendo-test/v1/v1428-beskyttet', { par: [['_tellixGroupId', 'post'], ['_vipps_syntetisk_epost', 'post'], ['_b2b_status', 'user'], ['_tellixCustomerId', 'user'], ['_tellixGroupId', 'user']] });
sjekk('bare for brukere: på innlegg/ordrer og for andre nøkler er de fortsatt beskyttet', JSON.stringify(b) === '[true,true,true,true,false]', b);

// ── B2B av ──────────────────────────────────────────────────────────────────
console.log('\nB2B av');
await moduler([]);
r = await soknad({ bruker_id: id('igjen'), org_nr: '966666666' });
m = (await meta(E('igjen')))[E('igjen')];
sjekk('b2b-soknad finnes ikke: 404, ingenting skrevet (frontenden ber kunden ringe)', r.status === 404 && m._b2b_status === '', [r.status, m]);
r = await wc('PUT', `/wc/v3/customers/${id('vipps')}`, { meta_data: [{ key: '_vipps_syntetisk_epost', value: '1' }, { key: '_tellixGroupId', value: '7' }] });
m = (await meta(E('vipps')))[E('vipps')];
sjekk('_vipps_syntetisk_epost lagres fortsatt (alle butikker), _tellixGroupId ikke', r.status === 200 && m._vipps_syntetisk_epost === '1' && m._tellixGroupId === '', m);
r = await wc('GET', `/wc/v3/customers/${id('wc')}`);
const nokler2 = (r.data?.meta_data || []).map(x => x.key);
sjekk('GET: _tellixGroupId er skjult igjen, _vipps_syntetisk_epost vises', !nokler2.includes('_tellixGroupId') && nokler2.includes('_vipps_syntetisk_epost'), nokler2);
await moduler(['helsebygget']);
b = await get('/amendo-test/v1/v1428-beskyttet', { par: [['_tellixGroupId', 'user']] });
sjekk('bare Helsebygget på: _tellixGroupId slippes gjennom (b2b-tellix.php lastes)', JSON.stringify(b) === '[false]', b);
await moduler([]);

ferdig();
