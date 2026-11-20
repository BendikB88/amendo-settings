// 1.4.46: bryteren «Send passordlenker til fronten» (B2B → Bedrifter) og
// engangsmigreringen i includes/moduler.php (amendo_moduler_migrer_1446()).
//
// Migreringen: databasen settes opp som en butikk på en eldre versjon har den
// (mu/v1446-test.php), og den NESTE forespørselen migrerer — som den første
// forespørselen etter en oppdatering. På når butikken kommer fra 1.4.29 eller
// nyere (oppdateringssjekken eller spor bare 1.4.29+ lager), ellers av (ny
// installasjon, Garçon fra 1.4.22). En verdi som finnes, røres aldri.
//
// Bryteren: av = som vanlig WordPress («Glemt passord» med wp-login.php, ett
// døgn, velkomstlenken til wp-login.php); på = <Frontend-URL>/sett-passord og
// 7 dager (som 1.4.29–1.4.45); uten Frontend-URL gjelder den ikke (1.4.43).
// E-post fanget i mu/v1429-test.php. Se includes/moduler/b2b-tellix-bedrifter.php.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { B, REPO, get, sjekk, ferdig } from '../lib/felles.mjs';

const opt = o => get('/amendo-test/v1/opt', o);
const klargjor = body => get('/amendo-test/v1/v1414-klargjor', body);
const k1429 = body => get('/amendo-test/v1/v1429-klargjor', body);
const m1 = async e => (await get('/amendo-test/v1/v1429-meta', { eposter: [e] }))[e];
const epost = async () => (await get('/amendo-test/v1/v1429-tilstand')).epost || [];
const tb = body => get('/amendo-test/v1/v1429-tilbakestill', body);
const alder = (e, dager) => get('/amendo-test/v1/v1429-nokkel-alder', { epost: e, dager });
const spor = body => get('/amendo-test/v1/v1446-spor', body);
const tilstand = () => get('/amendo-test/v1/v1446-tilstand');
const handling = (post, rolle = 'admin', nonce = true) => get('/amendo-test/v1/v1446-handling', { post, rolle, nonce });
const velkomst = e => get('/amendo-test/v1/v1446-velkomst', { epost: e });
const side = async () => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=admin')).text();
const glemt = async e => {
  const før = (await epost()).length;
  await get('/amendo-test/v1/v1429-glemt', { epost: e });
  const alle = await epost();
  return alle.length > før ? alle[alle.length - 1].html : '';
};
const WP_LENKE = /wp-login\.php\?\S*action=rp/;
const FRONT_LENKE = /https:\/\/front\.example\.no\/sett-passord\?key=([A-Za-z0-9]+)&login=(\S+)/;
const wpNokkel = html => ((html.match(/wp-login\.php\?\S*/) || [''])[0].match(/[?&]key=([A-Za-z0-9]+)/) || [])[1];
const E = 'kunde@pl1446.no';
const DAG = 86400;

await get('/amendo-test/v1/setup');
// Stille: ingen WP-cron ved siden av testene (wp_update_plugins ville skrevet
// oppdateringssjekken på nytt midt i en migreringssak).
await opt({ amendo_test_stille: '1', amendo_frontend_url: 'https://front.example.no', amendo_test_epost_fang: '1' });
await klargjor({ moduler: ['b2b'] });
await k1429({ nullstill: true, brukere: [{ epost: E, passord: 'Gammelt-passord-1' }] });

// ── Migreringen ─────────────────────────────────────────────────────────────
console.log('Migreringen (neste forespørsel etter oppdateringen)');
const migrer = async (oppsett) => { const s = await spor(oppsett); return { ...(await tilstand()), ryddet: s.bryter_finnes === false }; };
const HEX = 'a'.repeat(64);
let t = await migrer({});
sjekk('ny installasjon (ingen spor, ingen oppdateringssjekk): av', t.ryddet && t.bryter === '' && t.modus === 'wp', t);
sjekk('… og versjonen lagres (amendo_settings_versjon)', t.versjon === '1.4.46', t.versjon);
t = await migrer({ oppdatering: '1.4.22', options: { amendo_helsebygget_ansatte: JSON.stringify([{ id: 'a1', navn: 'Linda', pin: HEX, v: 1 }, { id: 'a2', navn: 'Admin', pin: 'b'.repeat(64), v: 1 }]) } });
sjekk('Garçon fra 1.4.22 (oppdateringssjekken sier 1.4.22, PIN-ene med gammel hash): av', t.bryter === '', t);
t = await migrer({ options: { amendo_helsebygget_ansatte: JSON.stringify([{ id: 'a1', navn: 'Linda', pin: HEX, v: 1 }]) } });
sjekk('fra 1.4.22 uten oppdateringssjekk (tømt av opplastingen): av', t.bryter === '', t);
t = await migrer({ oppdatering: '1.4.28' });
sjekk('fra 1.4.28: av', t.bryter === '', t);
t = await migrer({ oppdatering: '1.4.29' });
sjekk('fra 1.4.29 (oppdateringssjekken): på', t.bryter === '1' && t.modus === 'front', t);
t = await migrer({ oppdatering: '1.4.45' });
sjekk('fra 1.4.45 (oppdateringssjekken): på', t.bryter === '1', t);
t = await migrer({ oppdatering: '1.4.46' });
sjekk('oppdateringssjekken sier denne versjonen (ikke hvor den kom fra): teller ikke', t.bryter === '', t);

const SPOR = [
  ['amendo_b2b_epost tom (bryterne i kortet lagret én gang, 1.4.29)', { options: { amendo_b2b_epost: '' } }],
  ['amendo_b2b_velkomst (1.4.29)', { options: { amendo_b2b_velkomst: { emne: 'x' } } }],
  ['amendo_kontaktskjema_epost (1.4.34)', { options: { amendo_kontaktskjema_epost: 'post@example.com' } }],
  ['amendo_henting_ukedagsregler (1.4.40)', { options: { amendo_henting_ukedagsregler: '[]' } }],
  ['amendo_tellix_kopi_versjon 1.4.45', { options: { amendo_tellix_kopi_versjon: '1.4.45' } }],
  ['en PIN lagret på nytt med wp_hash_password (1.4.38)', { options: { amendo_helsebygget_ansatte: JSON.stringify([{ id: 'a1', navn: 'Linda', pin: '$wp$2y$10$abcdefghijklmnopqrstuuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ012', v: 1 }]) } }],
  ['bruker med _amendo_fra_tellix (1.4.29)', { meta: { _amendo_fra_tellix: '1' } }],
  ['bruker med _amendo_bp_onske (1.4.31)', { meta: { _amendo_bp_onske: [{ id: 'x' }] } }],
];
for (const [navn, oppsett] of SPOR) {
  t = await migrer(oppsett);
  sjekk(`spor: ${navn} → på`, t.bryter === '1', t);
}
t = await migrer({ options: { amendo_tellix_kopi_versjon: '1.4.46' } });
sjekk('amendo_tellix_kopi_versjon skrevet av denne versjonen: teller ikke', t.bryter === '', t);
t = await migrer({ oppdatering: '1.4.22', options: { amendo_b2b_velkomst: { emne: 'x' } } });
sjekk('oppdateringssjekken 1.4.22, men spor fra 1.4.29: på (den trygge retningen for en front med siden)', t.bryter === '1', t);
t = await migrer({ bryter: '', options: { amendo_b2b_velkomst: { emne: 'x' } }, oppdatering: '1.4.45' });
sjekk('bryteren finnes (av): røres ikke, selv med spor', t.bryter === '', t);
t = await migrer({ bryter: '1' });
sjekk('bryteren finnes (på): røres ikke uten spor', t.bryter === '1', t);
await klargjor({ moduler: [] });
t = await migrer({ oppdatering: '1.4.30' });
sjekk('B2B av: migreringen kjører likevel (riktig når B2B slås på)', t.bryter === '1' && t.modus === null, t);
t = await migrer({});
sjekk('B2B av, ingen spor: av', t.bryter === '', t);
await klargjor({ moduler: ['b2b'] });
await spor({}); // ny installasjon: av
await opt({ amendo_test_stille: null });

// ── Av: som vanlig WordPress ────────────────────────────────────────────────
console.log('\nAv (Frontend-URL satt): som vanlig WordPress');
t = await tilstand();
sjekk('modus wp, nøklene gjelder ett døgn', t.bryter === '' && t.modus === 'wp' && t.utlop === DAG, t);
let html = await glemt(E);
sjekk('«Glemt passord»: WordPress sin lenke, ikke /sett-passord', WP_LENKE.test(html) && !html.includes('sett-passord'), html.slice(0, 400));
let login = (await m1(E)).login;
let nokkel = wpNokkel(html);
sjekk('nøkkelen er gyldig', (await tb({ login, key: nokkel })).ok === true, nokkel);
await alder(E, 2);
let r = await tb({ login, key: nokkel });
sjekk('to dager gammel: utløpt (WordPress sitt døgn)', r.ok === false && r.feil === 'expired_key', r);
let v = await velkomst(E);
sjekk('velkomstlenken: wp-login.php?action=rp (ikke /sett-passord, ikke Min konto)', /wp-login\.php\?action=rp&key=[A-Za-z0-9]+&login=/.test(v.lenke || '') && !v.lenke.includes('sett-passord') && !v.lenke.includes('lost-password'), v.lenke);
sjekk('… og e-posten sier ett døgn', (v.html || '').includes('Lenken virker i ett døgn'), (v.html || '').slice(-400));
html = await side();
let kort = html.slice(html.indexOf('id="b2b-passordlenker"'), html.indexOf('id="b2b-passordlenker"') + 3000);
sjekk('kortet «Passordlenker» med bryteren av', kort.includes('name="b2b_passordlenker" value="1">') && !kort.includes('value="1" checked') && kort.includes('Send passordlenker til fronten'), kort.slice(0, 600));

// ── Bryteren i admin ────────────────────────────────────────────────────────
console.log('\nBryteren i admin');
r = await handling({ b2b_passordlenker: '1' }, 'customer');
sjekk('kunde-rolle kan ikke lagre', r.stoppet === true && r.bryter === '', r);
r = await handling({ b2b_passordlenker: '1' }, 'admin', false);
sjekk('uten nonce: stoppet', r.stoppet === true && r.bryter === '', r);
r = await handling({ b2b_passordlenker: '1' });
sjekk('lagret: på', r.bryter === '1' && /b2b_passord=lagret/.test(r.til || ''), r);
sjekk('kortet viser den på', (await side()).includes('name="b2b_passordlenker" value="1" checked'));

// ── På: fronten ─────────────────────────────────────────────────────────────
console.log('\nPå: lenkene til fronten');
t = await tilstand();
sjekk('modus front, nøklene gjelder 7 dager', t.modus === 'front' && t.utlop === 7 * DAG, t);
html = await glemt(E);
let f = html.match(FRONT_LENKE) || [];
sjekk('«Glemt passord»: <Frontend-URL>/sett-passord, ikke wp-login.php', !!f[0] && !html.includes('wp-login.php'), html.slice(0, 400));
login = decodeURIComponent(f[2] || '');
await alder(E, 6);
sjekk('6 dager gammel: gyldig', (await tb({ login, key: f[1] })).ok === true);
await alder(E, 8);
r = await tb({ login, key: f[1] });
sjekk('8 dager gammel: utløpt', r.ok === false && r.feil === 'expired_key', r);
v = await velkomst(E);
sjekk('velkomstlenken: /sett-passord, og e-posten sier 7 dager', (v.lenke || '').startsWith('https://front.example.no/sett-passord?key=') && (v.html || '').includes('Lenken virker i 7 dager'), v.lenke);

// ── Av igjen ────────────────────────────────────────────────────────────────
console.log('\nAv igjen');
r = await handling({});
sjekk('uten avkrysning: av', r.bryter === '', r);
html = await glemt(E);
sjekk('«Glemt passord»: WordPress sin lenke igjen, ett døgn', WP_LENKE.test(html) && !html.includes('sett-passord') && (await tilstand()).utlop === DAG, html.slice(0, 300));

// ── Uten Frontend-URL: bryteren gjelder ikke (1.4.43) ───────────────────────
console.log('\nUten Frontend-URL');
await opt({ amendo_frontend_url: '' });
for (const b of ['', '1']) {
  await opt({ amendo_b2b_passordlenker: b });
  t = await tilstand();
  v = await velkomst(E);
  html = await glemt(E);
  sjekk(`bryteren ${b ? 'på' : 'av'}: WooCommerce sin «Velg passord» og 7 dager, «Glemt passord» urørt`, t.modus === 'woo' && t.utlop === 7 * DAG && /lost-password/.test(v.lenke || '') && WP_LENKE.test(html) && !html.includes('sett-passord'), [t, v.lenke]);
}
html = await side();
kort = html.slice(html.indexOf('id="b2b-passordlenker"'), html.indexOf('id="b2b-passordlenker"') + 3000);
sjekk('kortet sier at bryteren ikke gjelder uten Frontend-URL', kort.includes('Frontend-URL er tom, så bryteren gjelder ikke nå'), kort.slice(0, 800));
await opt({ amendo_frontend_url: 'https://front.example.no', amendo_b2b_passordlenker: '1' });

// ── B2B av ──────────────────────────────────────────────────────────────────
console.log('\nB2B av');
await klargjor({ moduler: [] });
t = await tilstand();
html = await glemt(E);
sjekk('bryteren på, men B2B av: WordPress som før (ingen kroker, ingen handling)', t.modus === null && t.utlop === DAG && WP_LENKE.test(html) && !html.includes('sett-passord') && (await handling({ b2b_passordlenker: '' })).finnes === false, t);
await klargjor({ moduler: ['b2b'] });

// Koden, ikke kommentarene (toppkommentaren i moduler.php nevner butikkene).
const kilde = ['includes/moduler.php', 'includes/moduler/b2b-tellix-bedrifter.php'].map(f => readFileSync(resolve(REPO, f), 'utf8')).join('\n')
  .replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
sjekk('ingen butikknavn eller logging av kundedata i koden', !/Haugli|Aanerud|emmk|Garçon|Garcon/i.test(kilde) && !/error_log/.test(kilde), (kilde.match(/Haugli|Aanerud|emmk|Garçon|Garcon|error_log/gi) || []).slice(0, 5));

await klargjor({ moduler: null });
await k1429({ nullstill: true, slett_epost: [E] });
await opt({ amendo_frontend_url: null, amendo_test_epost_fang: null, amendo_b2b_passordlenker: null });
ferdig();
