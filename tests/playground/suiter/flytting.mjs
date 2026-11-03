// 1.4.28: flytting fra B2B Wholesale Suite. Tørrkjøring (ingenting endres),
// reglene for hvem som godkjennes og hvem som står manuelt, kunde-ID fra
// garcon-tellix-b2b-sync bare fra live og bare når den finnes i Tellix,
// ellers oppslag/opprettelse som massehandlingen, 25 per runde, «endret»,
// planens nøkkel, alder og lås, tilgang og nonce, og at b2bwhs-feltene står.
// Se includes/moduler/b2b-flytting.php.
//
// ⚠ Moduler og stubbene lastes når pluginen lastes: tilstanden settes i ett
// kall og sjekkes i det neste.
import { B, get, sjekk, ferdig } from '../lib/felles.mjs';

const klargjor = body => get('/amendo-test/v1/v1414-klargjor', body);
const k1422 = body => get('/amendo-test/v1/v1422-klargjor', body);
const tilstand = () => get('/amendo-test/v1/v1422-tilstand');
const k1428 = body => get('/amendo-test/v1/v1428-klargjor', body);
const sett = (epost, meta) => get('/amendo-test/v1/v1428-sett', { epost, meta });
const meta = eposter => get('/amendo-test/v1/v1428-meta', { eposter });
const plan = async () => (await get('/amendo-test/v1/v1428-plan')).plan;
const juster = body => get('/amendo-test/v1/v1428-juster', body);
const handling = (post, ekstra = {}) => get('/amendo-test/v1/v1428-handling', { post, ...ekstra });
const side = async (q = '') => (await fetch(B + '/amendo-test/v1/moduler-side' + q)).text();
const logg = async () => (await get('/amendo-test/v1/v1414-logg')).tekst || '';
const svar = til => (/[?&]flytting=([^&#]+)/.exec(til || '') || [])[1];
const kort = html => { const i = html.indexOf('id="b2b-flytting"'); return i < 0 ? '' : html.slice(i, html.indexOf('</table>', i) > 0 ? html.indexOf('</table>', i) : i + 4000); };

const GRUPPER = [{ id: 14, name: 'Bedriftskunder', discountPercent: 10 }];
const KUNDER = [
  { id: 'T10', uuid: 'U10', groupId: 14, email: 'kontakt@eksisterende.no', orgnr: '911111111', name: 'Eksisterende AS' },
  { id: 'T50', uuid: 'U50', groupId: 14, email: 'sync@firma.no', orgnr: '955555555', name: 'Sync-kunde AS' },
  { id: 'T60', uuid: 'U60', groupId: 0, email: 'h@fl1428.no', orgnr: '', name: 'Treff på e-post' },
];
const EGEN = { url: 'https://tellix.test/', versjon: 'v3', bruker: 'amendo', passord: 'hemmelig-pw-1428', nokkel: 'hemmelig-key-1428' };
const BRREG = { navn: 'FRA BRREG AS', forretningsadresse: { adresse: ['Storgata 1'], postnummer: '0155', poststed: 'OSLO' } };

const b2b = (ekstra = {}) => ({ b2bwhs_b2buser: 'yes', b2bwhs_customergroup: '13761', b2bwhs_account_approved: 'yes', ...ekstra });
const E = n => n + '@fl1428.no';
const HOVED = [
  [E('a'), b2b({ billing_org_number: '911111111', billing_company: 'A AS' })],                           // org.nr i Tellix → koblet T10
  [E('b'), b2b({ b2bwhs_custom_field_13764: '977 777 777', billing_company: 'B AS' })],                  // org.nr fra b2bwhs → opprettes
  [E('c'), b2b({ billing_org_number: '933333331', b2bwhs_account_approved: 'no' })],                     // venter i b2bwhs
  [E('d'), b2b()],                                                                                        // mangler org.nr
  [E('e'), b2b({ b2bwhs_custom_field_1: '944444441', b2bwhs_custom_field_2: '944444442' })],             // to org.nr
  [E('f'), { b2bwhs_customergroup: '13761', billing_org_number: '988888888', _garcon_tellix_customer_id: 'T50', _garcon_tellix_environment: 'live' }], // sync-ID → T50
  [E('g'), b2b({ billing_org_number: '933333334', _garcon_tellix_customer_id: '999', _garcon_tellix_environment: 'live' })], // sync-ID finnes ikke → opprettes
  [E('h'), b2b({ billing_org_number: '966666661', _garcon_tellix_customer_id: 'T50', _garcon_tellix_environment: 'test' })], // testmiljø → oppslag (e-post T60)
  [E('i'), { b2bwhs_b2buser: 'no', b2bwhs_customergroup: 'no', billing_org_number: '922222229' }],      // privatkunde i b2bwhs: ikke med
  [E('j'), b2b({ billing_org_number: '911111111', _b2b_status: 'godkjent', _tellixCustomerId: 'T10' })], // ferdig fra før
  [E('k'), b2b({ billing_org_number: '933333335', _b2b_status: 'avvist' })],
  [E('l'), b2b({ billing_org_number: '933333336', _garcon_b2b_declined: '1' })],
  [E('m'), b2b({ billing_org_number: '933333337', _tellixCustomerId: 'T10' })],                         // endres etter tørrkjøringen
  [E('n'), b2b({ billing_org_number: '933333338', _b2b_status: 'venter', _orgnr_soknad: '933333338' })],
];
// 30 som bare skal godkjennes (koblet til Tellix fra før): gir to runder.
const FYLL = Array.from({ length: 30 }, (_, i) => [E('fyll' + String(i).padStart(2, '0')), b2b({ billing_org_number: String(900000000 + i), _tellixCustomerId: 'T10' })]);
const ALLE = [...HOVED, ...FYLL].map(([e]) => e);

await get('/amendo-test/v1/setup');
await klargjor({ moduler: ['b2b'], tellix: '1', grupper: GRUPPER, kunder: KUNDER, api_feil: null, side: null, sync_size: null, liste_slett: true, nullstill: true });
await k1422({ web: { token: 'ok' }, egen: EGEN, sync: null, brreg: BRREG, kall_slett: true });

// ── Uten b2bwhs-kunder ──────────────────────────────────────────────────────
console.log('Uten b2bwhs-kunder');
await k1428({ slett_plan: true });
let html = await side();
sjekk('kortet vises ikke når ingen har b2bwhs-felter', !html.includes('id="b2b-flytting"'));

await k1428({ brukere: [...HOVED, ...FYLL].map(([epost, meta]) => ({ epost, meta })) });
html = await side();
sjekk('kortet vises når det finnes b2bwhs-kunder, med «Tørrkjør» og eget skjema', html.includes('id="b2b-flytting"') && html.includes('name="flytting" value="torrkjor"') && html.includes('id="amendo-b2b-flytting"') && !html.includes('value="flytt"'));

// ── Tilgang ─────────────────────────────────────────────────────────────────
console.log('\nTilgang');
let r = await handling({ flytting: 'torrkjor' }, { nonce: false });
sjekk('uten nonce: stoppet, ingen tørrkjøring', r.stoppet === true && !(await plan()), r);
r = await handling({ flytting: 'torrkjor' }, { rolle: 'customer' });
sjekk('kunde-rolle: stoppet', r.stoppet === true && !(await plan()), r);
r = await handling({ flytting: 'flytt', plan: 'x' });
sjekk('flytt uten tørrkjøring: «Tørrkjør først»', svar(r.til) === 'ingen_plan', r);

// ── Tørrkjøring ─────────────────────────────────────────────────────────────
console.log('\nTørrkjøring');
const før = await meta(ALLE);
r = await handling({ flytting: 'torrkjor' }, { rolle: 'shop_manager' });
sjekk('butikkadministrator kan tørrkjøre: flytting=torrkjort, tilbake til B2B', svar(r.til) === 'torrkjort' && /#b2b$/.test(r.til), r);
let p = await plan();
const etter = await meta(ALLE);
sjekk('ingenting endret på kundene', JSON.stringify(før) === JSON.stringify(etter));
sjekk('ingen kall til Tellix web-API', (await tilstand()).kall.filter(k => k.sti).length === 0);
const v = e => p.brukere[String(før[e].id)];
sjekk('43 kandidater: privatkunden i b2bwhs (gruppe «no») er ikke med', Object.keys(p.brukere).length === 43 && !v(E('i')), Object.keys(p.brukere).length);
sjekk('a: godkjennes, org.nr fra billing, oppslag i Tellix', v(E('a')).godkjenning === 'ny' && v(E('a')).orgnr === '911111111' && v(E('a')).kilde === 'billing' && v(E('a')).tellix === 'oppslag', v(E('a')));
sjekk('b: org.nr fra b2bwhs-feltet (mellomrom fjernet)', v(E('b')).godkjenning === 'ny' && v(E('b')).orgnr === '977777777' && v(E('b')).kilde === 'b2bwhs', v(E('b')));
sjekk('c: ikke godkjent i b2bwhs → manuelt, ingen Tellix', v(E('c')).godkjenning === 'manuelt' && v(E('c')).grunn === 'venter_i_b2bwhs' && v(E('c')).tellix === 'ingen', v(E('c')));
sjekk('d: mangler org.nr → manuelt', v(E('d')).grunn === 'mangler_orgnr', v(E('d')));
sjekk('e: to ulike org.nr → manuelt', v(E('e')).grunn === 'flere_orgnr', v(E('e')));
sjekk('f: bare kundegruppe i b2bwhs er nok; kunde-ID fra sync (live)', v(E('f')).godkjenning === 'ny' && v(E('f')).tellix === 'sync' && v(E('f')).sync_id === 'T50', v(E('f')));
sjekk('g: kunde-ID fra sync (live), sjekkes ved flytting', v(E('g')).tellix === 'sync' && v(E('g')).sync_id === '999', v(E('g')));
sjekk('h: kunde-ID fra testmiljøet brukes ikke: oppslag', v(E('h')).tellix === 'oppslag', v(E('h')));
sjekk('j: godkjent og koblet fra før', v(E('j')).godkjenning === 'allerede' && v(E('j')).tellix === 'allerede', v(E('j')));
sjekk('k: avvist under Søknader → manuelt', v(E('k')).grunn === 'avvist_i_amendo', v(E('k')));
sjekk('l: avslått i sync-pluginen → manuelt', v(E('l')).grunn === 'avslatt_i_sync', v(E('l')));
sjekk('n: venter under Søknader → manuelt (behandles der)', v(E('n')).grunn === 'venter_i_soknader', v(E('n')));

html = await side('&flytting=torrkjort');
let k = kort(html);
sjekk('beskjed: tørrkjøringen er ferdig, ingenting endret', html.includes('id="b2b-flytting-melding">Tørrkjøringen er ferdig.'));
sjekk('sammendrag: 43 kunder, 36 godkjennes, 1 fra før, 6 manuelt; Tellix 32 fra før, 2 sync, 3 oppslag; 36 igjen',
  /43 kunder\.\s+36 godkjennes, 1 er godkjent fra før, 6 må behandles manuelt\.\s+Tellix: 32 koblet fra før, 2 med kunde-ID fra garcon-tellix-b2b-sync, 3 slås opp eller opprettes\.\s+0 utført, 36 igjen\./.test(k), k.slice(0, 800));
sjekk('knappen «Flytt neste 25» med planens nøkkel og bekreftelse', k.includes(`name="plan" value="${p.nokkel}"`) && k.includes('value="flytt" class="amendo-btn-secondary b2b-bekreft"') && k.includes('Flytt neste 25'));
sjekk('radene: grunn, kilde og Tellix-planen', k.includes('Manuelt: Ikke godkjent i B2B Wholesale Suite') && k.includes('Manuelt: Flere ulike org.nr i b2bwhs-feltene') && k.includes('fra b2bwhs-feltet') && k.includes('Kunde T50 fra garcon-tellix-b2b-sync'));

// ── Vern ────────────────────────────────────────────────────────────────────
console.log('\nVern');
r = await handling({ flytting: 'flytt', plan: 'feil-nokkel' });
sjekk('annen nøkkel enn tørrkjøringen: avvist, ingenting flyttet', svar(r.til) === 'annen_plan' && Object.keys((await plan()).resultat).length === 0, r);
r = await handling({ flytting: 'flytt', plan: p.nokkel }, { rolle: 'customer' });
sjekk('kunde-rolle kan ikke flytte', r.stoppet === true && Object.keys((await plan()).resultat).length === 0, r);
await juster({ las: true });
r = await handling({ flytting: 'flytt', plan: p.nokkel });
sjekk('en runde kjører allerede (lås): opptatt', svar(r.til) === 'opptatt' && Object.keys((await plan()).resultat).length === 0, r);
await juster({ las: false, alder: 2 * 86400 });
r = await handling({ flytting: 'flytt', plan: p.nokkel });
sjekk('over et døgn gammel: avvist', svar(r.til) === 'gammel_plan' && Object.keys((await plan()).resultat).length === 0, r);
html = await side();
sjekk('… og kortet ber om ny tørrkjøring uten flytteknapp', kort(html).includes('over et døgn gammel') && !kort(html).includes('value="flytt"'));
await juster({ alder: 60 });

// m endres etter tørrkjøringen.
await sett(E('m'), { _tellixCustomerId: null });

// ── Runde 1 ─────────────────────────────────────────────────────────────────
console.log('\nRunde 1');
await k1422({ kall_slett: true });
r = await handling({ flytting: 'flytt', plan: p.nokkel });
p = await plan();
let m = await meta(ALLE);
sjekk('flytting=flyttet, 25 resultater, låsen fjernet', svar(r.til) === 'flyttet' && Object.keys(p.resultat).length === 25 && !(await get('/amendo-test/v1/v1428-plan')).las, [r, Object.keys(p.resultat).length]);
sjekk('a: godkjent med org.nr, kilde b2bwhs og dato; koblet til T10 på org.nr', m[E('a')]._b2b_status === 'godkjent' && m[E('a')].billing_org_number === '911111111' && m[E('a')]._b2b_kilde === 'b2bwhs' && !!m[E('a')]._b2b_behandlet
  && m[E('a')]._tellixCustomerId === 'T10' && m[E('a')]._tellixCustomerUuId === 'U10' && m[E('a')]._tellixGroupId === '14', m[E('a')]);
const kall = (await tilstand()).kall.filter(x => x.sti);
const lagre = kall.filter(x => x.sti === 'customer/save').map(x => x.body?.data?.[0] || {});
const recB = lagre.find(x => x.orgNumber === '977777777');
sjekk('b: org.nr fra b2bwhs til billing_org_number; opprettet i Tellix uten kundegruppe', m[E('b')]._b2b_status === 'godkjent' && m[E('b')].billing_org_number === '977777777' && /^\d+$/.test(m[E('b')]._tellixCustomerId)
  && recB && recB.name === 'B AS' && !('customerGroupId' in recB) && m[E('b')]._b2b_tellix_status === 'opprettet', [m[E('b')], recB]);
sjekk('f: kunde-ID fra sync finnes i Tellix: koblet til T50 uten oppslag eller opprettelse', m[E('f')]._b2b_status === 'godkjent' && m[E('f')]._tellixCustomerId === 'T50' && m[E('f')]._tellixCustomerUuId === 'U50'
  && /fra garcon-tellix-b2b-sync/.test(m[E('f')].tellix_melding) && !lagre.some(x => x.orgNumber === '988888888'), m[E('f')]);
sjekk('g: kunde-ID fra sync finnes ikke i Tellix: slått opp og opprettet', /^\d+$/.test(m[E('g')]._tellixCustomerId) && m[E('g')]._tellixCustomerId !== '999' && lagre.some(x => x.orgNumber === '933333334'), m[E('g')]);
sjekk('h: testmiljø-ID brukt ikke; koblet til T60 på e-post', m[E('h')]._tellixCustomerId === 'T60' && m[E('h')]._b2b_tellix_status === 'koblet', m[E('h')]);
sjekk('m: endret siden tørrkjøringen → hoppet over, ikke godkjent', p.resultat[String(m[E('m')].id)]?.godkjenning === 'endret' && m[E('m')]._b2b_status === '', [p.resultat[String(m[E('m')].id)], m[E('m')]]);
sjekk('manuelle (c, d, e, k, l, n) urørt', [E('c'), E('d'), E('e'), E('k'), E('l'), E('n')].every(e => JSON.stringify(m[e]) === JSON.stringify(etter[e])), [E('c'), E('d'), E('e'), E('k'), E('l'), E('n')].filter(e => JSON.stringify(m[e]) !== JSON.stringify(etter[e])));
sjekk('j (ferdig fra før) og i (privatkunde) urørt', JSON.stringify(m[E('j')]) === JSON.stringify(etter[E('j')]) && JSON.stringify(m[E('i')]) === JSON.stringify(etter[E('i')]));
sjekk('b2bwhs-feltene står (kakeabonnementet leser dem)', m[E('a')].b2bwhs_b2buser === 'yes' && m[E('a')].b2bwhs_customergroup === '13761' && m[E('a')].b2bwhs_account_approved === 'yes');
const fyllGodkjent = FYLL.map(([e]) => e).filter(e => m[e]._b2b_status === 'godkjent');
sjekk('19 av de 30 som bare skal godkjennes, er godkjent (25 i runden), Tellix urørt', fyllGodkjent.length === 19 && fyllGodkjent.every(e => m[e]._tellixCustomerId === 'T10'), fyllGodkjent.length);

html = await side('&flytting=flyttet');
k = kort(html);
sjekk('kortet: 25 utført, 11 igjen, «Flytt neste 11»', /25 utført, 11 igjen\./.test(k) && k.includes('Flytt neste 11'), k.slice(0, 900));
sjekk('resultatkolonnen: godkjent og Tellix-utfall', k.includes('Godkjent. Tellix: Koblet.') && k.includes('Godkjent. Tellix: Opprettet i Tellix.') && k.includes('Endret siden tørrkjøringen. Tørrkjør på nytt.'));
sjekk('Aktive B2B-kunder viser de flyttede', html.includes('<td class="b2b-tellix-kunde">T50</td>'));

// ── Runde 2 og ferdig ───────────────────────────────────────────────────────
console.log('\nRunde 2');
r = await handling({ flytting: 'flytt', plan: p.nokkel });
p = await plan();
m = await meta(ALLE);
sjekk('de siste 11 (og m hoppes ikke over to ganger): 36 resultater', svar(r.til) === 'flyttet' && Object.keys(p.resultat).length === 36, Object.keys(p.resultat).length);
sjekk('alle 30 som bare skulle godkjennes, er godkjent', FYLL.every(([e]) => m[e]._b2b_status === 'godkjent'));
r = await handling({ flytting: 'flytt', plan: p.nokkel });
sjekk('tredje runde: ingen flere (ferdig), ingenting endret', svar(r.til) === 'ferdig' && JSON.stringify(await meta(ALLE)) === JSON.stringify(m), r);
html = await side();
sjekk('kortet uten flytteknapp når ingen gjenstår', !kort(html).includes('value="flytt"') && /36 utført, 0 igjen/.test(kort(html)));

// Ny tørrkjøring tar med m (nå uten Tellix-kobling) og ser de andre som ferdige.
r = await handling({ flytting: 'torrkjor' });
p = await plan();
const vm = p.brukere[String(m[E('m')].id)];
sjekk('ny tørrkjøring: m godkjennes og slås opp; de flyttede er godkjent fra før', vm.godkjenning === 'ny' && vm.tellix === 'oppslag' && p.brukere[String(m[E('a')].id)].godkjenning === 'allerede' && Object.keys(p.resultat).length === 0, vm);
r = await handling({ flytting: 'forkast' });
sjekk('«Forkast tørrkjøringen» sletter planen', svar(r.til) === 'forkastet' && !(await plan()));

// ── Loggen ──────────────────────────────────────────────────────────────────
console.log('\nLoggen');
const l = await logg();
sjekk('loggen har antall for tørrkjøring og flytting', /B2B-flytting, tørrkjøring: 43 kunder/.test(l) && /B2B-flytting: \d+ godkjent/.test(l), l.slice(-600));
sjekk('… men ingen e-post, org.nr eller firmanavn', !/fl1428|911111111|977777777|B AS|Sync-kunde/.test(l));

// ── B2B av ──────────────────────────────────────────────────────────────────
console.log('\nB2B av');
await klargjor({ moduler: [] });
r = await handling({ flytting: 'torrkjor' });
sjekk('handlingen finnes ikke, og ingen tørrkjøring lages', r.finnes === false && !(await plan()), r);
await klargjor({ moduler: ['b2b'] });

ferdig();
