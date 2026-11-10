// 1.4.39: «Stengt for henting» — ukedager og enkeltdatoer i kortet Henting,
// lagret som amendo_henting_stengte_ukedager/amendo_henting_stengte_datoer,
// lister i /settings og /leveringsregler, og kassen (wc/v3/orders) avviser en
// ordre med henting på en stengt dag. Se includes/henting-stengt.php.
//
// Ukedagene (2026): 15.11 søndag, 16.11 mandag, 18.11 onsdag, 21.11 lørdag,
// 24.12 og 31.12 torsdag; 24.12.2027 og 31.12.2027 fredag; 29.02.2028 tirsdag.
import { readFileSync } from 'fs';
import { B, REPO, get, sjekk, ferdig } from '../lib/felles.mjs';

const opt = o => get('/amendo-test/v1/opt', o);
const lagre = post => get('/amendo-test/v1/v1414-lagre', { post });
const side = async () => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=admin')).text();
const tilstand = () => get('/amendo-test/v1/v1439-tilstand');
const settings = () => get('/amendo-settings/v1/settings');
const regler = () => get('/amendo-settings/v1/leveringsregler');
const ordre = (meta, id) => get('/amendo-test/v1/v1439-ordre', { meta, id });
const henting = dato => ordre({ _leveringstype: 'henting', _hentested: 'Stasjonen', _hentedato: dato, _hentetid: '11:15' });
const avvist = r => r.status === 400 && r.kode === 'amendo_henting_stengt' && r.nye === 0;
const laget = r => r.status === 201 && r.id > 0 && r.nye === 1;

await get('/amendo-test/v1/setup');
await opt({ amendo_henting_stengte_ukedager: null, amendo_henting_stengte_datoer: null, amendo_henting_ikke_dager: null });

// ── Standard: ingenting stengt ──────────────────────────────────────────────
console.log('Standard (ingenting lagret)');
let s = await settings();
sjekk('/settings: henting.stengte_ukedager og stengte_datoer er tomme lister', JSON.stringify(s.henting?.stengte_ukedager) === '[]' && JSON.stringify(s.henting?.stengte_datoer) === '[]', s.henting);
sjekk('/settings: henting.gamle_felter står som før', Array.isArray(s.henting?.gamle_felter), s.henting);
let g = (await regler()).global;
sjekk('/leveringsregler: global.stengte_ukedager og stengte_datoer tomme, ikke_dager som før', JSON.stringify(g?.stengte_ukedager) === '[]' && JSON.stringify(g?.stengte_datoer) === '[]' && JSON.stringify(g?.ikke_dager) === '["lørdag","søndag"]', g);
let html = await side();
const felt = html.slice(html.indexOf('id="henting-stengt"'), html.indexOf('</textarea>', html.indexOf('id="henting-stengt"')));
sjekk('admin: «Stengt for henting» i kortet Henting, etter ikke-dagene og i hovedskjemaet', html.includes('id="henting-stengt"') && html.indexOf('henting_ikke_dager[]') < html.indexOf('id="henting-stengt"') && html.indexOf('id="tab-levering"') < html.indexOf('id="henting-stengt"') && html.lastIndexOf('</form>', html.indexOf('id="henting-stengt"')) < html.lastIndexOf('<form', html.indexOf('id="henting-stengt"')));
sjekk('admin: markøren, sju ukedager uten kryss og tomt datofelt', felt.includes('name="henting_stengt[finnes]"') && (felt.match(/name="henting_stengt\[ukedager\]\[\]"/g) || []).length === 7 && !/henting_stengt\[ukedager\]\[\]" value="[^"]+"\s+checked/.test(felt) && /name="henting_stengt\[datoer\]"[^>]*>$/.test(felt), felt.slice(-300));
sjekk('admin: forklarer at det ikke er det samme som ikke-dagene', html.includes('Ikke det samme som dagene over'));
let r = await henting('2026-11-15');
sjekk('kassen: henting på en søndag går som før', laget(r), r);

// ── Lagring ─────────────────────────────────────────────────────────────────
console.log('\nLagring');
await lagre({
  henting_ikke_dager: ['lørdag'],
  henting_stengt: {
    finnes: '1',
    ukedager: ['søndag', 'mandag', 'fredagx', ' Lørdag ', 'søndag'],
    datoer: '24.12, 17/5\n1.1.\n24.12.2026; 31.02, 29.02, 29.02.2027, tull, 2026-12-31, 24.12, <b>1.6</b>',
  },
});
let t = await tilstand();
sjekk('ukedager: bare gyldige, unike, i ukerekkefølge (store bokstaver/mellomrom ok)', t.ukedager === '["mandag","lørdag","søndag"]', t.ukedager);
const DATOER = ['01.01', '29.02', '17.05', '24.12', '24.12.2026', '31.12.2026'];
sjekk('datoer: normalisert (17/5 → 17.05, 2026-12-31 → 31.12.2026), sortert, uten 31.02/29.02.2027/tekst/HTML/dublett', t.datoer === JSON.stringify(DATOER), t.datoer);
sjekk('ikke-dagene lagres for seg som før', JSON.parse(t.ikke_dager || 'null')?.join() === 'lørdag', t.ikke_dager);
s = await settings();
sjekk('/settings: listene', JSON.stringify(s.henting?.stengte_ukedager) === '["mandag","lørdag","søndag"]' && JSON.stringify(s.henting?.stengte_datoer) === JSON.stringify(DATOER), s.henting);
g = (await regler()).global;
sjekk('/leveringsregler: de samme listene, ikke_dager uendret', JSON.stringify(g?.stengte_ukedager) === '["mandag","lørdag","søndag"]' && JSON.stringify(g?.stengte_datoer) === JSON.stringify(DATOER) && JSON.stringify(g?.ikke_dager) === '["lørdag"]', g);
html = await side();
sjekk('admin: kryss på mandag, lørdag og søndag', ['mandag', 'lørdag', 'søndag'].every(d => new RegExp(`value="${d}"\\s+checked`).test(html.slice(html.indexOf('id="henting-stengt"')))) && !/value="tirsdag"\s+checked/.test(html.slice(html.indexOf('id="henting-stengt"'))));
sjekk('admin: datoene i feltet', html.includes('>' + DATOER.join(', ') + '</textarea>'));
await lagre({ henting_cutoff: '10:00' });
t = await tilstand();
sjekk('lagring uten markøren: listene står', t.ukedager === '["mandag","lørdag","søndag"]' && t.datoer === JSON.stringify(DATOER), t);

// ── Kassen ──────────────────────────────────────────────────────────────────
console.log('\nKassen (wc/v3/orders)');
r = await henting('2026-11-15');
sjekk('søndag (stengt ukedag): 400 amendo_henting_stengt, ingen ordre', avvist(r), r);
sjekk('… meldingen sier hvilken dag', /stengt for henting søndag 15\.11/.test(r.melding || '') && r.data?.status === 400 && r.data?.hentedato === '2026-11-15', [r.melding, r.data]);
r = await henting('2026-11-16');
sjekk('mandag: avvist', avvist(r), r);
r = await henting('2026-11-21');
sjekk('lørdag: avvist (både ikke-dag og stengt)', avvist(r), r);
r = await henting('2026-11-18');
sjekk('onsdag: ordren lages, med hentedatoen', laget(r) && r.hentedato === '2026-11-18', r);
r = await henting('2026-12-24');
sjekk('24.12 (hvert år, en torsdag): avvist', avvist(r) && /torsdag 24\.12/.test(r.melding || ''), r);
r = await henting('2027-12-24');
sjekk('24.12 neste år (fredag): avvist', avvist(r), r);
r = await henting('2026-12-31');
sjekk('31.12.2026 (bare det året): avvist', avvist(r), r);
r = await henting('2027-12-31');
sjekk('31.12.2027 (fredag): lages', laget(r), r);
r = await henting('2028-02-29');
sjekk('29.02 i et skuddår (tirsdag): avvist', avvist(r), r);
r = await henting('2026-05-17');
sjekk('17.05: avvist (17/5 lagret som 17.05)', avvist(r), r);
r = await ordre({ _hentested: 'Stasjonen', _hentedato: '2026-11-15' });
sjekk('uten _leveringstype, men med hentedato på en stengt dag: avvist', avvist(r), r);
r = await ordre({ _leveringstype: 'levering', _hentedato: '2026-11-15', _hentetid: '11:15' });
sjekk('levering på en stengt dag: lages (gjelder bare henting)', laget(r), r);
r = await ordre({ hentedato_stasjonen: '15/11/2026' });
sjekk('den gamle kassen (uten _hentedato): lages som før', laget(r), r);
r = await ordre({});
sjekk('uten noen hentefelt: lages', laget(r), r);
r = await henting('2026-02-31');
sjekk('ugyldig dato: ikke denne sjekkens sak, lages', laget(r), r);
const finnes = (await henting('2026-11-18')).id;
r = await ordre({ _hentedato: '2026-11-15' }, finnes);
sjekk('endring av en ordre som finnes (PUT) til en stengt dag: ikke avvist', r.status === 200 && r.nye === 0 && r.id === finnes, r);

// ── Tømt igjen ──────────────────────────────────────────────────────────────
console.log('\nTømt');
await lagre({ henting_stengt: { finnes: '1', datoer: '' } });
t = await tilstand();
sjekk('ingen kryss og tomt felt: tomme lister', t.ukedager === '[]' && t.datoer === '[]', t);
s = await settings();
sjekk('/settings: tomme lister', JSON.stringify(s.henting?.stengte_ukedager) === '[]' && JSON.stringify(s.henting?.stengte_datoer) === '[]', s.henting);
r = await henting('2026-11-15');
sjekk('søndag går igjen', laget(r), r);
await opt({ amendo_henting_stengte_ukedager: '{"a":"søndag"}', amendo_henting_stengte_datoer: '"24.12"' });
s = await settings();
r = await henting('2026-12-24');
sjekk('ødelagt option (objekt / ikke liste): leses som gyldige navn eller tom liste, ingen feil', s.henting?.stengte_ukedager?.join() === 'søndag' && JSON.stringify(s.henting?.stengte_datoer) === '[]' && laget(r), [s.henting, r.status]);

// ── Kildekoden ──────────────────────────────────────────────────────────────
console.log('\nKildekoden');
const kilde = readFileSync(REPO + '/includes/henting-stengt.php', 'utf8');
sjekk('ingen butikknavn', !/garçon|garcon|aanerud|emmk/i.test(kilde));
sjekk('ingen logging', !/error_log|wc_get_logger|->log\(/.test(kilde));

await opt({ amendo_henting_stengte_ukedager: null, amendo_henting_stengte_datoer: null, amendo_henting_ikke_dager: null });
ferdig();
