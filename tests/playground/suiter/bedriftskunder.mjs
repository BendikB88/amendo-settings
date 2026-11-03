// 1.4.29: bedriftskunder fra Tellix. Tellix bestemmer hvem som er bedrift
// (kunde med org.nr). Automatisk godkjenning på e-post ved innlogging og
// registrering (aldri på org.nr alene), jobben hver time for nye Tellix-
// bedrifter, «Send invitasjon» med tørrkjøring, velkomst-e-posten, nøklene i
// 7 dager og «Glemt passord» til frontendens /sett-passord. Mot Tellix-stubben i mu/tellix-stub.php; e-post fanges i
// mu/v1429-test.php. Se includes/moduler/b2b-tellix-bedrifter.php.
import { B, get, sjekk, ferdig } from '../lib/felles.mjs';

const SECRET = 'bedrift-hemmelig-1429';
const opt = o => get('/amendo-test/v1/opt', o);
const klargjor = body => get('/amendo-test/v1/v1414-klargjor', body);
const k1429 = body => get('/amendo-test/v1/v1429-klargjor', body);
const meta = async (...eposter) => get('/amendo-test/v1/v1429-meta', { eposter });
const m1 = async e => (await meta(e))[e];
const tilstand = () => get('/amendo-test/v1/v1429-tilstand');
const jobb = () => get('/amendo-test/v1/v1429-jobb');
const handling = (post, rolle = 'admin', nonce = true) => get('/amendo-test/v1/v1429-handling', { post, rolle, nonce });
const svar = til => (String(til || '').match(/b2b_auto=([a-z_]+)/) || [])[1];
const logInn = (epost, passord, via = 'authenticate') => get('/amendo-test/v1/v1429-logg-inn', { epost, passord, via });
const side = async () => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=admin')).text();
const logg = async () => (await get('/amendo-test/v1/v1414-logg')).tekst || '';
const post = async (sti, body, secret = SECRET) => {
  const r = await fetch(B + '/amendo-settings/v1/' + sti, { method: 'POST', headers: { 'content-type': 'application/json', ...(secret === null ? {} : { 'X-Amendo-Secret': secret }) }, body: JSON.stringify(body) });
  const tekst = await r.text();
  let json = null; try { json = JSON.parse(tekst); } catch {}
  return { status: r.status, json, tekst };
};
const kunde = async epost => {
  const r = await fetch(B + '/amendo-settings/v1/rabatt/kunde/' + (await m1(epost)).id, { headers: { 'X-Amendo-Secret': SECRET } });
  return { status: r.status, json: await r.json().catch(() => null) };
};
const soknad = async (epost, org_nr) => post('b2b-soknad', { bruker_id: (await m1(epost)).id, org_nr });
const tb = body => get('/amendo-test/v1/v1429-tilbakestill', body);
const alder = (epost, dager) => get('/amendo-test/v1/v1429-nokkel-alder', { epost, dager });
const glemt = async e => {
  const før = (await tilstand()).epost.length;
  await get('/amendo-test/v1/v1429-glemt', { epost: e });
  const alle = (await tilstand()).epost;
  return alle.length > før ? alle[alle.length - 1] : null;
};
// WordPress 6: wp-login.php?login=…&key=…&action=rp&wp_lang=…
const WP_LENKE = /wp-login\.php\?\S*action=rp/;
const godkjent = m => m && m._b2b_status === 'godkjent' && m.billing_org_number !== '';

const k = (id, email, orgnr, name, ekstra = {}) => ({ id, uuid: 'U' + id, groupId: 0, email, name, ...(orgnr ? { orgnr } : {}), ...ekstra });
const KUNDER = [
  k('T1', 'bedrift@bk.no', '911000001', 'Bedrift AS'),
  k('T2', 'privat@bk.no', null, 'Privat Person'),
  k('T3', 'to@bk.no', '911000003', 'To En AS'), k('T4', 'to@bk.no', '911000004', 'To To AS'),
  k('T5', 'annen@bk.no', '911000005', 'Annen AS'),
  k('T6', 'ekte@firma.no', '911000006', 'Ekte AS'),
  k('T7', 'avvist@bk.no', '911000007', 'Avvist AS'),
  k('T8', 'soknad@bk.no', '911000008', 'Soknad AS'),
  k('T9', 'login@bk.no', '911000009', 'Login AS'),
  k('T10', 'wplogin@bk.no', '911000010', 'WpLogin AS'),
  k('T11', 'butikk@bk.no', '911000011', 'Butikk AS'),
  k('T12', 'kobl@bk.no', '911000012', 'Kobl AS'),
  k('T13', '', '911000013', 'Uten Epost AS'),
  k('T14', 'av@bk.no', '911000014', 'Av AS'),
];
const TELLIX = { moduler: ['b2b'], tellix: '1', grupper: [{ id: 14, name: 'Bedriftskunder', discountPercent: 10 }], kundegrupper_ny: { 14: { gjelder: 'nettbutikk', b2b: 1, levering: 0 } },
  helsebygget: null, store_groups: null, kundegrupper: null, sov: null, kast: null, api_feil: null, side: null, faktura: null };

await get('/amendo-test/v1/setup');
await opt({ amendo_kasse_secret: SECRET, amendo_frontend_url: 'https://front.example.no', amendo_butikk_navn: 'Testbakeriet', amendo_butikk_logo_mork: 'https://cdn.example.no/logo-mork.png', amendo_test_epost_fang: '1' });
await klargjor({ ...TELLIX, kunder: KUNDER, liste_slett: true, nullstill: true });
await k1429({ nullstill: true, slett_epost: ['ekte@firma.no', 'ny1@bk.no', 'ny6@bk.no', 'finnes@bk.no'], brukere: [
  { epost: 'bedrift@bk.no' }, { epost: 'privat@bk.no' }, { epost: 'to@bk.no' }, { epost: 'annen@bk.no' }, { epost: 'falsk@bk.no' },
  { epost: 'avvist@bk.no', meta: { _b2b_status: 'avvist' } }, { epost: 'soknad@bk.no' }, { epost: 'login@bk.no', passord: 'Riktig-passord-1' },
  { epost: 'wplogin@bk.no' }, { epost: 'butikk@bk.no', rolle: 'shop_manager' }, { epost: 'kobl@bk.no', meta: { _tellixCustomerId: 'T2' } }, { epost: 'av@bk.no' },
] });

// ── Bryterne er av som standard ─────────────────────────────────────────────
console.log('Av som standard');
let t = await tilstand();
sjekk('alle tre bryterne av, jobben ikke planlagt', t.brytere.auto === '' && t.brytere.jobb === '' && t.brytere.epost === '' && !t.planlagt, t.brytere);
let r = await kunde('av@bk.no');
sjekk('rabatt/kunde med bryteren av: ikke godkjent', r.status === 200 && !godkjent(await m1('av@bk.no')), r.status);
r = await soknad('av@bk.no', '911000014');
sjekk('b2b-soknad med bryteren av: venter som før', r.status === 200 && r.json?.status === 'venter', r.json);
r = await jobb();
sjekk('jobben med bryteren av: gjør ingenting', r.svar?.status === 'av' && (await tilstand()).kjente === null, r);

// ── Innstillingene ──────────────────────────────────────────────────────────
console.log('\nInnstillingene');
r = await handling({ b2b_auto: 'lagre', b2b_bryter_auto: '1' }, 'customer');
sjekk('kunde-rolle kan ikke lagre', r.stoppet === true && (await tilstand()).brytere.auto === '', r);
r = await handling({ b2b_auto: 'lagre', b2b_bryter_auto: '1' }, 'admin', false);
sjekk('uten nonce: stoppet', r.stoppet === true && (await tilstand()).brytere.auto === '', r);
r = await handling({ b2b_auto: 'lagre', b2b_bryter_auto: '1' });
t = await tilstand();
sjekk('automatisk godkjenning på, jobb og e-post fortsatt av', svar(r.til) === 'lagret' && t.brytere.auto === '1' && t.brytere.jobb === '' && t.brytere.epost === '', [r, t.brytere]);

// ── Automatisk godkjenning ──────────────────────────────────────────────────
console.log('\nAutomatisk godkjenning');
r = await kunde('bedrift@bk.no');
let m = await m1('bedrift@bk.no');
sjekk('rabatt/kunde (innlogging): e-posten er bedrift i Tellix → godkjent, koblet, org.nr og firma fra Tellix', r.status === 200 && m._b2b_status === 'godkjent' && m.billing_org_number === '911000001' && m._b2b_kilde === 'tellix' && m._tellixCustomerId === 'T1' && m.billing_company === 'Bedrift AS' && m._b2b_behandlet_av === '0', m);
sjekk('… og samme svar gir faktura', r.json?.faktura === true && r.json?.tellix_koblet === true, r.json);
for (const [e, tekst] of [
  ['privat@bk.no', 'Tellix-kunde uten org.nr'],
  ['to@bk.no', 'samme e-post på to Tellix-bedrifter'],
  ['avvist@bk.no', 'avvist her'],
  ['butikk@bk.no', 'butikkbruker (shop_manager)'],
  ['kobl@bk.no', 'koblet til en annen Tellix-kunde'],
]) {
  await kunde(e);
  m = await m1(e);
  sjekk(`${tekst}: ikke godkjent`, !godkjent(m) && (e !== 'avvist@bk.no' || m._b2b_status === 'avvist') && (e !== 'kobl@bk.no' || m._tellixCustomerId === 'T2'), m);
}
r = await soknad('annen@bk.no', '922222222');
sjekk('søknad med et annet org.nr enn Tellix sitt: venter', r.json?.status === 'venter' && !godkjent(await m1('annen@bk.no')), r.json);
r = await soknad('falsk@bk.no', '911000006');
sjekk('⚠ org.nr som finnes i Tellix, men en annen e-post: venter (org.nr er offentlige)', r.json?.status === 'venter' && !godkjent(await m1('falsk@bk.no')), r.json);
let html = await side();
const soknader = html.slice(html.indexOf('id="b2b-soknader"'), html.indexOf('id="b2b-kunder"'));
sjekk('Søknader: hint om Tellix-kunden med samme org.nr', soknader.includes('Finnes i Tellix: Ekte AS, ekte@firma.no'), soknader.slice(0, 2000));
r = await soknad('soknad@bk.no', '911 000 008');
m = await m1('soknad@bk.no');
sjekk('b2b-soknad (registrering), e-post og org.nr som i Tellix: 200 status godkjent, ingen søknad', r.status === 200 && JSON.stringify(r.json) === '{"ok":true,"status":"godkjent","org_nr":"911000008"}' && godkjent(m) && m._orgnr_soknad === '' && m._tellixCustomerId === 'T8', [r.json, m]);
r = await logInn('login@bk.no', 'feil-passord');
sjekk('innlogging med feil passord: ingenting', r.ok === false && !godkjent(await m1('login@bk.no')), r);
r = await logInn('login@bk.no', 'Riktig-passord-1');
sjekk('innlogging via wp_authenticate (jwt-auth): godkjent', r.ok === true && godkjent(await m1('login@bk.no')), r);
await logInn('wplogin@bk.no', null, 'wp_login');
sjekk('innlogging via wp_login: godkjent', godkjent(await m1('wplogin@bk.no')));
let l = await logg();
sjekk('loggen: antall, ingen e-post eller navn', /B2B: 1 bruker godkjent automatisk fra Tellix/.test(l) && !/@bk\.no|Bedrift AS/.test(l), l.slice(-500));

// ── Jobben ──────────────────────────────────────────────────────────────────
console.log('\nJobben hver time');
r = await handling({ b2b_auto: 'lagre', b2b_bryter_auto: '1', b2b_bryter_jobb: '1' });
t = await tilstand();
sjekk('jobben slått på: planlagt, ingen grunnlinje ennå', svar(r.til) === 'lagret' && t.planlagt && t.kjente === null, t);
r = await jobb();
t = await tilstand();
sjekk('første kjøring: grunnlinje med alle 14 Tellix-kundene, ingen kontoer', r.svar?.status === 'grunnlinje' && r.svar.antall === 14 && t.kjente.length === 14 && (await m1('ekte@firma.no')) === null, [r, t.kjente]);
await k1429({ brukere: [{ epost: 'finnes@bk.no' }] });
const NYE = [
  k('N1', 'ny1@bk.no', '911100001', 'Ny En AS', { phone: '+4799990001' }),
  k('N2', 'finnes@bk.no', '911100002', 'Finnes AS'),
  k('N3', '', '911100003', 'Ny Uten Epost AS'),
  k('N4', 'ikkebedrift@bk.no', null, 'Ikke Bedrift'),
  k('N5', 'to@bk.no', '911100005', 'To Tre AS'),
];
await klargjor({ kunder: [...KUNDER, ...NYE], liste_slett: true });
r = await jobb();
sjekk('nye Tellix-kunder: 1 konto (uten e-post), 1 koblet, 2 manuelt', r.svar?.status === 'kjort' && r.svar.opprettet === 1 && r.svar.koblet === 1 && r.svar.manuell === 2 && r.svar.invitert === 0 && r.svar.feil === 0, r.svar);
m = await m1('ny1@bk.no');
sjekk('ny konto: kunde-rolle, godkjent, koblet, firma og telefon fra Tellix, e-posten ikke sendt (bryteren av)', m && m.roller.includes('customer') && godkjent(m) && m.billing_org_number === '911100001' && m._tellixCustomerId === 'N1' && m.billing_company === 'Ny En AS' && m.billing_phone === '+4799990001' && m._amendo_invitasjon === 'ikke_sendt' && m._amendo_fra_tellix !== '', m);
m = await m1('finnes@bk.no');
sjekk('e-posten finnes fra før: bare koblet og godkjent, ingen e-post', godkjent(m) && m._tellixCustomerId === 'N2' && m._amendo_invitasjon === '', m);
t = await tilstand();
sjekk('ingen e-post sendt', t.epost.length === 0, t.epost.length);
sjekk('uten org.nr: ingen konto', (await m1('ikkebedrift@bk.no')) === null);
sjekk('manuell behandling: uten e-post og samme e-post på flere Tellix-kunder', t.manuell.N3?.grunn === 'uten_epost' && t.manuell.N5?.grunn === 'flere_med_epost' && !t.manuell.T13, t.manuell);
sjekk('de nye er lagt til grunnlinjen', ['N1', 'N2', 'N3', 'N4', 'N5'].every(id => t.kjente.includes(id)), t.kjente);
r = await jobb();
sjekk('neste kjøring: ingenting nytt, ingen dobbel konto', r.svar?.status === 'kjort' && r.svar.opprettet === 0 && r.svar.koblet === 0 && r.svar.manuell === 0, r.svar);

console.log('\nVelkomst-e-posten');
r = await handling({ b2b_auto: 'lagre', b2b_bryter_auto: '1', b2b_bryter_jobb: '1', b2b_bryter_epost: '1', b2b_velkomst_emne: 'Velkommen til {butikk}', b2b_velkomst_tekst: 'Hei {firma}!\n\nDu er bedriftskunde hos {butikk}.', b2b_velkomst_knapp: 'Lag passord' });
t = await tilstand();
sjekk('e-posten slått på; jobben sto på, så grunnlinjen står', t.brytere.epost === '1' && t.kjente.includes('N1'), t.brytere);
await klargjor({ kunder: [...KUNDER, ...NYE, k('N6', 'ny6@bk.no', '911100006', 'Ny Seks AS')], liste_slett: true });
r = await jobb();
t = await tilstand();
sjekk('ny Tellix-bedrift: konto og velkomst-e-post', r.svar?.invitert === 1 && t.epost.length === 1 && t.epost[0].til[0] === 'ny6@bk.no', [r.svar, t.epost.map(e => e.til)]);
const ep = t.epost[0] || { html: '', emne: '', headers: [] };
sjekk('emnet fra innstillingene, {butikk} fra butikknavnet', ep.emne === 'Velkommen til Testbakeriet', ep.emne);
sjekk('teksten og knappen fra innstillingene, {firma} fra Tellix', ep.html.includes('Hei Ny Seks AS!') && ep.html.includes('Du er bedriftskunde hos Testbakeriet.') && ep.html.includes('>Lag passord</a>'), ep.html);
sjekk('logoen fra Butikk-fanen (lys bakgrunn)', ep.html.includes('src="https://cdn.example.no/logo-mork.png"'), ep.html);
sjekk('HTML-e-post', JSON.stringify(ep.headers).includes('text/html'), ep.headers);
const lenke = (ep.html.match(/href="(https:\/\/front\.example\.no\/sett-passord\?[^"]+)"/) || [])[1]?.replace(/&#038;|&amp;/g, '&') || '';
const q = new URL(lenke || 'https://x/').searchParams;
sjekk('lenken: <Frontend-URL>/sett-passord?key=…&login=…', lenke.startsWith('https://front.example.no/sett-passord?key=') && q.get('key')?.length >= 20 && q.get('login') === (await m1('ny6@bk.no')).login, lenke);
sjekk('ingen butikknavn i koden: bare det som er lagret', !/Garçon|Garcon|Aanerud|emmk/i.test(ep.html + ep.emne));
sjekk('kontoen er merket som invitert', (await m1('ny6@bk.no'))._amendo_invitasjon === 'sendt');

console.log('\nLenken i WordPress sin egen tilbakestilling (som frontendens /sett-passord)');
r = await post('sett-passord', { login: q.get('login'), key: q.get('key'), passord: 'Nytt-passord-123' });
sjekk('ingen egen rute: POST /sett-passord finnes ikke', r.status === 404, r.status);
sjekk('nøkkelen står som den er i lenken (bare bokstaver og tall)', /^[A-Za-z0-9]{20}$/.test(q.get('key') || ''), q.get('key'));
r = await tb({ login: q.get('login'), key: 'feilnokkel12345678901' });
sjekk('feil nøkkel: avvist', r.ok === false, r);
await alder('ny6@bk.no', 6);
r = await tb({ login: q.get('login'), key: q.get('key') });
sjekk('nøkkelen fra e-posten gjelder etter 6 dager (password_reset_expiration = 7 dager)', r.ok === true, r);
r = await tb({ login: q.get('login'), key: q.get('key'), passord: 'Nytt-passord-123' });
sjekk('passordet settes, og kunden kan logge inn', r.ok === true && (await logInn('ny6@bk.no', 'Nytt-passord-123')).ok === true, r);
r = await tb({ login: q.get('login'), key: q.get('key') });
sjekk('samme lenke to ganger: avvist', r.ok === false, r);
t = await tilstand();
sjekk('admin får ikke WordPress sitt «Passord endret» for kontoer fra Tellix', t.epost.length === 1, t.epost.map(e => [e.til[0], e.emne]));

r = await handling({ b2b_auto: 'lagre', b2b_bryter_auto: '1', b2b_bryter_epost: '1' });
t = await tilstand();
sjekk('jobben slått av: ikke planlagt, og kjører ikke', !t.planlagt && (await jobb()).svar?.status === 'av', t);

// ── «Send invitasjon» ───────────────────────────────────────────────────────
console.log('\nSend invitasjon');
r = await handling({ b2b_auto: 'torrkjor' });
t = await tilstand();
let p = t.plan || { kunder: {}, resultat: {} };
const h = id => p.kunder[id]?.handling + (p.kunder[id]?.grunn ? ':' + p.kunder[id].grunn : '');
sjekk('tørrkjørt', svar(r.til) === 'torrkjort' && p.nokkel, r);
sjekk('uten konto → inviteres; konto uten kobling → kobles; konto herfra uten e-post → sendes', h('T6') === 'inviter' && h('T14') === 'koble' && h('N1') === 'send', [h('T6'), h('T14'), h('N1')]);
sjekk('manuelt: flere med e-posten, avvist, butikkbruker, koblet annen, annet org.nr, uten e-post', h('T3') === 'manuell:flere_med_epost' && h('T7') === 'manuell:avvist' && h('T11') === 'manuell:butikkbruker' && h('T12') === 'manuell:koblet_annen' && h('T5') === 'manuell:annet_orgnr' && h('T13') === 'manuell:uten_epost', p.kunder);
sjekk('ferdige og ikke-bedrifter er ikke med', ['T1', 'T8', 'T9', 'T10', 'N2', 'N6', 'T2', 'N4'].every(id => !p.kunder[id]), Object.keys(p.kunder));
sjekk('tørrkjøringen endret ingenting', (await m1('ekte@firma.no')) === null && (await m1('av@bk.no'))._b2b_status === 'venter' && t.epost.length === 1);
r = await handling({ b2b_auto: 'send', plan: 'feil' });
sjekk('annen nøkkel enn tørrkjøringen: avvist', svar(r.til) === 'annen_plan' && Object.keys((await tilstand()).plan.resultat).length === 0, r);
r = await handling({ b2b_auto: 'send', plan: p.nokkel }, 'customer');
sjekk('kunde-rolle kan ikke sende', r.stoppet === true, r);
await handling({ b2b_auto: 'lagre', b2b_bryter_auto: '1' });
r = await handling({ b2b_auto: 'send', plan: p.nokkel });
sjekk('velkomst-e-posten av: sender ikke', svar(r.til) === 'epost_av' && (await m1('ekte@firma.no')) === null, r);
await handling({ b2b_auto: 'lagre', b2b_bryter_auto: '1', b2b_bryter_epost: '1' });
r = await handling({ b2b_auto: 'send', plan: p.nokkel });
t = await tilstand();
p = t.plan;
m = await m1('ekte@firma.no');
sjekk('sendt: alle 12 behandlet i én runde', svar(r.til) === 'sendt' && Object.keys(p.resultat).length === 12, [r, p.resultat]);
sjekk('T6: konto, godkjent, koblet, invitert', godkjent(m) && m._tellixCustomerId === 'T6' && m._amendo_invitasjon === 'sendt' && p.resultat.T6 === 'invitert', m);
m = await m1('av@bk.no');
sjekk('T14: godkjent og koblet, søknaden borte, ingen e-post', godkjent(m) && m.billing_org_number === '911000014' && m._orgnr_soknad === '' && p.resultat.T14 === 'koblet' && !t.epost.some(e => e.til[0] === 'av@bk.no'), m);
sjekk('N1: e-posten sendt nå', p.resultat.N1 === 'invitert' && (await m1('ny1@bk.no'))._amendo_invitasjon === 'sendt' && t.epost.some(e => e.til[0] === 'ny1@bk.no'));
sjekk('3 e-poster til sammen (ny6, ekte, ny1)', t.epost.length === 3, t.epost.map(e => e.til[0]));
sjekk('de manuelle står på lista', ['T3', 'T4', 'T5', 'T7', 'T11', 'T12', 'T13', 'N3', 'N5'].every(id => t.manuell[id]), Object.keys(t.manuell));
r = await handling({ b2b_auto: 'send', plan: p.nokkel });
sjekk('neste runde: ferdig', svar(r.til) === 'ferdig', r);
html = await side();
const kort = html.slice(html.indexOf('id="b2b-tellix-bedrifter"'));
sjekk('kortet: brytere, invitasjonslisten og manuell-lista', kort.includes('name="b2b_bryter_auto" value="1" checked') && kort.includes('data-tellix="T6"') && kort.includes('class="b2b-manuell-rad" data-tellix="T7"'), kort.slice(0, 1500));
r = await handling({ b2b_fjern: 'T7' });
sjekk('Fjern: borte fra manuell-lista', svar(r.til) === 'fjernet' && !(await tilstand()).manuell.T7, r);

console.log('\nGlemt passord');
let g = await glemt('login@bk.no');
const gl = (g?.html || '').match(/https:\/\/front\.example\.no\/sett-passord\?key=([A-Za-z0-9]+)&login=(\S+)/) || [];
sjekk('WordPress sin e-post peker til <Frontend-URL>/sett-passord, ikke wp-login.php', !!gl[0] && !g.html.includes('wp-login.php'), g?.html);
const glogin = decodeURIComponent(gl[2] || '');
sjekk('login er brukernavnet', glogin === (await m1('login@bk.no')).login, glogin);
r = await tb({ login: glogin, key: gl[1] });
sjekk('nøkkelen er gyldig i WordPress', r.ok === true, r);
await alder('login@bk.no', 8);
r = await tb({ login: glogin, key: gl[1] });
sjekk('over 7 dager gammel: utløpt', r.ok === false && r.feil === 'expired_key', r);
await opt({ amendo_frontend_url: '' });
g = await glemt('login@bk.no');
sjekk('uten Frontend-URL: WordPress sin lenke som før', !!g && WP_LENKE.test(g.html) && !g.html.includes('/sett-passord'), g?.html);
const før = (await tilstand()).epost.length;
r = await tb({ login: glogin, key: (g?.html.match(/key=([A-Za-z0-9]+)/) || [])[1], passord: 'Login-passord-456' });
t = await tilstand();
sjekk('andre kunder: admin får «Passord endret» som før', r.ok === true && t.epost.length === før + 1 && t.epost.at(-1).til[0] !== 'login@bk.no', [r, t.epost.slice(før).map(e => [e.til[0], e.emne])]);
await opt({ amendo_frontend_url: 'https://front.example.no' });

l = await logg();
sjekk('loggen: antall for jobb og invitasjon, ingen e-post', /B2B-jobb: \d+ invitert/.test(l) && /B2B-invitasjon: \d+ invitert/.test(l) && !/@bk\.no|@firma\.no/.test(l), l.slice(-600));

// ── B2B av ──────────────────────────────────────────────────────────────────
console.log('\nB2B av');
await klargjor({ moduler: [] });
t = await tilstand();
sjekk('ingen funksjoner', t.finnes === false, t.finnes);
g = await glemt('login@bk.no');
sjekk('«Glemt passord» går til wp-login.php som før (andre butikker har kanskje ikke siden)', !!g && WP_LENKE.test(g.html) && !g.html.includes('/sett-passord'), g?.html);
await alder('login@bk.no', 2);
r = await tb({ login: glogin, key: (g?.html.match(/key=([A-Za-z0-9]+)/) || [])[1] });
sjekk('nøklene gjelder ett døgn igjen (WordPress sin standard): to dager gammel = utløpt', r.ok === false && r.feil === 'expired_key', r);
sjekk('handlingen finnes ikke', (await handling({ b2b_auto: 'torrkjor' })).finnes === false);

await klargjor({ moduler: null, tellix: null, kundegrupper_ny: null, liste_slett: true });
await k1429({ nullstill: true });
await opt({ amendo_test_epost_fang: null });
ferdig();
