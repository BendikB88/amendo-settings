// 1.4.42: bedriftspakker, egen forespørsel. `egen_foresporsel` i POST
// /b2b-soknad (beskrivelse, hyppighet, ukedag, oppstart, antall_personer),
// alene eller med pakker: validering før noe lagres, lagringen, svaret,
// e-posten til butikken (merket «EGEN FORESPØRSEL»), Søknader, oppfølgingen
// (statusene og Ref), brukerprofilen, at ingen ordre lages, og modulen av.
// Testrutene er i mu/v1442-test.php (pluss v1428/v1429/v1431/v1437). Se
// includes/moduler/bedriftspakker.php.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { B, REPO, get, sjekk, ferdig } from '../lib/felles.mjs';

const SECRET = 'bp-hemmelig-1442';
const sett = o => get('/amendo-test/v1/v1423-sett', o);
const lagre = post => get('/amendo-test/v1/v1414-lagre', { post, rolle: 'admin' });
const lag = brukere => get('/amendo-test/v1/v1428-klargjor', { brukere });
const tilstand = (ider = []) => get('/amendo-test/v1/v1442-tilstand', { ider });
const profil = async (id, rolle = 'admin') => (await get('/amendo-test/v1/v1431-profil', { id, rolle })).html || '';
const side = async (g = {}) => (await fetch(B + '/amendo-test/v1/v1437-side', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ get: g }) })).text();
const E = n => `${n}@bp1442.no`;
const soknad = async body => {
  const r = await fetch(B + '/amendo-settings/v1/b2b-soknad', { method: 'POST', headers: { 'content-type': 'application/json', 'X-Amendo-Secret': SECRET }, body: JSON.stringify(body) });
  const tekst = await r.text();
  let json = null; try { json = JSON.parse(tekst); } catch {}
  return { status: r.status, json, tekst };
};
const eposter = async () => ((await get('/amendo-test/v1/v1437-tilstand', { ider: [] })).epost || []).filter(e => e.emne.startsWith('Bedriftspakke'));

await get('/amendo-test/v1/setup');
await sett({ amendo_test_stille: '1', amendo_kasse_secret: SECRET, amendo_moduler: ['b2b', 'bedriftspakker'], amendo_bedriftspakker: null,
  amendo_kontaktskjema_epost: 'salg@butikk.test', amendo_test_epost_fang: '1', amendo_test_epost: null, amendo_test_epost_feil: null });
const P = await get('/amendo-test/v1/v1431-produkter');
await lagre({ amendo_endret: { bedriftspakker: '1' }, bedriftspakker: { finnes: '1', pakker: [
  { navn: 'Fredagskos', type: 'fast', aktiv: '1', per_personer: '10', linjer: [{ produkt: P.bolle, antall: '20' }], dager: ['fredag'], hyppigheter: ['ukentlig'] },
] } });
const kunde = n => ({ epost: E(n), meta: { first_name: 'Kari', last_name: 'Nordmann', billing_company: 'Firma ' + n + ' AS', billing_phone: '912 34 567' } });
const { ider } = await lag(['alene', 'min', 'begge', 'feil', 'feil2', 'lang', 'av'].map(kunde));
const id = n => ider[E(n)];
const ordrerFor = (await tilstand()).ordrer;

const EGEN = { beskrivelse: 'Lunsj til 30 <b>ansatte</b>\r\nhver fredag, "glutenfritt" & laktosefritt \\ takk', hyppighet: 'ukentlig', ukedag: 'Fredag', oppstart: '2026-12-01', antall_personer: '30' };
const LAGRET = { beskrivelse: 'Lunsj til 30 ansatte\nhver fredag, "glutenfritt" & laktosefritt \\ takk', hyppighet: 'ukentlig', ukedag: 'fredag', oppstart: '2026-12-01', antall_personer: 30 };

// ── Alene ───────────────────────────────────────────────────────────────────
console.log('Egen forespørsel alene');
let r = await soknad({ bruker_id: id('alene'), org_nr: '911222333', ref: 'PER-9', egen_foresporsel: EGEN });
sjekk('200, venter, pakker [] og ref', r.status === 200 && r.json?.status === 'venter' && JSON.stringify(r.json.pakker) === '[]' && r.json.ref === 'PER-9', r.tekst);
sjekk('svaret: egen_foresporsel renset (uten HTML, \\r\\n → \\n, ukedag med liten bokstav, personer som tall)', JSON.stringify(r.json?.egen_foresporsel) === JSON.stringify(LAGRET), r.json?.egen_foresporsel);
sjekk('svarets nøkler: som før + egen_foresporsel sist', JSON.stringify(Object.keys(r.json || {})) === '["ok","status","org_nr","pakker","ref","egen_foresporsel"]', r.tekst);
let t = await tilstand([id('alene')]);
let u = t.brukere[id('alene')];
sjekk('lagret på brukeren med tid (backslash og anførselstegn står)', u.egen?.beskrivelse === LAGRET.beskrivelse && u.egen.antall_personer === 30 && /^\d{4}-\d\d-\d\dT/.test(u.egen.tid || ''), u.egen);
sjekk('_amendo_bp_tid satt (oppfølgingen gjelder), søknaden venter', /^\d{4}-\d\d-\d\dT/.test(u.tid) && u.status === 'venter', u);
sjekk('ingen ordre laget', t.ordrer === ordrerFor, [ordrerFor, t.ordrer]);

let ep = await eposter();
const e1 = ep[0] || { emne: '', html: '', headers: [] };
sjekk('én e-post, emnet «Bedriftspakke – egen forespørsel fra Firma alene AS»', ep.length === 1 && e1.emne === 'Bedriftspakke – egen forespørsel fra Firma alene AS', ep.map(e => e.emne));
sjekk('tydelig merket, med personer, hyppighet, ukedag, oppstart', ['=== EGEN FORESPØRSEL ===', 'Egen forespørsel (ikke en av pakkene, ingen pris er regnet):', '  Antall personer: 30', '  Hyppighet: ukentlig', '  Ukedag: fredag', '  Oppstart: 01.12.2026', '=== SLUTT PÅ EGEN FORESPØRSEL ==='].every(l => e1.html.includes(l + '\n')), e1.html);
sjekk('beskrivelsen innrykket linje for linje', e1.html.includes('  Beskrivelse:\n    Lunsj til 30 ansatte\n    hver fredag, "glutenfritt" & laktosefritt \\ takk\n'), e1.html);
sjekk('firma, org.nr., selgerkode; ingen «Pakke:» eller anslag', e1.html.includes('Firma: Firma alene AS\n') && e1.html.includes('Org.nr.: 911 222 333\n') && e1.html.includes('Selgerkode (ref): PER-9\n') && !e1.html.includes('Pakke:') && !e1.html.includes('Anslagene'), e1.html);
sjekk('Reply-To er kunden', [].concat(e1.headers || []).includes(`Reply-To: Kari Nordmann <${E('alene')}>`), e1.headers);

await soknad({ bruker_id: id('alene'), org_nr: '911222333', ref: 'PER-9', egen_foresporsel: EGEN });
sjekk('samme innhold igjen: ingen ny e-post', (await eposter()).length === 1);
r = await soknad({ bruker_id: id('alene'), org_nr: '911222333' });
sjekk('nytt kall uten feltet: det lagrede står, ingen e-post', JSON.stringify(r.json?.egen_foresporsel) === JSON.stringify(LAGRET) && (await eposter()).length === 1, r.json);
r = await soknad({ bruker_id: id('alene'), org_nr: '911222333', egen_foresporsel: null });
sjekk('… også med null', JSON.stringify(r.json?.egen_foresporsel) === JSON.stringify(LAGRET), r.json);
r = await soknad({ bruker_id: id('alene'), org_nr: '911222333', egen_foresporsel: { ...EGEN, antall_personer: 35 } });
ep = await eposter();
sjekk('endret: erstattes, ny e-post', r.json?.egen_foresporsel?.antall_personer === 35 && ep.length === 2 && ep[1].html.includes('  Antall personer: 35\n'), [r.json?.egen_foresporsel, ep.length]);

// ── Bare det påkrevde ───────────────────────────────────────────────────────
console.log('\nUten ukedag og oppstart');
r = await soknad({ bruker_id: id('min'), org_nr: '911222333', egen_foresporsel: { beskrivelse: 'Julebord for 80', hyppighet: 'en_gang', ukedag: null, oppstart: '', antall_personer: 80 } });
sjekk('200, ukedag og oppstart tomme', r.status === 200 && JSON.stringify(r.json?.egen_foresporsel) === '{"beskrivelse":"Julebord for 80","hyppighet":"en_gang","ukedag":"","oppstart":"","antall_personer":80}', r.tekst);
ep = await eposter();
const e2 = ep[2]?.html || '';
sjekk('e-posten: «én gang», ingen Ukedag- eller Oppstart-linje, selgerkode —', e2.includes('  Hyppighet: én gang\n') && !e2.includes('Ukedag:') && !e2.includes('Oppstart:') && e2.includes('Selgerkode (ref): —\n'), e2);
for (const h of ['ukentlig', 'annenhver_uke', 'manedlig']) {
  r = await soknad({ bruker_id: id('min'), org_nr: '911222333', egen_foresporsel: { beskrivelse: 'x', hyppighet: h, antall_personer: 1 } });
  sjekk(`hyppighet ${h}: 200 (felter som mangler = tomme)`, r.status === 200 && r.json?.egen_foresporsel?.hyppighet === h && r.json.egen_foresporsel.ukedag === '' && r.json.egen_foresporsel.oppstart === '', r.tekst);
}
r = await soknad({ bruker_id: id('lang'), org_nr: '911222333', egen_foresporsel: { beskrivelse: 'ø'.repeat(2000), hyppighet: 'manedlig', ukedag: 'lørdag', oppstart: '2028-02-29', antall_personer: 10000 } });
sjekk('2000 tegn (æøå teller som ett), lørdag, 29.02 i skuddår, 10 000 personer: 200', r.status === 200 && r.json?.egen_foresporsel?.beskrivelse?.length === 2000 && r.json.egen_foresporsel.ukedag === 'lørdag', [r.status, r.json?.message]);

// ── Med pakker ──────────────────────────────────────────────────────────────
console.log('\nSammen med pakker');
r = await soknad({ bruker_id: id('begge'), org_nr: '911222333', ref: 'PER-2', pakker: [{ pakke_id: 'fredagskos', antall_personer: 20, dag: 'fredag', hyppighet: 'ukentlig', anslag_eks_per_maned: 4000 }], egen_foresporsel: { beskrivelse: 'Kaffe i tillegg', hyppighet: 'ukentlig', ukedag: 'fredag', antall_personer: 20 } });
sjekk('200 med både pakken og den egne', r.status === 200 && r.json?.pakker?.[0]?.pakke_id === 'fredagskos' && r.json.egen_foresporsel?.beskrivelse === 'Kaffe i tillegg', r.tekst);
ep = await eposter();
const e3 = ep.at(-1) || { emne: '', html: '' };
sjekk('én e-post med begge: emnet sier det', ep.length === 8 && e3.emne === 'Bedriftspakke + egen forespørsel: søknad fra Firma begge AS', [ep.length, e3.emne]);
sjekk('egen forespørsel før pakkene, pakken og anslaget som før', e3.html.indexOf('=== EGEN FORESPØRSEL ===') > 0 && e3.html.indexOf('=== EGEN FORESPØRSEL ===') < e3.html.indexOf('Pakke:\n') && e3.html.includes('- Fredagskos\n') && e3.html.includes('Anslagene er regnet på nettsiden'), e3.html);

// ── Ugyldig ─────────────────────────────────────────────────────────────────
console.log('\nUgyldig: 400, ingenting lagret, ingen e-post');
const G = { beskrivelse: 'Gyldig', hyppighet: 'ukentlig', antall_personer: 5 };
const feil = [
  ['liste', ['a']], ['tekst', 'lunsj'], ['tall', 5],
  ['uten beskrivelse', { hyppighet: 'ukentlig', antall_personer: 5 }], ['tom beskrivelse', { ...G, beskrivelse: '   ' }],
  ['bare HTML', { ...G, beskrivelse: '<script>alert(1)</script>' }], ['beskrivelse som tall', { ...G, beskrivelse: 123 }],
  ['2001 tegn', { ...G, beskrivelse: 'a'.repeat(2001) }],
  ['ukjent hyppighet', { ...G, hyppighet: 'daglig' }], ['uten hyppighet', { beskrivelse: 'x', antall_personer: 5 }], ['hyppighet med stor bokstav', { ...G, hyppighet: 'Ukentlig' }],
  ['ukedag «fredagen»', { ...G, ukedag: 'fredagen' }], ['ukedag «lordag»', { ...G, ukedag: 'lordag' }], ['ukedag som tall', { ...G, ukedag: 5 }],
  ['oppstart 2026-02-30', { ...G, oppstart: '2026-02-30' }], ['oppstart 01.12.2026', { ...G, oppstart: '01.12.2026' }], ['oppstart som tall', { ...G, oppstart: 20261201 }],
  ['0 personer', { ...G, antall_personer: 0 }], ['10 001 personer', { ...G, antall_personer: 10001 }], ['2,5 personer', { ...G, antall_personer: '2,5' }],
  ['negativt', { ...G, antall_personer: -3 }], ['uten antall', { beskrivelse: 'x', hyppighet: 'ukentlig' }],
];
for (const [navn, egen] of feil) {
  r = await soknad({ bruker_id: id('feil'), org_nr: '911222333', egen_foresporsel: egen });
  sjekk(`${navn}: 400 amendo_b2b_soknad_ugyldig_egen med melding`, r.status === 400 && r.json?.code === 'amendo_b2b_soknad_ugyldig_egen' && typeof r.json.message === 'string' && r.json.message.length > 5, [r.status, r.json]);
}
r = await soknad({ bruker_id: id('feil2'), org_nr: '911222333', ref: 'X', pakker: [{ pakke_id: 'fredagskos' }], egen_foresporsel: { ...G, hyppighet: 'daglig' } });
sjekk('gyldig pakke + ugyldig egen: 400', r.status === 400 && r.json?.code === 'amendo_b2b_soknad_ugyldig_egen', r.json);
t = await tilstand([id('feil'), id('feil2')]);
sjekk('verken søknad, pakke, ref eller egen lagret', [id('feil'), id('feil2')].every(i => t.brukere[i].status === '' && t.brukere[i].egen === '' && t.brukere[i].onske === '' && t.brukere[i].ref === '' && t.brukere[i].tid === ''), t.brukere);
sjekk('ingen e-post for de ugyldige', (await eposter()).length === 8);
sjekk('fortsatt ingen ordre', t.ordrer === ordrerFor, [ordrerFor, t.ordrer]);

// ── Søknader, oppfølging og profil ──────────────────────────────────────────
console.log('\nSøknader, oppfølging og brukerprofilen');
let html = await side();
const soknader = html.slice(html.indexOf('id="b2b-soknader"'), html.indexOf('id="bp-oppfolging"'));
const rad = (n, i0 = soknader) => { const i = i0.indexOf(`data-bruker="${id(n)}"`); return i < 0 ? '' : i0.slice(i, i0.indexOf('</tr>', i)); };
sjekk('Søknader, kolonnen Pakke: «Egen forespørsel» med sammendraget', rad('alene').includes('<strong>Egen forespørsel</strong> · 35 personer · ukentlig · fredag · oppstart 01.12.2026'), rad('alene'));
sjekk('… beskrivelsen escapet, med linjeskift', rad('alene').includes('Lunsj til 30 ansatte<br />\nhver fredag, &quot;glutenfritt&quot; &amp; laktosefritt') && !rad('alene').includes('<b>'), rad('alene'));
sjekk('… Ref og oppfølgingen (status Ny og Lagre) som for pakkene', rad('alene').includes('<td class="b2b-ref">PER-9</td>') && rad('alene').includes(`name="bp_oppf[s][${id('alene')}][status]"`) && rad('alene').includes('<option value="ny" selected=\'selected\'>Ny</option>') && rad('alene').includes(`value="s:${id('alene')}"`), rad('alene'));
sjekk('pakke og egen i samme celle', rad('begge').includes('Fredagskos') && rad('begge').includes('Egen forespørsel'), rad('begge'));
sjekk('lang beskrivelse: 200 tegn + … i lista', rad('lang').includes('ø'.repeat(200) + '…') && !rad('lang').includes('ø'.repeat(201)), rad('lang').length);
const kort = html.slice(html.indexOf('id="bp-oppfolging"'), html.indexOf('id="b2b-kunder"'));
sjekk('oppfølgingskortet har den som bare sendte egen forespørsel', rad('alene', kort).includes('Egen forespørsel') && rad('alene', kort).includes('<td class="b2b-ref">PER-9</td>') && rad('min', kort) !== '', rad('alene', kort).slice(0, 300));
sjekk('kortets tekst nevner egen forespørsel', kort.includes('en egen forespørsel eller selgerkode'));
const h = await get('/amendo-test/v1/v1437-handling', { post: { lagre: `o:${id('alene')}`, bp_oppf: { o: { [id('alene')]: { status: 'kontaktet', dato: '2026-11-20', notat: 'Ringt' } } } } });
const o = (await get('/amendo-test/v1/v1437-tilstand', { ider: [id('alene')] })).brukere[id('alene')].oppfolging;
sjekk('statusen kan settes (Kontaktet)', h.til?.includes('bp_oppf=lagret') && o?.status === 'kontaktet', [h.til, o]);
let p = await profil(id('lang'));
sjekk('profilen: hele beskrivelsen', p.includes('ø'.repeat(2000)) && !p.includes('ø'.repeat(2000) + '…'), p.length);
sjekk('kunden ser den ikke', !(await profil(id('alene'), 'customer')).includes('Egen forespørsel'));

// ── Kilden og modulen av ────────────────────────────────────────────────────
console.log('\nKildekoden og modulen av');
const kilde = readFileSync(resolve(REPO, 'includes/moduler/bedriftspakker.php'), 'utf8');
const kode = kilde.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
sjekk('ingen butikknavn', !/Aanerud|Garçon|Garcon|emmk|Jessheim|Dorothea/i.test(kilde));
sjekk('ingen logging, ingen ordre', !/error_log|wc_get_logger|->log\(|print_r|var_dump/.test(kode) && !/wc_create_order|new WC_Order/.test(kode));
await sett({ amendo_moduler: ['b2b'] });
r = await soknad({ bruker_id: id('av'), org_nr: '911222333', egen_foresporsel: EGEN });
sjekk('av: feltet ignoreres, svaret som før', r.status === 200 && r.tekst === '{"ok":true,"status":"venter","org_nr":"911222333"}', r.tekst);
t = await tilstand([id('av')]);
sjekk('av: ingenting lagret, ingen e-post', t.brukere[id('av')].egen === '' && (await eposter()).length === 8, t.brukere[id('av')]);
r = await soknad({ bruker_id: id('av'), org_nr: '911222333', egen_foresporsel: 'ugyldig' });
sjekk('av: ugyldig felt gir ikke 400', r.status === 200, r.status);

await sett({ amendo_moduler: null, amendo_bedriftspakker: null, amendo_kontaktskjema_epost: null, amendo_test_epost_fang: null, amendo_test_epost: null });
ferdig();
