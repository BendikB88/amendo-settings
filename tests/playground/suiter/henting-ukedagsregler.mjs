// 1.4.40: «Egne regler for ukedager» i kortet Henting — ukedag, tillatte
// kategorier (underkategorier teller med) og bestillingsfrist (ukedag +
// klokkeslett før dagen). `global.ukedagsregler` i /leveringsregler, og
// wc/v3/orders avviser en ordre som bryter regelen. «Stengt for henting» går
// foran. Se includes/henting-ukedagsregler.php.
//
// Ukedagene (2026): 11.11 onsdag, 12.11 torsdag, 15.11 søndag, 16.11 mandag,
// 17.11 tirsdag, 18.11 onsdag, 20.11 fredag, 21.11 lørdag; 11.06 torsdag og
// 14.06 søndag (sommertid). Butikkens tidssone settes til Europe/Oslo.
import { readFileSync } from 'fs';
import { JSDOM } from 'jsdom';
import { B, REPO, ADMIN_JS, JQUERY, get, sjekk, ferdig } from '../lib/felles.mjs';

const opt = o => get('/amendo-test/v1/opt', o);
const lagre = post => get('/amendo-test/v1/v1414-lagre', { post });
const side = async () => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=admin')).text();
const tilstand = () => get('/amendo-test/v1/v1440-tilstand');
const regler = async () => (await get('/amendo-settings/v1/leveringsregler')).global;
const brudd = async (dato, produkter, naa) => (await get('/amendo-test/v1/v1440-brudd', { dato, produkter, naa })).brudd;
const ordre = (meta, varer, id) => get('/amendo-test/v1/v1440-ordre', { meta, varer, id });
const henting = (dato, ...varer) => ordre({ _leveringstype: 'henting', _hentested: 'Stasjonen', _hentedato: dato, _hentetid: '11:15' }, varer.map(v => typeof v === 'object' ? v : { product_id: v }));
const naa = ts => opt({ amendo_test_naa: ts === null ? null : String(ts) });
const utc = (...a) => Date.UTC(...a) / 1000;
const avvist = (r, grunn) => r.status === 400 && r.kode === 'amendo_henting_ukedagsregel' && r.nye === 0 && r.data?.grunn === grunn;
const laget = r => r.status === 201 && r.id > 0 && r.nye === 1;

await get('/amendo-test/v1/setup');
const P = await get('/amendo-test/v1/v1440-oppsett');
const tz0 = await tilstand();
await opt({ amendo_henting_ukedagsregler: null, amendo_henting_stengte_ukedager: null, amendo_henting_stengte_datoer: null, amendo_test_naa: null, timezone_string: 'Europe/Oslo' });

// Fristen søndag 15.11: torsdag 12.11 13:00 i Oslo (vintertid) = 12:00 UTC.
const FRIST = utc(2026, 10, 12, 12, 0, 0);

// ── Standard ────────────────────────────────────────────────────────────────
console.log('Standard (ingenting lagret)');
let g = await regler();
sjekk('/leveringsregler: global.ukedagsregler er en tom liste, resten som før', JSON.stringify(g?.ukedagsregler) === '[]' && Array.isArray(g?.stengte_ukedager) && Array.isArray(g?.ikke_dager), g);
await naa(FRIST + 86400);
let r = await henting('2026-11-15', P.brod);
sjekk('kassen: brød til søndag etter «fristen» går som før', laget(r), r);
let html = await side();
const start = html.indexOf('id="henting-ukedagsregler"');
sjekk('admin: kortet Henting, etter «Stengt for henting», i hovedskjemaet', start > 0 && html.indexOf('id="henting-stengt"') < start && html.indexOf('id="tab-levering"') < start && html.lastIndexOf('</form>', start) < html.lastIndexOf('<form', start));
const felt = html.slice(start, html.indexOf('id="ukedagsregel-mal"', start));
sjekk('admin: lagringsdelen, markøren, ingen regler og «+ Legg til regel»', /data-amendo-del="ukedagsregler"/.test(html.slice(start - 100, start + 100)) && felt.includes('name="amendo_endret[ukedagsregler]"') && felt.includes('name="henting_ukedagsregler[finnes]"') && !felt.includes('ukedagsregel-rad') && felt.includes('id="ukedagsregel-legg-til"'), felt.slice(0, 600));
sjekk('admin: forklaringen (bare henting, Stengt for henting går foran)', html.includes('Gjelder bare henting. «Stengt for henting» går foran.'));

// ── Lagring ─────────────────────────────────────────────────────────────────
console.log('\nLagring');
await lagre({
  henting_ukedagsregler: {
    finnes: '1',
    regler: {
      3: { ukedag: 'søndag', kategorier: [String(P.kaker), '', 'x', String(P.kaker)], frist_ukedag: 'torsdag', frist_tid: '13:00' },
      5: { ukedag: 'søndag', kategorier: [String(P.brodkat)] },
      7: { ukedag: 'lørdag', frist_ukedag: 'fredag', frist_tid: '9:30' },
      8: { ukedag: 'mandag', kategorier: [''], frist_ukedag: '', frist_tid: '' },
      9: { ukedag: 'tirsdag', kategorier: [String(P.bakst)], frist_ukedag: 'mandag', frist_tid: '25:00' },
      10: { ukedag: 'fredagx', kategorier: [String(P.kaker)] },
      11: { ukedag: ' Onsdag ', kategorier: [String(P.brodkat)], frist_ukedag: 'onsdag', frist_tid: '12:00' },
      12: { ukedag: 'fredag', kategorier: [String(P.kaker)], frist_ukedag: 'torsdag', frist_tid: '' },
    },
  },
});
let t = await tilstand();
const LAGRET = [
  { ukedag: 'tirsdag', kategorier: [P.bakst], frist_ukedag: '', frist_tid: '' },
  { ukedag: 'onsdag', kategorier: [P.brodkat], frist_ukedag: 'onsdag', frist_tid: '12:00' },
  { ukedag: 'fredag', kategorier: [P.kaker], frist_ukedag: '', frist_tid: '' },
  { ukedag: 'lørdag', kategorier: [], frist_ukedag: 'fredag', frist_tid: '09:30' },
  { ukedag: 'søndag', kategorier: [P.kaker], frist_ukedag: 'torsdag', frist_tid: '13:00' },
];
sjekk('renset: én per ukedag (den første), ukerekkefølge, unike kategorier, «9:30» → 09:30, ugyldig/halv frist ute, regel uten noe ute, ukjent ukedag ute', t.regler === JSON.stringify(LAGRET), t.regler);
g = await regler();
const son = g.ukedagsregler?.find(x => x.ukedag === 'søndag');
sjekk('/leveringsregler: fem regler i ukerekkefølge', g.ukedagsregler?.map(x => x.ukedag).join() === 'tirsdag,onsdag,fredag,lørdag,søndag', g.ukedagsregler);
sjekk('søndag: kategorien med id/slug/navn', JSON.stringify(son?.kategorier) === JSON.stringify([{ id: P.kaker, slug: 'kaker-v1440', navn: 'Kaker v1440' }]), son);
sjekk('søndag: tillatte_kategori_ider med under- og underunderkategorien, sortert', JSON.stringify(son?.tillatte_kategori_ider) === JSON.stringify([P.kaker, P.blotkaker, P.marsipankaker].sort((a, b) => a - b)), son);
sjekk('søndag: frist torsdag 13:00, tre dager før', JSON.stringify(son?.frist) === '{"ukedag":"torsdag","tid":"13:00","dager_for":3}', son?.frist);
const lor = g.ukedagsregler?.find(x => x.ukedag === 'lørdag'), ons = g.ukedagsregler?.find(x => x.ukedag === 'onsdag'), tir = g.ukedagsregler?.find(x => x.ukedag === 'tirsdag');
sjekk('lørdag: bare frist (fredag 09:30, dagen før), ingen kategorier', JSON.stringify(lor) === '{"ukedag":"lørdag","kategorier":[],"tillatte_kategori_ider":[],"frist":{"ukedag":"fredag","tid":"09:30","dager_for":1}}', lor);
sjekk('onsdag med frist onsdag: en uke før (dager_for 7)', ons?.frist?.dager_for === 7, ons);
sjekk('tirsdag: bare kategorier, frist null', tir?.frist === null && tir?.kategorier?.[0]?.id === P.bakst, tir);
html = await side();
const rader = html.slice(html.indexOf('id="ukedagsregler-liste"'), html.indexOf('id="ukedagsregel-legg-til"'));
sjekk('admin: fem regler', (rader.match(/class="ukedagsregel-rad"/g) || []).length === 5, rader.length);
sjekk('admin: søndag med Kaker valgt og frist torsdag 13:00', /\[4\]\[ukedag\]">[\s\S]*?value="søndag" selected/.test(rader) && /\[4\]\[frist_ukedag\]">[\s\S]*?value="torsdag" selected/.test(rader) && rader.includes('name="henting_ukedagsregler[regler][4][frist_tid]" value="13:00"') && new RegExp(`\\[4\\]\\[kategorier\\]\\[\\][^>]*>[\\s\\S]*?value="${P.kaker}"\\s+selected`).test(rader), rader.slice(rader.indexOf('[4][ukedag]'), rader.indexOf('[4][ukedag]') + 400));

const kopi = t.regler;
await lagre({ amendo_endret: { ukedagsregler: '' }, henting_ukedagsregler: { finnes: '1', regler: {} } });
t = await tilstand();
sjekk('urørt i nettleseren (flagget tomt): reglene står', t.regler === kopi, t.regler);
await lagre({ henting_cutoff: '10:00' });
t = await tilstand();
sjekk('lagring uten markøren: reglene står', t.regler === kopi, t.regler);

// ── Kassen ──────────────────────────────────────────────────────────────────
console.log('\nKassen (wc/v3/orders), før fristen');
await naa(FRIST - 1);
r = await henting('2026-11-15', P.kake);
sjekk('søndag, kake, før fristen: lages', laget(r), r);
r = await henting('2026-11-15', P.blotkake, P.marsipan, { product_id: P.variabel, variation_id: P.variant }, P.begge);
sjekk('underkategori, underunderkategori, variant av en kake og vare i både Kaker og Brød: lages', laget(r), r);
r = await henting('2026-11-15', P.kake, P.brod);
sjekk('kake + brød: 400 amendo_henting_ukedagsregel, grunn kategori, ingen ordre', avvist(r, 'kategori'), r);
sjekk('… varene utenfor og de tillatte kategoriene i svaret', JSON.stringify(r.data?.varer) === JSON.stringify([{ produkt_id: P.brod, variant_id: 0, navn: 'Grovbrød' }]) && JSON.stringify(r.data?.tillatte_kategorier) === '["Kaker v1440"]' && r.data?.status === 400 && r.data?.hentedato === '2026-11-15', r.data);
sjekk('… meldingen', r.melding === 'Til henting søndag 15.11 kan du bare bestille Kaker v1440. Fjern Grovbrød fra kurven, eller velg en annen dag.', r.melding);
r = await henting('2026-11-15', P.ukategorisert);
sjekk('vare uten egen kategori: avvist', avvist(r, 'kategori'), r);
r = await henting('2026-11-15', P.brod, P.bolle);
sjekk('to varer utenfor: begge i svaret, «og» i meldingen', avvist(r, 'kategori') && r.data?.varer?.length === 2 && /Fjern Grovbrød og Skolebolle fra kurven/.test(r.melding || ''), r);
r = await henting('2026-11-17', P.bolle);
sjekk('tirsdag (bare Bakst, ingen frist): bolle lages', laget(r), r);
r = await henting('2026-11-17', P.kake);
sjekk('tirsdag: kake avvist', avvist(r, 'kategori'), r);
r = await henting('2026-11-16', P.brod, P.kake);
sjekk('mandag (ingen regel): lages', laget(r), r);
r = await henting('2026-11-15');
sjekk('søndag uten varer før fristen: lages (ingen vare utenfor)', laget(r), r);

console.log('\nKassen, fristen');
await naa(FRIST);
r = await henting('2026-11-15', P.kake);
sjekk('søndag, kake, kl. 13:00:00 torsdag: avvist, grunn frist', avvist(r, 'frist'), r);
sjekk('… fristen i Oslo-tid i svaret og meldingen', r.data?.frist === '2026-11-12T13:00:00+01:00' && r.melding === 'Fristen for å bestille til henting søndag 15.11 var torsdag 12.11 kl. 13:00. Velg en annen dag.', [r.data, r.melding]);
r = await henting('2026-11-15', P.brod);
sjekk('frist passert OG vare utenfor: grunn frist (sjekkes først)', avvist(r, 'frist'), r);
r = await henting('2026-11-22', P.kake);
sjekk('søndagen etter: lages (fristen er torsdag 19.11)', laget(r), r);
r = await ordre({ _hentested: 'Stasjonen', _hentedato: '2026-11-15' }, [{ product_id: P.kake }]);
sjekk('uten _leveringstype, med hentedato: avvist', avvist(r, 'frist'), r);
r = await ordre({ _leveringstype: 'levering', _hentedato: '2026-11-15', _hentetid: '11:15' }, [{ product_id: P.brod }]);
sjekk('levering: lages (gjelder bare henting)', laget(r), r);
r = await ordre({ hentedato_stasjonen: '15/11/2026' }, [{ product_id: P.brod }]);
sjekk('den gamle kassen (uten _hentedato): lages', laget(r), r);
r = await henting('2026-02-31', P.brod);
sjekk('ugyldig dato: lages', laget(r), r);
const finnes = (await henting('2026-11-16', P.brod)).id;
r = await ordre({ _hentedato: '2026-11-15' }, [], finnes);
sjekk('PUT av en ordre som finnes, til søndag etter fristen: ikke avvist', r.status === 200 && r.nye === 0 && r.id === finnes, r);

await naa(utc(2026, 10, 11, 10, 59, 59));
r = await henting('2026-11-18', P.brod);
sjekk('onsdag med frist onsdag 12:00 uka før: 11.11 kl. 11:59:59 lages', laget(r), r);
await naa(utc(2026, 10, 11, 11, 0, 0));
r = await henting('2026-11-18', P.brod);
sjekk('… 11.11 kl. 12:00 avvist', avvist(r, 'frist') && /var onsdag 11\.11 kl\. 12:00/.test(r.melding || ''), r);
await naa(utc(2026, 10, 20, 8, 29, 59));
r = await henting('2026-11-21', P.brod, P.bolle);
sjekk('lørdag (bare frist fredag 09:30): alle varer før fristen', laget(r), r);
await naa(utc(2026, 10, 20, 8, 30, 0));
r = await henting('2026-11-21', P.kake);
sjekk('… fredag 09:30 avvist', avvist(r, 'frist'), r);

let b = await brudd('2026-06-14', [P.kake], utc(2026, 5, 11, 10, 59, 59));
sjekk('sommertid: søndag 14.06, torsdag 11.06 kl. 12:59:59 Oslo = ikke brudd', b === null, b);
b = await brudd('2026-06-14', [P.kake], utc(2026, 5, 11, 11, 0, 0));
sjekk('sommertid: kl. 13:00 Oslo (11:00 UTC) = frist, +02:00', b?.grunn === 'frist' && b?.frist === '2026-06-11T13:00:00+02:00', b);

console.log('\n«Stengt for henting» går foran');
await naa(FRIST - 1);
await opt({ amendo_henting_stengte_ukedager: '["søndag"]' });
r = await henting('2026-11-15', P.kake);
sjekk('stengt søndag, tillatt kake før fristen: amendo_henting_stengt', r.status === 400 && r.kode === 'amendo_henting_stengt' && r.nye === 0, r);
await naa(FRIST);
r = await henting('2026-11-15', P.brod);
sjekk('stengt søndag, brød etter fristen: fortsatt amendo_henting_stengt', r.status === 400 && r.kode === 'amendo_henting_stengt', r);
await opt({ amendo_henting_stengte_ukedager: '[]', amendo_henting_stengte_datoer: '["15.11"]' });
r = await henting('2026-11-15', P.kake);
sjekk('stengt dato: amendo_henting_stengt', r.kode === 'amendo_henting_stengt', r);
await opt({ amendo_henting_stengte_ukedager: null, amendo_henting_stengte_datoer: null });

// ── admin.js i jsdom ────────────────────────────────────────────────────────
console.log('\nadmin.js i jsdom');
const dom = new JSDOM(await side(), { url: 'http://localhost/wp-admin/admin.php?page=amendo-settings', runScripts: 'outside-only' });
const w = dom.window;
w.confirm = () => true;
w.eval(readFileSync(JQUERY, 'utf8'));
w.eval('window.jQuery = jQuery.noConflict(true);');
w.eval(readFileSync(ADMIN_JS, 'utf8'));
await new Promise(res => w.jQuery(res));
const d = w.document;
const før = new Set([...d.querySelectorAll('#henting-ukedagsregler [name]')].map(e => e.name));
d.querySelector('#ukedagsregel-legg-til').click();
const ny = [...d.querySelectorAll('#ukedagsregler-liste .ukedagsregel-rad')].at(-1);
const navn = [...ny.querySelectorAll('[name]')].map(e => e.name);
sjekk('ny regel med et nummer ingen andre har, søk i kategorivelgeren', navn.length === 4 && navn.every(n => /^henting_ukedagsregler\[regler\]\[\d+\]\[/.test(n) && !før.has(n)) && !!ny.querySelector('.amendo-kategori-sok'), navn);
sjekk('malen er ikke en del av skjemaet, delen merket som endret', !d.querySelector('form [name*="__i__"]') && d.querySelector('#henting-ukedagsregler > .amendo-endret').value === '1');
ny.querySelector('.ukedagsregel-ny-kategori').click();
const kat = ny.querySelectorAll('.ukedagsregel-kategori select');
sjekk('«+ Kategori»: ny velger med regelens nummer og søk', kat.length === 2 && kat[1].name === kat[0].name && kat[1].previousElementSibling?.classList.contains('amendo-kategori-sok'), [...kat].map(e => e.name));
ny.querySelector('.ukedagsregel-fjern-kategori').click();
sjekk('× fjerner kategorien', ny.querySelectorAll('.ukedagsregel-kategori').length === 1);
ny.querySelector('.ukedagsregel-slett').click();
sjekk('«Slett regel»', d.querySelectorAll('#ukedagsregler-liste .ukedagsregel-rad').length === 5);

// ── Slettet kategori, ødelagt option, tømt ──────────────────────────────────
console.log('\nSlettet kategori, ødelagt option, tømt');
await opt({ amendo_henting_ukedagsregler: JSON.stringify([{ ukedag: 'fredag', kategorier: [99999999] }]) });
g = await regler();
sjekk('slettet kategori: id står, slug/navn null, ingen underkategorier', JSON.stringify(g.ukedagsregler) === '[{"ukedag":"fredag","kategorier":[{"id":99999999,"slug":null,"navn":null}],"tillatte_kategori_ider":[99999999],"frist":null}]', g.ukedagsregler);
await naa(null);
r = await henting('2026-11-20', P.kake);
sjekk('… og kassen avviser alt den dagen (ekte tid, uten frist)', avvist(r, 'kategori') && /bare bestille enkelte varer/.test(r.melding || ''), r);
await opt({ amendo_henting_ukedagsregler: '{"x":1}' });
g = await regler();
sjekk('ødelagt option: tom liste, ingen feil', JSON.stringify(g?.ukedagsregler) === '[]', g?.ukedagsregler);
await lagre({ henting_ukedagsregler: { finnes: '1' } });
t = await tilstand();
sjekk('lagret uten regler: tom liste', t.regler === '[]', t.regler);
r = await henting('2026-11-15', P.brod);
sjekk('søndag med brød går igjen', laget(r), r);

// ── Kildekoden ──────────────────────────────────────────────────────────────
console.log('\nKildekoden');
const kilde = readFileSync(REPO + '/includes/henting-ukedagsregler.php', 'utf8');
sjekk('ingen butikknavn', !/garçon|garcon|aanerud|emmk/i.test(kilde));
sjekk('ingen logging', !/error_log|wc_get_logger|->log\(/.test(kilde));

await opt({ amendo_henting_ukedagsregler: null, amendo_test_naa: null, timezone_string: tz0.tz || null });
ferdig();
