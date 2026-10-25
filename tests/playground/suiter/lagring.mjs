// 1.4.16: faner som lagres som én samlet option (forsiden, menyen,
// kategoriraden, avdelingene, stengte perioder, kategori-regler, B2B-
// kundegruppene) lagres bare når de er endret i nettleseren, og avvises når
// optionen er endret på serveren etter at siden ble lastet. Se
// includes/lagring.php. På garcon.no ble forsiden overskrevet med tomme felt
// av en lagring fra en annen fane.
import { JSDOM } from 'jsdom';
import { readFileSync } from 'fs';
import { B, ADMIN_JS, JQUERY, get, sjekk, ferdig } from '../lib/felles.mjs';

const sett = o => get('/amendo-test/v1/v1416-sett', o);
const versjon = () => get('/amendo-test/v1/v1416-versjon');
const lagre = post => get('/amendo-test/v1/v1416-lagre', { post });
const side = async (q = '') => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=admin' + q)).text();
const DELER = ['forside', 'meny', 'kategorirad', 'avdelinger', 'stengte', 'kategoriregler'];

await get('/amendo-test/v1/setup');
const FORSIDE = { hero_tittel: 'Original tittel', hero_undertekst: 'Original tekst' };
const MENY = [{ label: 'Original', href: '/original' }];
const STENGTE = [{ label: 'Sommer', fra: '2026-07-01', til: '2026-07-31', avdeling: '' }];
const REGLER = [{ kategori_id: 5, prosesseringstid: 1, steng_fra: '', steng_til: '' }];
await sett({ amendo_forside: FORSIDE, amendo_meny: MENY, amendo_stengte_perioder: STENGTE, amendo_kategori_regler: REGLER, amendo_butikk_navn: 'Butikk før', amendo_moduler: null });

// ── Skjemaet ────────────────────────────────────────────────────────────────
console.log('Skjemaet');
let v = await versjon();
let html = await side();
for (const del of DELER) {
  sjekk(`${del}: endret-flagg (tomt) og versjon i skjemaet`, html.includes(`name="amendo_endret[${del}]" value=""`) && html.includes(`name="amendo_versjon[${del}]" value="${v[del]}"`) && /^[0-9a-f]{32}$/.test(v[del] || ''), v[del]);
}

// ── Lagring fra en annen fane ───────────────────────────────────────────────
console.log('\nLagring fra en annen fane (det som skjedde på garcon.no)');
const urort = Object.fromEntries(DELER.map(d => [d, '']));
let l = await lagre({
  butikk_navn: 'Butikk etter',
  amendo_endret: urort, amendo_versjon: v,
  forside: { hero_tittel: '', hero_undertekst: '' },
  meny_elementer: [{ label: 'Fra gammel side', href: '/x' }],
  stengte_perioder: [],
  kategori_regler: [],
});
sjekk('fanen som ble endret, er lagret', l.butikk_navn === 'Butikk etter', l.butikk_navn);
sjekk('forsiden er IKKE overskrevet med tomme felt', l.forside?.hero_tittel === 'Original tittel' && l.forside?.hero_undertekst === 'Original tekst', l.forside);
sjekk('menyen står', JSON.stringify(l.meny) === JSON.stringify(MENY), l.meny);
sjekk('stengte perioder står (før: tømt når skjemaet ikke hadde noen)', l.stengte?.length === 1 && l.stengte[0].label === 'Sommer', l.stengte);
sjekk('kategori-reglene står', l.regler?.length === 1 && l.regler[0].kategori_id === 5, l.regler);
sjekk('ingen avvisning', /saved=1/.test(l.til || ''), l.til);

// ── Endret i nettleseren ────────────────────────────────────────────────────
console.log('\nEndret i nettleseren');
v = await versjon();
l = await lagre({ amendo_endret: { ...urort, forside: '1', meny: '1' }, amendo_versjon: v,
  forside: { hero_tittel: 'Ny tittel' }, meny_elementer: [{ label: 'Ny', href: '/ny' }] });
sjekk('forsiden lagret', l.forside?.hero_tittel === 'Ny tittel', l.forside);
sjekk('menyen lagret', l.meny?.[0]?.label === 'Ny', l.meny);
v = await versjon();
l = await lagre({ amendo_endret: { ...urort, stengte: '1', kategoriregler: '1' }, amendo_versjon: v, stengte_perioder: [], kategori_regler: [] });
sjekk('stengte perioder og regler tømt når det var meningen', JSON.stringify(l.stengte) === '[]' && JSON.stringify(l.regler) === '[]', [l.stengte, l.regler]);

// ── Endret et annet sted mens siden var åpen ────────────────────────────────
console.log('\nEndret på serveren etter at siden ble lastet');
v = await versjon();
await sett({ amendo_forside: { hero_tittel: 'Endret et annet sted' } });
l = await lagre({ butikk_navn: 'Skal ikke lagres', amendo_endret: { ...urort, forside: '1' }, amendo_versjon: v, forside: { hero_tittel: 'Fra skjemaet' } });
sjekk('avvist: tilbake med avvist=forside', /avvist=forside/.test(l.til || '') && !/saved=1/.test(l.til || ''), l.til);
sjekk('forsiden fra det andre stedet står', l.forside?.hero_tittel === 'Endret et annet sted', l.forside);
sjekk('INGENTING er lagret (heller ikke andre faner)', l.butikk_navn === 'Butikk etter', l.butikk_navn);
html = await side('&avvist=forside');
sjekk('beskjed om å laste siden på nytt', html.includes('amendo-notice-avvist') && html.includes('Forside ble endret et annet sted') && html.includes('Last siden på nytt'));
l = await lagre({ butikk_navn: 'Butikk tre', amendo_endret: urort, amendo_versjon: v, forside: { hero_tittel: '' } });
sjekk('forsiden endret andre steder, men ikke her: lagringen går gjennom, forsiden står', l.butikk_navn === 'Butikk tre' && l.forside?.hero_tittel === 'Endret et annet sted', [l.butikk_navn, l.forside]);
const vMeny = (await versjon()).meny;
await sett({ amendo_meny: [{ label: 'Annen fane', href: '/a' }] });
l = await lagre({ amendo_endret: { ...urort, meny: '1' }, amendo_versjon: { ...v, meny: vMeny }, meny_elementer: [{ label: 'Gammel', href: '/g' }] });
sjekk('samme vern for menyen', /avvist=meny/.test(l.til || '') && l.meny?.[0]?.label === 'Annen fane', [l.til, l.meny]);

// ── Bakoverkompatibelt ──────────────────────────────────────────────────────
console.log('\nUten flaggene (eldre skjema eller annen klient)');
l = await lagre({ forside: { hero_tittel: 'Som før' }, meny_elementer: [{ label: 'Som før', href: '/' }] });
sjekk('lagres som før', l.forside?.hero_tittel === 'Som før' && l.meny?.[0]?.label === 'Som før', [l.forside, l.meny]);

// ── B2B-kundegruppene ───────────────────────────────────────────────────────
console.log('\nB2B: kundegruppe-tabellen');
// 1.4.17: tabellen er amendo_kundegrupper (b2b-kundegrupper.php).
await sett({ amendo_moduler: ['b2b'], amendo_kundegrupper: { 14: { gjelder: 'nettbutikk', b2b: 1, levering: 0 } } });
v = await versjon();
sjekk('kundegruppene er en egen del når B2B er på', /^[0-9a-f]{32}$/.test(v.kundegrupper || ''), v);
l = await lagre({ amendo_endret: { ...urort, kundegrupper: '' }, amendo_versjon: v, kundegrupper: { finnes: '1', rader: { 14: { med: '1' } } } });
sjekk('urørt tabell: står', l.kundegrupper?.['14']?.b2b === 1, l.kundegrupper);
l = await lagre({ amendo_endret: { ...urort, kundegrupper: '1' }, amendo_versjon: v, kundegrupper: { finnes: '1', rader: { 3: { med: '1', b2b: '1' } } } });
sjekk('endret tabell: lagret (bare radene som er sendt)', l.kundegrupper?.['3']?.b2b === 1 && l.kundegrupper?.['14']?.b2b === 1, l.kundegrupper);
await sett({ amendo_kundegrupper: { 14: { gjelder: 'nettbutikk', b2b: 0, levering: 0 } } });
l = await lagre({ amendo_endret: { ...urort, kundegrupper: '1' }, amendo_versjon: v, kundegrupper: { finnes: '1', rader: { 3: { med: '1' } } } });
sjekk('endret i nettleseren OG på serveren: avvist', /avvist=kundegrupper/.test(l.til || '') && l.kundegrupper?.['14']?.b2b === 0 && !('3' in (l.kundegrupper || {})), [l.til, l.kundegrupper]);

// ── admin.js ────────────────────────────────────────────────────────────────
console.log('\nadmin.js i jsdom');
html = await side();
const dom = new JSDOM(html, { runScripts: 'outside-only' });
const w = dom.window;
w.eval(readFileSync(JQUERY, 'utf8'));
w.eval('window.jQuery = jQuery.noConflict(true);');
w.eval(readFileSync(ADMIN_JS, 'utf8'));
await new Promise(res => w.jQuery(res));
const d = w.document;
const flagg = del => d.querySelector(`input[name="amendo_endret[${del}]"]`)?.value;
sjekk('ved lasting: ingen del er endret', [...DELER, 'kundegrupper'].every(del => flagg(del) === ''), Object.fromEntries([...DELER, 'kundegrupper'].map(x => [x, flagg(x)])));
const tittel = d.querySelector('input[name="forside[hero_tittel]"]');
tittel.value = 'Skrevet'; tittel.dispatchEvent(new w.Event('input', { bubbles: true }));
sjekk('skriving i Forside-fanen: bare forside endret', flagg('forside') === '1' && flagg('meny') === '' && flagg('avdelinger') === '', [flagg('forside'), flagg('meny')]);
d.querySelector('#legg-til-meny').click();
sjekk('knapp i menyen («+ Legg til menylenke»): meny endret', flagg('meny') === '1' && flagg('kategorirad') === '', [flagg('meny'), flagg('kategorirad')]);
d.querySelector('#legg-til-periode').click();
sjekk('ny stengt periode: stengte endret, kategori-regler ikke', flagg('stengte') === '1' && flagg('kategoriregler') === '');
const velg = d.querySelector('#kategorirad .kategorirad-velg');
if (velg) { velg.dispatchEvent(new w.Event('change', { bubbles: true })); }
sjekk('valg i kategoriraden: kategorirad endret', !velg || flagg('kategorirad') === '1');

await sett({ amendo_moduler: null, amendo_kundegrupper: null });
ferdig();
