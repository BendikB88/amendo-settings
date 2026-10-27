// 1.4.22: Tellix-kunde ved B2B-godkjenning. Oppslag på org.nr og e-post i
// kundelista (stubben i mu/tellix-stub.php), opprettelse via Tellix sitt
// web-API og Enhetsregisteret (besvart i mu/v1422-test.php — ingen ekte kall),
// valg når treffene er flere, feil som ikke stopper godkjenningen, «Prøv
// igjen», massehandlingen i Brukere og Tellix-tilgangen. Se
// includes/moduler/b2b-tellix-kunder.php.
//
// ⚠ Moduler og stubbene lastes når pluginen lastes: tilstanden settes i ett
// kall og sjekkes i det neste.
import { B, get, sjekk, ferdig } from '../lib/felles.mjs';

const klargjor = body => get('/amendo-test/v1/v1414-klargjor', body);
const k1422 = body => get('/amendo-test/v1/v1422-klargjor', body);
const opt = o => get('/amendo-test/v1/opt', o);
const bruker = (epost, meta) => get('/amendo-test/v1/bedrift-bruker', { epost, meta });
const meta = async epost => (await fetch(B + '/amendo-test/v1/v1422-meta&epost=' + encodeURIComponent(epost))).json();
const tilstand = () => get('/amendo-test/v1/v1422-tilstand');
const behandle = body => get('/amendo-test/v1/b2b-behandle', body);
const handling = body => get('/amendo-test/v1/v1422-handling', body);
const web = body => get('/amendo-test/v1/v1422-web', body);
const masse = body => get('/amendo-test/v1/v1422-masse', body);
const side = async (q = '') => (await fetch(B + '/amendo-test/v1/moduler-side' + q)).text();
const logg = async () => (await get('/amendo-test/v1/v1414-logg')).tekst || '';

const GRUPPER = [
  { id: 14, name: 'Bedriftskunder', discountPercent: 10 },
  { id: 4, name: 'NAV Lillestrøm', discountPercent: 40 },
  { id: 1, name: 'Bedriftsrabatt 10 %', discountPercent: 10 },
];
const KUNDER = [
  { id: 'T10', uuid: 'U10', groupId: 14, email: 'kontakt@eksisterende.no', orgnr: '911111111', name: 'Eksisterende AS' },
  { id: 'T20', uuid: 'U20', groupId: 14, email: 'a@to.no', orgnr: '922222222', name: 'To AS avd. Oslo' },
  { id: 'T21', uuid: 'U21', groupId: 4, email: 'b@to.no', orgnr: '922 222 222', name: 'To AS avd. Bergen' },
  { id: 'T30', uuid: 'U30', groupId: 1, email: 'Epost@Treff.no', orgnr: '', name: 'Treff på e-post' },
  { id: 'T40', uuid: 'U40', groupId: 14, email: 'annen@bedrift.no', orgnr: '944444444', name: 'Annen bedrift' },
];
const EGEN = { url: 'https://tellix.test/', versjon: 'v3', bruker: 'amendo', passord: 'hemmelig-pw-1422', nokkel: 'hemmelig-key-1422' };
const BRREG = { navn: 'NY BEDRIFT AS', forretningsadresse: { adresse: ['Storgata 1', ''], postnummer: '0155', poststed: 'OSLO' } };
const venter = (orgnr, firma, ekstra = {}) => ({ _b2b_status: 'venter', _orgnr_soknad: orgnr, billing_company: firma, ...ekstra });
const id = async epost => (await meta(epost)).id;
const godkjenn = async (epost, gruppe) => {
  const i = await id(epost);
  return behandle({ post: { godkjenn: String(i), ...(gruppe === undefined ? {} : { gruppe: { [i]: String(gruppe) } }) } });
};
const webkall = async () => (await tilstand()).kall.filter(k => k.sti);
const tellixParam = til => (/[?&]tellix=([^&#]+)/.exec(til || '') || [])[1];

await get('/amendo-test/v1/setup');
await klargjor({ moduler: ['b2b'], tellix: '1', grupper: GRUPPER, kunder: KUNDER, api_feil: null, side: null, sync_size: null, liste_slett: true, nullstill: true });
await k1422({ web: { token: 'ok' }, egen: EGEN, sync: null, brreg: BRREG, kall_slett: true });

// ── Søknadslisten ───────────────────────────────────────────────────────────
console.log('Søknadslisten');
await bruker('orgnr@tk1422.no', venter('911 111 111', 'Eksisterende AS'));
let html = await side();
const rad = html.slice(html.indexOf('id="b2b-soknader"'), html.indexOf('id="b2b-kunder"'));
sjekk('kundegruppe-valg per søknad, i søknadsskjemaet', /<select form="amendo-b2b-behandle" name="gruppe\[\d+\]"/.test(rad) && rad.includes('Bedriftskunder (14)') && rad.includes('value="0"'), rad.slice(0, 400));
sjekk('kortet Tellix-kobling og eget skjema finnes', html.includes('id="b2b-tellix"') && html.includes('id="amendo-b2b-tellix"'));

// ── Ett treff ───────────────────────────────────────────────────────────────
console.log('\nEtt treff i Tellix');
let b = await godkjenn('orgnr@tk1422.no', 4);
let m = await meta('orgnr@tk1422.no');
sjekk('org.nr (med mellomrom i søknaden): koblet til T10', m.meta._tellixCustomerId === 'T10' && m.meta._tellixCustomerUuId === 'U10', m.meta);
sjekk('… gruppen fra Tellix (14), ikke den valgte (4)', m.meta._tellixGroupId === '14', m.meta);
sjekk('… og admin får vite at valget ikke ble brukt', /Du valgte NAV Lillestrøm/.test(m.meta._b2b_tellix?.melding || ''), m.meta._b2b_tellix);
sjekk('godkjenningen er lagret, omdirigering med b2b=godkjent&tellix=koblet', m.meta._b2b_status === 'godkjent' && m.meta.billing_org_number === '911111111' && /b2b=godkjent/.test(b.til) && tellixParam(b.til) === 'koblet', [b, m.meta]);
sjekk('ingen kall til web-API-et når kunden finnes', (await webkall()).length === 0);

await bruker('epost@treff.no', venter('933333333', 'Treff AS'));
await godkjenn('epost@treff.no', '');
m = await meta('epost@treff.no');
sjekk('ikke på org.nr, men e-post (store/små bokstaver): koblet til T30 med gruppe 1', m.meta._tellixCustomerId === 'T30' && m.meta._tellixGroupId === '1' && m.meta._b2b_tellix_status === 'koblet', m.meta);

// ── Flere treff: admin velger ───────────────────────────────────────────────
console.log('\nFlere treff');
await bruker('to@tk1422.no', venter('922222222', 'To AS'));
b = await godkjenn('to@tk1422.no', 14);
m = await meta('to@tk1422.no');
sjekk('to kunder med org.nr: valg, ikke koblet, ikke opprettet', tellixParam(b.til) === 'valg' && m.meta._b2b_tellix_status === 'valg' && m.meta._tellixCustomerId === '' && (await webkall()).length === 0, m.meta);
sjekk('… godkjent likevel', m.meta._b2b_status === 'godkjent');
sjekk('… kandidatene T20 og T21 lagret', JSON.stringify((m.meta._b2b_tellix?.kandidater || []).map(k => k.id)) === '["T20","T21"]', m.meta._b2b_tellix);
html = await side('&tellix=valg&tb=' + m.id);
let kort = html.slice(html.indexOf('id="b2b-tellix"'));
sjekk('kortet viser valget med begge kundene og knappene', kort.includes('value="T20"') && kort.includes('value="T21"') && kort.includes('name="koble"') && kort.includes('name="ny"'), kort.slice(0, 600));
sjekk('beskjed øverst (advarsel)', /amendo-b2b-melding-advarsel" id="b2b-tellix-melding">Tellix: 2 Tellix-kunder har samme org.nr/.test(html));
b = await handling({ post: { koble: String(m.id), kunde: { [m.id]: 'T10' }, gruppe: { [m.id]: '14' } } });
m = await meta('to@tk1422.no');
sjekk('kunde som ikke var blant kandidatene (T10): avvist, valget står', m.meta._tellixCustomerId === '' && m.meta._b2b_tellix_status === 'valg' && (m.meta._b2b_tellix?.kandidater || []).length === 2, m.meta);
b = await handling({ post: { koble: String(m.id), kunde: { [m.id]: 'T21' }, gruppe: { [m.id]: '14' } }, rolle: 'customer' });
sjekk('kunde-rolle: stoppet', b.stoppet === true, b);
b = await handling({ post: { koble: String(m.id), kunde: { [m.id]: 'T21' } }, nonce: false });
sjekk('uten nonce: stoppet', b.stoppet === true, b);
b = await handling({ post: { koble: String(m.id), kunde: { [m.id]: 'T21' }, gruppe: { [m.id]: '14' } } });
m = await meta('to@tk1422.no');
sjekk('admin velger T21: koblet med Tellix-gruppen 4', m.meta._tellixCustomerId === 'T21' && m.meta._tellixCustomerUuId === 'U21' && m.meta._tellixGroupId === '4' && tellixParam(b.til) === 'koblet', [b, m.meta]);

await bruker('annen@bedrift.no', venter('955555555', 'Ikke Annen AS'));
await godkjenn('annen@bedrift.no', 14);
m = await meta('annen@bedrift.no');
sjekk('e-post-treff med et annet org.nr: valg, ikke koblet', m.meta._b2b_tellix_status === 'valg' && m.meta._tellixCustomerId === '' && /annet org.nr/.test(m.meta._b2b_tellix?.melding || ''), m.meta);
b = await handling({ post: { ny: String(m.id), gruppe: { [m.id]: '14' } } });
m = await meta('annen@bedrift.no');
sjekk('«Opprett ny»: ny kunde i Tellix tross treffet', tellixParam(b.til) === 'opprettet' && /^\d+$/.test(m.meta._tellixCustomerId) && m.meta._tellixCustomerId !== 'T40', m.meta);

// ── Ingen treff: opprett ────────────────────────────────────────────────────
console.log('\nIngen treff: opprettes i Tellix');
await k1422({ kall_slett: true });
await bruker('ny@tk1422.no', venter('966666666', '', { billing_phone: '+4791234567', first_name: 'Kari' }));
b = await godkjenn('ny@tk1422.no', 14);
m = await meta('ny@tk1422.no');
let kall = await webkall();
const tok = kall.find(k => k.sti === 'getaccesstoken');
const lagre = kall.find(k => k.sti === 'customer/save');
const rec = lagre?.body?.data?.[0] || {};
sjekk('innlogging som sync-pluginen: brukernavn, passord og API-nøkkel til web/v3/getaccesstoken', tok?.url === 'https://tellix.test/web/v3/getaccesstoken' && tok.body.username === 'amendo' && tok.body.password_satt && tok.body.apikey_satt, tok);
sjekk('customer/save med tokenet som Authorization og { data: [kunde] }', lagre?.auth === 'TOKEN-1422' && Array.isArray(lagre?.body?.data), lagre);
sjekk('felter: navn og adresse fra Enhetsregisteret, org.nr, e-post, telefon, Norge, aktiv', rec.name === 'NY BEDRIFT AS' && rec.customerName === 'NY BEDRIFT AS' && rec.address === 'Storgata 1' && rec.zip === '0155' && rec.location === 'OSLO'
  && rec.orgNumber === '966666666' && rec.email === 'ny@tk1422.no' && rec.phoneNumber === '+4791234567' && rec.country === 'Norge' && rec.isActive === true, rec);
sjekk('kundegruppen admin valgte: customerGroupId 14', rec.customerGroupId === 14, rec);
const nyId = m.meta._tellixCustomerId;
sjekk('customer/update/<id> etterpå, som sync-pluginen', /^\d+$/.test(nyId) && kall.some(k => k.sti === 'customer/update/' + nyId), kall.map(k => k.sti));
sjekk('Enhetsregisteret slått opp på org.nr', (await tilstand()).kall.some(k => /data\.brreg\.no\/enhetsregisteret\/api\/enheter\/966666666$/.test(k.url || '')));
sjekk('brukeren: kunde-ID, UUID (fra lista etterpå) og gruppe 14 — faktura virker', m.meta._tellixCustomerUuId === 'UUID-' + nyId && m.meta._tellixGroupId === '14', m.meta);
sjekk('status opprettet, godkjent, tellix=opprettet', m.meta._b2b_tellix_status === 'opprettet' && m.meta._b2b_status === 'godkjent' && tellixParam(b.til) === 'opprettet', [b, m.meta]);
html = await side('&tellix=opprettet&tb=' + m.id);
sjekk('beskjed: opprettet med gruppenavnet', html.includes(`amendo-b2b-melding-ok" id="b2b-tellix-melding">Tellix: Opprettet i Tellix som kunde ${nyId} med Bedriftskunder.`));
sjekk('Aktive B2B-kunder viser Tellix-kunden', html.includes(`<td class="b2b-tellix-kunde">${nyId}</td>`));

// Firmanavn og adresse på brukeren går foran Enhetsregisteret.
await k1422({ kall_slett: true });
await bruker('adresse@tk1422.no', venter('966666667', 'Egen AS', { billing_address_1: 'Kirkeveien 2', billing_postcode: '0368', billing_city: 'Oslo' }));
await godkjenn('adresse@tk1422.no', 0);
kall = await tilstand();
const rec2 = kall.kall.find(k => k.sti === 'customer/save')?.body?.data?.[0] || {};
m = await meta('adresse@tk1422.no');
sjekk('egen adresse og firmanavn brukes, ikke Enhetsregisteret', rec2.name === 'Egen AS' && rec2.address === 'Kirkeveien 2' && rec2.zip === '0368' && !kall.kall.some(k => /brreg/.test(k.url || '')), rec2);
sjekk('«Ingen gruppe»: uten customerGroupId, _tellixGroupId 0', !('customerGroupId' in rec2) && m.meta._tellixGroupId === '0' && m.meta._b2b_tellix_status === 'opprettet', [rec2, m.meta]);

await k1422({ web: { token: 'ok', uuid_i_svar: true, gruppe_lagres: false }, kall_slett: true });
await bruker('ignorert@tk1422.no', venter('966666668', 'Ignorert AS'));
await godkjenn('ignorert@tk1422.no', 14);
m = await meta('ignorert@tk1422.no');
sjekk('Tellix lagret ikke gruppen: Tellix sin (0) skrives, og admin får beskjed', m.meta._tellixGroupId === '0' && /Tellix lagret kunden med ingen gruppe, ikke Bedriftskunder/.test(m.meta._b2b_tellix?.melding || ''), m.meta);
sjekk('UUID fra svaret på customer/save', /^UUID-\d+$/.test(m.meta._tellixCustomerUuId), m.meta);

// To søkere fra samme nye bedrift i samme kjøring: én kunde i Tellix.
await k1422({ web: { token: 'ok' }, kall_slett: true });
await bruker('samme1@tk1422.no', { billing_org_number: '977777777', billing_company: 'Samme AS' });
await bruker('samme2@tk1422.no', { billing_org_number: '977777777', billing_company: 'Samme AS' });
let ms = await masse({ ider: [await id('samme1@tk1422.no'), await id('samme2@tk1422.no')], gruppe: '14' });
const s1 = await meta('samme1@tk1422.no'), s2 = await meta('samme2@tk1422.no');
sjekk('massehandling, to med samme org.nr: én opprettet, den andre koblet til samme kunde', (await webkall()).filter(k => k.sti === 'customer/save').length === 1
  && s1.meta._tellixCustomerId !== '' && s1.meta._tellixCustomerId === s2.meta._tellixCustomerId && s2.meta._b2b_tellix_status === 'koblet', [s1.meta, s2.meta]);
sjekk('… massehandlingen skriver ingenting ut, men omdirigerer med tallene', ms.ekko === '' && /[?&]amendo_tellix=1-1-0-0-0-0/.test(ms.til || ''), ms);
sjekk('… beskjeden i Brukere etter omdirigeringen', /1 koblet til en Tellix-kunde, 1 opprettet i Tellix/.test(ms.beskjed) && /notice-success/.test(ms.beskjed), ms.beskjed);

// ── Feil: godkjenningen står, «Prøv igjen» ──────────────────────────────────
console.log('\nFeil');
await bruker('ingengruppe@tk1422.no', venter('988888881', 'Uten Gruppe AS'));
await k1422({ kall_slett: true });
await godkjenn('ingengruppe@tk1422.no');
m = await meta('ingengruppe@tk1422.no');
sjekk('ingen treff og ingen gruppe valgt: feil, ikke opprettet, godkjent', m.meta._b2b_tellix_status === 'feil' && /Velg kundegruppe/.test(m.meta._b2b_tellix?.melding || '') && !(await webkall()).some(k => k.sti === 'customer/save') && m.meta._b2b_status === 'godkjent', m.meta);

await k1422({ web: { nede: true } });
await bruker('nede@tk1422.no', venter('988888882', 'Nede AS'));
b = await godkjenn('nede@tk1422.no', 14);
m = await meta('nede@tk1422.no');
sjekk('Tellix nede: godkjent, ikke koblet, tellix=feil', m.meta._b2b_status === 'godkjent' && m.meta.billing_org_number === '988888882' && m.meta._tellixCustomerId === '' && tellixParam(b.til) === 'feil', [b, m.meta]);
html = await side('&b2b=godkjent&tellix=feil&tb=' + m.id);
kort = html.slice(html.indexOf('id="b2b-tellix"'));
sjekk('tydelig feil øverst: «Fikk ikke kontakt med Tellix»', /amendo-b2b-melding-feil" id="b2b-tellix-melding">Tellix: Fikk ikke kontakt med Tellix\./.test(html) && html.includes('Søknaden er godkjent'));
const velger = (new RegExp(`name="gruppe\\[${m.id}\\]">([\\s\\S]*?)</select>`).exec(kort) || [])[1] || '';
sjekk('kortet: raden med «Prøv igjen» og gruppen som var valgt', kort.includes(`name="prov" value="${m.id}"`) && /<option value="14" selected/.test(velger), [velger, kort.slice(0, 800)]);
await k1422({ web: { token: 'ok' } });
b = await handling({ post: { prov: String(m.id), gruppe: { [m.id]: '14' } } });
m = await meta('nede@tk1422.no');
sjekk('«Prøv igjen» når Tellix er oppe: opprettet', tellixParam(b.til) === 'opprettet' && /^\d+$/.test(m.meta._tellixCustomerId) && m.meta._tellixGroupId === '14', m.meta);
html = await side();
sjekk('… og raden er borte fra kortet', !html.slice(html.indexOf('id="b2b-tellix"')).includes(`value="${m.id}"`));

await k1422({ web: { token: 'feil' } });
await bruker('token@tk1422.no', venter('988888883', 'Token AS'));
await godkjenn('token@tk1422.no', 14);
m = await meta('token@tk1422.no');
sjekk('innloggingen avvist: «Innloggingen til Tellix feilet (HTTP 401)»', m.meta._b2b_tellix_status === 'feil' && /Innloggingen til Tellix feilet \(HTTP 401\)/.test(m.meta._b2b_tellix?.melding || ''), m.meta);

await k1422({ web: { token: 'ok', save_uten_id: true }, kall_slett: true });
await bruker('utenid@tk1422.no', venter('988888884', 'Uten ID AS'));
await godkjenn('utenid@tk1422.no', 14);
m = await meta('utenid@tk1422.no');
sjekk('Tellix svarer uten kunde-ID: feil', m.meta._b2b_tellix_status === 'feil' && /Tellix opprettet ikke kunden/.test(m.meta._b2b_tellix?.melding || ''), m.meta);
await k1422({ web: { token: 'ok' } });
await handling({ post: { prov: String(m.id), gruppe: { [m.id]: '14' } } });
m = await meta('utenid@tk1422.no');
sjekk('«Prøv igjen» finner kunden Tellix likevel lagde (org.nr): koblet, ingen ny kunde', m.meta._b2b_tellix_status === 'koblet' && /^\d+$/.test(m.meta._tellixCustomerId) && (await webkall()).filter(k => k.sti === 'customer/save').length === 1, m.meta);

await klargjor({ api_feil: '1' });
await k1422({ kall_slett: true });
await bruker('liste@tk1422.no', venter('988888885', 'Liste AS'));
await godkjenn('liste@tk1422.no', 14);
m = await meta('liste@tk1422.no');
sjekk('kundelista kan ikke hentes: feil, og ingenting opprettes (kunne funnes)', m.meta._b2b_tellix_status === 'feil' && /Fikk ikke hentet kundelista/.test(m.meta._b2b_tellix?.melding || '') && (await webkall()).length === 0, m.meta);
await klargjor({ api_feil: null });

await bruker('las@tk1422.no', venter('988888886', 'Lås AS'));
const lasId = await id('las@tk1422.no');
await opt({ ['amendo_b2b_tellix_las_' + lasId]: String(Math.floor(Date.now() / 1000)) });
await k1422({ kall_slett: true });
b = await godkjenn('las@tk1422.no', 14);
m = await meta('las@tk1422.no');
sjekk('samme kunde behandles alt (dobbeltklikk): ingen ny kunde', tellixParam(b.til) === 'feil' && m.meta._tellixCustomerId === '' && !(await webkall()).some(k => k.sti === 'customer/save'), [b, m.meta]);
await opt({ ['amendo_b2b_tellix_las_' + lasId]: null });

await bruker('koblet@tk1422.no', venter('988888887', 'Koblet AS', { _tellixCustomerId: 'T99', _tellixGroupId: '1' }));
b = await godkjenn('koblet@tk1422.no', 14);
m = await meta('koblet@tk1422.no');
sjekk('allerede koblet: ingenting endres, tellix=allerede', tellixParam(b.til) === 'allerede' && m.meta._tellixCustomerId === 'T99' && m.meta._tellixGroupId === '1', [b, m.meta]);

// ── Tellix-tilgangen ────────────────────────────────────────────────────────
console.log('\nTellix-tilgangen');
await k1422({ egen: null, sync: { environment: 'live', api_version: 'v3', live_base_url: 'https://sync.test/', live_username: 'garcon', live_password: 'pw', live_apikey: 'key' }, kall_slett: true });
await bruker('sync@tk1422.no', venter('988888888', 'Sync AS'));
await godkjenn('sync@tk1422.no', 14);
m = await meta('sync@tk1422.no');
kall = await webkall();
sjekk('uten egen tilgang: innloggingen til garcon-tellix-b2b-sync (live)', m.meta._b2b_tellix_status === 'opprettet' && kall.some(k => k.url === 'https://sync.test/web/v3/customer/save'), kall.map(k => k.url));
html = await side();
sjekk('kortet sier hvor innloggingen kommer fra', /Bruker innloggingen fra Garcon Tellix Sync \(miljø: <strong>live<\/strong>/.test(html) && !/testmiljøet/.test(html));
await k1422({ sync: { environment: 'test', test_username: 'garcon', test_password: 'pw', test_apikey: 'key' } });
html = await side();
sjekk('sync-pluginen i testmiljøet: advarsel', /miljø: <strong>test<\/strong>/.test(html) && /testmiljøet/.test(html));
await k1422({ sync: null });
await bruker('ingen@tk1422.no', venter('988888889', 'Ingen Tilgang AS'));
await godkjenn('ingen@tk1422.no', 14);
m = await meta('ingen@tk1422.no');
sjekk('ingen innlogging i det hele tatt: feil som sier hva som mangler', m.meta._b2b_tellix_status === 'feil' && /Tellix-tilgangen for å opprette kunder er ikke satt opp/.test(m.meta._b2b_tellix?.melding || ''), m.meta);

let w = await web({ post: { tellix_web_bruker: 'x', tellix_web_passord: 'y', tellix_web_nokkel: 'z' }, rolle: 'shop_manager' });
sjekk('butikksjef kan ikke lagre innloggingen', w.stoppet === true && (await tilstand()).egen === null, w);
w = await web({ post: { tellix_web_url: 'https://tellix.test', tellix_web_versjon: 'v3', tellix_web_bruker: 'amendo', tellix_web_passord: 'hemmelig-pw-1422', tellix_web_nokkel: 'hemmelig-key-1422' } });
let t = await tilstand();
sjekk('administrator lagrer: adresse med skråstrek', /tellix_web=lagret/.test(w.til) && t.egen?.url === 'https://tellix.test/' && t.egen?.passord === 'hemmelig-pw-1422', t.egen);
w = await web({ post: { tellix_web_url: 'https://tellix.test/', tellix_web_versjon: 'v3', tellix_web_bruker: 'amendo2', tellix_web_passord: '', tellix_web_nokkel: '' } });
t = await tilstand();
sjekk('tomt passord og tom nøkkel: de lagrede står', t.egen?.bruker === 'amendo2' && t.egen?.passord === 'hemmelig-pw-1422' && t.egen?.nokkel === 'hemmelig-key-1422', t.egen);
html = await side();
sjekk('passord og API-nøkkel står aldri i siden', !html.includes('hemmelig-pw-1422') && !html.includes('hemmelig-key-1422') && html.includes('Satt — tomt felt beholder det'));
w = await web({ post: { tellix_web_test: '1' } });
sjekk('«Test innloggingen»: ok', /tellix_web=ok/.test(w.til), w);
await k1422({ web: { token: 'feil' } });
w = await web({ post: { tellix_web_test: '1' } });
sjekk('«Test innloggingen» med avvist innlogging: feil', /tellix_web=feil/.test(w.til), w);
await k1422({ web: { token: 'ok' } });
w = await web({ post: { tellix_web_fjern: '1' } });
sjekk('«Fjern» sletter den egne innloggingen', (await tilstand()).egen === null, w);
await k1422({ egen: EGEN });

// ── Massehandlingen i Brukere ───────────────────────────────────────────────
console.log('\nMassehandlingen');
await bruker('m1@tk1422.no', { billing_org_number: '911111111' });
await bruker('m2@tk1422.no', { billing_company: 'Uten Orgnr' });
await bruker('m3@tk1422.no', { _tellixCustomerId: 'T10' });
await bruker('m4@tk1422.no', { _tellixCustomerId: '0', _tellixCustomerUuId: '' });
let u = await get('/amendo-test/v1/v1422-ukoblet', {});
sjekk('filteret «Uten Tellix-kobling»: bare de uten kunde-ID/UUID (0 og tom teller som uten)', ['m1@tk1422.no', 'm2@tk1422.no', 'm4@tk1422.no'].every(e => u.eposter.includes(e)) && !u.eposter.includes('m3@tk1422.no') && !u.eposter.includes('orgnr@tk1422.no'), u);
const ider = [await id('m1@tk1422.no'), await id('m2@tk1422.no'), await id('m3@tk1422.no')];
await k1422({ kall_slett: true });
ms = await masse({ ider: [...ider, ...Array.from({ length: 24 }, (_, i) => 900000 + i)], gruppe: '' });
sjekk('handlingen «Koble til Tellix», gruppevalget over tabellen og visningen finnes', ms.handlinger?.amendo_tellix_koble === 'Koble til Tellix' && /name="amendo_tellix_gruppe"/.test(ms.valg) && /Uten Tellix-kobling/.test(ms.visninger?.amendo_tellix_ukoblet || ''), ms);
m = await meta('m1@tk1422.no');
sjekk('m1 (org.nr): koblet til T10', m.meta._tellixCustomerId === 'T10' && m.meta._tellixGroupId === '14', m.meta);
m = await meta('m2@tk1422.no');
sjekk('m2 (uten treff, ingen gruppe valgt): feil, ikke opprettet', m.meta._b2b_tellix_status === 'feil' && !(await webkall()).some(k => k.sti === 'customer/save'), m.meta);
sjekk('beskjeden: koblet, feilet, fra før, og 2 ikke behandlet (maks 25)', /1 koblet til en Tellix-kunde, 1 feilet, 1 var koblet fra før/.test(ms.beskjed) && /2 ble ikke behandlet \(maks 25 om gangen\)/.test(ms.beskjed) && /notice-warning/.test(ms.beskjed), ms.beskjed);
ms = await masse({ ider, gruppe: '14', rolle: 'customer' });
sjekk('kunde-rolle: ingen handling, stoppet', ms.stoppet === true && !ms.handlinger?.amendo_tellix_koble, ms);

// ── Loggen ──────────────────────────────────────────────────────────────────
const l = await logg();
sjekk('loggen har antall, men ingen kundedata, token eller innlogging', /Tellix-kobling: \d+ koblet, \d+ opprettet/.test(l) && !/@tk1422\.no|966666666|TOKEN-1422|hemmelig|NY BEDRIFT|Storgata/.test(l), l.slice(-500));

// ── Org.nr-feltet i kundelista ──────────────────────────────────────────────
console.log('\nOrg.nr i kundelista');
await klargjor({ kunder: [{ raa: { customerId: 'T60', customerUuid: 'U60', customerGroupId: 14, name: 'Ukjent felt AS', email: 'post@ukjentfelt.no', phoneNumber: '', customerOrgNo: '987 654 321', isActive: true, isDeleted: false } }] });
await k1422({ web: { token: 'ok' }, kall_slett: true });
await bruker('feltnavn@tk1422.no', venter('987654321', 'Ukjent felt AS'));
await godkjenn('feltnavn@tk1422.no', 14);
m = await meta('feltnavn@tk1422.no');
sjekk('org.nr under et annet feltnavn med «org» (customerOrgNo): koblet til T60', m.meta._tellixCustomerId === 'T60' && m.meta._b2b_tellix_status === 'koblet', m.meta);

await klargjor({ kunder: [{ id: 'T70', uuid: 'U70', groupId: 14, email: 'uten@orgnr.no', name: 'Uten Orgnr' }] });
await k1422({ kall_slett: true });
await bruker('utenfelt@tk1422.no', venter('912121212', 'Uten Felt AS'));
b = await godkjenn('utenfelt@tk1422.no', 14);
m = await meta('utenfelt@tk1422.no');
sjekk('lista har ingen org.nr: ingen kunde opprettes, admin må se i Tellix (valg)', tellixParam(b.til) === 'valg' && m.meta._b2b_tellix_status === 'valg' && /Kundelista fra Tellix har ikke org\.nr/.test(m.meta._b2b_tellix?.melding || '') && !(await webkall()).some(k => k.sti === 'customer/save') && m.meta._b2b_status === 'godkjent', m.meta);
html = await side();
kort = html.slice(html.indexOf('id="b2b-tellix"'));
const utenRad = (new RegExp(`data-bruker="${m.id}"[\\s\\S]*?</tr>`).exec(kort) || [])[0] || '';
sjekk('… raden har «Opprett ny», men ikke «Koble til valgt»', utenRad.includes('name="ny"') && !utenRad.includes('name="koble"'), utenRad);
b = await handling({ post: { ny: String(m.id), gruppe: { [m.id]: '14' } } });
m = await meta('utenfelt@tk1422.no');
sjekk('… «Opprett ny»: opprettet', tellixParam(b.til) === 'opprettet' && /^\d+$/.test(m.meta._tellixCustomerId) && m.meta._tellixGroupId === '14', m.meta);

// ── Uten Tellix / modulen av ────────────────────────────────────────────────
console.log('\nUten Tellix og med B2B av');
await klargjor({ tellix: null });
await k1422({ egen: null, sync: null, kall_slett: true });
await bruker('utentellix@tk1422.no', venter('912345678', 'Uten Tellix AS'));
b = await godkjenn('utentellix@tk1422.no');
m = await meta('utentellix@tk1422.no');
html = await side();
sjekk('butikk uten Tellix: godkjent som før, uten tellix i omdirigeringen og ingen status', /b2b=godkjent/.test(b.til) && !/tellix=/.test(b.til) && m.meta._b2b_tellix_status === '' && (await tilstand()).kall.length === 0, [b, m.meta]);
sjekk('… og verken kortet, gruppevalget eller Tellix-kolonnen vises', !html.includes('id="b2b-tellix"') && !html.includes('Kundegruppe i Tellix') && !html.includes('b2b-tellix-kunde'));
await klargjor({ moduler: [] });
ms = await masse({ ider: [await id('m2@tk1422.no')], gruppe: '14' });
sjekk('B2B av: ingen massehandling og ingen visning i Brukere', !ms.handlinger?.amendo_tellix_koble && !ms.visninger?.amendo_tellix_ukoblet && !/amendo_tellix=/.test(ms.til || ''), ms);

ferdig();
