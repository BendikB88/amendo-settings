// 1.4.34: sesongseksjoner på forsiden (`sesongseksjoner` i /settings, bare de
// som gjelder i dag) og kontaktskjemaet (POST /kontakt: X-Amendo-Secret,
// brems per kunde-IP og totalt, honningkrukke, wp_mail til adressen fra
// admin). Testrutene er i mu/v1434-test.php, e-post fanges i mu/v1429-test.php.
// Se includes/sesong.php og includes/kontakt.php.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { JSDOM } from 'jsdom';
import { B, REPO, ADMIN_JS, JQUERY, get, sjekk, ferdig } from '../lib/felles.mjs';

const SECRET = 'kontakt-hemmelig-1434';
const sett = o => get('/amendo-test/v1/v1423-sett', o);
const lagre = post => get('/amendo-test/v1/v1434-lagre', { post });
const side = async () => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=admin')).text();
const settings = () => get('/amendo-settings/v1/settings');
const eposter = async () => (await get('/amendo-test/v1/v1434-epost')).epost || [];
const nullstill = () => get('/amendo-test/v1/v1434-nullstill');
const dag = (fra, n) => { const d = new Date(fra + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };

const kontakt = async (body, { secret = SECRET, ip } = {}) => {
  const headers = { 'content-type': 'application/json' };
  if (secret !== null) headers['X-Amendo-Secret'] = secret;
  if (ip) headers['X-Amendo-Klient-IP'] = ip;
  const r = await fetch(B + '/amendo-settings/v1/kontakt', { method: 'POST', headers, body: JSON.stringify(body) });
  const tekst = await r.text();
  let json = null; try { json = JSON.parse(tekst); } catch {}
  return { status: r.status, json, tekst };
};

await get('/amendo-test/v1/setup');
await sett({ amendo_test_stille: '1', timezone_string: 'Europe/Oslo', amendo_sesongseksjoner: null, amendo_frontend_url: 'https://frontend.example', amendo_revalidate_secret: 'reval-1434',
  amendo_kasse_secret: SECRET, amendo_kontaktskjema_epost: null, amendo_kontakt_epost: null, amendo_test_epost_fang: '1', amendo_test_epost: null, amendo_test_epost_feil: null,
  amendo_test_kontakt_brems: null, amendo_test_kontakt_totalt: null });
const T = await get('/amendo-test/v1/v1434-termer');
const I = T.idag;

// ── Sesongseksjoner ─────────────────────────────────────────────────────────
console.log('Sesongseksjoner');
let s = await settings();
sjekk('/settings har alltid sesongseksjoner, tom uten seksjoner', JSON.stringify(s.sesongseksjoner) === '[]', s.sesongseksjoner);
let html = await side();
sjekk('fanen Sesong med kortet, markøren og malen', html.includes('data-tab="sesong"') && html.includes('data-amendo-del="sesong"') && html.includes('name="sesong[finnes]"') && html.includes('id="sesong-mal"'));

const SEKSJONER = [
  { overskrift: 'Julekaker', tekst: '<b>Bakt</b> med smør', kilde: 'kategori', kategori: T.jul, antall: '30', sortering: '20', vis_fra: dag(I, -1), vis_til: dag(I, 1) },
  { overskrift: 'Påske', kilde: 'etikett', etikett: T.paske, kategori: T.jul, antall: '0', sortering: '10', vis_fra: '', vis_til: '' },
  { overskrift: 'Kommer', kilde: 'kategori', kategori: T.jul, vis_fra: dag(I, 1) },
  { overskrift: 'Utløpt', kilde: 'kategori', kategori: T.jul, vis_til: dag(I, -1) },
  { overskrift: 'Akkurat i dag', kilde: 'kategori', kategori: T.jul, vis_fra: I, vis_til: I },
  { overskrift: 'Feil rekkefølge', kilde: 'kategori', kategori: T.jul, vis_fra: dag(I, 2), vis_til: dag(I, -2) },
  { overskrift: '', kilde: 'kategori', kategori: T.jul },
  { overskrift: 'Slettet kategori', kilde: 'kategori', kategori: T.borte, sortering: '1' },
  { overskrift: 'Ugyldig dato', kilde: 'tull', kategori: T.jul, vis_fra: '2026-02-30', sortering: '99' },
];
let l = await lagre({ amendo_endret: { sesong: '1' }, sesong: { finnes: '1', seksjoner: SEKSJONER } });
let lagret = l.sesong || [];
sjekk('lagret: seksjonen uten overskrift er borte, ID-ene sesong-1 …', lagret.length === 8 && lagret.map(x => x.id).join() === 'sesong-1,sesong-2,sesong-3,sesong-4,sesong-5,sesong-6,sesong-7,sesong-8', lagret.map(x => x.id));
sjekk('teksten uten HTML; antall høyst 24, 0 = 8', lagret[0]?.tekst === 'Bakt med smør' && lagret[0]?.antall === 24 && lagret[1]?.antall === 8, [lagret[0], lagret[1]?.antall]);
sjekk('etikett: kategorien nullstilles; ukjent kilde = kategori; ugyldig dato = tom', lagret[1]?.kilde === 'etikett' && lagret[1]?.etikett === T.paske && lagret[1]?.kategori === 0 && lagret[7]?.kilde === 'kategori' && lagret[7]?.vis_fra === '', [lagret[1], lagret[7]]);
sjekk('lagringen varsler frontenden (revalidering med taggen innstillinger)', l.kall.some(k => k.url === 'https://frontend.example/api/revalidate' && (k.tags || []).includes('innstillinger')), l.kall);

await get('/amendo-test/v1/v1434-slett-term', { id: T.borte, taks: 'product_cat' });
s = await settings();
const aktive = s.sesongseksjoner || [];
sjekk('bare de som gjelder i dag, sortert (kommer, utløpt, feil rekkefølge og slettet kategori er ute)', aktive.map(x => x.overskrift).join() === 'Påske,Julekaker,Akkurat i dag,Ugyldig dato', aktive.map(x => x.overskrift));
sjekk('seksjonen: id, overskrift, tekst, kilde, kategori { id, slug, navn }, etikett null, antall, sortering, datoene', JSON.stringify(aktive.find(x => x.id === 'sesong-1')) === JSON.stringify({
  id: 'sesong-1', overskrift: 'Julekaker', tekst: 'Bakt med smør', kilde: 'kategori', kategori: { id: T.jul, slug: 'jul-1434', navn: 'Jul 1434' }, etikett: null, antall: 24, sortering: 20, vis_fra: dag(I, -1), vis_til: dag(I, 1),
}), aktive.find(x => x.id === 'sesong-1'));
sjekk('etikett: { id, slug, navn }, kategori null', JSON.stringify(aktive.find(x => x.id === 'sesong-2')?.etikett) === JSON.stringify({ id: T.paske, slug: 'paske-1434', navn: 'Påske 1434' }) && aktive.find(x => x.id === 'sesong-2')?.kategori === null, aktive.find(x => x.id === 'sesong-2'));
sjekk('første og siste dag er med (vis fra = vis til = i dag)', aktive.some(x => x.overskrift === 'Akkurat i dag'));

html = await side();
const sesongKort = html.slice(html.indexOf('id="tab-sesong"'), html.indexOf('id="sesong-mal"'));
sjekk('admin viser status: Vises nå, Vises fra, Utløpt, vises aldri', ['Vises nå', 'Vises fra ' + dag(I, 1), 'Utløpt', 'vises aldri'].every(t => sesongKort.includes(t)));

const medId = lagret.map(x => ({ ...x }));
l = await lagre({ amendo_endret: { sesong: '1' }, sesong: { finnes: '1', seksjoner: [{ overskrift: 'Ny øverst', kilde: 'kategori', kategori: T.jul }, ...medId] } });
sjekk('ny seksjon øverst: de gamle beholder ID-en, den nye får en ledig', l.sesong?.map(x => x.id).join() === 'sesong-9,sesong-1,sesong-2,sesong-3,sesong-4,sesong-5,sesong-6,sesong-7,sesong-8', l.sesong?.map(x => x.id));
await lagre({ amendo_endret: { sesong: '1' }, sesong: { finnes: '1', seksjoner: medId } });
l = await lagre({ amendo_endret: { sesong: '1' }, sesong: { seksjoner: [] } });
sjekk('uten markør: urørt', l.sesong?.length === 8, l.sesong?.length);
l = await lagre({ amendo_endret: { sesong: '' }, sesong: { finnes: '1', seksjoner: [] } });
sjekk('urørt i nettleseren (flagget tomt): urørt', l.sesong?.length === 8, l.sesong?.length);

// admin.js i jsdom
const dom = new JSDOM(await side(), { url: 'http://localhost/wp-admin/admin.php?page=amendo-settings#sesong', runScripts: 'outside-only' });
const w = dom.window;
w.confirm = () => true;
w.eval(readFileSync(JQUERY, 'utf8'));
w.eval('window.jQuery = jQuery.noConflict(true);');
w.eval(readFileSync(ADMIN_JS, 'utf8'));
await new Promise(res => w.jQuery(res));
const d = w.document;
sjekk('jsdom: #sesong åpner fanen', d.querySelector('#tab-sesong').classList.contains('active'));
const før = new Set([...d.querySelectorAll('#sesong-liste [name]')].map(e => e.name));
d.querySelector('#sesong-legg-til').click();
const ny = [...d.querySelectorAll('#sesong-liste .sesong-rad')].at(-1);
const nyOverskrift = ny.querySelector('input[name$="[overskrift]"]');
sjekk('jsdom: ny rad med et nummer ingen andre har, og søk i kategorivelgeren', /^sesong\[seksjoner\]\[\d+\]\[overskrift\]$/.test(nyOverskrift.name) && ![...ny.querySelectorAll('[name]')].some(e => før.has(e.name)) && !!ny.querySelector('.amendo-kategori-sok'), nyOverskrift.name);
sjekk('jsdom: malen er ikke en del av skjemaet, delen er merket som endret', !d.querySelector('form [name*="__i__"]') && d.querySelector('#sesong > .amendo-endret').value === '1');
const kilde = ny.querySelector('.sesong-kilde');
kilde.value = 'etikett'; kilde.dispatchEvent(new w.Event('change', { bubbles: true }));
sjekk('jsdom: kilde etikett viser etikettvelgeren og skjuler kategorien', ny.querySelector('.sesong-bare-kategori').hidden && !ny.querySelector('.sesong-bare-etikett').hidden);
nyOverskrift.value = 'Halloween'; nyOverskrift.dispatchEvent(new w.Event('input', { bubbles: true }));
sjekk('jsdom: tittelen følger overskriften', ny.querySelector('.sesong-tittel').textContent === 'Halloween');
ny.querySelector('.sesong-slett').click();
sjekk('jsdom: slett', d.querySelectorAll('#sesong-liste .sesong-rad').length === 8);

// ── Kontaktskjemaet ─────────────────────────────────────────────────────────
console.log('\nKontaktskjemaet: tilgang');
const GYLDIG = { navn: 'Kari Nordmann', epost: 'kari@example.com', telefon: '+47 999 88 777', emne: 'Bestilling til bursdag', melding: 'Hei!\nKan dere lage en kake til 20 personer?', dato: '2026-12-24', antall_personer: 20 };
let r = await kontakt(GYLDIG, { secret: null });
sjekk('uten X-Amendo-Secret: avvist', r.status === 401 || r.status === 403, r.status);
r = await kontakt(GYLDIG, { secret: 'feil' });
sjekk('feil secret: avvist', r.status === 401 || r.status === 403, r.status);
await sett({ amendo_kasse_secret: '' });
r = await kontakt(GYLDIG, { secret: '' });
sjekk('tom secret i admin: avvist, også med tom header (fail-closed)', r.status === 401 || r.status === 403, r.status);
await sett({ amendo_kasse_secret: SECRET });
sjekk('… og ingen e-post', (await eposter()).length === 0);

console.log('\nKontaktskjemaet: validering');
await nullstill();
r = await kontakt({ telefon: 'abc', dato: '2026-13-01', antall_personer: 0 });
sjekk('400 med alle feltene som er feil', r.status === 400 && r.json?.feil === 'ugyldig' && ['navn', 'epost', 'melding', 'telefon', 'dato', 'antall_personer'].every(f => r.json.felt.includes(f)), r.json);
r = await kontakt({ ...GYLDIG, epost: 'ikke-en-epost' });
sjekk('ugyldig e-post', r.status === 400 && JSON.stringify(r.json?.felt) === '["epost"]', r.json);
r = await kontakt({ ...GYLDIG, antall_personer: 'mange' });
sjekk('antall personer som tekst', r.status === 400 && JSON.stringify(r.json?.felt) === '["antall_personer"]', r.json);
r = await kontakt({ ...GYLDIG, antall_personer: 10001 });
sjekk('antall personer over 10 000', r.status === 400 && JSON.stringify(r.json?.felt) === '["antall_personer"]', r.json);
r = await kontakt({ ...GYLDIG, melding: 'x'.repeat(5001) });
sjekk('melding over 5000 tegn', r.status === 400 && JSON.stringify(r.json?.felt) === '["melding"]', r.json);
sjekk('… ingen e-post', (await eposter()).length === 0);

console.log('\nKontaktskjemaet: sendes');
const admin = (await get('/amendo-test/v1/v1434-epost')).admin;
r = await kontakt(GYLDIG);
let e = (await eposter()).at(-1) || { til: [], emne: '', html: '', headers: [] };
sjekk('200 { ok: true }', r.status === 200 && r.tekst === '{"ok":true}', r.tekst);
sjekk('ingen adresse i admin: til admin-e-posten', e.til.join() === admin, e.til);
sjekk('emne fra skjemaet', e.emne === 'Kontaktskjema: Bestilling til bursdag', e.emne);
sjekk('innholdet: navn, e-post, telefon, dato (norsk), antall personer og meldingen', ['Navn: Kari Nordmann', 'E-post: kari@example.com', 'Telefon: +47 999 88 777', 'Dato: 24.12.2026', 'Antall personer: 20', 'Hei!\nKan dere lage en kake til 20 personer?'].every(t => e.html.includes(t)), e.html);
sjekk('Reply-To er avsenderen, ren tekst', e.headers.includes('Reply-To: Kari Nordmann <kari@example.com>') && e.headers.includes('Content-Type: text/plain; charset=UTF-8'), e.headers);
r = await kontakt({ navn: 'Ola', epost: 'ola@example.com', melding: 'Bare et spørsmål' });
e = (await eposter()).at(-1);
sjekk('uten emne, telefon, dato og antall: «Kontaktskjema fra <navn>», ingen tomme linjer for dem', r.status === 200 && e.emne === 'Kontaktskjema fra Ola' && !/Telefon:|Dato:|Antall personer:/.test(e.html), [e.emne, e.html]);
r = await kontakt({ ...GYLDIG, emne: 'Hei\r\nBcc: spam@example.com', navn: 'Kari\nBcc: x@example.com' });
e = (await eposter()).at(-1);
sjekk('linjeskift i emne og navn blir mellomrom (ingen nye e-posthoder)', r.status === 200 && !/[\r\n]/.test(e.emne) && e.headers.every(h => !/[\r\n]/.test(h)), [e.emne, e.headers]);

await sett({ amendo_kontakt_epost: 'post@butikk.example' });
await nullstill();
await kontakt(GYLDIG);
sjekk('uten egen adresse: kontakt-e-posten fra fanen Kontakt', (await eposter()).at(-1).til.join() === 'post@butikk.example');
l = await lagre({ kontaktskjema_finnes: '1', kontaktskjema_epost: 'skjema@butikk.example, tull, skjema2@butikk.example' });
sjekk('lagring: adressene, ugyldige fjernet', l.kontakt === 'skjema@butikk.example, skjema2@butikk.example', l.kontakt);
await kontakt(GYLDIG);
sjekk('sendes til adressene fra admin', (await eposter()).at(-1).til.join() === 'skjema@butikk.example,skjema2@butikk.example', (await eposter()).at(-1).til);
l = await lagre({ kontaktskjema_epost: '' });
sjekk('lagring uten markør: urørt', l.kontakt === 'skjema@butikk.example, skjema2@butikk.example', l.kontakt);
html = await side();
sjekk('feltet i fanen Kontakt', html.includes('name="kontaktskjema_epost" value="skjema@butikk.example, skjema2@butikk.example"') && html.includes('name="kontaktskjema_finnes"'));

console.log('\nKontaktskjemaet: honningkrukke og brems');
let antall = (await eposter()).length;
r = await kontakt({ ...GYLDIG, nettside: 'http://spam.example' });
sjekk('honningkrukka fylt ut: 200 { ok: true } som ellers, men ingen e-post', r.status === 200 && r.tekst === '{"ok":true}' && (await eposter()).length === antall, r.tekst);
await sett({ amendo_test_kontakt_brems: '2' });
await nullstill();
const A = '203.0.113.5', Bip = '203.0.113.6';
const a1 = await kontakt(GYLDIG, { ip: A }), a2 = await kontakt(GYLDIG, { ip: A }), a3 = await kontakt(GYLDIG, { ip: A });
sjekk('per kunde-IP: 2 går gjennom, den 3. får 429 med ventetid', a1.status === 200 && a2.status === 200 && a3.status === 429 && a3.json?.feil === 'brems' && a3.json.vent > 0, [a1.status, a2.status, a3.status, a3.json]);
r = await kontakt(GYLDIG, { ip: Bip });
sjekk('en annen kunde bak samme server slipper gjennom', r.status === 200, r.status);
await nullstill();
for (let i = 0; i < 3; i++) await kontakt({ navn: 'x' }, { ip: A });
r = await kontakt(GYLDIG, { ip: A });
sjekk('ugyldige innsendinger teller ikke', r.status === 200, r.status);
await nullstill();
await kontakt(GYLDIG, { ip: 'tull' });
await kontakt(GYLDIG, { ip: 'tull' });
r = await kontakt(GYLDIG);
sjekk('ugyldig X-Amendo-Klient-IP: REMOTE_ADDR gjelder', r.status === 429, r.status);
await sett({ amendo_test_kontakt_brems: null, amendo_test_kontakt_totalt: '3' });
await nullstill();
const t = [];
for (const ip of ['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4']) t.push((await kontakt(GYLDIG, { ip })).status);
sjekk('totalt per server: 3 går gjennom, den 4. får 429 uansett kunde-IP', JSON.stringify(t) === '[200,200,200,429]', t);
await sett({ amendo_test_kontakt_totalt: null });
await nullstill();

await sett({ amendo_test_epost_feil: '1' });
r = await kontakt(GYLDIG);
sjekk('wp_mail feiler: 502 ikke_sendt', r.status === 502 && r.json?.feil === 'ikke_sendt', r);
await sett({ amendo_test_epost_feil: null });

console.log('\nKildekoden');
const kilde2 = ['includes/sesong.php', 'includes/kontakt.php'].map(f => readFileSync(resolve(REPO, f), 'utf8')).join('\n');
sjekk('ingen butikknavn i sesong.php og kontakt.php', !/Aanerud|Garçon|Garcon|emmk|Jessheim|Dorothea/i.test(kilde2));

// Rydd
await nullstill();
await sett({ amendo_sesongseksjoner: null, amendo_frontend_url: null, amendo_revalidate_secret: null, amendo_kontaktskjema_epost: null, amendo_kontakt_epost: null,
  amendo_test_epost_fang: null, amendo_test_epost: null, amendo_test_epost_feil: null, amendo_test_kontakt_brems: null, amendo_test_kontakt_totalt: null, timezone_string: null });
ferdig();
