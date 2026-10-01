import { JSDOM } from 'jsdom';
import { readFileSync } from 'fs';
import { B, ADMIN_JS, JQUERY } from '../lib/felles.mjs';
const html = await (await fetch(B + '/amendo-test/v1/side')).text();
const jq = readFileSync(JQUERY, 'utf8');
const adminJs = readFileSync(ADMIN_JS, 'utf8');
let ok = 0, feil = 0;
const sjekk = (n, b, i) => { if (b) { ok++; console.log('  ✓', n); } else { feil++; console.log('  ✗', n, i ?? ''); } };

const lag = async (confirmSvar) => {
  const dom = new JSDOM(`<!doctype html><body>${html}</body>`, { runScripts: 'outside-only' });
  const w = dom.window;
  // wp-admin: jQuery i noConflict-modus, ingen global $.
  w.eval(jq); w.eval('window.jQuery = jQuery.noConflict(true);');
  w.confirmTekst = null;
  w.confirm = t => { w.confirmTekst = t; return confirmSvar; };
  w.eval(adminJs);
  // jQuery kjører ready-callbacks asynkront; en callback lagt til etter
  // admin.js kjører etter admin.js sin.
  await new Promise(r => w.jQuery(r));
  return w;
};

const kasseKnapp = w => w.document.querySelector('[data-hemmelighet=kasse] .amendo-generer-secret');
console.log('admin.js – secrets (jsdom, jQuery noConflict)');
let w = await lag(false);
const form = w.document.querySelector('form');
const forsteSubmit = form.querySelector('button[type=submit], input[type=submit], button:not([type])');
sjekk('første submit-knapp i skjemaet er «Lagre» (Enter treffer ikke «Generer ny»)', forsteSubmit && /Lagre/.test(forsteSubmit.textContent), forsteSubmit?.outerHTML);
sjekk('«Generer ny» er type=button', kasseKnapp(w).getAttribute('type') === 'button');
let submits = 0;
w.jQuery(form).on('submit', e => { submits++; e.preventDefault(); });
kasseKnapp(w).click();
sjekk('avbrutt bekreftelse: ingen innsending', submits === 0);
sjekk('avbrutt bekreftelse: skjult felt fortsatt 0', w.document.getElementById('amendo_ny_kasse_secret').value === '0');
sjekk('bekreftelse ved eksisterende secret sier «Erstatte»', /^Erstatte/.test(w.confirmTekst || ''), w.confirmTekst);

w = await lag(true);
submits = 0;
const form2 = w.document.querySelector('form');
let sendtVerdi = null;
w.jQuery(form2).on('submit', e => { submits++; sendtVerdi = new w.FormData(form2).get('amendo_ny_kasse_secret'); e.preventDefault(); });
kasseKnapp(w).click();
sjekk('bekreftet: skjemaet sendes én gang', submits === 1, submits);
sjekk('bekreftet: amendo_ny_kasse_secret=1 i innsendingen', sendtVerdi === '1', sendtVerdi);
sjekk('bekreftet kasse: revalidering-flagget står på 0', w.document.getElementById('amendo_ny_revalidering_secret').value === '0');

// Revaliderings-knappen setter bare sitt eget flagg.
w = await lag(true);
const form4 = w.document.querySelector('form');
let flagg = null;
w.jQuery(form4).on('submit', e => { const d = new w.FormData(form4); flagg = [d.get('amendo_ny_revalidering_secret'), d.get('amendo_ny_kasse_secret')]; e.preventDefault(); });
w.document.querySelector('[data-hemmelighet=revalidering] .amendo-generer-secret').click();
sjekk('revalidering: bare eget flagg = 1', JSON.stringify(flagg) === '["1","0"]', flagg);
sjekk('revalidering: bekreftelsen nevner Vercel', /Vercel/.test(w.confirmTekst || ''), w.confirmTekst);
sjekk('første submit-knapp er fortsatt «Lagre»', /Lagre/.test(form4.querySelector('button[type=submit], input[type=submit], button:not([type])').textContent));

// Lagre-knappen sender 0.
w = await lag(true);
const form3 = w.document.querySelector('form');
let lagreVerdi = null;
w.jQuery(form3).on('submit', e => { lagreVerdi = new w.FormData(form3).get('amendo_ny_kasse_secret'); e.preventDefault(); });
form3.querySelector('button[type=submit]').click();
sjekk('«Lagre» sender amendo_ny_kasse_secret=0', lagreVerdi === '0', lagreVerdi);

// Kopier (uten sikker kontekst: execCommand-fallback).
let kopiert = null;
w.document.execCommand = c => { kopiert = c + ':' + w.document.getElementById('amendo_ny_kasse_secret_verdi').value; return true; };
w.document.querySelector('[data-hemmelighet=kasse] .amendo-kopier-secret').click();
sjekk('kopier bruker execCommand-fallback med verdien', kopiert === 'copy:' + 'a'.repeat(64), kopiert);
sjekk('knappen viser «Kopiert ✓»', w.document.querySelector('[data-hemmelighet=kasse] .amendo-kopier-secret').textContent === 'Kopiert ✓');
sjekk('verdien vises bare i det skrivebeskyttede feltet', (html.match(/a{64}/g) || []).length === 1);

// Mediebiblioteket: valgt bilde setter vedlegg-ID; håndskrevet URL tømmer den.
w = await lag(true);
let velgCb = null;
w.wp = { media: () => ({ on: (e, cb) => { if (e === 'select') velgCb = cb; }, open: () => {},
  state: () => ({ get: () => ({ first: () => ({ toJSON: () => ({ id: 4242, url: 'https://x.example/wp-content/uploads/hero.jpg' }) }) }) }) }) };
const heroUrl = w.document.querySelector('input[name="forside[hero_bilde]"]');
const heroId = w.document.querySelector('input[name="forside[hero_bilde_id]"]');
sjekk('skjult ID-felt for hero_bilde finnes', !!heroId && heroId.type === 'hidden');
heroUrl.closest('.amendo-media').querySelector('.amendo-velg-media').click();
velgCb && velgCb();
sjekk('valgt fra biblioteket: URL og ID satt', heroUrl.value === 'https://x.example/wp-content/uploads/hero.jpg' && heroId.value === '4242', [heroUrl.value, heroId && heroId.value]);
heroUrl.value = 'https://annen.example/b.jpg';
heroUrl.dispatchEvent(new w.Event('input', { bubbles: true }));
sjekk('URL endret for hånd: ID tømt', heroId.value === '', heroId.value);
heroUrl.closest('.amendo-media').querySelector('.amendo-velg-media').click(); velgCb();
heroUrl.closest('.amendo-media').querySelector('.amendo-fjern-media').click();
sjekk('Fjern: URL og ID tømt', heroUrl.value === '' && heroId.value === '', [heroUrl.value, heroId.value]);
sjekk('video har ikke ID-felt', !w.document.querySelector('input[name="forside[hero_video_id]"]'));

console.log(`\n${ok} ok, ${feil} feil`);
process.exit(feil ? 1 : 0);
