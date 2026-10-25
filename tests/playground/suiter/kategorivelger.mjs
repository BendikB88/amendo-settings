// 1.4.17: alle steder der man velger en WooCommerce-kategori i admin
// (kategori-regler under Levering, kategoriflisene på Forside, kategoriraden
// under Meny) har nedtrekkslisten med navn, antall produkter og søk. Lagret
// verdi er fortsatt term_id. Se includes/kategorivelger.php.
import { JSDOM } from 'jsdom';
import { readFileSync } from 'fs';
import { B, ADMIN_JS, JQUERY, get, sjekk, ferdig } from '../lib/felles.mjs';

const sett = o => get('/amendo-test/v1/v1416-sett', o);
const lagre = post => get('/amendo-test/v1/v1416-lagre', { post });
const side = async () => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=admin')).text();

await get('/amendo-test/v1/setup');
const kat = await get('/amendo-test/v1/kategorirad-oppsett');
await sett({
  amendo_kategori_regler: [{ kategori_id: kat.gullringer, prosesseringstid: 1, steng_fra: '', steng_til: '' }, { kategori_id: 999999, prosesseringstid: 2, steng_fra: '', steng_til: '' }],
  amendo_forside: { kategorifliser: [{ term_id: kat.ringer, tittel: 'Ringer' }] },
  amendo_moduler: null,
});

// ── Markeringen ─────────────────────────────────────────────────────────────
console.log('Markeringen');
let html = await side();
const velgere = [...html.matchAll(/<select name="([^"]*)" class="amendo-kategori-velger"/g)].map(m => m[1]);
sjekk('kategori-reglene bruker velgeren', velgere.includes('kategori_regler[0][kategori_id]') && velgere.includes('kategori_regler[1][kategori_id]'), velgere);
sjekk('kategoriflisene bruker velgeren', velgere.includes('forside[kategorifliser][0][term_id]'), velgere);
sjekk('kategoriraden bruker velgeren', /<select class="kategorirad-velg amendo-kategori-velger"/.test(html));
sjekk('ingen tallfelt for kategori-ID igjen', !/type="number"[^>]*kategori_id|kategori_id[^>]*type="number"/.test(html) && !html.includes('Kategori-ID'));
const regel0 = html.slice(html.indexOf('name="kategori_regler[0][kategori_id]"'), html.indexOf('</select>', html.indexOf('name="kategori_regler[0][kategori_id]"')));
sjekk('navn med forelder og antall produkter', /<option value="\d+"\s*>Ringer \(\d+\)</.test(regel0) && new RegExp(`<option value="${kat.gullringer}"\\s+selected(?:='selected')?>Ringer › Gullringer \\(1\\)<`).test(regel0), regel0.slice(0, 600));
sjekk('lagret term_id er valgt', new RegExp(`value="${kat.gullringer}"\\s+selected`).test(regel0));
sjekk('alle kategoriene, ikke bare de 100 første eller toppnivået', (regel0.match(/<option value="\d+"/g) || []).length >= 7);
const regel1 = html.slice(html.indexOf('name="kategori_regler[1][kategori_id]"'), html.indexOf('</select>', html.indexOf('name="kategori_regler[1][kategori_id]"')));
sjekk('slettet kategori står som «Slettet kategori (#ID)», ikke byttet ut', /<option value="999999" selected>Slettet kategori \(#999999\)</.test(regel1), regel1.slice(0, 300));
sjekk('mal for nye regler med velgeren', /<template id="kategori-regel-mal">[^]*name="kategori_regler\[__i__\]\[kategori_id\]" class="amendo-kategori-velger"/.test(html));

// ── admin.js ────────────────────────────────────────────────────────────────
console.log('\nadmin.js i jsdom');
const dom = new JSDOM(html, { url: 'http://localhost/wp-admin/admin.php?page=amendo-settings#levering', runScripts: 'outside-only' });
const w = dom.window;
w.eval(readFileSync(JQUERY, 'utf8'));
w.eval('window.jQuery = jQuery.noConflict(true);');
w.eval(readFileSync(ADMIN_JS, 'utf8'));
await new Promise(res => w.jQuery(res));
const d = w.document;
const synligeVelgere = [...d.querySelectorAll('select.amendo-kategori-velger')];
sjekk('et søkefelt foran hver velger', synligeVelgere.length >= 4 && synligeVelgere.every(s => s.previousElementSibling?.classList.contains('amendo-kategori-sok')), synligeVelgere.length);
sjekk('søkefeltet sendes ikke med skjemaet', [...d.querySelectorAll('.amendo-kategori-sok')].every(i => !i.name));
const flagg = del => d.querySelector(`input[name="amendo_endret[${del}]"]`)?.value;
const s0 = d.querySelector('select[name="kategori_regler[0][kategori_id]"]');
const sok0 = s0.previousElementSibling;
const tekster = () => [...s0.options].map(o => o.textContent);
const alle = s0.options.length;
sok0.value = 'øre'; sok0.dispatchEvent(new w.Event('input', { bubbles: true }));
sjekk('søk «øre»: bare treffene, tomt valg og det valgte', JSON.stringify(tekster().map(t => t.replace(/ \(\d+\)$/, ''))) === JSON.stringify(['— Velg kategori —', 'Ringer › Gullringer', 'Øredobber']) && s0.value === String(kat.gullringer), tekster());
sjekk('søket regnes ikke som en endring', flagg('kategoriregler') === '');
sok0.value = ''; sok0.dispatchEvent(new w.Event('input', { bubbles: true }));
sjekk('tomt søk: alle tilbake, i samme rekkefølge', s0.options.length === alle && s0.value === String(kat.gullringer));
sok0.value = 'salg'; sok0.dispatchEvent(new w.Event('input', { bubbles: true }));
sok0.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
sjekk('Enter velger første treff og regnes som en endring', s0.value === String(kat.salg) && flagg('kategoriregler') === '1', [s0.value, flagg('kategoriregler')]);

d.querySelector('#legg-til-kategori-regel').click();
const ny = d.querySelector('select[name="kategori_regler[2][kategori_id]"]');
sjekk('+ Legg til regel: ny rad med velgeren og søkefelt', !!ny && ny.classList.contains('amendo-kategori-velger') && ny.previousElementSibling?.classList.contains('amendo-kategori-sok') && ny.value === '');
const fliser = d.querySelector('.amendo-repeter[data-repeter="kategorifliser"]');
fliser.querySelector('.amendo-legg-til-rad').click();
const nyFlis = [...fliser.querySelectorAll('.amendo-repeter-liste select.amendo-kategori-velger')].at(-1);
sjekk('ny kategoriflis: velgeren med søkefelt', !!nyFlis && nyFlis.name === 'forside[kategorifliser][1][term_id]' && nyFlis.previousElementSibling?.classList.contains('amendo-kategori-sok'), nyFlis?.name);

// Kategoriraden: en kategori som er brukt, er fortsatt sperret etter et søk.
const radVelg = d.querySelector('#kategorirad .kategorirad-velg');
const radSok = radVelg.previousElementSibling;
radVelg.value = String(kat.ringer);
radVelg.closest('.kategorirad-liste-wrap').querySelector('.kategorirad-legg-til-knapp').click();
radSok.value = 'xyz'; radSok.dispatchEvent(new w.Event('input', { bubbles: true }));
radSok.value = ''; radSok.dispatchEvent(new w.Event('input', { bubbles: true }));
sjekk('kategoriraden: brukt kategori fortsatt sperret etter søk', radVelg.querySelector(`option[value="${kat.ringer}"]`)?.disabled === true);

// ── Lagring ─────────────────────────────────────────────────────────────────
console.log('\nLagring');
const l = await lagre({ kategori_regler: [{ kategori_id: String(kat.salg), prosesseringstid: '3', steng_fra: '', steng_til: '' }, { kategori_id: '', prosesseringstid: '1' }] });
sjekk('lagret verdi er term_id (tall), tom velger hoppes over', JSON.stringify(l.regler) === JSON.stringify([{ kategori_id: kat.salg, prosesseringstid: 3, steng_fra: '', steng_til: '' }]), l.regler);
const lr = await (await fetch(B + '/amendo-settings/v1/leveringsregler')).json();
sjekk('/leveringsregler: som før', lr.kategori_regler?.[0]?.kategori_id === kat.salg, lr.kategori_regler);

await sett({ amendo_kategori_regler: null, amendo_forside: null });
ferdig();
