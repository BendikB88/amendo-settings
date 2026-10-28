// 1.4.23: den felles malen. Nye modulnøkler og moduler_kjente, engangs-
// migreringen (B2B på → helsebygget, levering og faktura på; inngang på
// avdelingene), Helsebygget som egen modul, avdelingsfeltet inngang og de nye
// feltene i /settings og /leveringsregler: tomme = som før, og satt.
// Se includes/moduler.php, includes/mal.php og includes/moduler/helsebygget.php.
//
// ⚠ En modul lastes når pluginen lastes, og migreringen kjøres før det: hver
// tilstand settes i ett kall og sjekkes i det neste.
import { JSDOM } from 'jsdom';
import { readFileSync } from 'fs';
import { B, ADMIN_JS, JQUERY, get, sjekk, ferdig } from '../lib/felles.mjs';

const sett = o => get('/amendo-test/v1/v1423-sett', o);
const migrer = o => get('/amendo-test/v1/v1423-migrer', o);
const inspiser = () => get('/amendo-test/v1/v1423-inspiser');
// Feiler en skriving i migreringen (låst database), settes ikke flagget, og den
// prøves igjen i neste forespørsel (includes/moduler.php). Maks tre forsøk.
const inspiserMigrert = async () => { let t; for (let i = 0; i < 3; i++) { t = await inspiser(); if (t.flagg === '1') break; } return t; };
const lagre = post => get('/amendo-test/v1/v1423-lagre', { post });
const side = async () => (await fetch(B + '/amendo-test/v1/v1423-side')).text();
const hbHandling = body => get('/amendo-test/v1/v1417-handling', body);

const KJENTE = ['b2b', 'helsebygget', 'henting', 'levering', 'faktura', 'wolt', 'gavekort', 'sporing'];
const dag = { åpen: '1', fra: '09:00', til: '17:00' };
const tider = Object.fromEntries(['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'].map(d => [d, dag]));
const avd = (navn, ekstra = {}) => ({ navn, adresse: '', telefon: '', bilde: '', åpningstider: tider, henting_aktiv: '1', prosesseringstid: null, cutoff: '', tidsluke: null, maks_per_tidsluke: null, ...ekstra });
const avdPost = (navn, inngang) => ({ navn, adresse: '', telefon: '', bilde: '', prosesseringstid: '', cutoff: '', tidsluke: '60', maks_per_tidsluke: '10', bestillingsvindu_dager: '60', ...(inngang === undefined ? {} : { inngang }) });

await sett({ amendo_test_stille: '1' }); // ingen cron ved siden av testene, se mu/v1423-test.php
await get('/amendo-test/v1/setup');

// ── Uten noe satt: som før ──────────────────────────────────────────────────
console.log('Uten noe satt (= garcon.no før feltene fylles ut)');
await sett({ amendo_moduler: null, amendo_avdelinger: [avd('Stasjonen'), avd('Helsebygget')] });
let t = await inspiser();
let s = t.settings;
sjekk('moduler: []', JSON.stringify(s.moduler) === '[]', s.moduler);
sjekk('moduler_kjente: alle nøklene, i rekkefølgen fra amendo_moduler_alle()', JSON.stringify(s.moduler_kjente) === JSON.stringify(KJENTE), s.moduler_kjente);
sjekk('butikk.logo_mork: tom, og de gamle butikkfeltene står', s.butikk?.logo_mork === '' && 'logo' in s.butikk && 'navn' in s.butikk && 'organisasjonsnummer' in s.butikk, s.butikk);
sjekk('design.tema: tom, fargene står', s.design?.tema === '' && 'primærfarge' in s.design, s.design);
sjekk('levering: { produkt_id: null, pris: null }', JSON.stringify(s.levering) === '{"produkt_id":null,"pris":null}', s.levering);
sjekk('henting.gamle_felter: []', JSON.stringify(s.henting) === '{"gamle_felter":[]}', s.henting);
sjekk('min_side.lenker: []', JSON.stringify(s.min_side) === '{"lenker":[]}', s.min_side);
sjekk('sporing: tre tomme ID-er', JSON.stringify(s.sporing) === '{"gtm":"","klaviyo":"","facebook":""}', s.sporing);
sjekk('ingen helsebygget-felt når modulen er av', !('helsebygget' in s));
sjekk('de gamle feltene i /settings står', ['butikk', 'design', 'kontakt', 'sosiale', 'meny', 'kategorirad', 'avdelinger', 'moduler'].every(k => k in s));
let l = t.regler.levering;
sjekk('/leveringsregler: min_timer, luke_minutter og krever_gruppe er null', l.min_timer === null && l.luke_minutter === null && l.krever_gruppe === null, l);
sjekk('/leveringsregler: de gamle leveringsfeltene står', ['aktiv', 'metode', 'tidsluke_minutter', 'prosesseringstid', 'har_wolt'].every(k => k in l), Object.keys(l));
sjekk('avdelinger[].inngang: «nettbutikk» for alle uten feltet (også en som heter Helsebygget)', t.regler.avdelinger.length === 2 && t.regler.avdelinger.every(a => a.inngang === 'nettbutikk'), t.regler.avdelinger.map(a => a.inngang));
sjekk('ingen wolt- eller gavekort-ruter', !t.ruter.some(r => /wolt|gavekort/.test(r)), t.ruter);

// ── Migreringen ─────────────────────────────────────────────────────────────
console.log('\nMigreringen ved oppgradering');
await migrer({ moduler: ['henting', 'b2b'], avdelinger: [avd('Stasjonen'), avd('Garçon Helsebygget'), avd('Kaker')] });
t = await inspiserMigrert();
sjekk('B2B var på: helsebygget, levering og faktura slått på', JSON.stringify(t.lagret) === '["b2b","helsebygget","henting","levering","faktura"]', t.lagret);
sjekk('… wolt, gavekort og sporing er av', !['wolt', 'gavekort', 'sporing'].some(k => t.aktive.includes(k)), t.aktive);
sjekk('… og /settings har dem i moduler', JSON.stringify(t.settings.moduler) === JSON.stringify(t.aktive), t.settings.moduler);
sjekk('… Helsebygget-modulen er lastet i samme forespørsel', t.lastet.helsebygget && t.lastet.hb_kort, t.lastet);
sjekk('flagget er satt', t.flagg === '1', t.flagg);
sjekk('inngang: «helsebygget» på avdelingen med Helsebygget i navnet, «nettbutikk» på de andre', JSON.stringify(t.avdelinger.map(a => a.inngang)) === '["nettbutikk","helsebygget","nettbutikk"]', t.avdelinger.map(a => a.inngang));
sjekk('inngang lagret på avdelingene, resten urørt', t.avdelinger[1].navn === 'Garçon Helsebygget' && t.avdelinger[1].åpningstider?.mandag?.fra === '09:00', t.avdelinger[1]);
sjekk('/leveringsregler: inngang fra lagringen', JSON.stringify(t.regler.avdelinger.map(a => a.inngang)) === '["nettbutikk","helsebygget","nettbutikk"]');

await sett({ amendo_moduler: ['b2b'] });
t = await inspiser();
sjekk('bare én gang: B2B slått på etterpå gir ikke de andre', JSON.stringify(t.lagret) === '["b2b"]', t.lagret);

await migrer({ moduler: ['henting'], avdelinger: [avd('Helsebygget', { inngang: 'nettbutikk' }), avd('Sentrum')] });
t = await inspiserMigrert();
sjekk('B2B var av: ingenting slås på', JSON.stringify(t.lagret) === '["henting"]', t.lagret);
sjekk('en avdeling som alt har inngang, røres ikke', JSON.stringify(t.avdelinger.map(a => a.inngang)) === '["nettbutikk","nettbutikk"]', t.avdelinger.map(a => a.inngang));

await migrer({ moduler: null, avdelinger: null });
t = await inspiserMigrert();
sjekk('ny butikk uten moduler og avdelinger: ingenting endres, flagget settes', t.lagret === null && t.avdelinger === null && t.flagg === '1', [t.lagret, t.avdelinger, t.flagg]);

// ── Helsebygget som egen modul ──────────────────────────────────────────────
console.log('\nHelsebygget som egen modul');
await sett({ amendo_moduler: ['b2b'], amendo_avdelinger: [avd('Stasjonen'), avd('Helsebygget', { inngang: 'helsebygget' })] });
t = await inspiser();
let html = await side();
sjekk('bare B2B: Helsebygget-filene er ikke lastet', t.lastet.b2b && !t.lastet.helsebygget && !t.lastet.hb_kort, t.lastet);
sjekk('bare B2B: kundegruppene og rabatt/telefon som før', t.lastet.kundegrupper && t.lastet.tellix);
sjekk('bare B2B: ingen underfane Helsebygget og ikke noe Helsebygget-skjema', !html.includes('data-under="helsebygget"') && !html.includes('id="amendo-hb-ansatt"'));
sjekk('bare B2B: ingen helsebygget i /settings', !('helsebygget' in t.settings));
sjekk('modulen av: avdelingen med inngang Helsebygget beholder valget («modulen er av»)', /<option value="helsebygget"\s+selected[^>]*>Helsebygget \(modulen er av\)/.test(html));
sjekk('modulen av: «Helsebygget» kan ikke velges for de andre avdelingene', !/name="avdelinger\[0\]\[inngang\]">\s*<option value="nettbutikk"[^<]*<\/option>\s*<option value="helsebygget"/.test(html));

await sett({ amendo_moduler: ['helsebygget'] });
t = await inspiser();
html = await side();
sjekk('bare Helsebygget (B2B av): filene lastes likevel', t.lastet.helsebygget && t.lastet.hb_kort && t.lastet.kundegrupper && t.lastet.tellix && !t.lastet.b2b, t.lastet);
sjekk('… egen fane med navnet, de ansatte og kundegruppene', html.includes('data-tab="helsebygget"') && html.includes('id="tab-helsebygget"') && html.includes('id="helsebygget-navn"') && html.includes('id="amendo-hb-ansatt"') && html.includes('id="b2b-kundegrupper"'));
sjekk('… ingen B2B-fane', !html.includes('data-tab="b2b"'));
sjekk('… rabatt/telefon finnes', t.ruter.includes('/amendo-settings/v1/rabatt/telefon'));
sjekk('… /settings: helsebygget.navn tom, og kundegruppe_config', JSON.stringify(t.settings.helsebygget) === '{"navn":""}' && 'kundegruppe_config' in t.settings, t.settings.helsebygget);
sjekk('… «Helsebygget» kan velges som inngang', /name="avdelinger\[0\]\[inngang\]">\s*<option value="nettbutikk"[^>]*>Nettbutikken<\/option>\s*<option value="helsebygget"\s*>Helsebygget</.test(html));
let h = await hbHandling({ post: { ny_ansatt: '1', navn: 'Test 1423' } });
sjekk('… «Legg til ansatt» sender tilbake til fanen Helsebygget', /hb=lagt_til#helsebygget$/.test(h.til || ''), h);

let lag = await lagre({ helsebygget_navn_finnes: '1', helsebygget_navn: 'Helsebygget <b>' });
sjekk('navnet lagres, renset', lag.amendo_helsebygget_navn === 'Helsebygget', lag.amendo_helsebygget_navn);
t = await inspiser();
sjekk('/settings: helsebygget.navn', t.settings.helsebygget?.navn === 'Helsebygget', t.settings.helsebygget);
lag = await lagre({ butikk_navn: 'Test' });
sjekk('lagring uten markøren: navnet urørt', lag.amendo_helsebygget_navn === 'Helsebygget');

await sett({ amendo_moduler: ['b2b', 'helsebygget'] });
html = await side();
sjekk('B2B og Helsebygget: underfanen i B2B (med navnet), ingen egen fane', html.includes('data-under="helsebygget"') && html.includes('id="helsebygget-navn"') && !html.includes('data-tab="helsebygget"'));
h = await hbHandling({ post: { ny_ansatt: '1', navn: 'Test 1423 b' } });
sjekk('… «Legg til ansatt» sender tilbake til B2B → Helsebygget som før', /hb=lagt_til#b2b-helsebygget$/.test(h.til || ''), h);

// ── De nye feltene satt ─────────────────────────────────────────────────────
console.log('\nDe nye feltene satt');
const FELT = {
  mal_finnes: '1',
  butikk_logo_mork: 'https://garcon.no/wp-content/uploads/garcon-logo-dark-1.png',
  design_tema: 'Garcon',
  levering_produkt_id: '9747',
  levering_pris: '200',
  levering_min_timer: '4',
  levering_luke_minutter: '15',
  levering_krever_gruppe: '1',
  henting_gamle_felter: 'leveringsdato, leveringstidspunkt\nhentetid_kaker, hentetidspunkt_kaker\nhentetid_helsebygget, tidspunkt_helsebygget\nhentedato_stasjonen, tidspunkt_stasjonen\nugyldig felt!, x\nbare_ett',
  min_side_lenker: 'Bursdagskaker | https://aanerudbakeri.no/min-konto/ab-bursdagskaker/\nBakstplaner | https://aanerudbakeri.no/min-konto/ab-bakstplaner/\nFarlig | javascript:alert(1)\nUten adresse |',
  sporing_gtm: 'gtm-abc1234',
  sporing_klaviyo: 'pk_abcdef1234567890',
  sporing_facebook: '1234 5678 9012 345',
};
lag = await lagre(FELT);
t = await inspiser();
s = t.settings;
sjekk('butikk.logo_mork', s.butikk.logo_mork === FELT.butikk_logo_mork, s.butikk.logo_mork);
sjekk('design.tema (som nøkkel)', s.design.tema === 'garcon', s.design.tema);
sjekk('levering: produkt_id 9747 og pris 200 (tall)', s.levering.produkt_id === 9747 && s.levering.pris === 200, s.levering);
sjekk('henting.gamle_felter: Garçons fire par, ugyldige linjer bort', JSON.stringify(s.henting.gamle_felter) === JSON.stringify([
  { dato: 'leveringsdato', tid: 'leveringstidspunkt' }, { dato: 'hentetid_kaker', tid: 'hentetidspunkt_kaker' },
  { dato: 'hentetid_helsebygget', tid: 'tidspunkt_helsebygget' }, { dato: 'hentedato_stasjonen', tid: 'tidspunkt_stasjonen' },
]), s.henting.gamle_felter);
sjekk('min_side.lenker: to lenker, javascript: og tom adresse avvist', JSON.stringify(s.min_side.lenker) === JSON.stringify([
  { tittel: 'Bursdagskaker', url: 'https://aanerudbakeri.no/min-konto/ab-bursdagskaker/' }, { tittel: 'Bakstplaner', url: 'https://aanerudbakeri.no/min-konto/ab-bakstplaner/' },
]), s.min_side.lenker);
sjekk('sporing: GTM i store bokstaver, piksel uten mellomrom', s.sporing.gtm === 'GTM-ABC1234' && s.sporing.facebook === '123456789012345', s.sporing);
sjekk('sporing: Klaviyos private nøkkel (pk_…) lagres ALDRI', s.sporing.klaviyo === '' && !JSON.stringify(lag).includes('pk_abcdef'), s.sporing);
l = t.regler.levering;
sjekk('/leveringsregler: min_timer 4, luke_minutter 15, krever_gruppe true', l.min_timer === 4 && l.luke_minutter === 15 && l.krever_gruppe === true, l);

lag = await lagre({ ...FELT, levering_pris: '199,50', levering_krever_gruppe: '0', sporing_klaviyo: 'AbC123', levering_min_timer: '0', levering_luke_minutter: '3', butikk_logo_mork: 'javascript:alert(1)' });
t = await inspiser();
sjekk('pris med komma: 199.5', t.settings.levering.pris === 199.5, t.settings.levering.pris);
sjekk('krever_gruppe false (Aanerud) og min_timer 0', t.regler.levering.krever_gruppe === false && t.regler.levering.min_timer === 0, t.regler.levering);
sjekk('luke_minutter under 5: null (som før)', t.regler.levering.luke_minutter === null, t.regler.levering.luke_minutter);
sjekk('offentlig Klaviyo-nøkkel lagres', t.settings.sporing.klaviyo === 'AbC123');
sjekk('logo_mork som ikke er http(s): tom', t.settings.butikk.logo_mork === '', t.settings.butikk.logo_mork);

lag = await lagre({ butikk_navn: 'Test' });
t = await inspiser();
sjekk('lagring uten mal_finnes: ingenting endres', t.settings.levering.produkt_id === 9747 && t.settings.henting.gamle_felter.length === 4 && t.settings.sporing.gtm === 'GTM-ABC1234');

html = await side();
sjekk('admin: feltene står med verdiene', html.includes('name="mal_finnes"') && /name="levering_produkt_id" value="9747"/.test(html) && html.includes('hentetid_kaker, hentetidspunkt_kaker') && /name="sporing_gtm" value="GTM-ABC1234"/.test(html) && html.includes('Bursdagskaker | https://aanerudbakeri.no/min-konto/ab-bursdagskaker/') && /name="design_tema" value="garcon"/.test(html));
sjekk('admin: krever_gruppe valgt', /<option value="0"\s+selected[^>]*>Alle kunder/.test(html));

lag = await lagre({ mal_finnes: '1' });
t = await inspiser();
s = t.settings;
sjekk('alt tømt igjen: tilbake til tomt (= som før)', s.butikk.logo_mork === '' && s.design.tema === '' && JSON.stringify(s.levering) === '{"produkt_id":null,"pris":null}' && s.henting.gamle_felter.length === 0 && s.min_side.lenker.length === 0 && JSON.stringify(s.sporing) === '{"gtm":"","klaviyo":"","facebook":""}' && t.regler.levering.min_timer === null && t.regler.levering.krever_gruppe === null, [s.levering, t.regler.levering]);

// ── Inngang på avdelingene ──────────────────────────────────────────────────
console.log('\nInngang på avdelingene');
await sett({ amendo_moduler: ['helsebygget'] });
lag = await lagre({ avdelinger: [avdPost('Stasjonen', 'nettbutikk'), avdPost('Helsebygget', 'helsebygget'), avdPost('Ukjent', 'bakdør'), avdPost('Uten')] });
sjekk('lagret: helsebygget, og nettbutikk for ugyldig og manglende', JSON.stringify(lag.amendo_avdelinger.map(a => a.inngang)) === '["nettbutikk","helsebygget","nettbutikk","nettbutikk"]', lag.amendo_avdelinger.map(a => a.inngang));
t = await inspiser();
sjekk('/leveringsregler: inngang på alle fire', JSON.stringify(t.regler.avdelinger.map(a => a.inngang)) === '["nettbutikk","helsebygget","nettbutikk","nettbutikk"]');
html = await side();
sjekk('admin: valget står på avdelingen', /name="avdelinger\[1\]\[inngang\]">[\s\S]*?<option value="helsebygget"\s+selected/.test(html));
sjekk('admin: malen til nye avdelinger', html.includes('id="avdeling-inngang-mal"') && html.includes('avdelinger[__i__][inngang]'));

const dom = new JSDOM(html, { url: 'http://localhost/wp-admin/admin.php?page=amendo-settings#avdelinger', runScripts: 'outside-only' });
const w = dom.window;
w.eval(readFileSync(JQUERY, 'utf8'));
w.eval('window.jQuery = jQuery.noConflict(true);');
w.eval(readFileSync(ADMIN_JS, 'utf8'));
await new Promise(r => w.jQuery(r));
const d = w.document;
d.querySelector('#legg-til-avdeling').click();
const nyVelger = [...d.querySelectorAll('#avdelinger-liste select[name$="[inngang]"]')].at(-1);
sjekk('jsdom: ny avdeling får inngang-valget, med eget nummer', !!nyVelger && nyVelger.name === 'avdelinger[4][inngang]' && nyVelger.value === 'nettbutikk' && !!nyVelger.querySelector('option[value="helsebygget"]'), nyVelger?.name);
sjekk('jsdom: malen er ikke en del av skjemaet', !d.querySelector('form select[name="avdelinger[__i__][inngang]"]'));

// Rydd
await sett({ amendo_moduler: null, amendo_avdelinger: null, amendo_helsebygget_navn: null, amendo_helsebygget_ansatte: null });
ferdig();
