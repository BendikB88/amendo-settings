// 1.4.17: én kundegruppetabell i B2B → Kundegrupper, rabatten følger Tellix
// (også Helsebygget, uten faste satser), «Hvor gjelder rabatten», faktura i
// gatewayens innstilling, migrering fra de gamle kildene og /settings. Mot
// stubben i mu/tellix-stub.php. Se includes/moduler/b2b-kundegrupper.php.
//
// ⚠ Moduler og stubben lastes når pluginen lastes: tilstanden settes i ett
// kall og sjekkes i det neste.
import { JSDOM } from 'jsdom';
import { readFileSync } from 'fs';
import { B, ADMIN_JS, JQUERY, get, sjekk, ferdig } from '../lib/felles.mjs';

const SECRET = 'kg-hemmelig-1417';
const klargjor = body => get('/amendo-test/v1/v1414-klargjor', body);
const opt = o => get('/amendo-test/v1/opt', o);
const bruker = (epost, meta) => get('/amendo-test/v1/bedrift-bruker', { epost, meta });
const brukermeta = async epost => (await fetch(B + '/amendo-test/v1/v1414-brukermeta&epost=' + encodeURIComponent(epost))).json();
const lagre = (post, rolle = 'admin') => get('/amendo-test/v1/v1414-lagre', { post, rolle });
const side = async (rolle = 'admin') => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=' + rolle)).text();
const settings = async () => (await fetch(B + '/amendo-settings/v1/settings&x=' + Date.now())).json();
const rute = async sti => {
  const r = await fetch(B + '/amendo-settings/v1/' + sti, { headers: { 'X-Amendo-Secret': SECRET } });
  const tekst = await r.text();
  let json = null; try { json = JSON.parse(tekst); } catch {}
  return { status: r.status, json, tekst };
};
const telefon = nr => rute('rabatt/telefon&nr=' + encodeURIComponent(nr));
const kunde = async epost => rute('rabatt/kunde/' + (await brukermeta(epost)).id);
const tabell = h => h.slice(h.indexOf('id="b2b-kundegrupper"'), h.indexOf('data-under-panel="helsebygget"'));
const rad = (h, id) => (tabell(h).match(new RegExp(`<tr class="kundegruppe-rad[^"]*" data-gruppe="${id}"[^]*?</tr>`)) || [''])[0];

await get('/amendo-test/v1/setup');
const kat = await get('/amendo-test/v1/kategorirad-oppsett');
const [A, Bk, C, D, E] = [kat.ringer, kat.oredobber, kat.smykker, kat.salg, kat.merker];

// Fasit fra garcon.no: 4, 6 og 7 har 0 % i grunnsats og 40 % på 187, 162 og
// 262; 5 har det samme pluss 249; 19 har 50 % og 50 % på 221. Her med
// kategorier som finnes i testbutikken (A, B, C, D, E) — og gruppe 6 med de
// ekte ID-ene, som går rett gjennom.
const NAV = { [A]: 40, [Bk]: 40, [C]: 40 };
const GRUPPER = [
  { id: 4, name: 'NAV Lillestrøm', discountPercent: 0, categories: NAV },
  { id: 5, name: 'NAV Arbeidslivssenter', discountPercent: 0, categories: { ...NAV, [D]: 40 } },
  { id: 6, name: 'NAV Arbeidsrådgivning', discountPercent: 0, categories: { 187: 40, 162: 40, 262: 40 } },
  { id: 7, name: 'NAV SFJ', discountPercent: 0, categories: NAV },
  { id: 19, name: 'Personalrabatt 50', discountPercent: 50, categories: { [E]: 50 } },
  { id: 14, name: 'Bedriftskunder', discountPercent: 10, categories: { [A]: 15 } },
  { id: 1, name: 'Bedriftsrabatt 10 %', discountPercent: 10 },
  { id: 3, name: 'Faktura 25', discountPercent: 25, active: false },
  { id: 8, name: 'Tom gruppe', discountPercent: 5 },
];
const KUNDER = [
  { id: 'T1', uuid: 'U1', groupId: 14, email: 'bedrift@example.com', phone: '+4741128080', name: 'Kari Hansen' },
  { id: 'T2', uuid: 'U2', groupId: 4, email: 'nav@example.com', phone: '90000001', name: 'Ola' },
  { id: 'T5', uuid: 'U5', groupId: 5, email: 'nav5@example.com', phone: '90000005', name: 'Per' },
  { id: 'T6', uuid: 'U6', groupId: 6, email: 'nav6@example.com', phone: '90000006', name: 'Siv' },
  { id: 'T7', uuid: 'U7', groupId: 7, email: 'nav7@example.com', phone: '90000007', name: 'Åse' },
  { id: 'T19', uuid: 'U19', groupId: 19, email: 'personal@example.com', phone: '90000019', name: 'Liv' },
  { id: 'T11', uuid: 'U11', groupId: 1, email: 'rabatt@example.com', phone: '90000011', name: 'Bo' },
];
// Lista slik den står på garcon.no (1.4.15–1.4.16).
const LISTE = { 4: 40, 5: 40, 6: 40, 7: 40, 19: 50 };

await opt({ amendo_kasse_secret: SECRET });
await klargjor({ moduler: ['b2b', 'helsebygget'], tellix: '1', grupper: GRUPPER, kunder: KUNDER, helsebygget: LISTE, store_groups: null, kundegrupper: null, kundegrupper_ny: null, faktura: null, sov: null, kast: null, api_feil: null, side: null, liste_slett: true, nullstill: true });
await bruker('nav@example.com', { billing_phone: '90000001' });

// ── Rabatten følger Tellix ──────────────────────────────────────────────────
console.log('Helsebygget-rabatten følger Tellix (ingen faste satser)');
let r = await telefon('90000001');
const kats = o => JSON.stringify(Object.entries(o || {}).map(([k, v]) => [Number(k), v]).sort((a, b) => a[0] - b[0]));
const forvent = o => JSON.stringify(Object.entries(o).map(([k, v]) => [Number(k), v]).sort((a, b) => a[0] - b[0]));
sjekk('gruppe 4: helsebygget = {prosent: 0, kategorier: 40 % på tre}, ikke 40 fra lista', r.json?.helsebygget?.prosent === 0 && kats(r.json?.helsebygget?.kategorier) === forvent(NAV) && r.json?.nettbutikk === null, r.json);
sjekk('samme form som nettbutikk (prosent + kategorier)', JSON.stringify(Object.keys(r.json?.helsebygget || {}).sort()) === '["kategorier","prosent"]', r.json?.helsebygget);
r = await telefon('90000005');
sjekk('gruppe 5: det samme pluss en kategori til', r.json?.helsebygget?.prosent === 0 && kats(r.json?.helsebygget?.kategorier) === forvent({ ...NAV, [D]: 40 }), r.json?.helsebygget);
r = await telefon('90000006');
sjekk('gruppe 6: term 187, 162 og 262 med 40 % rett fra Tellix', r.json?.helsebygget?.prosent === 0 && kats(r.json?.helsebygget?.kategorier) === forvent({ 187: 40, 162: 40, 262: 40 }), r.json?.helsebygget);
r = await telefon('90000019');
sjekk('gruppe 19: 50 % grunnsats og 50 % på én kategori', r.json?.helsebygget?.prosent === 50 && kats(r.json?.helsebygget?.kategorier) === forvent({ [E]: 50 }) && r.json?.nettbutikk === null, r.json);
r = await telefon('41128080');
sjekk('gruppe 14 (ikke i lista): nettbutikk med Tellix-satsene', r.json?.nettbutikk?.prosent === 10 && r.json?.nettbutikk?.kategorier?.[String(A)] === 15 && r.json?.helsebygget === null, r.json);
r = await kunde('nav@example.com');
sjekk('rabatt/kunde: samme form', r.json?.gruppe_id === 4 && r.json?.helsebygget?.prosent === 0 && kats(r.json?.helsebygget?.kategorier) === forvent(NAV), r.json);
let sc = (await settings()).kundegruppe_config;
sjekk('/settings: {} til tabellen er lagret (frontenden bytter ikke kilde av seg selv)', sc && Object.keys(sc).length === 0, sc);

// ── Tabellen ────────────────────────────────────────────────────────────────
console.log('\nTabellen i B2B → Kundegrupper');
let html = await side();
const t = tabell(html);
sjekk('én rad per aktiv Tellix-gruppe (8), ikke den inaktive', (t.match(/<tr class="kundegruppe-rad/g) || []).length === 8 && !t.includes('Faktura 25'), (t.match(/<tr class="kundegruppe-rad/g) || []).length);
sjekk('navn fra Tellix', t.includes('NAV Lillestrøm') && t.includes('Personalrabatt 50'));
sjekk('ingen ID-felt, ingen «Fjern», ingen Vercel', !/name="[^"]*\[id\]"/.test(t) && !t.includes('Fjern') && !/vercel/i.test(t));
sjekk('kolonnene, hver med kort hjelpetekst', ['Kundegruppe', 'Kunder', 'Rabatt', 'Hvor gjelder rabatten', 'Faktura', 'Bedriftsvisning', 'Levering'].every(k => t.includes('<th>' + k + '<span class="kolonne-hjelp">')) && (t.match(/kolonne-hjelp/g) || []).length === 7);
sjekk('rabatt som setning: «40 % på Ringer, Øredobber, Smykker & klokker»', rad(html, 4).includes('40 % på Ringer, Øredobber, Smykker &amp; klokker'), rad(html, 4).match(/kundegruppe-rabatt">[^]*?<\/td>/)?.[0]);
sjekk('«10 % på alt» og kategorisatsen for seg', rad(html, 14).includes('10 % på alt<br>15 % på Ringer') && rad(html, 1).includes('10 % på alt'), rad(html, 14).match(/kundegruppe-rabatt">[^]*?<\/td>/)?.[0]);
sjekk('kategori med samme sats som grunnsatsen nevnes ikke («50 % på alt»)', rad(html, 19).includes('50 % på alt') && !rad(html, 19).includes('Merker'));
sjekk('ukjent kategori-ID: «kategori #187»', rad(html, 6).includes('kategori #187'));
sjekk('skrivebeskyttet rabatt med «Settes i Tellix»', (t.match(/Settes i Tellix/g) || []).length === 8 && !/name="[^"]*\[(prosent|rabatt)\]"/.test(t));
sjekk('migrert: gruppene i Helsebygget-lista er «Bare Helsebygget»', [4, 5, 6, 7, 19].every(id => /<option value="helsebygget"\s+selected/.test(rad(html, id))));
sjekk('… og resten «Nettbutikken»', [14, 1, 8].every(id => /<option value="nettbutikk"\s+selected/.test(rad(html, id))));
sjekk('valgene: Nettbutikken / Bare Helsebygget / Ingen rabatt på nett', rad(html, 4).includes('>Nettbutikken<') && rad(html, 4).includes('>Bare Helsebygget<') && rad(html, 4).includes('>Ingen rabatt på nett<'));
sjekk('antall kunder fra Tellix', />1<\/td>/.test(rad(html, 4).match(/kundegruppe-antall">\d+<\/td>/)?.[0] || ''), rad(html, 4).match(/kundegruppe-antall">\d+/)?.[0]);
sjekk('gruppe uten kunder er skjult, med knappen «Vis grupper uten kunder»', /kundegruppe-tom" data-gruppe="8" hidden/.test(t) && t.includes('Vis grupper uten kunder (1)') && !/data-gruppe="4" hidden/.test(t));
sjekk('beskjed om at tabellen ikke er lagret', t.includes('amendo-kundegrupper-ikke-lagret'));
// id="b2b-tellix" er siden 1.4.22 kortet «Tellix-kobling» (b2b-tellix-kunder.php); det gamle hadde overskriften under.
sjekk('de gamle kortene er borte', !html.includes('<h2>Kundegrupper fra Tellix</h2>') && !html.includes('helsebygget_grupper[verdi]') && !html.includes('kundegruppe_config[') && !html.includes('Faktura og rettigheter per kundegruppe'));
let sm = await side('shop_manager');
sjekk('butikkadministrator: «Hvor gjelder rabatten» er tekst, ikke valg', !/name="kundegrupper\[rader\]\[4\]\[gjelder\]"/.test(sm) && rad(sm, 4).includes('Bare Helsebygget') && /name="kundegrupper\[rader\]\[4\]\[faktura\]"/.test(sm));

// ── Migrering fra amendo_kundegruppe_config og gatewayen ────────────────────
console.log('\nMigrering: faktura, bedriftsvisning og levering');
await klargjor({
  kundegrupper: { 14: { navn: 'Bedrift', faktura: 1, b2b: 1, helsebygget: 0, helsebygget_rabatt: 0, levering: 1 }, 1: { navn: 'x', faktura: 1, b2b: 0, helsebygget: 0, helsebygget_rabatt: 0 } },
  faktura: { description: 'Betal med faktura', enabled_customer_group_1: 'no', enabled_customer_group_4: 'yes' },
});
html = await side();
const av = (id, felt) => new RegExp(`name="kundegrupper\\[rader\\]\\[${id}\\]\\[${felt}\\]" value="1"\\s+checked`).test(rad(html, id));
sjekk('faktura: gatewayen vinner (1: nei, 4: ja)', !av(1, 'faktura') && av(4, 'faktura'));
sjekk('faktura: fra den gamle tabellen når gatewayen ikke har gruppen (14)', av(14, 'faktura'));
sjekk('bedriftsvisning og levering fra den gamle tabellen', av(14, 'b2b') && av(14, 'levering') && !av(1, 'b2b') && !av(4, 'levering'));
sc = (await settings()).kundegruppe_config;
sjekk('/settings: med den gamle tabellen er den som før ikke tom', Object.keys(sc).length >= 8, Object.keys(sc));
sjekk('/settings: de gamle feltene for 14', sc['14']?.faktura === 1 && sc['14']?.b2b === 1 && sc['14']?.helsebygget === 0 && sc['14']?.helsebygget_rabatt === 0, sc['14']);
sjekk('/settings: nye felter (gjelder, levering, rabatt)', sc['14']?.gjelder === 'nettbutikk' && sc['14']?.levering === 1 && sc['14']?.rabatt?.prosent === 10 && sc['14']?.rabatt?.kategorier?.[String(A)] === 15, sc['14']);
sjekk('/settings: Helsebygget-gruppe 4 med grunnsatsen som helsebygget_rabatt og kategoriene i rabatt', sc['4']?.helsebygget === 1 && sc['4']?.gjelder === 'helsebygget' && sc['4']?.helsebygget_rabatt === 0 && kats(sc['4']?.rabatt?.kategorier) === forvent(NAV) && sc['4']?.faktura === 1, sc['4']);
sjekk('/settings: 19 → helsebygget_rabatt 50', sc['19']?.helsebygget === 1 && sc['19']?.helsebygget_rabatt === 50, sc['19']);
sjekk('/settings: ingen gruppenavn', !JSON.stringify(sc).includes('Lillestrøm') && !Object.values(sc).some(g => 'navn' in g));

// ── Lagring ─────────────────────────────────────────────────────────────────
console.log('\nLagring');
const rader = {
  4: { med: '1', gjelder: 'nettbutikk' },
  14: { med: '1', gjelder: 'ingen', faktura: '1', b2b: '1' },
  19: { med: '1', gjelder: 'helsebygget', levering: '1' },
  abc: { med: '1', b2b: '1' },
  0: { med: '1', b2b: '1' },
  1: { b2b: '1' },
};
let l = await lagre({ kundegrupper: { finnes: '1', rader } });
sjekk('lagret i amendo_kundegrupper', JSON.stringify(l.kundegrupper_ny) === JSON.stringify({ 4: { gjelder: 'nettbutikk', b2b: 0, levering: 0 }, 14: { gjelder: 'ingen', b2b: 1, levering: 0 }, 19: { gjelder: 'helsebygget', b2b: 0, levering: 1 } }), l.kundegrupper_ny);
sjekk('faktura skrevet til gatewayen, resten av innstillingene urørt', l.faktura?.enabled_customer_group_4 === 'no' && l.faktura?.enabled_customer_group_14 === 'yes' && l.faktura?.enabled_customer_group_19 === 'no' && l.faktura?.enabled_customer_group_1 === 'no' && l.faktura?.description === 'Betal med faktura', l.faktura);
sjekk('den gamle tabellen røres ikke', l.kundegrupper?.['14']?.faktura === 1, l.kundegrupper);
r = await telefon('90000001');
sjekk('gruppe 4 → Nettbutikken: rabatten i nettbutikk, ikke helsebygget', r.json?.nettbutikk?.prosent === 0 && kats(r.json?.nettbutikk?.kategorier) === forvent(NAV) && r.json?.helsebygget === null, r.json);
r = await telefon('41128080');
sjekk('gruppe 14 → Ingen rabatt på nett: begge null, gruppen står', r.json?.gruppe_id === 14 && r.json?.nettbutikk === null && r.json?.helsebygget === null, r.json);
r = await telefon('90000011');
sjekk('gruppe 1 (ikke lagret): Nettbutikken', r.json?.nettbutikk?.prosent === 10, r.json);
html = await side();
sjekk('etter lagring: ingen beskjed om ulagret tabell, valgene står', !html.includes('amendo-kundegrupper-ikke-lagret') && /<option value="ingen"\s+selected/.test(rad(html, 14)));
l = await lagre({ kundegrupper: { finnes: '1', rader: { 4: { med: '1', gjelder: 'helsebygget', b2b: '1' } } } }, 'shop_manager');
sjekk('butikkadministrator: «Hvor gjelder» ignoreres, resten lagres', l.kundegrupper_ny?.['4']?.gjelder === 'nettbutikk' && l.kundegrupper_ny?.['4']?.b2b === 1, l.kundegrupper_ny?.['4']);
l = await lagre({ butikk_navn: 'Test' });
sjekk('lagring uten kundegrupper[finnes]: urørt', l.kundegrupper_ny?.['14']?.gjelder === 'ingen' && l.faktura?.enabled_customer_group_14 === 'yes', l.kundegrupper_ny);
sc = (await settings()).kundegruppe_config;
sjekk('/settings: etter lagring', sc['4']?.gjelder === 'nettbutikk' && sc['4']?.helsebygget === 0 && sc['14']?.gjelder === 'ingen' && sc['14']?.faktura === 1 && sc['19']?.levering === 1, [sc['4'], sc['14'], sc['19']]);

console.log('\nUten fakturagatewayen');
await klargjor({ faktura: null, kundegrupper: null });
l = await lagre({ kundegrupper: { finnes: '1', rader: { 4: { med: '1', gjelder: 'nettbutikk' } } } });
sjekk('bare «nei»: gatewayens innstilling opprettes ikke', l.faktura === null, l.faktura);
l = await lagre({ kundegrupper: { finnes: '1', rader: { 4: { med: '1', gjelder: 'nettbutikk', faktura: '1' } } } });
sjekk('«ja»: opprettet', l.faktura?.enabled_customer_group_4 === 'yes', l.faktura);

// ── Ikke satt opp ───────────────────────────────────────────────────────────
console.log('\nIkke satt opp');
await klargjor({ kundegrupper_ny: null, helsebygget: null, store_groups: { 4: 'helsebygget' } });
r = await telefon('90000001');
sjekk('verken tabell eller gammel liste: 503', r.status === 503 && r.json?.code === 'amendo_helsebygget_ikke_satt', [r.status, r.json]);
html = await side();
sjekk('amendo-store-groups gir forslaget «Bare Helsebygget» for 4', /<option value="helsebygget"\s+selected/.test(rad(html, 4)) && /<option value="nettbutikk"\s+selected/.test(rad(html, 5)));
l = await lagre({ kundegrupper: { finnes: '1', rader: { 4: { med: '1', gjelder: 'helsebygget' } } } });
r = await telefon('90000001');
sjekk('tabellen lagret: 200 og Tellix-satsene på Helsebygget', r.status === 200 && r.json?.helsebygget?.prosent === 0 && kats(r.json?.helsebygget?.kategorier) === forvent(NAV), [r.status, r.json]);

// ── admin.js i jsdom ────────────────────────────────────────────────────────
console.log('\nadmin.js i jsdom');
await klargjor({ helsebygget: LISTE, store_groups: null });
html = await side();
const dom = new JSDOM(html, { url: 'http://localhost/wp-admin/admin.php?page=amendo-settings#b2b-kundegrupper', runScripts: 'outside-only' });
const w = dom.window;
w.eval(readFileSync(JQUERY, 'utf8'));
w.eval('window.jQuery = jQuery.noConflict(true);');
w.eval(readFileSync(ADMIN_JS, 'utf8'));
await new Promise(res => w.jQuery(res));
const d = w.document;
const flagg = () => d.querySelector('input[name="amendo_endret[kundegrupper]"]').value;
sjekk('#b2b-kundegrupper åpner underfanen', d.querySelector('[data-under-panel="kundegrupper"]').classList.contains('active'));
d.querySelector('#vis-tomme-kundegrupper').click();
sjekk('«Vis grupper uten kunder» viser dem, og regnes ikke som en endring', !d.querySelector('[data-gruppe="8"]').hidden && !d.querySelector('#vis-tomme-kundegrupper') && flagg() === '', flagg());
const valg = d.querySelector('select[name="kundegrupper[rader][4][gjelder]"]');
valg.value = 'ingen'; valg.dispatchEvent(new w.Event('change', { bubbles: true }));
sjekk('endret valg: tabellen lagres', flagg() === '1');

await klargjor({ moduler: null, tellix: null, helsebygget: null, kundegrupper: null, kundegrupper_ny: null, faktura: null, nullstill: true });
await opt({ amendo_kasse_secret: null });
ferdig();
