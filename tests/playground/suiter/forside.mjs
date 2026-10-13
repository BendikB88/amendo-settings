// 1.4.13: forsideseksjonene, `seksjoner` og ACF-reserven felt for felt.
// PHP-delen: mu/forside-test.php (med en ACF-stubb som bare gjelder denne suiten).
import { JSDOM } from 'jsdom';
import { readFileSync } from 'fs';
import { B, ADMIN_JS, JQUERY, get, sjekk, ferdig } from '../lib/felles.mjs';

const vis = o => { for (const [k, v] of Object.entries(o)) sjekk(k, v === true, v === true ? undefined : v); };
const sak = s => get('/amendo-test/v1/forside&sak=' + s);

await get('/amendo-test/v1/setup');
const k = await sak('klargjor');
sjekk('klargjort (forside, kategorier, ACF-stubb)', k.ok === true, k);
// Stubben defineres når mu-fila lastes — fra forespørselen etter klargjøringen.
vis(await sak('stubb'));

console.log('\nUten noe i Amendo');
vis(await sak('uten_amendo'));
console.log('\nOppdatering og lagring hos en ACF-butikk');
vis(await sak('lagring_uten_endring'));
console.log('\nFelt for felt');
vis(await sak('felt_for_felt'));
console.log('\nBare nye felter (nyhetsbrev)');
vis(await sak('bare_nye_felt'));
console.log('\nRensing og maks antall rader');
vis(await sak('saniter'));
console.log('\nSeksjoner');
vis(await sak('seksjoner'));
console.log('\nBildemål');
vis(await sak('bildemaal'));
console.log('\nSlettet kategori');
vis(await sak('slettet_kategori'));
console.log('\nForside lagret før 1.4.5');
vis(await sak('migrering'));
console.log('\nGarçon: uten nye felter er svaret som i 1.4.12');
vis(await sak('garcon_identisk'));
console.log('\nAdmin-fanen');
vis(await sak('admin'));

console.log('\nadmin.js (jsdom, jQuery noConflict)');
const html = await (await fetch(B + '/amendo-test/v1/side')).text();
const dom = new JSDOM(`<!doctype html><body>${html}</body>`, { runScripts: 'outside-only' });
const w = dom.window;
w.eval(readFileSync(JQUERY, 'utf8')); w.eval('window.jQuery = jQuery.noConflict(true);');
w.eval(readFileSync(ADMIN_JS, 'utf8'));
await new Promise(r => w.jQuery(r));
const d = w.document;

const seksjoner = () => [...d.querySelectorAll('.forside-seksjon-liste > .forside-seksjon-rad')].map(li => {
  const id = li.querySelector('input[type=hidden]'), skjul = li.querySelector('input[type=checkbox]');
  return { id: id.value, idNavn: id.name, skjulNavn: skjul.name };
});
const før = seksjoner();
sjekk('seksjonslista har 9 rader', før.length === 9, før.length);
d.querySelector('.forside-seksjon-rad .forside-seksjon-ned').click();
let etter = seksjoner();
sjekk('↓ bytter de to første', etter[0].id === før[1].id && etter[1].id === før[0].id, etter.map(s => s.id));
sjekk('↓ nummererer id-feltene', etter.every((s, i) => s.idNavn === `forside[seksjoner][${i}][id]`), etter.map(s => s.idNavn));
sjekk('↓ nummererer «Skjul»', etter.every((s, i) => s.skjulNavn === `forside[seksjoner][${i}][skjult]`), etter.map(s => s.skjulNavn));
d.querySelector('.forside-seksjon-rad .forside-seksjon-opp').click();
sjekk('↑ på den første: ingen endring', JSON.stringify(seksjoner()) === JSON.stringify(etter));
const rader = d.querySelectorAll('.forside-seksjon-rad');
rader[rader.length - 1].querySelector('.forside-seksjon-opp').click();
etter = seksjoner();
sjekk('↑ på den siste: flyttet én opp og nummerert', etter[7].idNavn === 'forside[seksjoner][7][id]' && etter[7].id === før[8].id, etter.map(s => s.id));
const fd = new w.FormData(d.querySelector('form'));
sjekk('innsendingen følger den nye rekkefølgen', fd.get('forside[seksjoner][0][id]') === før[1].id && fd.get('forside[seksjoner][7][id]') === før[8].id);

const fliser = d.querySelector('[data-repeter=kategorifliser]');
const antallFliser = () => fliser.querySelectorAll('.amendo-repeter-liste > .amendo-repeter-rad').length;
const n0 = antallFliser();
fliser.querySelector('.amendo-legg-til-rad').click();
const ny = fliser.querySelectorAll('.amendo-repeter-liste > .amendo-repeter-rad')[n0];
sjekk('ny flis: kategorivelger med riktig navn', ny?.querySelector('select')?.name === `forside[kategorifliser][${n0}][term_id]`, ny?.querySelector('select')?.name);
sjekk('ny flis: kategoriene i velgeren', (ny?.querySelectorAll('select option').length ?? 0) > 5);
sjekk('ny flis: bildefelt med skjult id', !!ny?.querySelector(`input[name="forside[kategorifliser][${n0}][bilde_id]"]`));
fliser.querySelector('.amendo-repeter-rad .amendo-slett-rad').click();
sjekk('slett første flis: resten nummerert fra 0', fliser.querySelector('.amendo-repeter-rad select')?.name === 'forside[kategorifliser][0][term_id]' && antallFliser() === n0);

const galleri = d.querySelector('[data-repeter=galleri_bilder]');
galleri.querySelector('.amendo-legg-til-rad').click();
sjekk('nytt galleribilde: url-felt', !!galleri.querySelector('input.amendo-media-url[name="forside[galleri_bilder][0][bilde]"]'));
const url = galleri.querySelector('.amendo-media-url');
galleri.querySelector('.amendo-media-id').value = '42';
w.jQuery(url).val('https://cdn.example/ny.jpg').trigger('input');
sjekk('galleri: ny URL tømmer bilde-id-en', galleri.querySelector('.amendo-media-id').value === '');

ferdig();
