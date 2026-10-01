import { JSDOM } from 'jsdom';
import { readFileSync } from 'fs';
import { B, ADMIN_JS, JQUERY } from '../lib/felles.mjs';
const get = async p => (await fetch(B + p)).json();
let ok = 0, feil = 0;
const sjekk = (n, b, i) => { if (b) { ok++; console.log('  ✓', n); } else { feil++; console.log('  ✗', n, i !== undefined ? JSON.stringify(i) : ''); } };

await get('/amendo-test/v1/setup');
console.log('Kategoriraden (PHP)');
const r = await get('/amendo-test/v1/kategorirad');
for (const [k, v] of Object.entries(r)) if (typeof v === 'boolean') sjekk(k, v, v ? undefined : r);
console.log('   settings.kategorirad:', JSON.stringify(r.settings_kategorirad));

console.log('\nVarsel når kategorier i raden endres');
const ks = s => get('/amendo-test/v1/kategorirad-reval&sak=' + s);
const ettVarsel = r => r.http?.length === 1 && JSON.stringify(r.http[0]) === '["innstillinger"]';
await ks('klargjor');
let kr = await ks('nytt_navn_i_rad');
sjekk('nytt navn på kategori i raden: ett varsel ["innstillinger"]', ettVarsel(kr), kr);
kr = await ks('ny_slug_i_panel');
sjekk('ny slug på kategori i panel-lista: ett varsel', ettVarsel(kr), kr);
kr = await ks('bare_beskrivelse');
sjekk('bare ny beskrivelse: ingen varsel', kr.http?.length === 0, kr);
kr = await ks('nytt_navn_utenfor');
sjekk('nytt navn på kategori utenfor raden: ingen varsel', kr.http?.length === 0, kr);
kr = await ks('to_i_rad');
sjekk('to endringer i samme forespørsel: ett varsel', ettVarsel(kr), kr);
kr = await ks('slett_i_rad');
sjekk('sletting av kategori i raden: ett varsel', ettVarsel(kr), kr);
kr = await ks('slett_utenfor');
sjekk('sletting av kategori utenfor raden: ingen varsel', kr.http?.length === 0, kr);
await ks('rydd');

console.log('\nKategoriraden (admin.js i jsdom)');
const html = await (await fetch(B + '/amendo-test/v1/side')).text();
const kat = await get('/amendo-test/v1/kategorirad-oppsett');
const dom = new JSDOM(`<!doctype html><body>${html}</body>`, { runScripts: 'outside-only' });
const w = dom.window;
w.eval(readFileSync(JQUERY, 'utf8'));
w.eval('window.jQuery = jQuery.noConflict(true);');
w.confirm = () => true;
w.eval(readFileSync(ADMIN_JS, 'utf8'));
await new Promise(res => w.jQuery(res));
const d = w.document;
const wrap = l => d.querySelector(`.kategorirad-liste-wrap[data-liste=${l}]`);
const rader = l => [...wrap(l).querySelectorAll('.kategorirad-liste > .kategorirad-rad')];
const ider = l => rader(l).map(r => r.querySelector('.kategorirad-term').value);
const navnOk = l => rader(l).every((r, i) => [...r.querySelectorAll('[name]')].every(e => e.name.startsWith(`kategorirad[${l}][${i}]`)));
const opt = (l, id) => wrap(l).querySelector(`.kategorirad-velg option[value="${id}"]`);
const leggTil = (l, id) => { wrap(l).querySelector('.kategorirad-velg').value = String(id); wrap(l).querySelector('.kategorirad-legg-til-knapp').click(); };

sjekk('utgangspunkt: rad [ringer, smykker], panel [merker]', JSON.stringify(ider('rad')) === JSON.stringify([kat.ringer, kat.smykker].map(String)) && JSON.stringify(ider('panel')) === JSON.stringify([String(kat.merker)]), [ider('rad'), ider('panel')]);
sjekk('brukte kategorier er deaktivert i begge velgerne', opt('rad', kat.ringer).disabled && opt('panel', kat.ringer).disabled && opt('rad', kat.merker).disabled && !opt('rad', kat.epoker).disabled);

leggTil('rad', kat.salg);
const ny = rader('rad').at(-1);
sjekk('legg til: ny rad sist med term_id', ider('rad').at(-1) === String(kat.salg), ider('rad'));
sjekk('legg til: navn og antall som tekst', ny.querySelector('.kategorirad-navn').textContent === 'Salg' && /^\(\d+\)$/.test(ny.querySelector('.kategorirad-antall').textContent), ny.querySelector('.kategorirad-navn').textContent);
sjekk('legg til: navn nummerert 0..n', navnOk('rad'));
sjekk('legg til: valget deaktiveres og nullstilles', opt('panel', kat.salg).disabled && wrap('rad').querySelector('.kategorirad-velg').value === '');
sjekk('ny rad i raden har «Uthev»', !!ny.querySelector('input[type=checkbox][name$="[uthevet]"]'));

leggTil('panel', kat.salg);
sjekk('allerede brukt: legges ikke til i panel', !ider('panel').includes(String(kat.salg)), ider('panel'));

leggTil('panel', kat.epoker);
sjekk('panel: lagt til uten «Uthev»', ider('panel').at(-1) === String(kat.epoker) && !rader('panel').at(-1).querySelector('input[type=checkbox]'));

// Flytt «Salg» (sist) opp to ganger → først.
rader('rad').at(-1).querySelector('.kategorirad-opp').click();
rader('rad').find(r => r.querySelector('.kategorirad-term').value === String(kat.salg)).querySelector('.kategorirad-opp').click();
sjekk('↑ to ganger: Salg først', ider('rad')[0] === String(kat.salg), ider('rad'));
sjekk('↑: navn nummerert på nytt', navnOk('rad'));
rader('rad')[0].querySelector('.kategorirad-opp').click();
sjekk('↑ på første: ingen endring', ider('rad')[0] === String(kat.salg));
rader('rad')[0].querySelector('.kategorirad-ned').click();
sjekk('↓: Salg nummer to', ider('rad')[1] === String(kat.salg), ider('rad'));

const form = d.querySelector('form');
const fd = [...new w.FormData(form).entries()].filter(([k]) => k.startsWith('kategorirad[rad]') && k.endsWith('[term_id]'));
sjekk('innsendt rekkefølge = rekkefølgen på skjermen', JSON.stringify(fd.map(([, v]) => v)) === JSON.stringify(ider('rad')), fd);
sjekk('innsendt med markør', new w.FormData(form).get('kategorirad[finnes]') === '1');

rader('rad').find(r => r.querySelector('.kategorirad-term').value === String(kat.salg)).querySelector('.kategorirad-fjern').click();
sjekk('fjern: borte, valget aktivt igjen', !ider('rad').includes(String(kat.salg)) && !opt('panel', kat.salg).disabled);
sjekk('fjern: navn nummerert på nytt', navnOk('rad'));
sjekk('uten jQuery UI (jsdom) kjører resten uten feil', typeof w.jQuery.fn.sortable === 'undefined');

console.log(`\n${ok} ok, ${feil} feil`);
process.exit(feil ? 1 : 0);
