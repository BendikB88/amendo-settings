// Moduler (⚙️ Moduler i admin) og B2B-modulen. Se includes/moduler.php og
// includes/moduler/b2b.php.
//
// ⚠ En modul lastes når pluginen lastes: hver tilstand settes i ett kall og
// sjekkes i det neste.
import { JSDOM } from 'jsdom';
import { readFileSync } from 'fs';
import { B, ADMIN_JS, JQUERY, get, sjekk, ferdig } from '../lib/felles.mjs';

const klargjor = body => get('/amendo-test/v1/moduler-klargjor', body);
const inspiser = () => get('/amendo-test/v1/moduler-inspiser');
const lagre = post => get('/amendo-test/v1/moduler-lagre', { post });
const behandle = body => get('/amendo-test/v1/b2b-behandle', body);
const meta = async epost => (await fetch(B + '/amendo-test/v1/b2b-meta&epost=' + encodeURIComponent(epost))).json();
const bruker = (epost, m) => get('/amendo-test/v1/bedrift-bruker', { epost, meta: m });
const side = async (q = '') => (await fetch(B + '/amendo-test/v1/moduler-side' + q)).text();
const B2B_KROKER = ['b2b_lastet', 'krok_faner', 'krok_paneler', 'krok_etter_skjema', 'krok_lagre', 'krok_settings', 'krok_admin_post'];

await get('/amendo-test/v1/setup');

// ── Av ──────────────────────────────────────────────────────────────────────
console.log('Modulen av (standard)');
await klargjor({ aktive: null, kundegrupper: null, slett_brukere: true });
let t = await inspiser();
sjekk('ingen moduler på', JSON.stringify(t.aktive) === '[]', t.aktive);
for (const k of B2B_KROKER) sjekk(`av: ${k} er ikke registrert`, t[k] === false, t[k]);
sjekk('av: ingen B2B-fane, -panel eller -skjema i admin', !t.html_fane_b2b && !t.html_panel_b2b && !t.html_skjema_b2b && !t.html_kundegrupper);
sjekk('fanen Moduler finnes alltid, med B2B av', t.html_fane_moduler && !t.html_b2b_avkrysset);
sjekk('av: /settings har moduler: []', Array.isArray(t.settings_json.moduler) && t.settings_json.moduler.length === 0, t.settings_json.moduler);
sjekk('av: /settings har ikke kundegruppe_config', !t.settings_har_kundegruppe_config);
const ruter = await (await fetch(B + '/amendo-settings/v1')).json();
sjekk('av: ingen nye ruter i amendo-settings/v1', Object.keys(ruter.routes || {}).every(r => !/b2b|modul|kundegruppe/i.test(r)), Object.keys(ruter.routes || {}));
sjekk('av: /settings har fortsatt alle de gamle feltene', ['butikk', 'design', 'kontakt', 'sosiale', 'meny', 'kategorirad', 'avdelinger'].every(k => k in t.settings_json));

// ── På ──────────────────────────────────────────────────────────────────────
console.log('\nModulen på');
await klargjor({ aktive: ['b2b'] });
t = await inspiser();
for (const k of B2B_KROKER) sjekk(`på: ${k} er registrert`, t[k] === true, t[k]);
sjekk('på: B2B-fane, -panel og egne skjemaer i admin', t.html_fane_b2b && t.html_panel_b2b && t.html_skjema_b2b && t.html_kundegrupper);
sjekk('på: bryteren vises avkrysset', t.html_b2b_avkrysset);
sjekk('på: /settings har moduler: ["b2b"]', JSON.stringify(t.settings_json.moduler) === '["b2b"]', t.settings_json.moduler);
sjekk('på: kundegruppe_config er {} (objekt) når tom', t.kundegruppe_config_raa === '{}', t.kundegruppe_config_raa);

// ── Oppdatering hos en butikk som ikke har bedt om B2B ──────────────────────
// emmk, Dorothea o.l.: kunder med org.nr (fra en annen plugin) og til og med en
// søknad skal IKKE slå på noe. Ingen auto-aktivering — se includes/moduler.php.
console.log('\nOppdatering: org.nr hos kundene slår ikke på B2B');
await bruker('annen-plugin@example.com', { billing_org_number: '923207074' });
await bruker('soker@example.com', { _orgnr_soknad: '998877665', _b2b_status: 'venter' });
await klargjor({ aktive: null });
for (let i = 0; i < 3; i++) {
  t = await inspiser();
  sjekk(`forespørsel ${i + 1}: fortsatt av, moduler: [] og ingen B2B-kroker`, JSON.stringify(t.aktive) === '[]' && JSON.stringify(t.settings_json.moduler) === '[]' && B2B_KROKER.every(k => t[k] === false), t.aktive);
}
await klargjor({ slett_brukere: true });

// ── Bryteren ────────────────────────────────────────────────────────────────
console.log('\nBryteren i admin');
await klargjor({ aktive: ['b2b'] });
let l = await lagre({ butikk_navn: 'Test', moduler: { finnes: '1', paa: { b2b: '1' } } });
sjekk('avkrysset: på', JSON.stringify(l.aktive) === '["b2b"]', l);
l = await lagre({ butikk_navn: 'Test' });
sjekk('lagring uten moduler[finnes]: ingenting endres', JSON.stringify(l.aktive) === '["b2b"]', l);
l = await lagre({ butikk_navn: 'Test', moduler: { finnes: '1' } });
sjekk('ikke avkrysset: av', JSON.stringify(l.aktive) === '[]', l);
t = await inspiser();
sjekk('slått av: neste forespørsel har ingen B2B-kroker', B2B_KROKER.every(k => t[k] === false) && !t.settings_har_kundegruppe_config);
l = await lagre({ butikk_navn: 'Test', moduler: { finnes: '1', paa: { b2b: '1' } } });
sjekk('slått på igjen: på', JSON.stringify(l.aktive) === '["b2b"]', l);
l = await lagre({ butikk_navn: 'Test', moduler: { finnes: '1', paa: { b2b: '1', ukjent: '1' } } });
sjekk('ukjent modulnøkkel lagres ikke', JSON.stringify(l.aktive) === '["b2b"]', l);

// ── Den gamle kundegruppe-tabellen (≤ 1.4.16) ──────────────────────────────
// 1.4.17: tabellen i admin er byttet ut (b2b-kundegrupper.php, suiten
// `kundegrupper`), men en butikk med rader i amendo_kundegruppe_config skal
// få det samme i /settings som før, med de gamle feltene.
console.log('\nDen gamle kundegruppe-tabellen i /settings');
await klargjor({ aktive: ['b2b'], kundegrupper: {
  14: { navn: 'Bedriftskunder', faktura: 1, b2b: 1, helsebygget: 0, helsebygget_rabatt: 0 },
  4: { navn: 'NAV Lillestrøm', faktura: 0, b2b: 0, helsebygget: 1, helsebygget_rabatt: 40 },
}, slett_brukere: true });
t = await inspiser();
const sc = t.settings_json.kundegruppe_config;
sjekk('/settings: de gamle feltene for gruppe 14', sc['14']?.faktura === 1 && sc['14']?.b2b === 1 && sc['14']?.helsebygget === 0 && sc['14']?.helsebygget_rabatt === 0, sc['14']);
sjekk('/settings: Helsebygget-gruppen (4) er fortsatt Helsebygget', sc['4']?.helsebygget === 1 && sc['4']?.gjelder === 'helsebygget' && sc['4']?.faktura === 0, sc['4']);
sjekk('/settings: ingen gruppenavn (offentlig svar)', !JSON.stringify(sc).includes('Lillestrøm') && !Object.values(sc).some(g => 'navn' in g));
let l2 = await lagre({ butikk_navn: 'Test' });
sjekk('lagring uten kundegrupper[finnes]: den gamle tabellen urørt', Object.keys(l2.kundegrupper || {}).length === 2, l2.kundegrupper);
await klargjor({ kundegrupper: null });
t = await inspiser();
sjekk('/settings: uten tabell er den {}', t.kundegruppe_config_raa === '{}', t.kundegruppe_config_raa);

// ── Søknader ────────────────────────────────────────────────────────────────
console.log('\nSøknader');
// Med Helsebygget (egen modul fra 1.4.23), så underfanen finnes i jsdom-sjekken nederst.
await klargjor({ aktive: ['b2b', 'helsebygget'], kundegrupper: null, slett_brukere: true });
const venter = { _b2b_status: 'venter', _b2b_sokt: '2026-10-01T08:00:00Z' };
await bruker('godkjenn@example.com', { ...venter, _orgnr_soknad: '923 207 074', billing_company: 'Garçon AS', first_name: 'Kari', _tellixGroupId: '14' });
await bruker('avvis@example.com', { ...venter, _orgnr_soknad: '998877665', billing_company: '<script>alert(1)</script>' });
await bruker('ugyldig@example.com', { ...venter, _orgnr_soknad: '1234', billing_company: 'Kort AS' });
await bruker('privat@example.com', { _orgnr_soknad: '911111111', _tellixGroupId: '3' });
await bruker('kollega@example.com', { billing_org_number: '923207074', billing_company: 'Garçon AS', _tellixGroupId: '3' });
const id = async e => (await meta(e)).id;

let html = await side();
sjekk('listen viser søknadene som venter', html.includes('godkjenn@example.com') && html.includes('avvis@example.com') && html.includes('ugyldig@example.com'));
const soknadDel = h => h.slice(h.indexOf('id="b2b-soknader"'), h.indexOf('id="b2b-kunder"'));
sjekk('listen viser ikke kunder uten søknad som venter', !soknadDel(html).includes('privat@example.com') && !soknadDel(html).includes('kollega@example.com'));
sjekk('Brreg-lenke på org.nummeret (bare sifre)', html.includes('virksomhet.brreg.no/oppslag/enheter/923207074'));
sjekk('firmanavn escapes', !html.includes('<script>alert(1)</script>') && html.includes('&lt;script&gt;'));
sjekk('viser at nummeret alt har én godkjent bruker', /923207074[^]*?1 bruker</.test(html));

let b = await behandle({ post: { godkjenn: String(await id('godkjenn@example.com')) }, rolle: 'customer' });
sjekk('kunde uten tilgang: stoppes', b.stoppet && !b.til, b);
b = await behandle({ post: { godkjenn: String(await id('godkjenn@example.com')) }, nonce: false });
sjekk('uten nonce: stoppes', b.stoppet && !b.til, b);
let m = await meta('godkjenn@example.com');
sjekk('… og søknaden er urørt', m.meta._b2b_status === 'venter' && m.meta.billing_org_number === '', m.meta);

b = await behandle({ post: { godkjenn: String(m.id) } });
m = await meta('godkjenn@example.com');
sjekk('godkjenn: tilbake til #b2b med melding', /b2b=godkjent#b2b$/.test(b.til || ''), b);
sjekk('godkjenn: billing_org_number = sifrene fra søknaden', m.meta.billing_org_number === '923207074', m.meta);
sjekk('godkjenn: status godkjent, søknadsnøkkelen fjernet', m.meta._b2b_status === 'godkjent' && m.meta._orgnr_soknad === '', m.meta);
sjekk('godkjenn: tidspunkt og hvem', !!m.meta._b2b_behandlet && Number(m.meta._b2b_behandlet_av) > 0, m.meta);
b = await behandle({ post: { godkjenn: String(m.id) } });
sjekk('godkjenn to ganger: «allerede behandlet»', /b2b=ikke_venter/.test(b.til || ''), b);

b = await behandle({ post: { avvis: String(await id('avvis@example.com')) } });
m = await meta('avvis@example.com');
sjekk('avvis: status avvist, ingen tilgang', /b2b=avvist/.test(b.til || '') && m.meta._b2b_status === 'avvist' && m.meta.billing_org_number === '', m.meta);
sjekk('avvis: nummeret frigjøres (søknadsnøkkelen fjernet, tatt vare på)', m.meta._orgnr_soknad === '' && m.meta._b2b_avvist_orgnr === '998877665', m.meta);

b = await behandle({ post: { godkjenn: String(await id('ugyldig@example.com')) } });
m = await meta('ugyldig@example.com');
sjekk('ugyldig org.nr kan ikke godkjennes', /b2b=ugyldig_orgnr/.test(b.til || '') && m.meta._b2b_status === 'venter' && m.meta.billing_org_number === '', [b, m.meta]);

b = await behandle({ post: { godkjenn: String(await id('privat@example.com')) } });
m = await meta('privat@example.com');
sjekk('bruker uten søknad som venter: røres ikke', /b2b=ikke_venter/.test(b.til || '') && m.meta.billing_org_number === '', [b, m.meta]);
b = await behandle({ post: { godkjenn: '999999' } });
sjekk('ukjent bruker-ID: feilmelding', /b2b=ukjent_bruker/.test(b.til || ''), b);

// ── Aktive B2B-kunder ───────────────────────────────────────────────────────
console.log('\nAktive B2B-kunder');
const kunderDel = h => h.slice(h.indexOf('id="b2b-kunder"'), h.indexOf('id="b2b-kundegrupper"'));
html = await side('&b2b=godkjent');
sjekk('melding etter godkjenning vises', html.includes('Søknaden er godkjent'));
sjekk('godkjent søknad er borte fra søknadslisten', !soknadDel(html).includes('godkjenn@example.com') && !soknadDel(html).includes('avvis@example.com') && soknadDel(html).includes('ugyldig@example.com'));
sjekk('aktive: de to med org.nr, ikke de andre', kunderDel(html).includes('godkjenn@example.com') && kunderDel(html).includes('kollega@example.com') && !kunderDel(html).includes('privat@example.com') && !kunderDel(html).includes('avvis@example.com'));
sjekk('aktive: antall i overskriften', /Aktive B2B-kunder \(2\)/.test(html));
html = await side('&b2b_sok=' + encodeURIComponent('923 207'));
sjekk('søk på org.nr med mellomrom', /Aktive B2B-kunder \(2\)/.test(html), html.match(/Aktive B2B-kunder \(\d+\)/)?.[0]);
html = await side('&b2b_sok=' + encodeURIComponent('garç'));
sjekk('søk på firmanavn', /Aktive B2B-kunder \(2\)/.test(html), html.match(/Aktive B2B-kunder \(\d+\)/)?.[0]);
html = await side('&b2b_sok=finnesikke');
sjekk('søk uten treff', /Aktive B2B-kunder \(0\)/.test(html) && kunderDel(html).includes('Ingen treff'));
html = await side('&b2b_sok=' + encodeURIComponent('"><img src=x>'));
sjekk('søkeordet escapes', !html.includes('"><img src=x>'));

html = await side();

// ── admin.js i jsdom ────────────────────────────────────────────────────────
console.log('\nadmin.js i jsdom');
const dom = new JSDOM(html, { url: 'http://localhost/wp-admin/admin.php?page=amendo-settings#b2b', runScripts: 'outside-only' });
const w = dom.window;
w.eval(readFileSync(JQUERY, 'utf8'));
w.eval('window.jQuery = jQuery.noConflict(true);');
let svar = false;
w.confirm = () => svar;
w.eval(readFileSync(ADMIN_JS, 'utf8'));
await new Promise(r => w.jQuery(r));
const d = w.document;
sjekk('#b2b i adressen åpner B2B-fanen', d.querySelector('#tab-b2b').classList.contains('active') && d.querySelector('.amendo-tab[data-tab=b2b]').classList.contains('active') && !d.querySelector('#tab-butikk').classList.contains('active'));
const synlig = navn => d.querySelector(`[data-under-panel="${navn}"]`)?.classList.contains('active');
sjekk('B2B: underfanen Bedrifter vises først', synlig('bedrifter') && !synlig('kundegrupper') && !synlig('helsebygget'));
d.querySelector('.amendo-undertab[data-under="helsebygget"]').click();
sjekk('klikk på Helsebygget: bare den underfanen vises', synlig('helsebygget') && !synlig('bedrifter'));
const dom2 = new JSDOM(html, { url: 'http://localhost/wp-admin/admin.php?page=amendo-settings#b2b-kundegrupper', runScripts: 'outside-only' });
dom2.window.eval(readFileSync(JQUERY, 'utf8'));
dom2.window.eval('window.jQuery = jQuery.noConflict(true);');
dom2.window.eval(readFileSync(ADMIN_JS, 'utf8'));
await new Promise(r => dom2.window.jQuery(r));
sjekk('#b2b-kundegrupper i adressen åpner B2B → Kundegrupper', dom2.window.document.querySelector('#tab-b2b').classList.contains('active') && dom2.window.document.querySelector('[data-under-panel="kundegrupper"]').classList.contains('active'));
const knapp = d.querySelector('.b2b-bekreft');
if (knapp) {
  const ev = new w.MouseEvent('click', { bubbles: true, cancelable: true });
  knapp.dispatchEvent(ev);
  sjekk('Godkjenn/Avvis: avbrutt bekreftelse sender ikke', ev.defaultPrevented);
} else sjekk('Godkjenn/Avvis-knapp finnes', false);
sjekk('knappene peker på det egne skjemaet utenfor hovedskjemaet', [...d.querySelectorAll('.b2b-bekreft')].every(k => k.getAttribute('form') === 'amendo-b2b-behandle') && !d.querySelector('form[action*="admin-post.php"] #amendo-b2b-behandle') && !!d.querySelector('#amendo-b2b-behandle input[name=_wpnonce]'));

// Rydd: modulen av igjen, så suiter som kjøres etter mot samme server ikke påvirkes.
await klargjor({ aktive: null, kundegrupper: null, slett_brukere: true });
ferdig();
