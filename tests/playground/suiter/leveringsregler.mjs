// 1.4.18: bestillingsvindu per avdeling, antallsregler og norske helligdager
// i /leveringsregler, med lagring, admin og admin.js. Se
// includes/leveringsregler.php.
import { JSDOM } from 'jsdom';
import { readFileSync } from 'fs';
import { B, ADMIN_JS, JQUERY, get, sjekk, ferdig } from '../lib/felles.mjs';

const sett = o => get('/amendo-test/v1/v1416-sett', o);
const lagre = post => get('/amendo-test/v1/v1416-lagre', { post });
const side = async () => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=admin')).text();
const regler = async () => (await fetch(B + '/amendo-settings/v1/leveringsregler')).json();
const helligdager = body => get('/amendo-test/v1/v1418-helligdager', body);

await get('/amendo-test/v1/setup');
const kat = await get('/amendo-test/v1/kategorirad-oppsett');
const dag = { åpen: '1', fra: '09:00', til: '17:00' };
const tider = Object.fromEntries(['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'].map(d => [d, dag]));
// Avdelinger slik 1.4.17 lagret dem: uten bestillingsvindu.
await sett({
  amendo_avdelinger: [
    { navn: 'Sentrum', adresse: 'Storgata 1', telefon: '', bilde: '', åpningstider: tider, henting_aktiv: '1', prosesseringstid: 1, cutoff: '10:00', tidsluke: 60, maks_per_tidsluke: 10 },
    { navn: 'Helsebygget', adresse: 'Helseveien 2', telefon: '', bilde: '', åpningstider: tider, henting_aktiv: '1', prosesseringstid: null, cutoff: '', tidsluke: null, maks_per_tidsluke: null },
  ],
  amendo_kategori_regler: [{ kategori_id: kat.ringer, prosesseringstid: 3, steng_fra: '', steng_til: '' }, { kategori_id: 999999, prosesseringstid: 1, steng_fra: '', steng_til: '' }],
  amendo_antall_regler: null,
  amendo_stengt_helligdager: null,
});

// ── Som før uten noe lagret ─────────────────────────────────────────────────
console.log('Uten noe lagret');
let r = await regler();
sjekk('bestillingsvindu: 60 for avdelinger lagret før 1.4.18', r.avdelinger?.length === 2 && r.avdelinger.every(a => a.bestillingsvindu_dager === 60), r.avdelinger?.map(a => a.bestillingsvindu_dager));
sjekk('klargjoring_minutter: 15 for avdelinger lagret før 1.4.24', r.avdelinger?.every(a => a.klargjoring_minutter === 15), r.avdelinger?.map(a => a.klargjoring_minutter));
sjekk('de gamle avdelingsfeltene står som før', r.avdelinger?.[0]?.prosesseringstid_resolved === 1 && r.avdelinger?.[1]?.prosesseringstid_resolved === 2 && r.avdelinger?.[0]?.navn === 'Sentrum');
sjekk('global.antall_regler: tom liste', Array.isArray(r.global?.antall_regler) && r.global?.antall_regler.length === 0, r.global?.antall_regler);
sjekk('stengt på helligdager er standard', r.global?.stengt_helligdager === true);
const h = await helligdager({});
const iDag = h.i_dag;
const omEttAar = new Date(Date.UTC(+iDag.slice(0, 4) + 1, +iDag.slice(5, 7) - 1, +iDag.slice(8, 10))).toISOString().slice(0, 10);
sjekk('helligdager: datoer (YYYY-MM-DD), sortert, fra i dag og 12 måneder fram', Array.isArray(r.global?.helligdager) && r.global.helligdager.length >= 10 && r.global.helligdager.every(d => /^\d{4}-\d{2}-\d{2}$/.test(d) && d >= iDag && d < omEttAar) && r.global.helligdager.join() === [...r.global.helligdager].sort().join(), r.global?.helligdager);
sjekk('… det samme som pluginen regner ut for i dag', JSON.stringify(r.global.helligdager) === JSON.stringify(Object.keys(h.neste)));
sjekk('de gamle globale feltene står', Array.isArray(r.global?.ikke_dager) && Array.isArray(r.global?.stengte_perioder) && 'prosesseringstid' in r.global);
sjekk('kategori_regler: samme felter som før, pluss kategori_navn', r.kategori_regler?.[0]?.kategori_id === kat.ringer && r.kategori_regler[0].prosesseringstid === 3 && r.kategori_regler[0].kategori_navn === 'Ringer' && 'steng_fra' in r.kategori_regler[0], r.kategori_regler);
sjekk('slettet kategori: kategori_navn null', r.kategori_regler?.[1]?.kategori_navn === null, r.kategori_regler?.[1]);

// ── Helligdagene ────────────────────────────────────────────────────────────
console.log('\nNorske helligdager');
const fasit = {
  2026: ['2026-01-01', '2026-04-02', '2026-04-03', '2026-04-05', '2026-04-06', '2026-05-01', '2026-05-14', '2026-05-17', '2026-05-24', '2026-05-25', '2026-12-25', '2026-12-26'],
  2027: ['2027-01-01', '2027-03-25', '2027-03-26', '2027-03-28', '2027-03-29', '2027-05-01', '2027-05-06', '2027-05-16', '2027-05-17', '2027-12-25', '2027-12-26'],
  2024: ['2024-01-01', '2024-03-28', '2024-03-29', '2024-03-31', '2024-04-01', '2024-05-01', '2024-05-09', '2024-05-17', '2024-05-19', '2024-05-20', '2024-12-25', '2024-12-26'],
  2038: ['2038-01-01', '2038-04-22', '2038-04-23', '2038-04-25', '2038-04-26', '2038-05-01', '2038-05-17', '2038-06-03', '2038-06-13', '2038-06-14', '2038-12-25', '2038-12-26'],
};
const aar = (await helligdager({ aar: Object.keys(fasit) })).aar;
for (const [a, datoer] of Object.entries(fasit)) {
  sjekk(`${a}: alle tolv (bevegelige ut fra påskedag)`, JSON.stringify(Object.keys(aar[a] || {})) === JSON.stringify(datoer), Object.keys(aar[a] || {}));
}
sjekk('2027: 17. mai og 2. pinsedag samme dag gir én dato', Object.keys(aar[2027]).length === 11);
sjekk('navnene', aar[2026]['2026-04-03'] === 'Langfredag' && aar[2026]['2026-05-14'] === 'Kristi himmelfartsdag' && aar[2026]['2026-12-26'] === '2. juledag', aar[2026]);
const fra = await helligdager({ fra: '2026-10-25' });
sjekk('fra 25.10.2026: 1. juledag til og med 2. pinsedag 2027, ikke 25.10.2027 eller senere', JSON.stringify(Object.keys(fra.neste)) === JSON.stringify(['2026-12-25', '2026-12-26', ...fasit[2027].slice(0, 9)]), Object.keys(fra.neste));
const fraHelligdag = await helligdager({ fra: '2026-12-25' });
sjekk('i dag er en helligdag: med', Object.keys(fraHelligdag.neste)[0] === '2026-12-25' && !Object.keys(fraHelligdag.neste).includes('2027-12-25'), Object.keys(fraHelligdag.neste));

// ── Lagring ─────────────────────────────────────────────────────────────────
console.log('\nLagring');
const avdPost = (vindu) => ({ navn: 'Sentrum', adresse: 'Storgata 1', telefon: '', bilde: '', åpningstider: tider, henting_aktiv: '1', prosesseringstid: '1', cutoff: '10:00', tidsluke: '60', maks_per_tidsluke: '10', ...(vindu === undefined ? {} : { bestillingsvindu_dager: vindu }) });
let l = await lagre({ avdelinger: [avdPost('14'), { ...avdPost(''), navn: 'Helsebygget' }, { ...avdPost('999'), navn: 'Stor' }, { ...avdPost(undefined), navn: 'Gammel' }] });
sjekk('bestillingsvindu lagret: 14, tomt = 60, 999 = 365, uten feltet = 60', JSON.stringify(l.avdelinger?.map(a => a.bestillingsvindu_dager)) === '[14,60,365,60]', l.avdelinger?.map(a => a.bestillingsvindu_dager));
r = await regler();
sjekk('/leveringsregler: bestillingsvindu per avdeling', JSON.stringify(r.avdelinger.map(a => a.bestillingsvindu_dager)) === '[14,60,365,60]');
// 1.4.24: klargjøringstid i minutter. 0 er gyldig; tomt, negativt, desimal og tekst = 15; maks 1440.
const kPost = (k) => ({ ...avdPost('60'), ...(k === undefined ? {} : { klargjoring_minutter: k }) });
l = await lagre({ avdelinger: [kPost('30'), kPost('0'), kPost(''), kPost('-5'), kPost('2000'), kPost('abc'), kPost('7.5'), kPost(undefined), kPost(' 45 ')] });
sjekk('klargjøringstid lagret: 30, 0, tomt = 15, -5 = 15, 2000 = 1440, abc = 15, 7.5 = 15, uten feltet = 15, « 45 » = 45', JSON.stringify(l.avdelinger?.map(a => a.klargjoring_minutter)) === '[30,0,15,15,1440,15,15,15,45]', l.avdelinger?.map(a => a.klargjoring_minutter));
r = await regler();
sjekk('/leveringsregler: klargjoring_minutter som heltall per avdeling', JSON.stringify(r.avdelinger.map(a => a.klargjoring_minutter)) === '[30,0,15,15,1440,15,15,15,45]', r.avdelinger.map(a => a.klargjoring_minutter));
await sett({ amendo_avdelinger: [{ navn: 'Rå', klargjoring_minutter: '20' }, { navn: 'Rå 2', klargjoring_minutter: 'x' }] });
r = await regler();
sjekk('/leveringsregler renser også det som ligger lagret («20» → 20, «x» → 15)', JSON.stringify(r.avdelinger.map(a => a.klargjoring_minutter)) === '[20,15]', r.avdelinger.map(a => a.klargjoring_minutter));
await lagre({ avdelinger: [{ ...avdPost('14'), klargjoring_minutter: '25' }, { ...avdPost('7'), navn: 'Helsebygget' }] });

const antallPost = [
  { fra_antall: '20', min_virkedager: '3', kategori_id: '', unntatt_avdelinger: ['Helsebygget', 'Helsebygget'] },
  { fra_antall: '5', min_virkedager: '2', kategori_id: String(kat.ringer) },
  { fra_antall: '0', min_virkedager: '4', kategori_id: '' },
  { fra_antall: '50', min_virkedager: '99', kategori_id: '' },
  { fra_antall: '', min_virkedager: '1' },
];
l = await lagre({ amendo_endret: { antallregler: '' }, antall_regler_finnes: '1', antall_regler: antallPost });
sjekk('ikke endret i nettleseren: ikke lagret', l.antall_regler === null, l.antall_regler);
l = await lagre({ antall_regler: antallPost });
sjekk('uten markøren (annet skjema): ikke lagret', l.antall_regler === null, l.antall_regler);
l = await lagre({ amendo_endret: { antallregler: '1' }, antall_regler_finnes: '1', antall_regler: antallPost });
sjekk('lagret: regler uten antall hoppes over, virkedager maks 60, unntak uten duplikater', JSON.stringify(l.antall_regler) === JSON.stringify([
  { fra_antall: 20, min_virkedager: 3, kategori_id: null, unntatt_avdelinger: ['Helsebygget'] },
  { fra_antall: 5, min_virkedager: 2, kategori_id: kat.ringer, unntatt_avdelinger: [] },
  { fra_antall: 50, min_virkedager: 60, kategori_id: null, unntatt_avdelinger: [] },
]), l.antall_regler);
r = await regler();
sjekk('/leveringsregler: global.antall_regler med kategori_navn', !('antall_regler' in r) && JSON.stringify(r.global?.antall_regler) === JSON.stringify([
  { fra_antall: 20, min_virkedager: 3, kategori_id: null, unntatt_avdelinger: ['Helsebygget'], kategori_navn: null },
  { fra_antall: 5, min_virkedager: 2, kategori_id: kat.ringer, unntatt_avdelinger: [], kategori_navn: 'Ringer' },
  { fra_antall: 50, min_virkedager: 60, kategori_id: null, unntatt_avdelinger: [], kategori_navn: null },
]), r.global?.antall_regler);

l = await lagre({ stengt_helligdager_finnes: '1' });
r = await regler();
sjekk('bryteren av: stengt_helligdager false og tom liste', l.stengt_helligdager === '0' && r.global.stengt_helligdager === false && Array.isArray(r.global.helligdager) && r.global.helligdager.length === 0, [l.stengt_helligdager, r.global]);
l = await lagre({ henting_prosesseringstid: '2' });
sjekk('lagring uten markøren slår den ikke på igjen', l.stengt_helligdager === '0');
l = await lagre({ stengt_helligdager_finnes: '1', stengt_helligdager: 'on' });
r = await regler();
sjekk('på igjen: datoene er tilbake', l.stengt_helligdager === '1' && r.global.helligdager.length >= 10);

// ── Admin ───────────────────────────────────────────────────────────────────
console.log('\nAdmin');
const html = await side();
sjekk('hjelpeteksten om den lengste tiden', html.includes('Kunden får den lengste tiden av: avdelingens produksjonstid, kategoriens produksjonstid og antallsreglene.'));
sjekk('«Kan bestilles inntil N dager fram» per avdeling', /name="avdelinger\[0\]\[bestillingsvindu_dager\]" value="14"/.test(html) && /name="avdelinger\[1\]\[bestillingsvindu_dager\]" value="7"/.test(html) && html.includes('Kan bestilles inntil'));
sjekk('«Klargjøringstid (minutter)» per avdeling: 25 lagret, 15 som standard', /name="avdelinger\[0\]\[klargjoring_minutter\]" value="25"/.test(html) && /name="avdelinger\[1\]\[klargjoring_minutter\]" value="15"/.test(html) && html.includes('Klargjøringstid (minutter)'));
sjekk('bryteren «Stengt på helligdager», på', /id="stengt_helligdager" name="stengt_helligdager"\s+checked/.test(html) && html.includes('name="stengt_helligdager_finnes"'));
sjekk('neste helligdager vises med navn', /Neste: [^<]*(juledag|nyttårsdag|påskedag|Skjærtorsdag|Langfredag|mai|himmelfartsdag|pinsedag)/.test(html));
sjekk('antallsregel-kortet med del, markør og mal', html.includes('data-amendo-del="antallregler"') && html.includes('name="antall_regler_finnes"') && html.includes('<template id="antall-regel-mal">'));
sjekk('regel 1: kategorivelgeren med «Alle varer i kurven», Ringer valgt', /<select name="antall_regler\[1\]\[kategori_id\]" class="amendo-kategori-velger"/.test(html) && /— Alle varer i kurven —/.test(html) && new RegExp(`name="antall_regler\\[1\\]\\[kategori_id\\][^]*?value="${kat.ringer}"\\s+selected`).test(html));
sjekk('regel 0: Helsebygget unntatt, Sentrum ikke', /name="antall_regler\[0\]\[unntatt_avdelinger\]\[\]" value="Helsebygget"\s+checked/.test(html) && /name="antall_regler\[0\]\[unntatt_avdelinger\]\[\]" value="Sentrum"\s*>/.test(html));
sjekk('antall og virkedager i raden', /name="antall_regler\[0\]\[fra_antall\]" value="20"/.test(html) && /name="antall_regler\[2\]\[min_virkedager\]" value="60"/.test(html));

// ── admin.js ────────────────────────────────────────────────────────────────
console.log('\nadmin.js i jsdom');
const dom = new JSDOM(html, { url: 'http://localhost/wp-admin/admin.php?page=amendo-settings#levering', runScripts: 'outside-only' });
const w = dom.window;
w.eval(readFileSync(JQUERY, 'utf8'));
w.eval('window.jQuery = jQuery.noConflict(true);');
w.eval(readFileSync(ADMIN_JS, 'utf8'));
await new Promise(res => w.jQuery(res));
const d = w.document;
const flagg = () => d.querySelector('input[name="amendo_endret[antallregler]"]').value;
sjekk('ingen endring ved lasting', flagg() === '');
d.querySelector('#legg-til-antall-regel').click();
const ny = d.querySelector('select[name="antall_regler[3][kategori_id]"]');
sjekk('+ Legg til regel: ny rad med kategorivelger og søkefelt', !!ny && ny.previousElementSibling?.classList.contains('amendo-kategori-sok') && !!d.querySelector('input[name="antall_regler[3][fra_antall]"]') && !!d.querySelector('input[name="antall_regler[3][unntatt_avdelinger][]"][value="Helsebygget"]'));
sjekk('… og regnes som en endring', flagg() === '1');
d.querySelector('input[name="amendo_endret[antallregler]"]').value = '';
d.querySelector('.slett-antall-regel').click();
sjekk('Slett regel: raden borte og regnes som en endring', !d.querySelector('input[name="antall_regler[0][fra_antall]"]') && flagg() === '1');
d.querySelector('#legg-til-avdeling').click();
const nyAvd = [...d.querySelectorAll('input[name$="[bestillingsvindu_dager]"]')].at(-1);
sjekk('ny avdeling: «Kan bestilles inntil» 60', nyAvd?.value === '60' && /avdelinger\[\d+\]\[bestillingsvindu_dager\]/.test(nyAvd.name), nyAvd?.name);
const nyKlar = [...d.querySelectorAll('input[name$="[klargjoring_minutter]"]')].at(-1);
sjekk('ny avdeling: «Klargjøringstid» 15, samme indeks som resten', nyKlar?.value === '15' && nyKlar.name.replace('[klargjoring_minutter]', '') === nyAvd?.name.replace('[bestillingsvindu_dager]', ''), [nyKlar?.name, nyAvd?.name]);

await sett({ amendo_avdelinger: null, amendo_kategori_regler: null, amendo_antall_regler: null, amendo_stengt_helligdager: null });
ferdig();
