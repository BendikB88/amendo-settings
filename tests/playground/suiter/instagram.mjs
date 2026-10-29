// 1.4.25: Instagram-feeden på forsiden, GET /instagram. Ingen ekte kall:
// Instagram/Facebook Graph besvares i mu/v1425-test.php, og Smash Balloon er
// stubben i mu/v1425-sbi-stub.php. Se includes/instagram.php.
import { B, get, sjekk, ferdig } from '../lib/felles.mjs';

const sett = o => get('/amendo-test/v1/v1425-sett', o);
const tilstand = () => get('/amendo-test/v1/v1425-tilstand');
const lagre = (post, rolle = 'admin') => get('/amendo-test/v1/v1425-lagre', { post, rolle });
const sbi = kontoer => get('/amendo-test/v1/v1425-sbi', { kontoer });
const side = async (rolle = 'admin') => (await fetch(B + '/amendo-test/v1/v1425-side&rolle=' + rolle)).text();
const feed = async (antall) => {
  const r = await fetch(B + '/amendo-settings/v1/instagram' + (antall === undefined ? '' : '&antall=' + encodeURIComponent(antall)));
  const tekst = await r.text();
  let json = null; try { json = JSON.parse(tekst); } catch {}
  return { status: r.status, json, tekst };
};
const naa = () => Math.floor(Date.now() / 1000);
const DAG = 86400;
const utcDato = t => { const d = new Date(t * 1000); return `${d.getUTCDate()}.${d.getUTCMonth() + 1}.${d.getUTCFullYear()}`; };
const omkring = (t, sek) => [utcDato(t - sek), utcDato(t), utcDato(t + sek)];
const sbTid = t => new Date(t * 1000).toISOString().slice(0, 19).replace('T', ' ');

const EGET = 'IGAAegettoken' + 'x'.repeat(40);
const FORNYET = 'IGAAfornyettoken' + 'y'.repeat(40);
const SB_TOKEN = 'IGAAsmashballoon' + 'z'.repeat(40);
const FB_TOKEN = 'EAAsidetoken' + 'w'.repeat(40);

const bilde = n => `https://scontent.cdninstagram.com/v/${n}.jpg?x=1`;
const MEDIA = [
  { id: '1', media_type: 'IMAGE', media_url: bilde(1), permalink: 'https://www.instagram.com/p/A1/', caption: 'Første 🍰', timestamp: '2026-10-27T10:00:00+0000' },
  { id: '2', media_type: 'VIDEO', media_url: 'https://video.cdninstagram.com/v/2.mp4', thumbnail_url: bilde('2-mini'), permalink: 'https://www.instagram.com/reel/A2/', caption: 'Video', timestamp: '2026-10-26T10:00:00+0000' },
  { id: '3', media_type: 'CAROUSEL_ALBUM', media_url: bilde(3), permalink: 'https://www.instagram.com/p/A3/', caption: 'Karusell', timestamp: '2026-10-25T10:00:00+0000', children: { data: [{ media_type: 'IMAGE', media_url: bilde(3) }, { media_type: 'VIDEO', media_url: 'https://video.cdninstagram.com/v/3b.mp4', thumbnail_url: bilde('3b') }] } },
  { id: '4', media_type: 'CAROUSEL_ALBUM', media_url: 'https://video.cdninstagram.com/v/4.mp4', permalink: 'https://www.instagram.com/p/A4/', caption: 'Karusell med video først', timestamp: '2026-10-24T10:00:00+0000', children: { data: [{ media_type: 'VIDEO', media_url: 'https://video.cdninstagram.com/v/4.mp4', thumbnail_url: bilde('4-mini') }] } },
  { id: '5', media_type: 'VIDEO', media_url: 'https://video.cdninstagram.com/v/5.mp4', permalink: 'https://www.instagram.com/reel/A5/', caption: 'Uten miniatyr', timestamp: '2026-10-23T10:00:00+0000' },
  { id: '6', media_type: 'IMAGE', media_url: 'http://scontent.cdninstagram.com/v/6.jpg', permalink: 'https://www.instagram.com/p/A6/', caption: 'http', timestamp: '2026-10-22T10:00:00+0000' },
  { id: '7', media_type: 'IMAGE', media_url: bilde(7), permalink: 'https://www.instagram.com/p/A7/', timestamp: '2026-10-21T10:00:00+0000' },
  ...[8, 9, 10, 11, 12].map(n => ({ id: String(n), media_type: 'IMAGE', media_url: bilde(n), permalink: `https://www.instagram.com/p/A${n}/`, caption: 'Nr ' + n, timestamp: `2026-10-${28 - n}T10:00:00+0000` })),
];
const IG = { media: { kode: 200, svar: { data: MEDIA, paging: {} } }, forny: { kode: 200, svar: { access_token: FORNYET, token_type: 'bearer', expires_in: 60 * DAG } } };
const lekker = (tekst, ...tokens) => tokens.filter(t => tekst.includes(t));

await sett({ amendo_test_stille: '1' }); // ingen cron ved siden av testene, se mu/v1423-test.php
await get('/amendo-test/v1/setup');
await sett({
  amendo_test_sbi: null, amendo_forside: null, amendo_sosiale_instagram: null,
  amendo_instagram_token: null, amendo_instagram_utloper: null, amendo_instagram_fornyet: null,
  amendo_instagram_status: null, amendo_instagram_siste: null,
  amendo_test_ig: IG, amendo_test_ig_kall: null, cache_tom: true,
});

// ── Uten konto og uten tilkobling ───────────────────────────────────────────
console.log('Uten konto og uten tilkobling');
let r = await feed();
sjekk('ingen konto: 200 { brukernavn: null, innlegg: [], oppdatert: null, reserve: false }', r.status === 200 && JSON.stringify(r.json) === '{"brukernavn":null,"innlegg":[],"oppdatert":null,"reserve":false}', r.tekst);
await sett({ amendo_sosiale_instagram: 'https://www.instagram.com/garconoslo/', cache_tom: true });
r = await feed();
sjekk('konto fra sosiale medier («https://www.instagram.com/garconoslo/» → garconoslo), ingen tilkobling: tom liste', r.status === 200 && r.json?.brukernavn === 'garconoslo' && r.json.innlegg.length === 0 && r.json.reserve === false, r.json);
await sett({ amendo_forside: { ig_handle: '@garcon_kaker' }, cache_tom: true });
r = await feed();
sjekk('ig_handle på forsiden går foran sosiale medier', r.json?.brukernavn === 'garcon_kaker', r.json);
await sett({ amendo_forside: null, cache_tom: true });
let s = await tilstand();
sjekk('… og ingen kall til Instagram uten tilkobling', s.kall.length === 0, s.kall);

// ── Eget token ──────────────────────────────────────────────────────────────
console.log('\nToken lagt inn i Amendo-admin');
let l = await lagre({ instagram_finnes: '1', instagram_token: '  ' + EGET + ' ' });
sjekk('lagret (mellomrom fjernet), utløpsdato ukjent, «fornyet» = nå', l.token === EGET && l.utloper === null && Math.abs(Number(l.fornyet) - naa()) < 120, l);
s = await tilstand();
sjekk('… ikke autoload', ['no', 'off'].includes(s.autoload), s.autoload);
r = await feed();
s = await tilstand();
sjekk('200 med brukernavn, oppdatert og reserve: false', r.status === 200 && r.json?.brukernavn === 'garconoslo' && /^\d{4}-\d{2}-\d{2}T/.test(r.json.oppdatert || '') && r.json.reserve === false, r.json);
sjekk('de siste 9 innleggene', r.json?.innlegg?.length === 9, r.json?.innlegg?.length);
sjekk('hvert innlegg: nøyaktig { bilde, lenke, tekst, dato, type }', r.json?.innlegg?.every(i => JSON.stringify(Object.keys(i)) === '["bilde","lenke","tekst","dato","type"]'));
const inn = r.json?.innlegg || [];
sjekk('bilde: media_url, lenke, tekst og dato (UTC)', JSON.stringify(inn[0]) === JSON.stringify({ bilde: bilde(1), lenke: 'https://www.instagram.com/p/A1/', tekst: 'Første 🍰', dato: '2026-10-27T10:00:00+00:00', type: 'bilde' }), inn[0]);
sjekk('video: miniatyrbildet, ikke .mp4', inn[1]?.type === 'video' && inn[1]?.bilde === bilde('2-mini'), inn[1]);
sjekk('karusell: første bilde', inn[2]?.type === 'karusell' && inn[2]?.bilde === bilde(3), inn[2]);
sjekk('karusell med video først: miniatyrbildet til videoen', inn[3]?.type === 'karusell' && inn[3]?.bilde === bilde('4-mini'), inn[3]);
sjekk('video uten miniatyrbilde og bilde over http hoppes over', !inn.some(i => /\/(5|6)\.jpg|\.mp4/.test(i.bilde)) && inn[4]?.bilde === bilde(7), inn.map(i => i.bilde));
sjekk('uten bildetekst: tekst ""', inn[4]?.tekst === '', inn[4]);
sjekk('9 av 10 gyldige: det tiende (nr 12) er ikke med', inn[8]?.bilde === bilde(11), inn[8]);
sjekk('ett kall: graph.instagram.com/me/media med tokenet, limit 33 (30 + margin, 1.4.26)', s.kall.length === 1 && s.kall[0].vert === 'graph.instagram.com' && s.kall[0].sti === '/me/media' && s.kall[0].token === EGET && s.kall[0].limit === '33', s.kall);
sjekk('ingen fornying av et token lagt inn for under et døgn siden', !s.kall.some(k => k.sti === '/refresh_access_token'));
sjekk('tokenet er ikke i svaret', lekker(r.tekst, EGET).length === 0);
sjekk('mellomlagret i 1 time, alle de 10 gyldige (inntil 30)', s.cache?.innlegg?.length === 10 && s.cache_tid > 3500 && s.cache_tid <= 3600, [s.cache?.innlegg?.length, s.cache_tid]);
sjekk('siste gode svar lagret', s.siste?.innlegg?.length === 10 && s.siste.brukernavn === 'garconoslo');
sjekk('status: tid og HTTP-kode, ikke tokenet', s.status?.kode === 200 && s.status.kilde === 'amendo' && lekker(JSON.stringify(s.status), EGET).length === 0, s.status);
r = await feed();
s = await tilstand();
sjekk('neste forespørsel: fra mellomlageret, ingen nye kall', r.json?.innlegg?.length === 9 && s.kall.length === 1, s.kall.length);

// ── ?antall (1.4.26) ────────────────────────────────────────────────────────
console.log('\n?antall=1–30');
const STOR = Array.from({ length: 35 }, (_, i) => ({ id: 's' + (i + 1), media_type: 'IMAGE', media_url: bilde('s' + (i + 1)), permalink: `https://www.instagram.com/p/S${i + 1}/`, caption: 'S' + (i + 1), timestamp: '2026-10-01T10:00:00+0000' }));
await sett({ amendo_test_ig: { ...IG, media: { kode: 200, svar: { data: STOR } } }, amendo_test_ig_kall: null, cache_tom: true });
r = await feed();
sjekk('uten antall: 9, som før', r.status === 200 && r.json?.innlegg?.length === 9 && r.json.innlegg[8].bilde === bilde('s9'), r.json?.innlegg?.length);
sjekk('… svaret har de samme fire feltene', JSON.stringify(Object.keys(r.json || {})) === '["brukernavn","innlegg","oppdatert","reserve"]', Object.keys(r.json || {}));
s = await tilstand();
sjekk('30 hentet og mellomlagret i ett kall (limit 33)', s.kall.length === 1 && s.kall[0].limit === '33' && s.cache?.innlegg?.length === 30 && s.cache.innlegg[29].bilde === bilde('s30'), [s.kall.length, s.cache?.innlegg?.length]);
const antall = {};
for (const a of ['30', '1', '15', '0', '-5', '31', '100', 'abc', '7.5', '', ' 12 ']) antall[a] = (await feed(a)).json?.innlegg?.length;
sjekk('antall=30: 30, i rekkefølge', antall['30'] === 30);
sjekk('antall=1 og 15', antall['1'] === 1 && antall['15'] === 15, antall);
sjekk('under 1 (0, -5): 1', antall['0'] === 1 && antall['-5'] === 1, antall);
sjekk('over 30 (31, 100): 30', antall['31'] === 30 && antall['100'] === 30, antall);
sjekk('tekst, desimaltall og tomt: 9', antall.abc === 9 && antall['7.5'] === 9 && antall[''] === 9, antall);
sjekk('mellomrom rundt tallet: godtas (« 12 » = 12)', antall[' 12 '] === 12, antall);
r = await feed('5');
sjekk('de første 5 er de samme som de første 5 av 30', JSON.stringify(r.json?.innlegg?.map(i => i.bilde)) === JSON.stringify(STOR.slice(0, 5).map(m => m.media_url)));
s = await tilstand();
sjekk('alle antallene fra samme mellomlager: fortsatt ett kall', s.kall.length === 1, s.kall.length);
await sett({ amendo_test_ig: { ...IG, media: { kode: 500, svar: {} } }, cache_tom: true });
r = await feed('20');
sjekk('Instagram feiler: forrige gode svar, kuttet til antall=20', r.json?.reserve === true && r.json.innlegg.length === 20, [r.json?.reserve, r.json?.innlegg?.length]);
r = await feed();
sjekk('… og 9 uten antall', r.json?.reserve === true && r.json.innlegg.length === 9);
await sett({ amendo_test_ig: IG, amendo_test_ig_kall: null, cache_tom: true });
r = await feed('30');
sjekk('færre enn antall hos Instagram: alle de gyldige (10)', r.json?.reserve === false && r.json.innlegg.length === 10, r.json?.innlegg?.length);
await sett({ amendo_test_ig_kall: null, cache_tom: true });

// ── Fornying av eget token ──────────────────────────────────────────────────
console.log('\nFornying og ny utløpsdato');
await sett({ amendo_instagram_fornyet: String(naa() - 2 * DAG), amendo_test_ig_kall: null, cache_tom: true });
r = await feed();
s = await tilstand();
sjekk('over et døgn gammelt, ukjent utløp: refresh_access_token med det gamle tokenet', s.kall[0]?.sti === '/refresh_access_token' && s.kall[0]?.token === EGET, s.kall);
sjekk('… nytt token lagret og brukt i samme forespørsel', s.token === FORNYET && s.kall[1]?.sti === '/me/media' && s.kall[1]?.token === FORNYET, [s.token === FORNYET, s.kall.map(k => k.sti)]);
sjekk('… ny utløpsdato = nå + expires_in (60 dager)', Math.abs(Number(s.utloper) - (naa() + 60 * DAG)) < 300, s.utloper);
sjekk('… «fornyet» satt til nå', Math.abs(Number(s.fornyet) - naa()) < 300, s.fornyet);
sjekk('… svaret har innleggene, uten noe av tokenene', r.json?.innlegg?.length === 9 && lekker(r.tekst, EGET, FORNYET).length === 0);

await sett({ amendo_instagram_fornyet: String(naa() - 2 * DAG), amendo_instagram_utloper: String(naa() + 55 * DAG), amendo_test_ig_kall: null, cache_tom: true });
await feed();
s = await tilstand();
sjekk('55 dager igjen: fornyes ikke', !s.kall.some(k => k.sti === '/refresh_access_token') && s.kall.length === 1, s.kall);

await sett({ amendo_instagram_fornyet: String(naa() - 3600), amendo_instagram_utloper: String(naa() + 5 * DAG), amendo_test_ig_kall: null, cache_tom: true });
await feed();
s = await tilstand();
sjekk('fornyet for under et døgn siden: ikke igjen, selv med 5 dager igjen', !s.kall.some(k => k.sti === '/refresh_access_token'), s.kall);

await sett({ amendo_instagram_fornyet: String(naa() - 2 * DAG), amendo_instagram_utloper: String(naa() + 10 * DAG), amendo_test_ig: { ...IG, forny: { kode: 400, svar: { error: { message: 'Invalid' } } } }, amendo_test_ig_kall: null, cache_tom: true });
r = await feed();
s = await tilstand();
sjekk('fornying feiler: det gamle tokenet står, og feeden hentes likevel', s.token === FORNYET && s.kall.map(k => k.sti).join() === '/refresh_access_token,/me/media' && s.kall[1].token === FORNYET && r.json?.innlegg?.length === 9, [s.token === FORNYET, s.kall.map(k => k.sti)]);
sjekk('… utløpsdatoen er urørt', Math.abs(Number(s.utloper) - (naa() + 10 * DAG)) < 300, s.utloper);
// Videre: nylig fornyet og 55 dager igjen, så ingen fornying forstyrrer.
await sett({ amendo_test_ig: IG, amendo_instagram_fornyet: String(naa()), amendo_instagram_utloper: String(naa() + 55 * DAG), amendo_test_ig_kall: null, cache_tom: true });

// ── Instagram feiler ────────────────────────────────────────────────────────
console.log('\nInstagram feiler: forrige gode svar');
await sett({ amendo_test_ig: { ...IG, media: { kode: 500, svar: { error: { message: 'Feil', code: 2 } } } }, amendo_test_ig_kall: null, cache_tom: true });
r = await feed();
s = await tilstand();
sjekk('HTTP 500: forrige gode svar med reserve: true', r.status === 200 && r.json?.reserve === true && r.json.innlegg.length === 9 && r.json.brukernavn === 'garconoslo' && r.json.innlegg[0].bilde === bilde(1), r.json);
sjekk('… «oppdatert» er tiden det gode svaret ble hentet', r.json?.oppdatert === s.siste?.oppdatert);
sjekk('… status med feiltid og HTTP 500, ikke tokenet', s.status?.kode === 500 && s.status.feil > 0 && s.status.ok > 0 && lekker(JSON.stringify(s.status), FORNYET).length === 0, s.status);
sjekk('… feilen mellomlagres ikke som et godt svar', s.cache === false && s.siste?.innlegg?.length === 10);
r = await feed();
s = await tilstand();
sjekk('pause i 5 minutter: neste forespørsel går ikke til Instagram', r.json?.reserve === true && s.kall.length === 1, s.kall.length);
const feilside = await side();
sjekk('admin: «Sist feilet» med HTTP-koden', /Sist feilet: [^<]*\(HTTP 500\)/.test(feilside), feilside.match(/Sist (hentet|feilet):[^<]*/g));
await sett({ amendo_test_ig: { ...IG, nede: true }, amendo_test_ig_kall: null, cache_tom: true });
r = await feed();
sjekk('Instagram nede (tidsavbrudd): forrige gode svar', r.json?.reserve === true && r.json.innlegg.length === 9, r.json);
await sett({ amendo_test_ig: { ...IG, media: { kode: 200, svar: { error: 'ingen data' } } }, cache_tom: true });
r = await feed();
sjekk('200 uten data-liste: også feil, forrige gode svar', r.json?.reserve === true && r.json.innlegg.length === 9, r.json);
await sett({ amendo_test_ig: { ...IG, media: { kode: 500, svar: {} } }, amendo_sosiale_instagram: 'annenkonto', cache_tom: true });
r = await feed();
sjekk('annen konto uten tidligere svar: tom liste, reserve: true (aldri den forrige kontoens innlegg)', JSON.stringify(r.json) === '{"brukernavn":"annenkonto","innlegg":[],"oppdatert":null,"reserve":true}', r.json);
await sett({ amendo_test_ig: IG, amendo_sosiale_instagram: 'garconoslo', amendo_test_ig_kall: null, cache_tom: true });
r = await feed();
sjekk('Instagram tilbake: ferskt svar, reserve: false', r.json?.reserve === false && r.json.innlegg.length === 9, r.json?.reserve);
let html = await side();
sjekk('admin viser når feeden sist ble hentet', /Sist hentet: \d/.test(html) && html.includes('id="amendo-instagram"'), html.match(/Sist hentet:[^<]*/)?.[0]);

// ── Admin ───────────────────────────────────────────────────────────────────
console.log('\nAdmin');
html = await side();
sjekk('kortet: konto, «token lagt inn her», utløpsdatoen', html.includes('@garconoslo') && html.includes('token lagt inn her') && omkring(naa() + 55 * DAG, 3600).some(d => html.includes('Tokenet utløper: <strong>' + d)), html.match(/Tokenet utløper:[^<]*<strong>[^<]*/)?.[0]);
sjekk('tomt passordfelt og «Fjern tokenet»', /<input type="password" id="instagram_token" name="instagram_token" value=""/.test(html) && html.includes('name="instagram_token_fjern"') && html.includes('name="instagram_finnes"'));
sjekk('tokenet står ingen steder i siden', lekker(html, EGET, FORNYET).length === 0);
const sm = await side('shop_manager');
sjekk('butikkadministrator: ser status, men ikke feltet', sm.includes('id="amendo-instagram"') && !sm.includes('name="instagram_token"') && sm.includes('Bare administrator kan legge inn eller bytte token') && lekker(sm, FORNYET).length === 0);

l = await lagre({ instagram_finnes: '1', instagram_token: 'IGAAbutikkadmin' + 'q'.repeat(40) }, 'shop_manager');
sjekk('butikkadministrator kan ikke bytte tokenet', l.token === FORNYET, l.token === FORNYET);
l = await lagre({ instagram_finnes: '1', instagram_token_fjern: '1' }, 'shop_manager');
sjekk('… eller fjerne det', l.token === FORNYET);
l = await lagre({ butikk_navn: 'Test', instagram_token: 'IGAAutenmarkor' + 'q'.repeat(40) });
sjekk('lagring uten markøren: urørt', l.token === FORNYET);
l = await lagre({ instagram_finnes: '1', instagram_token: '' });
sjekk('tomt felt: urørt, og mellomlageret står', l.token === FORNYET && l.cache?.innlegg?.length === 10);
l = await lagre({ instagram_finnes: '1', instagram_token: 'for kort' });
sjekk('ugyldig token (for kort, mellomrom inni er fjernet): urørt', l.token === FORNYET);
l = await lagre({ instagram_finnes: '1', instagram_token: EGET });
sjekk('nytt token: lagret, utløp nullstilt, mellomlageret tømt', l.token === EGET && l.utloper === null && l.cache === false, l);
l = await lagre({ instagram_finnes: '1', instagram_token_fjern: '1' });
sjekk('«Fjern tokenet»: token, utløp og fornyet slettet', l.token === null && l.utloper === null && l.fornyet === null, l);
await sett({ amendo_test_ig_kall: null });
r = await feed();
s = await tilstand();
sjekk('uten token: tom liste, ingen kall', r.json?.innlegg?.length === 0 && s.kall.length === 0, [r.json, s.kall.length]);

// ── Smash Balloon ───────────────────────────────────────────────────────────
console.log('\nInstagram Feed (Smash Balloon)');
await sett({ amendo_test_sbi: '1', amendo_test_sbi_fornyet: null });
let sb = await sbi([{ account_id: '17841400000000001', account_type: 'personal', token: SB_TOKEN, username: 'GarconOslo', expires: sbTid(naa() + 55 * DAG) }]);
sjekk('stubben: tokenet ligger kryptert i sbi_sources', sb.rader === 1 && sb.kryptert[0] !== SB_TOKEN && !sb.kryptert[0].includes('IGAA'), sb);
await sett({ amendo_instagram_token: EGET, amendo_instagram_fornyet: String(naa()), amendo_test_ig_kall: null, cache_tom: true });
r = await feed();
s = await tilstand();
sjekk('personal-konto fra Smash Balloon (brukernavn uten store/små): graph.instagram.com/{konto}/media med deres token', s.kall.length === 1 && s.kall[0].vert === 'graph.instagram.com' && s.kall[0].sti === '/17841400000000001/media' && s.kall[0].token === SB_TOKEN, s.kall);
sjekk('… foran tokenet i Amendo-admin', !s.kall.some(k => k.token === EGET) && r.json?.innlegg?.length === 9);
sjekk('… 55 dager igjen: Smash Balloon fornyer ikke', s.sb_fornyet === 0);
sjekk('… ingen token i svaret', lekker(r.tekst, SB_TOKEN, EGET).length === 0);
html = await side();
sjekk('admin: «Instagram Feed (Smash Balloon)» og deres utløpsdato, ingen token', html.includes('Instagram Feed (Smash Balloon)') && omkring(naa() + 55 * DAG, 3600).some(d => html.includes('Tokenet utløper: <strong>' + d)) && lekker(html, SB_TOKEN, EGET, sb.kryptert[0]).length === 0);

await sbi([{ account_id: '17841400000000001', account_type: 'basic', token: SB_TOKEN, username: 'garconoslo', expires: sbTid(naa() + 10 * DAG) }]);
await sett({ amendo_test_ig_kall: null, cache_tom: true });
r = await feed();
s = await tilstand();
sjekk('basic-konto, 10 dager igjen: Smash Balloons egen fornyer kalles (refresh_access_token med deres token)', s.sb_fornyet === 1 && s.kall[0]?.sti === '/refresh_access_token' && s.kall[0]?.token === SB_TOKEN, [s.sb_fornyet, s.kall.map(k => k.sti)]);
sjekk('… nytt token og ny utløpsdato i deres tabell, og det nye tokenet brukes med en gang', s.sb['17841400000000001']?.token === FORNYET && Math.abs(Date.parse(s.sb['17841400000000001'].expires.replace(' ', 'T') + 'Z') / 1000 - (naa() + 60 * DAG)) < 300 && s.kall[1]?.token === FORNYET, [s.sb, s.kall.map(k => k.token === FORNYET)]);
sjekk('… vi skriver ikke vårt eget token', s.token === EGET);
await sett({ amendo_test_ig_kall: null, cache_tom: true });
await feed();
s = await tilstand();
sjekk('… og ikke igjen i neste forespørsel (nå 60 dager igjen)', s.sb_fornyet === 1, s.sb_fornyet);

await sbi([{ account_id: '17841400000000002', account_type: 'business', token: FB_TOKEN, username: 'garconoslo', expires: '2037-12-30 00:00:00', connect_type: 'business' }]);
await sett({ amendo_test_ig_kall: null, cache_tom: true });
r = await feed();
s = await tilstand();
sjekk('business-konto: graph.facebook.com/{konto}/media, ingen fornying', s.kall.length === 1 && s.kall[0].vert === 'graph.facebook.com' && s.kall[0].sti === '/17841400000000002/media' && s.kall[0].token === FB_TOKEN && r.json?.innlegg?.length === 9, s.kall);
html = await side();
sjekk('… admin: «utløper ikke»', /Tokenet utløper: <strong>utløper ikke/.test(html));

await sbi([{ account_id: '17841400000000003', account_type: 'basic', token: SB_TOKEN, username: 'enannenkonto', expires: sbTid(naa() + 55 * DAG) }]);
await sett({ amendo_test_ig_kall: null, cache_tom: true });
r = await feed();
s = await tilstand();
sjekk('Smash Balloon har ikke kontoen: tokenet i Amendo-admin brukes', s.kall.length === 1 && s.kall[0].sti === '/me/media' && s.kall[0].token === EGET, s.kall);

await sett({ amendo_test_sbi: null, amendo_sosiale_instagram: null, amendo_instagram_token: null, amendo_instagram_utloper: null, amendo_instagram_fornyet: null, amendo_instagram_status: null, amendo_instagram_siste: null, amendo_test_ig: null, amendo_test_ig_kall: null, cache_tom: true });
ferdig();
