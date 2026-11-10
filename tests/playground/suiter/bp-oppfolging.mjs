// 1.4.37: bedriftspakker, oppfølging. E-post til butikken når en søknad har
// pakker (innholdet, mottakerne som kontaktskjemaet, ikke to ganger samme
// innhold innen et døgn, wp_mail som feiler), oppfølgingsstatus per bedrift
// (Ny, Kontaktet, Avtale startet, Ikke aktuell) med notat og dato i Søknader,
// i kortet «Oppfølging av bedriftspakker» med filter og på brukerprofilen, og
// kolonnen Ref. Testrutene er i mu/v1437-test.php. Se
// includes/moduler/bedriftspakker.php.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { B, REPO, get, sjekk, ferdig } from '../lib/felles.mjs';

const SECRET = 'bp-hemmelig-1437';
const sett = o => get('/amendo-test/v1/v1423-sett', o);
const lagre = post => get('/amendo-test/v1/v1414-lagre', { post, rolle: 'admin' });
const lag = brukere => get('/amendo-test/v1/v1428-klargjor', { brukere });
const meta = (epost, m) => get('/amendo-test/v1/v1428-sett', { epost, meta: m });
const tilstand = (ider = []) => get('/amendo-test/v1/v1437-tilstand', { ider });
const handling = (post, o = {}) => get('/amendo-test/v1/v1437-handling', { post, ...o });
const profilLagre = (id, post, o = {}) => get('/amendo-test/v1/v1437-profil-lagre', { id, post, ...o });
const profil = async (id, rolle = 'admin') => (await get('/amendo-test/v1/v1431-profil', { id, rolle })).html || '';
const side = async (g = {}) => (await fetch(B + '/amendo-test/v1/v1437-side', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ get: g }) })).text();
const E = n => `${n}@bp1437.no`;
const soknad = async body => {
  const r = await fetch(B + '/amendo-settings/v1/b2b-soknad', { method: 'POST', headers: { 'content-type': 'application/json', 'X-Amendo-Secret': SECRET }, body: JSON.stringify(body) });
  const tekst = await r.text();
  let json = null; try { json = JSON.parse(tekst); } catch {}
  return { status: r.status, json, tekst };
};
const eposter = async () => ((await tilstand()).epost || []).filter(e => e.emne.startsWith('Bedriftspakke: '));

await get('/amendo-test/v1/setup');
await sett({ amendo_test_stille: '1', amendo_kasse_secret: SECRET, amendo_moduler: ['b2b', 'bedriftspakker'], amendo_bedriftspakker: null,
  amendo_kontaktskjema_epost: 'salg@butikk.test, leder@butikk.test', amendo_kontakt_epost: 'post@butikk.test',
  amendo_test_epost_fang: '1', amendo_test_epost: null, amendo_test_epost_feil: null });
const P = await get('/amendo-test/v1/v1431-produkter');
await lagre({ amendo_endret: { bedriftspakker: '1' }, bedriftspakker: { finnes: '1', pakker: [
  { navn: 'Fredagskos', type: 'fast', aktiv: '1', per_personer: '10', linjer: [{ produkt: P.bolle, antall: '20' }], dager: ['fredag'], hyppigheter: ['annenhver_uke', 'ukentlig'],
    nivaer: [{ navn: 'Liten', per_personer: '5', linjer: [{ produkt: P.bolle, antall: '10' }] }, { navn: 'Stor', per_personer: '20', linjer: [{ produkt: P.bolle, antall: '40' }] }] },
  { navn: 'Kakeabonnement', type: 'bursdag', aktiv: '1', kaker: [{ produkt: P.k12, personer: '' }] },
  { navn: 'Møtemat', type: 'engang', aktiv: '1', linjer: [{ produkt: P.bolle, antall: '1' }] },
] } });
const kunde = (n, firma = 'Testfirma AS') => ({ epost: E(n), meta: { first_name: 'Kari', last_name: 'Nordmann', billing_company: firma, billing_phone: '912 34 567' } });
const { ider } = await lag([kunde('a'), kunde('tom'), kunde('ref'), kunde('feil'), kunde('kontakt'), kunde('ugyldig'), kunde('hode', "Firma\nBcc: angriper@example.com"), kunde('profil'), kunde('kort')]);
const id = n => ider[E(n)];
const FREDAG = { pakke_id: 'fredagskos', niva: 'stor', antall_personer: 25, dag: 'fredag', hyppighet: 'annenhver_uke', pris_eks_per_levering: 1150, anslag_eks_per_maned: 2491.67 };
const KAKE = { pakke_id: 'kakeabonnement', antall_personer: 40, kake_produkt_id: P.kake, kake_variant_id: P.k12, anslag_eks_per_maned: 1466.67 };

// ── E-posten ────────────────────────────────────────────────────────────────
console.log('E-post til butikken');
let r = await soknad({ bruker_id: id('a'), org_nr: '911222333', ref: 'PER-7', pakker: [FREDAG, KAKE] });
let ep = await eposter();
sjekk('søknaden som før (200, venter, pakker og ref)', r.status === 200 && r.json?.status === 'venter' && r.json.pakker?.length === 2 && r.json.ref === 'PER-7' && !('oppfolging' in (r.json || {})), r.tekst);
sjekk('én e-post, til «Kontaktskjema sendes til» (begge adressene)', ep.length === 1 && JSON.stringify(ep[0].til) === '["salg@butikk.test","leder@butikk.test"]', ep.map(e => e.til));
const e1 = ep[0] || { html: '', emne: '', headers: [] };
sjekk('emnet: «Bedriftspakke: søknad fra Testfirma AS»', e1.emne === 'Bedriftspakke: søknad fra Testfirma AS', e1.emne);
sjekk('firma, org.nr., kontakt, telefon og e-post', ['Firma: Testfirma AS', 'Org.nr.: 911 222 333', 'Kontakt: Kari Nordmann', 'Telefon: 912 34 567', `E-post: ${E('a')}`].every(l => e1.html.includes(l + '\n')), e1.html);
sjekk('selgerkoden og at søknaden venter', e1.html.includes('Selgerkode (ref): PER-7\n') && e1.html.includes('Søknaden: venter på godkjenning\n'), e1.html);
sjekk('pakken med nivå, personer, dag, hyppighet og anslag', ['- Fredagskos – Stor', '  Nivå: Stor', '  Antall personer: 25', '  Dag: fredag', '  Hyppighet: annenhver uke', '  Anslag: ca. 2 492 kr/mnd eks. mva', '  Anslag per levering: ca. 1 150 kr eks. mva'].every(l => e1.html.includes(l + '\n')), e1.html);
sjekk('kakeabonnementet med kaken', ['- Kakeabonnement', '  Antall personer: 40', '  Kake: Sjokoladekake – 12 personer', '  Anslag: ca. 1 467 kr/mnd eks. mva'].every(l => e1.html.includes(l + '\n')), e1.html);
sjekk('at anslagene ikke er avtalte priser', e1.html.includes('ikke avtalte priser'));
sjekk('lenke til søknaden og til brukeren i admin', /Søknaden i admin: \S+\/wp-admin\/admin\.php\?page=amendo-settings#b2b\n/.test(e1.html) && new RegExp(`Brukeren i admin: \\S+/wp-admin/user-edit\\.php\\?user_id=${id('a')}\\n`).test(e1.html), e1.html);
const hoder = [].concat(e1.headers || []);
sjekk('ren tekst, Reply-To er kunden', hoder.includes('Content-Type: text/plain; charset=UTF-8') && hoder.includes(`Reply-To: Kari Nordmann <${E('a')}>`), hoder);
let t = await tilstand([id('a')]);
sjekk('varselet er merket på brukeren (hash og tid)', /^[0-9a-f]{32}$/.test(t.brukere[id('a')].varslet?.hash || '') && t.brukere[id('a')].varslet.tid > 0, t.brukere[id('a')].varslet);

await soknad({ bruker_id: id('a'), org_nr: '911222333', ref: 'PER-7', pakker: [FREDAG, KAKE] });
sjekk('samme innhold én gang til: ingen ny e-post', (await eposter()).length === 1);
await soknad({ bruker_id: id('a'), org_nr: '911222333', ref: 'PER-8' });
sjekk('bare ny selgerkode (uten pakker): ingen e-post', (await eposter()).length === 1);
await soknad({ bruker_id: id('a'), org_nr: '911222333', pakker: [{ ...FREDAG, antall_personer: 30 }] });
ep = await eposter();
sjekk('endret pakke: ny e-post, med den nye selgerkoden', ep.length === 2 && ep[1].html.includes('Antall personer: 30\n') && ep[1].html.includes('Selgerkode (ref): PER-8\n') && !ep[1].html.includes('Kakeabonnement'), ep[1]?.html);
await get('/amendo-test/v1/v1437-eldre-varsel', { id: id('a'), sekunder: 86400 + 60 });
await soknad({ bruker_id: id('a'), org_nr: '911222333', pakker: [{ ...FREDAG, antall_personer: 30 }] });
sjekk('samme innhold etter et døgn: sendes igjen', (await eposter()).length === 3);

await soknad({ bruker_id: id('tom'), org_nr: '911222333' });
await soknad({ bruker_id: id('ref'), org_nr: '911222333', ref: 'KUN-REF' });
sjekk('søknad uten pakker, og med bare selgerkode: ingen e-post', (await eposter()).length === 3);
r = await soknad({ bruker_id: id('ugyldig'), org_nr: '911222333', pakker: [{ pakke_id: 'finnes-ikke' }] });
sjekk('ugyldig pakke (400): ingen e-post', r.status === 400 && (await eposter()).length === 3, r.status);

await sett({ amendo_test_epost_feil: '1' });
r = await soknad({ bruker_id: id('feil'), org_nr: '911222333', pakker: [FREDAG] });
t = await tilstand([id('feil')]);
sjekk('wp_mail feiler: søknaden lagres og svarer som før, ikke merket som varslet', r.status === 200 && r.json?.status === 'venter' && r.json.pakker?.length === 1 && t.brukere[id('feil')].varslet === '', [r.status, t.brukere[id('feil')]]);
await sett({ amendo_test_epost_feil: null });
await soknad({ bruker_id: id('feil'), org_nr: '911222333', pakker: [FREDAG] });
sjekk('… neste innsending sender den', (await eposter()).length === 4);

await sett({ amendo_kontaktskjema_epost: null });
await soknad({ bruker_id: id('kontakt'), org_nr: '911222333', pakker: [{ pakke_id: 'motemat', antall_personer: 12 }] });
ep = await eposter();
sjekk('uten «Kontaktskjema sendes til»: kontakt-e-posten', ep.length === 5 && JSON.stringify(ep[4].til) === '["post@butikk.test"]' && ep[4].html.includes('- Møtemat\n') && ep[4].html.includes('Selgerkode (ref): —\n'), ep[4]?.til);
await sett({ amendo_kontaktskjema_epost: 'salg@butikk.test' });

await soknad({ bruker_id: id('hode'), org_nr: '911222333', pakker: [FREDAG] });
ep = await eposter();
const eh = ep[5] || { emne: '', headers: [] };
sjekk('linjeskift i firmanavnet havner ikke i e-posthodet', ep.length === 6 && !/[\r\n]/.test(eh.emne) && [].concat(eh.headers).length === 2 && ![].concat(eh.headers).some(h => /bcc/i.test(h)), [eh.emne, eh.headers]);

// ── Søknader ────────────────────────────────────────────────────────────────
console.log('\nSøknader');
let html = await side();
const soknader = html.slice(html.indexOf('id="b2b-soknader"'), html.indexOf('id="bp-oppfolging"'));
const rad = (n, i0 = soknader) => { const i = i0.indexOf(`data-bruker="${id(n)}"`); return i < 0 ? '' : i0.slice(i, i0.indexOf('</tr>', i)); };
sjekk('kolonnene Pakke, Ref og Oppfølging', soknader.includes('<th>Pakke</th><th>Ref</th><th>Oppfølging</th>'));
sjekk('Ref i egen kolonne, ikke i Pakke', rad('a').includes('<td class="b2b-ref">PER-8</td>') && !rad('a').includes('Selgerkode'), rad('a'));
sjekk('oppfølgingen: status Ny, dato, notat og Lagre, i skjemaet for oppfølging', rad('a').includes(`name="bp_oppf[s][${id('a')}][status]" form="amendo-bp-oppfolging"`) && rad('a').includes('<option value="ny" selected=\'selected\'>Ny</option>') && rad('a').includes(`name="bp_oppf[s][${id('a')}][dato]"`) && rad('a').includes(`name="bp_oppf[s][${id('a')}][notat]"`) && rad('a').includes(`value="s:${id('a')}"`), rad('a'));
sjekk('alle fire statusene', ['Ny', 'Kontaktet', 'Avtale startet', 'Ikke aktuell'].every(s => rad('a').includes(`>${s}</option>`)));
sjekk('uten pakke og selgerkode: «—» i Ref og Oppfølging', rad('tom').includes('<td class="b2b-ref">—</td>') && rad('tom').includes('<td class="b2b-oppfolging">—</td>'), rad('tom'));
sjekk('skjemaet for oppfølging, med nonce, utenfor hovedskjemaet', /<form id="amendo-bp-oppfolging" method="post"[^>]*>[\s\S]*?name="_wpnonce"[\s\S]*?value="amendo_bp_oppfolging"/.test(html));
let kort = html.slice(html.indexOf('id="bp-oppfolging"'), html.indexOf('id="b2b-kunder"'));
sjekk('kortet «Oppfølging av bedriftspakker»: de med pakke eller selgerkode, ikke de uten', kort.includes('Oppfølging av bedriftspakker') && rad('a', kort) && rad('ref', kort) && !rad('tom', kort), kort.slice(0, 300));
sjekk('kortet: firma, org.nr., kontakt med telefon, Ref, søknaden og egne feltnavn', rad('a', kort).includes('Testfirma AS') && rad('a', kort).includes('911 222 333') && rad('a', kort).includes('912 34 567') && rad('a', kort).includes('<td class="b2b-ref">PER-8</td>') && rad('a', kort).includes('<td class="bp-b2b-status">Venter</td>') && rad('a', kort).includes(`name="bp_oppf[o][${id('a')}][status]"`), rad('a', kort));
sjekk('filteret: Alle og hver status med antall', kort.includes('Alle (5)') && kort.includes('Ny (5)') && kort.includes('Kontaktet (0)') && kort.includes('bp_status=avtale'), kort.match(/Status:[\s\S]*?<\/p>/)?.[0]);

// ── Lagre ───────────────────────────────────────────────────────────────────
console.log('\nLagre oppfølgingen');
const A = id('a');
const begge = (s, o) => ({ lagre: `s:${A}`, bp_oppf: { s: { [A]: s }, o: { [A]: o } }, bp_status: 'kontaktet' });
let h = await handling(begge({ status: 'kontaktet', dato: '2026-11-10', notat: 'Ringte <b>Kari</b>, "ring igjen" \\ fredag' }, { status: 'ikke_aktuell', dato: '', notat: 'fra kortet' }));
t = await tilstand([A]);
let o = t.brukere[A].oppfolging;
sjekk('Lagre i Søknader: radens felter (ikke kortets), notatet uten HTML', o?.status === 'kontaktet' && o.dato === '2026-11-10' && o.notat === 'Ringte Kari, "ring igjen" \\ fredag', o);
sjekk('endret (tid) og av hvem', /^\d{4}-\d\d-\d\dT/.test(o?.endret || '') && o.endret_av > 0, o);
sjekk('tilbake til fanen med melding og filteret', /admin\.php\?page=amendo-settings&bp_oppf=lagret&bp_status=kontaktet#b2b$/.test(h.til || ''), h.til);
h = await handling({ ...begge({ status: 'kontaktet' }, { status: 'avtale', dato: '2026-12-01', notat: 'Avtale fra 1. desember' }), lagre: `o:${A}` });
o = (await tilstand([A])).brukere[A].oppfolging;
sjekk('Lagre i kortet: kortets felter', o?.status === 'avtale' && o.dato === '2026-12-01' && o.notat === 'Avtale fra 1. desember' && h.til?.includes('bp_oppf=lagret'), [o, h.til]);
h = await handling({ ...begge({}, { status: 'avtale', dato: '2026-12-01', notat: 'Avtale fra 1. desember' }), lagre: `o:${A}` });
sjekk('ingen endring: «uendret»', h.til?.includes('bp_oppf=uendret'), h.til);
for (const [navn, felt] of [['ukjent status', { status: 'vunnet', dato: '', notat: '' }], ['ugyldig dato', { status: 'ny', dato: '2026-02-30', notat: '' }], ['dato i annet format', { status: 'ny', dato: '10.11.2026', notat: '' }]]) {
  h = await handling({ ...begge({}, felt), lagre: `o:${A}` });
  o = (await tilstand([A])).brukere[A].oppfolging;
  sjekk(`${navn}: «ugyldig», ingenting lagret`, h.til?.includes('bp_oppf=ugyldig') && o?.status === 'avtale' && o.dato === '2026-12-01', [h.til, o]);
}
h = await handling({ lagre: `o:${id('tom')}`, bp_oppf: { o: { [id('tom')]: { status: 'kontaktet' } } } });
sjekk('bedrift uten pakke eller selgerkode: «ukjent», ingenting lagret', h.til?.includes('bp_oppf=ukjent') && (await tilstand([id('tom')])).brukere[id('tom')].oppfolging === '', h.til);
h = await handling({ lagre: `x:${A}` });
sjekk('ukjent verdi på Lagre: «ukjent»', h.til?.includes('bp_oppf=ukjent'), h.til);
h = await handling(begge({ status: 'ny' }, {}), { nonce: false });
sjekk('uten nonce: stoppet', h.stoppet && !h.til && (await tilstand([A])).brukere[A].oppfolging.status === 'avtale', h);
h = await handling(begge({ status: 'ny' }, {}), { rolle: 'customer' });
sjekk('kunde: stoppet', h.stoppet && !h.til && (await tilstand([A])).brukere[A].oppfolging.status === 'avtale', h);
h = await handling({ lagre: `s:${id('ref')}`, bp_oppf: { s: { [id('ref')]: { status: 'ikke_aktuell', dato: '', notat: 'Har egen leverandør' } } } }, { rolle: 'shop_manager' });
sjekk('butikkadministrator kan lagre', h.til?.includes('bp_oppf=lagret') && (await tilstand([id('ref')])).brukere[id('ref')].oppfolging?.status === 'ikke_aktuell', h);
await handling({ lagre: `o:${id('kort')}`, bp_oppf: { o: { [id('kort')]: { status: 'kontaktet', dato: '', notat: '' } } } });
sjekk('… (kort-brukeren har ikke søkt ennå: ukjent)', (await tilstand([id('kort')])).brukere[id('kort')].oppfolging === '');

// ── Filter og visning ───────────────────────────────────────────────────────
console.log('\nFilter og visning');
await meta(E('a'), { _b2b_status: 'godkjent', _orgnr_soknad: null, billing_org_number: '911222333' });
html = await side({ bp_status: 'avtale', bp_oppf: 'lagret' });
kort = html.slice(html.indexOf('id="bp-oppfolging"'), html.indexOf('id="b2b-kunder"'));
const soknader2 = html.slice(html.indexOf('id="b2b-soknader"'), html.indexOf('id="bp-oppfolging"'));
sjekk('godkjent: ute av Søknader, fortsatt i kortet med status Godkjent', !soknader2.includes(`data-bruker="${A}"`) && rad('a', kort).includes('<td class="bp-b2b-status">Godkjent</td>'), rad('a', kort).slice(0, 200));
sjekk('filteret «Avtale startet»: bare den, valgt, med antall', rad('a', kort) && !rad('ref', kort) && !rad('feil', kort) && /class="bp-filter-valg current" data-status="avtale">Avtale startet \(1\)/.test(kort) && kort.includes('Ikke aktuell (1)') && kort.includes('Ny (3)'), kort.match(/Status:[\s\S]*?<\/p>/)?.[0]);
sjekk('lagret verdi i feltene: status, dato, notat og «Endret … av»', rad('a', kort).includes('<option value="avtale" selected=\'selected\'>') && rad('a', kort).includes('value="2026-12-01"') && rad('a', kort).includes('>Avtale fra 1. desember</textarea>') && /Endret \d\d\.\d\d\.\d{4} \d\d:\d\d av /.test(rad('a', kort)), rad('a', kort));
sjekk('meldingen etter lagring', kort.includes('Oppfølgingen er lagret.'));
sjekk('filteret blir med i skjemaet, så Lagre kommer tilbake til samme filter', /<form id="amendo-bp-oppfolging"[\s\S]*?name="bp_status" value="avtale"/.test(html));
kort = (h => h.slice(h.indexOf('id="bp-oppfolging"'), h.indexOf('id="b2b-kunder"')))(await side({ bp_status: 'tull' }));
sjekk('ukjent filter = alle', /class="bp-filter-valg current" data-status="">Alle \(5\)/.test(kort) && rad('a', kort) && rad('ref', kort), kort.match(/Status:[\s\S]*?<\/p>/)?.[0]);
kort = (h => h.slice(h.indexOf('id="bp-oppfolging"'), h.indexOf('id="b2b-kunder"')))(await side({ bp_status: 'kontaktet' }));
sjekk('filter uten treff: «Ingen med denne statusen.»', kort.includes('Ingen med denne statusen.') && !kort.includes('<tbody>'), kort.slice(0, 600));

// ── Brukerprofilen ──────────────────────────────────────────────────────────
console.log('\nBrukerprofilen');
let p = await profil(A);
p = p.slice(p.indexOf('Bedriftspakke fra søknaden'));
p = p.slice(0, p.indexOf('</table>'));
sjekk('admin: pakken, selgerkoden og oppfølgingen med lagrede verdier og markør', p.includes('Bedriftspakke fra søknaden') && p.includes('Selgerkode: PER-8') && p.includes('name="amendo_bp_oppf_finnes" value="1"') && p.includes('name="amendo_bp_oppf[status]"') && p.includes('<option value="avtale" selected=\'selected\'>') && p.includes('value="2026-12-01"') && !p.includes(' form="'), p.slice(0, 600));
sjekk('kunden ser den ikke', !(await profil(A, 'customer')).includes('Oppfølging'));
sjekk('bruker uten pakke eller selgerkode: ingen seksjon', !(await profil(id('tom'))).includes('Bedriftspakke'));
let pl = await profilLagre(A, { amendo_bp_oppf_finnes: '1', amendo_bp_oppf: { status: 'kontaktet', dato: '', notat: 'Ny runde i januar' } });
sjekk('lagring fra profilen', pl.oppfolging?.status === 'kontaktet' && pl.oppfolging.dato === '' && pl.oppfolging.notat === 'Ny runde i januar', pl);
pl = await profilLagre(A, { amendo_bp_oppf: { status: 'ny' } });
sjekk('uten markøren: urørt', pl.oppfolging?.status === 'kontaktet', pl);
pl = await profilLagre(A, { amendo_bp_oppf_finnes: '1', amendo_bp_oppf: { status: 'ny', dato: '2026-13-01' } });
sjekk('ugyldig dato: urørt', pl.oppfolging?.status === 'kontaktet', pl);
pl = await profilLagre(A, { amendo_bp_oppf_finnes: '1', amendo_bp_oppf: { status: 'ikke_aktuell' } }, { rolle: 'customer', egen: true });
sjekk('kunden kan ikke sette den (egen profil)', pl.oppfolging?.status === 'kontaktet', pl);
pl = await profilLagre(id('tom'), { amendo_bp_oppf_finnes: '1', amendo_bp_oppf: { status: 'kontaktet' } });
sjekk('bruker uten pakke eller selgerkode: ingenting lagret', pl.oppfolging === '', pl);

// ── REST og kilden ──────────────────────────────────────────────────────────
console.log('\nREST og kildekoden');
r = await soknad({ bruker_id: id('ref'), org_nr: '911222333' });
sjekk('søknadssvaret har ingen oppfølging', r.status === 200 && JSON.stringify(Object.keys(r.json || {})) === '["ok","status","org_nr","pakker","ref"]', r.tekst);
const kilde = readFileSync(resolve(REPO, 'includes/moduler/bedriftspakker.php'), 'utf8');
const kode = kilde.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
sjekk('ingen butikknavn i modulen', !/Aanerud|Garçon|Garcon|emmk|Jessheim|Dorothea/i.test(kilde));
sjekk('ingen logging', !/error_log|wc_get_logger|->log\(|print_r|var_dump/.test(kode));

// ── Av ──────────────────────────────────────────────────────────────────────
console.log('\nModulen av (B2B på)');
await sett({ amendo_moduler: ['b2b'] });
await soknad({ bruker_id: id('kort'), org_nr: '911222333', pakker: [FREDAG] });
sjekk('søknad med pakker: ingen e-post', (await eposter()).length === 6);
html = await side();
sjekk('ingen kort, kolonner eller skjema for oppfølging', !html.includes('id="bp-oppfolging"') && !html.includes('<th>Oppfølging</th>') && !html.includes('<th>Ref</th>') && !html.includes('amendo-bp-oppfolging'));
h = await handling({ lagre: `o:${A}`, bp_oppf: { o: { [A]: { status: 'ny' } } } });
sjekk('handlingen finnes ikke', !h.til && !h.stoppet && (await tilstand([A])).brukere[A].oppfolging?.status === 'kontaktet', h);
sjekk('profilen viser den ikke', !(await profil(A)).includes('Oppfølging'));

// Rydd
await sett({ amendo_moduler: null, amendo_bedriftspakker: null, amendo_kontaktskjema_epost: null, amendo_kontakt_epost: null, amendo_test_epost_fang: null, amendo_test_epost: null });
ferdig();
