// 1.4.36: POST /kakebilder — filene ut av wcpa_temp og _WCPA_order_meta_data
// på REST-ordrer, mot den ekte WCPA Pro 5.5.0 (tests/playground/eksterne/).
// Rutene i mu/v1436-test.php. Se includes/tilvalg.php.
import { readFileSync } from 'node:fs';
import { B, REPO, URL_, get, sjekk, ferdig } from '../lib/felles.mjs';

const SECRET = 'hemmelig-1436';
const kakebilder = async (id, secret = SECRET) => {
  const r = await fetch(B + '/amendo-settings/v1/kakebilder', {
    method: 'POST', headers: { 'content-type': 'application/json', ...(secret !== null ? { 'X-Amendo-Secret': secret } : {}) },
    body: JSON.stringify({ order_id: id }),
  });
  return { status: r.status, json: await r.json().catch(() => null) };
};
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
/** Ekte opplasting via WCPA, slik frontendens /api/upload gjør. */
const lastOpp = async (produkt, navn) => {
  const fd = new FormData();
  fd.append('wcpa_file', new Blob([PNG], { type: 'image/png' }), navn);
  const r = await fetch(B + `/wcpa/front/upload/${produkt}/bilde-1436`, { method: 'POST', body: fd });
  return r.json().catch(() => null);
};
const uploadsRel = url => decodeURIComponent(url.replace(/^https?:\/\/[^/]+\/wp-content\/uploads\//, ''));
const fil = (url, slett) => get(`/amendo-test/v1/v1436-fil&sti=${encodeURIComponent(uploadsRel(url))}${slett ? '&slett=1' : ''}`);
const linjer = id => get('/amendo-test/v1/v1436-linjer&id=' + id);
const rad = (l, key) => l.rader.find(r => r.key === key);
const ingenUnderstrek = l => l.rader.filter(r => !r.key.startsWith('_'));
/** Felter fra kurven/strukturen uten det som skal være ulikt (meta_id, filer) og uten tomme. */
const sammenlignbar = felter => (felter ?? [])
  .filter(f => f.type !== 'file' && f.value !== false && f.value !== '' && !(Array.isArray(f.value) && !f.value.length))
  .map(({ meta_id, ...f }) => f)
  .sort((a, b) => a.elementId.localeCompare(b.elementId));

// ── Oppsett ─────────────────────────────────────────────────────────────────
await get('/amendo-test/v1/setup');
let w = await get('/amendo-test/v1/v1436-wcpa&pa=1');
if (!w.finnes) {
  console.log('  ✗ WCPA Pro finnes ikke i tests/playground/eksterne/woo-custom-product-addons-pro — pakk ut woo-custom-product-addons-pro-5.5.0.zip der');
  sjekk('WCPA Pro er montert', false);
  ferdig();
}
w = await get('/amendo-test/v1/v1436-wcpa');
sjekk('WCPA Pro 5.5.0 er lastet', w.lastet === '5.5.0', w);
sjekk('filteret wcpa_add_cart_item_data finnes', w.filter === true, w);
const ider = await get('/amendo-test/v1/v1436-produkter');
sjekk('produktene og skjemaet', ider.kake > 0 && ider.fyllkake > 0 && ider.stor > 0 && ider.skjema > 0, ider);
await get('/amendo-test/v1/v1436-sett', { amendo_test_v1436_gruppe: 'olsread' });
await get('/amendo-test/v1/v1436-rettigheter');

console.log('\nTilgang');
let r = await kakebilder(1, null);
sjekk('uten X-Amendo-Secret: 401', r.status === 401, r);
r = await kakebilder(1, 'feil');
sjekk('feil secret: 401', r.status === 401, r);
await get('/amendo-test/v1/v1436-sett', { amendo_kasse_secret: '' });
r = await kakebilder(1, '');
sjekk('tom amendo_kasse_secret: 401 også med tom header', r.status === 401, r);
await get('/amendo-test/v1/v1436-sett', { amendo_kasse_secret: SECRET });
r = await kakebilder(99999999);
sjekk('ukjent ordre: 404', r.status === 404, r);

// ── Ordre A: alle feltene ───────────────────────────────────────────────────
console.log('\nOpplasting via WCPA');
const opp = await lastOpp(ider.kake, 'kakebilde-a1436.png');
sjekk('WCPA tok imot bildet i wcpa_temp', opp?.status === true && /\/wcpa_uploads\/wcpa_temp\//.test(opp?.file?.url ?? ''), opp);
const tempUrl = opp?.file?.url ?? '';
const tempFør = await fil(tempUrl);
sjekk('temp-filen finnes', tempFør.finnes === true, tempFør);

const metaA = [
  { key: 'Velg kakefyll', value: 'Sjokolade' },
  { key: 'Farge & dekor', value: 'Rosa' },
  { key: 'Pynt', value: 'Strøssel, Lys, 10 stk' },
  { key: 'Ekstra lys', value: 'Hvite lys x 3' },
  { key: 'Tekst på kaken', value: 'Gratulerer, Ola!' },
  { key: 'Last opp ditt bilde!', value: `kakebilde-a1436.png | ${tempUrl}` },
  { key: 'Leveringsnotat', value: 'Ring på' },
];
const A = await get('/amendo-test/v1/v1436-ordre', { linjer: [{ product_id: ider.kake, quantity: 1, meta_data: metaA }] });
sjekk('ordre A laget via wc/v3/orders', A.status === 201 && A.id > 0, A);
const før = (await linjer(A.id)).linjer[0];
sjekk('A før: ingen _WCPA_order_meta_data', før.struktur === null, før.struktur);

console.log('\nKall 1 på A');
r = await kakebilder(A.id);
sjekk('200 { ok, kopiert: 1, mangler: [], tilvalg: 1 }', r.status === 200 && r.json?.ok === true && r.json.kopiert === 1 && r.json.mangler?.length === 0 && r.json.tilvalg === 1, r);
let a = (await linjer(A.id)).linjer[0];
const bilde = rad(a, 'Last opp ditt bilde!');
const nyUrl = (bilde?.value ?? '').split(' | ')[1] ?? '';
const aar = String(new Date().getFullYear());
sjekk('filen: «<filnavn> | <ny url>» på samme meta_id', bilde?.id === rad(før, 'Last opp ditt bilde!').id && bilde.value === `kakebilde-a1436.png | ${nyUrl}`, bilde);
sjekk(`ny url under uploads/kakebilder/${aar}/<ordre-id>-<filnavn>`, nyUrl === `${URL_}/wp-content/uploads/kakebilder/${aar}/${A.id}-kakebilde-a1436.png`, nyUrl);
const kopi = await fil(nyUrl);
sjekk('kopien finnes og er lik', kopi.finnes === true && kopi.md5 === tempFør.md5, kopi);
sjekk('kopiert, ikke flyttet: temp-filen står', (await fil(tempUrl)).finnes === true);
const rett = await get('/amendo-test/v1/v1436-rettigheter');
const stier = rett.kall.map(k => k.sti);
const filkall = rett.kall.find(k => !k.mappe);
sjekk('filen: modusen til en opplasting (mappens, uten kjøring)', filkall && filkall.modus !== null && (filkall.modus & 0o111) === 0 && (filkall.modus & 0o444) !== 0, rett.kall);
sjekk('mappene: modusen til mappen over', rett.kall.filter(k => k.mappe).every(k => k.modus !== null && (k.modus & 0o111) !== 0), rett.kall);
sjekk('gruppen settes på kakebilder/, årsmappen og filen', stier.includes('/kakebilder') && stier.includes(`/kakebilder/${aar}`) && stier.includes(`/kakebilder/${aar}/${A.id}-kakebilde-a1436.png`), rett);
sjekk('… med gruppen fra amendo_tilvalg_filgruppe (olsread)', rett.kall.length > 0 && rett.kall.every(k => k.gruppe === 'olsread'), rett);

console.log('\n_WCPA_order_meta_data på A');
const s = a.struktur ?? [];
sjekk('strukturen finnes', s.length > 0, a.struktur);
const radIder = ingenUnderstrek(a).map(r => r.id);
sjekk('hvert felt har meta_id til en rad på linjen', s.length > 0 && s.every(f => radIder.includes(f.meta_id)), s.map(f => [f.elementId, f.meta_id]));
sjekk('meta_id-ene er unike', new Set(s.map(f => f.meta_id)).size === s.length, s.map(f => f.meta_id));
sjekk('ingen nye rader (samme antall, samme nøkler)', JSON.stringify(ingenUnderstrek(a).map(r => [r.id, r.key])) === JSON.stringify(ingenUnderstrek(før).map(r => [r.id, r.key])), { før: ingenUnderstrek(før), etter: ingenUnderstrek(a) });
sjekk('feltene uten rad er ikke med (Hilsen, Velg fyll)', !s.some(f => ['hilsen-1436', 'fyll-liten-1436', 'fyll-stor-1436'].includes(f.elementId)), s.map(f => f.elementId));
sjekk('raden uten felt står urørt (Leveringsnotat)', rad(a, 'Leveringsnotat')?.value === 'Ring på' && !s.some(f => f.meta_id === rad(a, 'Leveringsnotat').id), rad(a, 'Leveringsnotat'));
const filfelt = s.find(f => f.elementId === 'bilde-1436');
sjekk('filfeltet peker på kopien (url og sti)', filfelt?.value?.[0]?.url === nyUrl && /\/kakebilder\/\d{4}\/\d+-kakebilde-a1436\.png$/.test(filfelt?.value?.[0]?.file ?? ''), filfelt);

const fasit = await get('/amendo-test/v1/v1436-kurv', { produkt: ider.kake, post: {
  'kakefyll-1436': 'sjokolade', 'farge-1436': 'rosa', 'pynt-1436': ['strossel', 'lys-10'],
  'lys-1436': 'hvite', 'lys-1436_quantity': '3', 'tekst-1436': 'Gratulerer, Ola!',
} });
sjekk('fasiten: WCPA sin egen «legg i kurv»', fasit.nokkel === true && fasit.felter?.length > 0, fasit);
const vår = sammenlignbar(s), deres = sammenlignbar(fasit.felter);
sjekk('strukturen er lik kurvens (type, etikett, verdier, antall, pris)', JSON.stringify(vår) === JSON.stringify(deres), { vår, deres });
const kakefyll = s.find(f => f.elementId === 'kakefyll-1436');
sjekk('bildevalget: Sjokolade med ikonet', kakefyll?.value?.[0]?.value === 'sjokolade' && /sjokolade\.png$/.test(kakefyll?.value?.[0]?.image ?? ''), kakefyll);
sjekk('flervalg med «, » i etiketten (Strøssel + «Lys, 10 stk»)', JSON.stringify((s.find(f => f.elementId === 'pynt-1436')?.value ?? []).map(v => v.value)) === '["strossel","lys-10"]', s.find(f => f.elementId === 'pynt-1436'));
sjekk('antall (Hvite lys x 3)', s.find(f => f.elementId === 'lys-1436')?.quantity === 3, s.find(f => f.elementId === 'lys-1436'));
sjekk('etikett med &amp; finnes («Farge & dekor»)', s.some(f => f.elementId === 'farge-1436' && f.value?.[0]?.color === '#ff99cc'), s.find(f => f.elementId === 'farge-1436'));

console.log('\nVisningen');
const vis = await linjer(A.id);
a = vis.linjer[0];
sjekk('e-posten viser ikonet til kakefyllet', /sjokolade\.png/.test(vis.epost), vis.epost.slice(0, 2000));
sjekk('e-posten viser fargen', /#ff99cc/i.test(vis.epost), vis.epost.slice(0, 2000));
sjekk('e-posten lenker til kopien, ikke temp', vis.epost.includes(`kakebilder/${aar}/${A.id}-kakebilde-a1436.png`) && !vis.epost.includes('wcpa_temp'), vis.epost.slice(0, 2000));
sjekk('e-posten: hver verdi én gang (ingen dobbel visning)', (vis.epost.match(/Gratulerer, Ola!/g) ?? []).length === 1, (vis.epost.match(/Gratulerer, Ola!/g) ?? []).length);
sjekk('wp-admin: WCPA tegner linjen fra strukturen', /class="wcpa_order_meta"/.test(a.admin) && /data-wcpa=/.test(a.admin), a.admin.slice(0, 300));
sjekk('meta-visningen (PDF/kvittering): ikon og farge', /sjokolade\.png/.test(a.vist) && /#ff99cc/i.test(a.vist), a.vist.slice(0, 1500));

console.log('\nKall 2 på A (ingenting skal skje)');
const radene1 = JSON.stringify(a.rader), struktur1 = JSON.stringify(a.struktur);
r = await kakebilder(A.id);
sjekk('200 { kopiert: 0, tilvalg: 0 }', r.status === 200 && r.json?.kopiert === 0 && r.json?.tilvalg === 0 && r.json?.mangler?.length === 0, r);
a = (await linjer(A.id)).linjer[0];
sjekk('radene er uendret', JSON.stringify(a.rader) === radene1, { før: JSON.parse(radene1), etter: a.rader });
sjekk('strukturen er uendret', JSON.stringify(a.struktur) === struktur1);
sjekk('ingen ny kopi', (await fil(nyUrl)).filer_i_mappen === kopi.filer_i_mappen);

console.log('\nProcessing (WCPA kjører update_order_item igjen)');
await get(`/amendo-test/v1/v1436-status&id=${A.id}&status=processing`);
a = (await linjer(A.id)).linjer[0];
sjekk('ingen nye rader', JSON.stringify(ingenUnderstrek(a).map(r => [r.id, r.key])) === JSON.stringify(JSON.parse(radene1).filter(r => !r.key.startsWith('_')).map(r => [r.id, r.key])), ingenUnderstrek(a));
sjekk('ingen WCPA_id_-rader', !a.rader.some(r => r.key.startsWith('WCPA_id_')), a.rader.map(r => r.key));
sjekk('verdiene som før', JSON.stringify(ingenUnderstrek(a).map(r => r.value)) === JSON.stringify(JSON.parse(radene1).filter(r => !r.key.startsWith('_')).map(r => r.value)), ingenUnderstrek(a));

// ── Ordre B: «Velg fyll» styrt av varianten ─────────────────────────────────
console.log('\nOrdre B: «Velg fyll» på varianten Stor');
const Bo = await get('/amendo-test/v1/v1436-ordre', { linjer: [{ product_id: ider.fyllkake, variation_id: ider.stor, quantity: 1, meta_data: [{ key: 'Velg fyll', value: 'Sitron' }] }] });
r = await kakebilder(Bo.id);
sjekk('200 { kopiert: 0, tilvalg: 1 }', r.status === 200 && r.json?.kopiert === 0 && r.json?.tilvalg === 1, r);
const b = (await linjer(Bo.id)).linjer[0];
const fyll = (b.struktur ?? []).filter(f => f.label === 'Velg fyll');
sjekk('ett «Velg fyll» i strukturen', fyll.length === 1, b.struktur);
const fasitB = await get('/amendo-test/v1/v1436-kurv', { produkt: ider.fyllkake, variant: ider.stor, attributter: { attribute_storrelse: 'Stor' }, post: { 'fyll-stor-1436': 'sitron' } });
sjekk('feltet for Stor, som i kurven (pris 100)', JSON.stringify(sammenlignbar(b.struktur)) === JSON.stringify(sammenlignbar(fasitB.felter)), { vår: sammenlignbar(b.struktur), deres: sammenlignbar(fasitB.felter) });
sjekk('ingen nye rader', ingenUnderstrek(b).filter(r => !/^(pa_|storrelse)/.test(r.key)).length === 1, b.rader);

// ── Ordre C: filen er borte ─────────────────────────────────────────────────
console.log('\nOrdre C: temp-filen er slettet');
const oppC = await lastOpp(ider.kake, 'kakebilde-c1436.png');
const tempC = oppC?.file?.url ?? '';
await fil(tempC, true);
const C = await get('/amendo-test/v1/v1436-ordre', { linjer: [{ product_id: ider.kake, quantity: 1, meta_data: [{ key: 'Last opp ditt bilde!', value: `kakebilde-c1436.png | ${tempC}` }] }] });
r = await kakebilder(C.id);
sjekk('200 { kopiert: 0, mangler: [temp-url] }', r.status === 200 && r.json?.kopiert === 0 && JSON.stringify(r.json?.mangler) === JSON.stringify([tempC]), r);
let c = await linjer(C.id);
sjekk('linjen står urørt', rad(c.linjer[0], 'Last opp ditt bilde!')?.value === `kakebilde-c1436.png | ${tempC}`, c.linjer[0].rader);
sjekk('ett notat: «… fantes ikke lenger — kontakt kunden.»', c.notater.length === 1 && /Bursdagskake/.test(c.notater[0]) && /kontakt kunden/.test(c.notater[0]), c.notater);
r = await kakebilder(C.id);
c = await linjer(C.id);
sjekk('kall nr. 2: fortsatt bare ett notat', c.notater.length === 1 && JSON.stringify(r.json?.mangler) === JSON.stringify([tempC]), { r, notater: c.notater });

// ── Ordre D: bestill på nytt (filen ligger alt i kakebilder/) ───────────────
console.log('\nOrdre D: bestill på nytt');
const D = await get('/amendo-test/v1/v1436-ordre', { linjer: [{ product_id: ider.kake, quantity: 1, meta_data: [{ key: 'Last opp ditt bilde!', value: `kakebilde-a1436.png | ${nyUrl}` }, { key: 'Velg kakefyll', value: 'Vanilje' }] }] });
const filerFør = (await fil(nyUrl)).filer_i_mappen;
r = await kakebilder(D.id);
sjekk('200 { kopiert: 0, mangler: [], tilvalg: 1 }', r.status === 200 && r.json?.kopiert === 0 && r.json?.mangler?.length === 0 && r.json?.tilvalg === 1, r);
const d = (await linjer(D.id)).linjer[0];
sjekk('URL-en i kakebilder/ står', rad(d, 'Last opp ditt bilde!')?.value === `kakebilde-a1436.png | ${nyUrl}`, d.rader);
sjekk('ingen ny kopi', (await fil(nyUrl)).filer_i_mappen === filerFør);
sjekk('filfeltet peker på den gamle kopien', (d.struktur ?? []).find(f => f.elementId === 'bilde-1436')?.value?.[0]?.url === nyUrl, d.struktur);

// ── Ordre E: URL-er som ikke er våre ────────────────────────────────────────
console.log('\nOrdre E: andre verter og ../');
const base = tempUrl.replace(/wcpa_temp\/.*$/, '');
const utenfor = `${base}../../../wp-config.php`;
const kodet = `${base}wcpa_temp/%2e%2e/%2e%2e/%2e%2e/wp-config.php`;
const annen = 'https://example.com/wp-content/uploads/wcpa_uploads/wcpa_temp/x/y.png';
const E = await get('/amendo-test/v1/v1436-ordre', { linjer: [{ product_id: ider.kake, quantity: 1, meta_data: [
  { key: 'Fil 1', value: `a | ${utenfor}` }, { key: 'Fil 2', value: `b | ${kodet}` }, { key: 'Fil 3', value: `c | ${annen}` },
] }] });
r = await kakebilder(E.id);
sjekk('ingenting kopiert', r.status === 200 && r.json?.kopiert === 0, r);
sjekk('../ (også %2e%2e) er ikke en fil vi kopierer: i mangler', r.json?.mangler?.includes(utenfor) && r.json?.mangler?.includes(kodet), r.json);
sjekk('annen vert: ikke vår, ikke i mangler', !r.json?.mangler?.includes(annen), r.json);
const e = (await linjer(E.id)).linjer[0];
sjekk('radene står urørt', JSON.stringify(e.rader.filter(x => x.key.startsWith('Fil')).map(x => x.value)) === JSON.stringify([`a | ${utenfor}`, `b | ${kodet}`, `c | ${annen}`]), e.rader);
sjekk('wp-config.php ble ikke kopiert', !(await fil(`${URL_}/wp-content/uploads/kakebilder/${aar}/${E.id}-wp-config.php`)).finnes);

// ── Andre filtyper (logo som PDF eller SVG) ─────────────────────────────────
console.log('\nPDF og SVG');
const lastOppType = async (navn, innhold, type) => {
  const fd = new FormData();
  fd.append('wcpa_file', new Blob([innhold], { type }), navn);
  const res = await fetch(B + `/wcpa/front/upload/${ider.kake}/bilde-1436`, { method: 'POST', body: fd });
  return res.json().catch(() => null);
};
const PDF = '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n';
const SVG = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="#c00"/></svg>';
const oppPdf = await lastOppType('logo-1436.pdf', PDF, 'application/pdf');
sjekk('WCPA tok imot PDF-en', oppPdf?.status === true, oppPdf);
await get('/amendo-test/v1/v1436-wcpa-filtyper', { typer: ['svg'] });
const oppSvg = await lastOppType('logo-1436.svg', SVG, 'image/svg+xml');
sjekk('WCPA tok imot SVG-en (svg slått på i WCPA)', oppSvg?.status === true, oppSvg);
const php = await get('/amendo-test/v1/v1436-temp', { navn: 'lur-1436.php', innhold: '<?php echo 1;' });
const G = await get('/amendo-test/v1/v1436-ordre', { linjer: [{ product_id: ider.kake, quantity: 1, meta_data: [
  { key: 'Logo PDF', value: `logo-1436.pdf | ${oppPdf?.file?.url}` },
  { key: 'Logo SVG', value: `logo-1436.svg | ${oppSvg?.file?.url}` },
  { key: 'Fil PHP', value: `lur-1436.php | ${php.url}` },
] }] });
r = await kakebilder(G.id);
sjekk('PDF og SVG kopiert, PHP ikke (i mangler)', r.status === 200 && r.json?.kopiert === 2 && JSON.stringify(r.json?.mangler) === JSON.stringify([php.url]), r);
const g = (await linjer(G.id)).linjer[0];
const pdfUrl = (rad(g, 'Logo PDF')?.value ?? '').split(' | ')[1] ?? '';
const svgUrl = (rad(g, 'Logo SVG')?.value ?? '').split(' | ')[1] ?? '';
sjekk('PDF-en under kakebilder/', pdfUrl.endsWith(`/kakebilder/${aar}/${G.id}-logo-1436.pdf`) && (await fil(pdfUrl)).finnes === true, g.rader);
sjekk('SVG-en under kakebilder/', svgUrl.endsWith(`/kakebilder/${aar}/${G.id}-logo-1436.svg`) && (await fil(svgUrl)).finnes === true, g.rader);
sjekk('PHP-filen ble ikke kopiert', !(await fil(`${URL_}/wp-content/uploads/kakebilder/${aar}/${G.id}-lur-1436.php`)).finnes && rad(g, 'Fil PHP')?.value === `lur-1436.php | ${php.url}`, g.rader);
await get('/amendo-test/v1/v1436-wcpa-filtyper', { typer: [] });

// ── Kilden ──────────────────────────────────────────────────────────────────
console.log('\nKilden');
const kilde = readFileSync(`${REPO}/includes/tilvalg.php`, 'utf8');
const kode = kilde.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
sjekk('ingen butikknavn i tilvalg.php', !/Aanerud|Garçon|Garcon|emmk|Jessheim|Dorothea/i.test(kilde));
sjekk('ingen logging fra ordren', !/error_log|wc_get_logger|amendo_logg/.test(kode));
sjekk('aldri `new Order()` fra WCPA (dobbel visning)', !/new\s+\\?Acowebs\\WCPA\\Order\s*\(/.test(kode));
sjekk('ingen henting over HTTP', !/wp_remote_|file_get_contents\(\s*\$f\['url'\]|download_url/.test(kode));

// ── Uten WCPA ───────────────────────────────────────────────────────────────
console.log('\nUten WCPA');
const oppF = await lastOpp(ider.kake, 'kakebilde-f1436.png');
await get('/amendo-test/v1/v1436-wcpa&pa=0');
w = await get('/amendo-test/v1/v1436-wcpa');
sjekk('WCPA er av', w.lastet === null, w);
const F = await get('/amendo-test/v1/v1436-ordre', { linjer: [{ product_id: ider.kake, quantity: 1, meta_data: [
  { key: 'Last opp ditt bilde!', value: `kakebilde-f1436.png | ${oppF?.file?.url}` }, { key: 'Velg kakefyll', value: 'Sjokolade' },
] }] });
r = await kakebilder(F.id);
sjekk('filen kopieres fortsatt, ingen struktur', r.status === 200 && r.json?.kopiert === 1 && r.json?.tilvalg === 0, r);
const f = (await linjer(F.id)).linjer[0];
sjekk('ingen _WCPA_order_meta_data', f.struktur === null, f.struktur);
sjekk('verdiene som frontenden skrev dem', rad(f, 'Velg kakefyll')?.value === 'Sjokolade', f.rader);

ferdig();
