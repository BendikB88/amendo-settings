// 1.4.14 punkt 4–6: stengte perioder per avdeling, hentedato til Tellix, og
// hentelinjen/filteret i modulen «Hentedato på ordrer». Se includes/stengte.php
// og includes/moduler/henting.php.
//
// ⚠ Moduler lastes når pluginen lastes: tilstanden settes i ett kall og sjekkes
// i det neste.
import { JSDOM } from 'jsdom';
import { readFileSync } from 'fs';
import { B, ADMIN_JS, JQUERY, get, sjekk, ferdig } from '../lib/felles.mjs';

const klargjor = body => get('/amendo-test/v1/v1414-klargjor', body);
const lagre = post => get('/amendo-test/v1/v1414-lagre', { post });
const side = async () => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=admin')).text();
const ordre = meta => get('/amendo-test/v1/v1414-ordre', { meta });
const vis = async id => (await fetch(B + '/amendo-test/v1/v1414-vis&id=' + id)).json();
const tellixFelt = (id, felt) => get('/amendo-test/v1/v1414-tellix-felt', { id, felt });
const filter = (dato, sted, ider) => get('/amendo-test/v1/v1414-filter', { dato, sted, ider });
const regler = async () => (await fetch(B + '/amendo-settings/v1/leveringsregler')).json();

await get('/amendo-test/v1/setup');
const GATEWAY = {
  pairs: [
    { date: 'leveringsdato', time: 'leveringstidspunkt' },
    { date: 'hentetid_kaker', time: 'hentetidspunkt_kaker' },
    { date: 'hentetid_helsebygget', time: 'tidspunkt_helsebygget' },
    { date: 'hentedato_stasjonen', time: 'tidspunkt_stasjonen' },
  ],
  method: 'hentested',
  method_value: 'Levering',
  delivery_time: 'leveringstidspunkt',
};
const NYTT = { date: '_hentedato', time: '_hentetid' };
await klargjor({ hpos: true });

// ── 4. Stengte perioder per avdeling ────────────────────────────────────────
console.log('Stengte perioder per avdeling');
await klargjor({
  moduler: null,
  avdelinger: [{ navn: 'Stasjonen' }, { navn: 'Helsebygget' }],
  stengte: [{ label: 'Sommer', fra: '2026-07-01', til: '2026-07-31' }],
});
let lr = await regler();
let p = lr.global?.stengte_perioder || [];
sjekk('eksisterende periode (uten avdeling) får avdeling: "" (alle)', p.length === 1 && p[0].avdeling === '' && p[0].label === 'Sommer' && p[0].fra === '2026-07-01', p);

let l = await lagre({ stengte_perioder: [
  { label: 'Sommer', fra: '2026-07-01', til: '2026-07-31', avdeling: '' },
  { label: 'Ombygging', fra: '2026-11-02', til: '2026-11-06', avdeling: 'Stasjonen' },
  { label: 'Uten dato', fra: '', til: '2026-11-06', avdeling: 'Stasjonen' },
  { label: '<b>x</b>', fra: '2026-12-24', til: '2026-12-26', avdeling: 'Helse<script>' },
] });
sjekk('lagret med avdeling; rad uten dato hoppes over', l.stengte?.length === 3 && l.stengte[1].avdeling === 'Stasjonen' && l.stengte[0].avdeling === '', l.stengte);
sjekk('avdeling og beskrivelse renset', l.stengte?.[2]?.avdeling === 'Helse' && l.stengte?.[2]?.label === 'x', l.stengte?.[2]);
lr = await regler();
p = lr.global?.stengte_perioder || [];
sjekk('/leveringsregler: avdeling på hver periode', p.length === 3 && p.every(x => typeof x.avdeling === 'string') && p[1].avdeling === 'Stasjonen', p);
sjekk('/leveringsregler: resten uendret (label, fra, til)', JSON.stringify(Object.keys(p[1]).sort()) === '["avdeling","fra","label","til"]', Object.keys(p[1] || {}));
l = await lagre({ butikk_navn: 'Test' });
sjekk('lagring uten perioder i skjemaet: tømmer som før', JSON.stringify(l.stengte) === '[]', l.stengte);

await lagre({ stengte_perioder: [{ label: 'Ombygging', fra: '2026-11-02', til: '2026-11-06', avdeling: 'Stasjonen' }, { label: 'Gammel', fra: '2026-11-09', til: '2026-11-10', avdeling: 'Nedlagt' }] });
let html = await side();
sjekk('admin: velger per periode med «Alle avdelinger» og avdelingene', /name="stengte_perioder\[0\]\[avdeling\]"/.test(html) && html.includes('Alle avdelinger') && /<option value="Stasjonen"\s+selected/.test(html));
sjekk('admin: periode for en avdeling som ikke finnes, vises som det', html.includes('(finnes ikke: Nedlagt)'));

const dom = new JSDOM(html, { runScripts: 'outside-only' });
const w = dom.window;
w.eval(readFileSync(JQUERY, 'utf8'));
w.eval('window.jQuery = jQuery.noConflict(true);');
w.eval(readFileSync(ADMIN_JS, 'utf8'));
await new Promise(r => w.jQuery(r));
w.document.querySelector('#legg-til-periode').click();
const ny = [...w.document.querySelectorAll('#stengte-perioder-liste .steng-rad')].at(-1);
const velger = ny?.querySelector('select');
sjekk('jsdom: ny periode får avdelingsvelger med riktig indeks', velger?.name === 'stengte_perioder[2][avdeling]' && velger?.value === '' && [...velger.options].map(o => o.value).includes('Stasjonen'), velger?.name);

// ── Modulen av: ingenting endres ────────────────────────────────────────────
console.log('\nModulen av (emmk, Dorothea, Aanerud)');
const ny1 = (await ordre({ _leveringstype: 'henting', _hentested: 'Stasjonen', _hentedato: '2026-10-21', _hentetid: '11:15' })).id;
let v = await vis(ny1);
sjekk('ingen hentelinje noe sted', v.linje === 'MODUL_AV' && v.ordreside === '' && v.epost_html === '' && v.wpo === '' && !('amendo_henting' in v.kolonner), v);
sjekk('ingen Tellix-krok', v.tellix_kroker === false);
let tf = await tellixFelt(ny1, GATEWAY);
sjekk('feltlista til Tellix urørt', JSON.stringify(tf.felt) === JSON.stringify(GATEWAY), tf.felt);

await klargjor({ moduler: ['henting'] });

// ── 6. Hentelinjen ──────────────────────────────────────────────────────────
console.log('\nHentelinjen');
const lev = (await ordre({ _leveringstype: 'levering', _hentested: 'Stasjonen', _hentedato: '2026-10-21', _hentetid: '11:15' })).id;
const utenTid = (await ordre({ _leveringstype: 'henting', _hentested: 'Helsebygget', _hentedato: '2026-10-25' })).id;
const gammel = (await ordre({ hentedato_stasjonen: '21/10/2026', tidspunkt_stasjonen: '11:15' })).id;
const ugyldig = (await ordre({ _leveringstype: 'henting', _hentested: 'Stasjonen', _hentedato: '2026-02-31' })).id;
const xss = (await ordre({ _leveringstype: 'henting', _hentested: '<img src=x onerror=1>', _hentedato: '2026-10-21' })).id;

v = await vis(ny1);
const LINJE = 'Henting: Stasjonen, onsdag 21.10 kl. 11:15';
sjekk('henting: «' + LINJE + '»', v.linje === LINJE, v.linje);
sjekk('ordresiden i wp-admin', v.ordreside.includes(LINJE));
sjekk('kolonnen «Henting» etter status', JSON.stringify(Object.keys(v.kolonner)) === '["cb","order_number","order_status","amendo_henting","order_total"]' && v.kolonne === LINJE, [Object.keys(v.kolonner), v.kolonne]);
sjekk('e-post til butikken (HTML)', v.epost_html.includes(LINJE));
sjekk('e-post til kunden', v.epost_kunde.includes(LINJE));
sjekk('e-post som ren tekst', v.epost_tekst.trim() === LINJE, v.epost_tekst);
sjekk('WP Overnight Order List (wpo_wcol_custom_row), ordren som objekt', v.wpo.includes(LINJE));
sjekk('… og som ID i et annet argument', v.wpo_id.includes(LINJE));

v = await vis(lev);
sjekk('levering: «Levering: onsdag 21.10 kl. 11:15» (uten hentested)', v.linje === 'Levering: onsdag 21.10 kl. 11:15', v.linje);
v = await vis(utenTid);
sjekk('uten hentetid: «Henting: Helsebygget, søndag 25.10»', v.linje === 'Henting: Helsebygget, søndag 25.10', v.linje);
v = await vis(gammel);
sjekk('gammel ordre (uten _hentedato): ingenting nytt noe sted', v.linje === null && v.ordreside === '' && v.epost_html === '' && v.epost_tekst === '' && v.wpo === '' && v.kolonne === '–', v);
v = await vis(ugyldig);
sjekk('ugyldig dato (31.02): som gammel ordre', v.linje === null && v.ordreside === '', v.linje);
v = await vis(xss);
sjekk('hentested escapes', !v.ordreside.includes('<img') && v.ordreside.includes('&lt;img'), v.ordreside);

// ── 5. Hentedato til Tellix ─────────────────────────────────────────────────
console.log('\nHentedato til Tellix (amendo_tellix_delivery_fields)');
tf = await tellixFelt(ny1, GATEWAY);
sjekk('ny ordre: {date: _hentedato, time: _hentetid} først i pairs', JSON.stringify(tf.felt.pairs) === JSON.stringify([NYTT, ...GATEWAY.pairs]), tf.felt.pairs);
sjekk('… og method/method_value/delivery_time urørt', tf.felt.method === 'hentested' && tf.felt.method_value === 'Levering' && tf.felt.delivery_time === 'leveringstidspunkt' && JSON.stringify(Object.keys(tf.felt)) === JSON.stringify(Object.keys(GATEWAY)), Object.keys(tf.felt));
tf = await tellixFelt(gammel, GATEWAY);
sjekk('gammel ordre: urørt', JSON.stringify(tf.felt) === JSON.stringify(GATEWAY), tf.felt);
tf = await tellixFelt(ny1, { ...GATEWAY, pairs: [NYTT, ...GATEWAY.pairs] });
sjekk('paret finnes alt: ikke doblet', JSON.stringify(tf.felt.pairs) === JSON.stringify([NYTT, ...GATEWAY.pairs]), tf.felt.pairs);
tf = await tellixFelt(lev, GATEWAY);
sjekk('levering med _hentedato: paret først også her', JSON.stringify(tf.felt.pairs[0]) === JSON.stringify(NYTT), tf.felt.pairs);
tf = await tellixFelt(ny1, [['a', 'b']]);
sjekk('ukjent form (uten pairs): urørt', JSON.stringify(tf.felt) === '[["a","b"]]', tf.felt);
const dt = await get('/amendo-test/v1/v1414-gateway-dato', { id: ny1 });
sjekk('gatewayens egen leselogikk (kopi av 1.36.13) gir 2026-10-21 11:15:00', dt.resultat === '2026-10-21 11:15:00', dt);
const dtLev = await get('/amendo-test/v1/v1414-gateway-dato', { id: lev });
sjekk('… også for levering: _hentetid, ikke leveringstidspunkt (ny ordre har ikke meta hentested)', dtLev.resultat === '2026-10-21 11:15:00' && dtLev.er_levering === false, dtLev);
const dtGammel = await get('/amendo-test/v1/v1414-gateway-dato', { id: gammel });
sjekk('… og gammel ordre leses som før (hentedato_stasjonen)', dtGammel.resultat === '2026-10-21 11:15:00', dtGammel);

// ── 6. Filter i ordrelisten ─────────────────────────────────────────────────
console.log('\nFilter i ordrelisten (HPOS)');
const gammelHelse = (await ordre({ hentetid_helsebygget: '21/10/2026' })).id;
const gammelKake = (await ordre({ hentetid_kaker: '21/10/2026' })).id;
const annenDag = (await ordre({ _leveringstype: 'henting', _hentested: 'Stasjonen', _hentedato: '2026-10-22' })).id;
const alle = [ny1, lev, utenTid, gammel, gammelHelse, gammelKake, annenDag, xss];
let f = await filter('2026-10-21', '', alle);
sjekk('ordrene ligger i HPOS (som på garcon.no)', f.hpos === true, f.hpos);
sjekk('dato 21.10: nye (Y-m-d) og alle tre gamle felt (d/m/Y)', JSON.stringify(f.funnet.sort((a, b) => a - b)) === JSON.stringify([ny1, lev, gammel, gammelHelse, gammelKake, xss].sort((a, b) => a - b)), f.funnet);
sjekk('… ikke andre dager', !f.funnet.includes(annenDag) && !f.funnet.includes(utenTid));
f = await filter('2026-10-21', 'Stasjonen', alle);
sjekk('dato + hentested Stasjonen', JSON.stringify(f.funnet) === JSON.stringify([ny1]), f.funnet);
f = await filter('', 'Stasjonen', alle);
sjekk('bare hentested', JSON.stringify(f.funnet.sort((a, b) => a - b)) === JSON.stringify([ny1, annenDag].sort((a, b) => a - b)), f.funnet);
f = await filter('', '__levering', alle);
sjekk('hentested «Levering»', JSON.stringify(f.funnet) === JSON.stringify([lev]), f.funnet);
f = await filter('', '', alle);
sjekk('uten filter: alle', f.funnet.length === alle.length, f.funnet.length);
f = await filter('21/10/2026', '', alle);
sjekk('ugyldig datoformat i filteret: ignoreres (alle)', f.funnet.length === alle.length, f.funnet.length);
sjekk('feltnavnet kolliderer ikke med snutten «Orderfilter» (amendo_delivery_date)', !f.html.includes('amendo_delivery_date'));
sjekk('filterfeltene: dato og hentested med avdelingene', f.html.includes('name="amendo_hentedato"') && f.html.includes('name="amendo_hentested"') && f.html.includes('>Stasjonen<') && f.html.includes('>Levering<'));

await klargjor({ moduler: null, stengte: null, avdelinger: null });
ferdig();
