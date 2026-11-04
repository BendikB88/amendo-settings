// 1.4.31: modulen Bedriftspakker, med frontendens kontrakt (garcon-b2b,
// lib/bedriftspakker.ts). Pakkene i admin (lagring, rensing, stabile ID-er,
// advarsel om tellix_id med gatewayens reserveprodukt), GET /bedriftspakker
// med pris fra dagens WooCommerce-priser, `pakker` og `ref` i POST
// /b2b-soknad (sjekket før noe lagres, med i svaret), visningen i Søknader og
// på brukerprofilen, og admin.js i jsdom. Testrutene er i mu/v1431-test.php.
// 1.4.33: nivåer (Liten / Medium / Stor) med egen tekst og oppskrift, i GET
// og som `niva` i søknaden. Se includes/moduler/bedriftspakker.php.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { JSDOM } from 'jsdom';
import { B, REPO, ADMIN_JS, JQUERY, get, sjekk, ferdig } from '../lib/felles.mjs';

const SECRET = 'bp-hemmelig-1431';
const sett = o => get('/amendo-test/v1/v1423-sett', o);
const lagre = (post, rolle = 'admin') => get('/amendo-test/v1/v1414-lagre', { post, rolle });
const side = async () => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=admin')).text();
const lag = brukere => get('/amendo-test/v1/v1428-klargjor', { brukere });
const tilstand = (ider = []) => get('/amendo-test/v1/v1431-tilstand', { ider });
const profil = async (id, rolle = 'admin') => (await get('/amendo-test/v1/v1431-profil', { id, rolle })).html || '';
const pris = (id, p, tilbud = '') => get('/amendo-test/v1/v1431-pris', { id, pris: p, tilbud });
const reserve = o => get('/amendo-test/v1/v1431-reserve', o);
const E = n => `${n}@bp1431.no`;

const hent = async () => {
  const r = await fetch(B + '/amendo-settings/v1/bedriftspakker');
  const tekst = await r.text();
  let json = null; try { json = JSON.parse(tekst); } catch {}
  return { status: r.status, json, tekst };
};
const soknad = async body => {
  const r = await fetch(B + '/amendo-settings/v1/b2b-soknad', { method: 'POST', headers: { 'content-type': 'application/json', 'X-Amendo-Secret': SECRET }, body: JSON.stringify(body) });
  const tekst = await r.text();
  let json = null; try { json = JSON.parse(tekst); } catch {}
  return { status: r.status, json, tekst };
};
const VENTER = '{"ok":true,"status":"venter","org_nr":"911222333"}';

await get('/amendo-test/v1/setup');
await sett({ amendo_test_stille: '1', amendo_kasse_secret: SECRET, amendo_moduler: ['b2b'], amendo_bedriftspakker: null });
const P = await get('/amendo-test/v1/v1431-produkter');
await reserve({ drikke: null, standard: null });
const { ider } = await lag(['av', 'ok', 'kake', 'enkel', 'ref', 'tom', 'feil'].map(n => ({ epost: E(n), meta: {} })));
const id = n => ider[E(n)];

// ── Av ──────────────────────────────────────────────────────────────────────
console.log('Modulen av (B2B på)');
let r = await hent();
sjekk('GET /bedriftspakker: 404', r.status === 404, r.status);
r = await soknad({ bruker_id: id('av'), org_nr: '911222333', pakker: [{ pakke_id: 'finnes-ikke' }], ref: '<ugyldig>' });
sjekk('søknaden: pakker og ref ignoreres, svaret som før', r.status === 200 && r.tekst === VENTER, r.tekst);
let t = await tilstand([id('av')]);
sjekk('… og ingenting om pakker på brukeren', t.brukere[id('av')].onske === '' && t.brukere[id('av')].ref === '', t.brukere[id('av')]);
let html = await side();
sjekk('ingen fane, ingen Pakke-kolonne i Søknader', !html.includes('data-tab="bedriftspakker"') && !html.includes('<th>Pakke</th>'));
let s = await get('/amendo-settings/v1/settings');
sjekk('/settings: ikke i moduler, sist i moduler_kjente', !s.moduler.includes('bedriftspakker') && s.moduler_kjente.at(-1) === 'bedriftspakker', [s.moduler, s.moduler_kjente]);

await sett({ amendo_moduler: ['b2b', 'bedriftspakker'] });

// ── På, tom ─────────────────────────────────────────────────────────────────
console.log('\nModulen på');
r = await hent();
sjekk('GET: 200 { valuta, pakker: [] }', r.status === 200 && r.tekst === '{"valuta":"NOK","pakker":[]}', r.tekst);
s = await get('/amendo-settings/v1/settings');
sjekk('/settings: i moduler', s.moduler.includes('bedriftspakker'), s.moduler);
html = await side();
sjekk('fanen og kortet med markør og malene', html.includes('data-tab="bedriftspakker"') && html.includes('data-amendo-del="bedriftspakker"') && html.includes('name="bedriftspakker[finnes]"') && ['bp-pakke-mal', 'bp-linje-mal', 'bp-kake-mal'].every(m => html.includes(`id="${m}"`)));
sjekk('ingen advarsler uten pakker', !html.includes('id="bp-advarsler"'));

// ── Lagring ─────────────────────────────────────────────────────────────────
console.log('\nLagring');
const PAKKER = [
  { navn: 'Fredagskos', type: 'fast', tekst: 'Boller og kaffe <b>hver fredag</b>', sortering: '20', aktiv: '1', per_personer: '10', bilde: 'https://cdn.example/fredag.jpg',
    linjer: [{ produkt: P.bolle, antall: '20' }, { produkt: P.kaffe, antall: '1,5' }, { produkt: 999999, antall: '1' }, { produkt: P.bolle, antall: '0' }],
    dager: ['lørdag', 'fredag', 'tull', 'torsdag'], hyppigheter: ['ukentlig', 'annenhver_uke', 'hver_dag'], kaker: [{ produkt: P.k8 }] },
  { navn: 'Kakeabonnement', type: 'bursdag', aktiv: '1', sortering: '10', kaker: [{ produkt: P.k8, personer: '' }, { produkt: P.k12, personer: '14' }, { produkt: P.k8 }, { produkt: 999999 }], dager: ['fredag'] },
  { navn: 'Møtemat', type: 'engang', sortering: '5', per_personer: '0', linjer: [{ produkt: P.bolle, antall: '1' }] },
  { navn: '', type: 'engang', aktiv: '1' },
  { navn: 'Fredagskos', type: 'tull', aktiv: '1', linjer: [{ produkt: P.kladd, antall: '1' }, { produkt: P.prislos, antall: '1' }] },
];
await lagre({ amendo_endret: { bedriftspakker: '1' }, bedriftspakker: { finnes: '1', pakker: PAKKER } });
t = await tilstand();
let pk = t.pakker || [];
sjekk('pakken uten navn er borte, de andre i samme rekkefølge', pk.length === 4 && pk.map(p => p.navn).join() === 'Fredagskos,Kakeabonnement,Møtemat,Fredagskos', pk.map(p => p.navn));
sjekk('ID fra navnet, unik (fredagskos-2), uten æøå', pk.map(p => p.id).join() === 'fredagskos,kakeabonnement,motemat,fredagskos-2', pk.map(p => p.id));
sjekk('linjene: ukjent produkt og antall 0 fjernet, «1,5» = 1.5; per_personer', JSON.stringify(pk[0]?.linjer) === JSON.stringify([{ produkt: P.bolle, antall: 20 }, { produkt: P.kaffe, antall: 1.5 }]) && pk[0]?.per_personer === 10, pk[0]);
sjekk('fast: dagene (med ø) og hyppighetene bare de kjente, i ukens rekkefølge; ingen kaker', pk[0]?.dager.join() === 'torsdag,fredag,lørdag' && pk[0]?.hyppigheter.join() === 'ukentlig,annenhver_uke' && pk[0]?.kaker.length === 0, pk[0]);
sjekk('teksten uten HTML, sortering, på, bilde-URL (ID 0 utenfor mediebiblioteket)', pk[0]?.tekst === 'Boller og kaffe hver fredag' && pk[0]?.sortering === 20 && pk[0]?.aktiv === true && pk[0]?.bilde === 'https://cdn.example/fredag.jpg' && pk[0]?.bilde_id === 0, pk[0]);
sjekk('bursdag: kakene med personer (tomt = null), uten dobbel og ukjent; ingen dager', JSON.stringify(pk[1]?.kaker) === JSON.stringify([{ produkt: P.k8, personer: null }, { produkt: P.k12, personer: 14 }]) && pk[1]?.dager.length === 0, pk[1]);
sjekk('av når ikke krysset av; per_personer minst 1; ukjent type = engang; sortering fra plassen', pk[2]?.aktiv === false && pk[2]?.per_personer === 1 && pk[3]?.type === 'engang' && pk[3]?.sortering === 50, [pk[2], pk[3]?.type, pk[3]?.sortering]);

const medId = pk.map((p, i) => ({ ...PAKKER[[0, 1, 2, 4][i]], id: p.id }));
medId[0].navn = 'Fredagskos stor';
await lagre({ amendo_endret: { bedriftspakker: '1' }, bedriftspakker: { finnes: '1', pakker: medId } });
t = await tilstand();
sjekk('nytt navn: ID-en står (fredagskos)', t.pakker?.[0]?.id === 'fredagskos' && t.pakker[0].navn === 'Fredagskos stor', t.pakker?.[0]);
medId[0].navn = 'Fredagskos';
await lagre({ amendo_endret: { bedriftspakker: '1' }, bedriftspakker: { finnes: '1', pakker: [{ navn: 'Fredagskos', type: 'engang' }, ...medId] } });
t = await tilstand();
sjekk('ny pakke øverst med samme navn: de gamle beholder ID-en, den nye får en ledig', t.pakker?.map(p => p.id).join() === 'fredagskos-3,fredagskos,kakeabonnement,motemat,fredagskos-2', t.pakker?.map(p => p.id));
await lagre({ amendo_endret: { bedriftspakker: '1' }, bedriftspakker: { finnes: '1', pakker: medId } });

await lagre({ amendo_endret: { bedriftspakker: '1' }, bedriftspakker: { pakker: [] } });
await lagre({ amendo_endret: { bedriftspakker: '' }, bedriftspakker: { finnes: '1', pakker: [] } });
t = await tilstand();
sjekk('uten markør, eller urørt i nettleseren: pakkene står', t.pakker?.length === 4, t.pakker?.length);
await lagre({ amendo_endret: { bedriftspakker: '1' }, bedriftspakker: { finnes: '1', pakker: [...medId, { navn: 'Butikksjef', type: 'engang' }] } }, 'shop_manager');
t = await tilstand();
sjekk('butikkadministrator (manage_woocommerce) kan lagre', t.pakker?.length === 5 && t.pakker[4].navn === 'Butikksjef', t.pakker?.length);
await lagre({ amendo_endret: { bedriftspakker: '1' }, bedriftspakker: { finnes: '1', pakker: medId } });

// ── Admin: advarslene ───────────────────────────────────────────────────────
console.log('\nAdvarsler i admin');
const advarsler = async () => {
  const h = await side();
  const adv = h.includes('id="bp-advarsler"') ? h.slice(h.indexOf('id="bp-advarsler"'), h.indexOf('id="bp-liste"')) : '';
  return { h, a: pid => (adv.match(new RegExp(`data-produkt="${pid}">([^<]*)<`)) || [])[1] || '' };
};
let { h: side1, a } = await advarsler();
sjekk('Kaffe og «12 personer» mangler tellix_id, og ingen reserve', a(P.kaffe).includes('mangler tellix_id') && a(P.kaffe).includes('reserveprodukt') && a(P.k12).includes('Sjokoladekake – 12 personer') && a(P.k12).includes('mangler tellix_id'), [a(P.kaffe), a(P.k12)]);
sjekk('kladden er ikke publisert, den prisløse har ingen pris', a(P.kladd).includes('ikke publisert') && a(P.prislos).includes('ingen pris'), [a(P.kladd), a(P.prislos)]);
sjekk('ingen advarsel for Bolle og «8 personer» (har tellix_id)', a(P.bolle) === '' && a(P.k8) === '');
sjekk('produktvelgerne viser det valgte produktet, kakene med antall personer', side1.includes(`<option value="${P.bolle}" selected>`) && side1.includes('class="wc-product-search bp-produkt" name="bedriftspakker[pakker][0][linjer][0][produkt]"') && side1.includes('name="bedriftspakker[pakker][1][kaker][1][personer]" value="14"'));
await reserve({ drikke: '9001' });
({ a } = await advarsler());
sjekk('reserve på overkategorien til Kaffe (arves): ingen advarsel for Kaffe, «12 personer» står', a(P.kaffe) === '' && a(P.k12).includes('mangler tellix_id'), [a(P.kaffe), a(P.k12)]);
await reserve({ drikke: null, standard: '9000' });
let side2;
({ h: side2, a } = await advarsler());
sjekk('standard reserveprodukt: ingen tellix_id-advarsler, de andre står', !side2.includes('mangler tellix_id') && a(P.kladd).includes('ikke publisert'));
await reserve({ standard: null });

// ── GET /bedriftspakker ─────────────────────────────────────────────────────
console.log('\nGET /bedriftspakker');
r = await hent();
const pakker = r.json?.pakker || [];
sjekk('bare de som er på, sortert på sortering', pakker.map(p => p.id).join() === 'kakeabonnement,fredagskos,fredagskos-2', pakker.map(p => p.id));
const fk = pakker.find(p => p.id === 'fredagskos') || {};
sjekk('pakken: id, navn, tekst, bilde (URL), sortering, aktiv, type, per_personer', fk.navn === 'Fredagskos' && fk.tekst === 'Boller og kaffe hver fredag' && fk.bilde === 'https://cdn.example/fredag.jpg' && fk.sortering === 20 && fk.aktiv === true && fk.type === 'fast' && fk.per_personer === 10, fk);
sjekk('linjene: produkt_id, variant_id, navn, antall, pris_eks, pris_inkl', JSON.stringify(fk.linjer) === JSON.stringify([
  { produkt_id: P.bolle, variant_id: null, navn: 'Bolle', antall: 20, pris_eks: 20, pris_inkl: 25 },
  { produkt_id: P.kaffe, variant_id: null, navn: 'Kaffe', antall: 1.5, pris_eks: 40, pris_inkl: 50 },
]), fk.linjer);
sjekk('pris_eks og pris_inkl = Σ antall × pris (20 × 20 + 1,5 × 40 = 460)', fk.pris_eks === 460 && fk.pris_inkl === 575, [fk.pris_eks, fk.pris_inkl]);
sjekk('dager med ø; hyppigheter { nokkel, navn, per_maned }; ingen kaker; ikke ufullstendig', JSON.stringify(fk.dager) === '["torsdag","fredag","lørdag"]' && JSON.stringify(fk.hyppigheter) === JSON.stringify([{ nokkel: 'ukentlig', navn: 'Ukentlig', per_maned: 4.33 }, { nokkel: 'annenhver_uke', navn: 'Annenhver uke', per_maned: 2.17 }]) && fk.kaker?.length === 0 && fk.ufullstendig === false, [fk.dager, fk.hyppigheter]);
const ka = pakker.find(p => p.id === 'kakeabonnement') || {};
sjekk('bursdag: kakene { produkt_id (hovedproduktet), variant_id, navn, storrelse, personer, pris_eks, pris_inkl }', JSON.stringify(ka.kaker) === JSON.stringify([
  { produkt_id: P.kake, variant_id: P.k8, navn: 'Sjokoladekake', storrelse: '8 personer', personer: 8, pris_eks: 320, pris_inkl: 400 },
  { produkt_id: P.kake, variant_id: P.k12, navn: 'Sjokoladekake', storrelse: '12 personer', personer: 14, pris_eks: 440, pris_inkl: 550 },
]), ka.kaker);
sjekk('personer: fra størrelsen når admin-feltet er tomt, ellers admin-feltet (14)', ka.kaker?.[0]?.personer === 8 && ka.kaker?.[1]?.personer === 14);
sjekk('bursdag uten linjer: pris 0, bilde \'\', ingen dager eller hyppigheter', ka.linjer?.length === 0 && ka.pris_eks === 0 && ka.bilde === '' && ka.dager?.length === 0 && ka.hyppigheter?.length === 0, ka);
const f2 = pakker.find(p => p.id === 'fredagskos-2') || {};
sjekk('kladd og produkt uten pris er utelatt, pakken er ufullstendig', f2.linjer?.length === 0 && f2.ufullstendig === true, f2);
sjekk('ingen tellix_id, e-post eller kundedata i svaret', !/tellix|T-BOLLE|@|bilde_id/.test(r.tekst), r.tekst.slice(0, 300));
await pris(P.bolle, '30');
r = await hent();
let l = r.json.pakker.find(p => p.id === 'fredagskos').linjer[0];
sjekk('ny pris i WooCommerce gjelder med en gang', l.pris_inkl === 30 && l.pris_eks === 24, l);
await pris(P.bolle, '30', '20');
r = await hent();
l = r.json.pakker.find(p => p.id === 'fredagskos').linjer[0];
sjekk('tilbudspris gjelder', l.pris_inkl === 20 && l.pris_eks === 16, l);
await pris(P.bolle, '25');

// ── Nivåer (1.4.33) ─────────────────────────────────────────────────────────
console.log('\nNivåer');
const lagreNivaer = nivaer => lagre({ amendo_endret: { bedriftspakker: '1' }, bedriftspakker: { finnes: '1', pakker: medId.map((p, i) => (i === 0 ? { ...p, nivaer } : p)) } });
sjekk('uten nivåer: nivaer [] i GET, pakken ellers som før', (await hent()).json.pakker.every(p => Array.isArray(p.nivaer) && p.nivaer.length === 0));
await lagreNivaer([
  { navn: 'Liten', tekst: 'Til små møter <i>x</i>', per_personer: '5', linjer: [{ produkt: P.bolle, antall: '10' }, { produkt: 999999, antall: '1' }] },
  { navn: 'Stor', per_personer: '20', linjer: [{ produkt: P.bolle, antall: '40' }, { produkt: P.kaffe, antall: '3' }, { produkt: P.kladd, antall: '1' }] },
  { navn: '', per_personer: '3' },
  { navn: 'Liten' },
]);
t = await tilstand();
let nv = t.pakker?.[0]?.nivaer || [];
sjekk('lagret: nivået uten navn er borte, nøkkel fra navnet og unik (liten-2)', nv.map(n => n.nokkel).join() === 'liten,stor,liten-2', nv.map(n => n.nokkel));
sjekk('teksten uten HTML, per_personer, linjene renset som pakkens', nv[0]?.tekst === 'Til små møter x' && nv[0]?.per_personer === 5 && JSON.stringify(nv[0]?.linjer) === JSON.stringify([{ produkt: P.bolle, antall: 10 }]) && nv[2]?.per_personer === 1, nv[0]);
await lagreNivaer([
  { navn: 'Stor', per_personer: '20' },
  { nokkel: 'liten', navn: 'Liten pluss', per_personer: '5' },
  { nokkel: 'stor', navn: 'Stor', per_personer: '20' },
]);
t = await tilstand();
nv = t.pakker?.[0]?.nivaer || [];
sjekk('nytt navn: nøkkelen står; nytt nivå øverst med samme navn får en ledig', nv.map(n => n.nokkel).join() === 'stor-2,liten,stor' && nv[1]?.navn === 'Liten pluss', nv.map(n => [n.nokkel, n.navn]));
const NIVAER = [
  { nokkel: 'liten', navn: 'Liten', tekst: 'Til små møter', per_personer: '5', linjer: [{ produkt: P.bolle, antall: '10' }] },
  { nokkel: 'stor', navn: 'Stor', tekst: '', per_personer: '20', linjer: [{ produkt: P.bolle, antall: '40' }, { produkt: P.kaffe, antall: '3' }, { produkt: P.kladd, antall: '1' }] },
];
await lagreNivaer(NIVAER);
medId[0] = { ...medId[0], nivaer: NIVAER };
html = await side();
sjekk('admin: nivåene med navn, oppskrift og mal', html.includes('name="bedriftspakker[pakker][0][nivaer][1][navn]" value="Stor"') && html.includes('data-prefiks="bedriftspakker[pakker][0][nivaer][1][linjer]"') && html.includes('name="bedriftspakker[pakker][0][nivaer][1][linjer][1][produkt]"') && html.includes('id="bp-niva-mal"'));
r = await hent();
const fkn = r.json.pakker.find(p => p.id === 'fredagskos') || {};
sjekk('GET: nivaer { nokkel, navn, tekst, per_personer, linjer, pris_eks, pris_inkl }', JSON.stringify(fkn.nivaer) === JSON.stringify([
  { nokkel: 'liten', navn: 'Liten', tekst: 'Til små møter', per_personer: 5, linjer: [{ produkt_id: P.bolle, variant_id: null, navn: 'Bolle', antall: 10, pris_eks: 20, pris_inkl: 25 }], pris_eks: 200, pris_inkl: 250 },
  { nokkel: 'stor', navn: 'Stor', tekst: '', per_personer: 20, linjer: [
    { produkt_id: P.bolle, variant_id: null, navn: 'Bolle', antall: 40, pris_eks: 20, pris_inkl: 25 },
    { produkt_id: P.kaffe, variant_id: null, navn: 'Kaffe', antall: 3, pris_eks: 40, pris_inkl: 50 }], pris_eks: 920, pris_inkl: 1150 },
]), fkn.nivaer);
sjekk('pakkens egen oppskrift står; kladden i et nivå gjør pakken ufullstendig', fkn.pris_eks === 460 && fkn.linjer?.length === 2 && fkn.ufullstendig === true, [fkn.pris_eks, fkn.ufullstendig]);

// ── Søknaden ────────────────────────────────────────────────────────────────
console.log('\nSøknaden: feil (ingenting lagres)');
const feil = async (navn, ekstra, kode = 'amendo_b2b_soknad_ugyldig_pakke') => {
  const svar = await soknad({ bruker_id: id('feil'), org_nr: '911222333', ...ekstra });
  const b = (await tilstand([id('feil')])).brukere[id('feil')];
  sjekk(navn + ': 400, og verken søknad eller pakke lagret', svar.status === 400 && svar.json?.code === kode && b.status === '' && b.onske === '' && b.ref === '', [svar.status, svar.json, b]);
};
const pakke = (pakke_id, ekstra = {}) => ({ pakker: [{ pakke_id, ...ekstra }] });
await feil('ukjent pakke_id', pakke('finnes-ikke'));
await feil('dag pakken ikke har', pakke('fredagskos', { dag: 'mandag' }));
await feil('hyppighet pakken ikke har', pakke('fredagskos', { hyppighet: 'manedlig' }));
await feil('dag på en pakke som ikke er fast', pakke('motemat', { dag: 'fredag' }));
await feil('kake på en pakke som ikke er bursdag', pakke('fredagskos', { kake_produkt_id: P.kake, kake_variant_id: P.k8 }));
await feil('variant som ikke er i pakkens liste', pakke('kakeabonnement', { kake_produkt_id: P.kake, kake_variant_id: 999999 }));
await feil('variant som ikke hører til produktet', pakke('kakeabonnement', { kake_produkt_id: P.bolle, kake_variant_id: P.k8 }));
await feil('produkt utenfor lista, uten variant', pakke('kakeabonnement', { kake_produkt_id: P.bolle, kake_variant_id: null }));
await feil('antall_personer 0', pakke('motemat', { antall_personer: 0 }));
await feil('antall_personer som tekst', pakke('motemat', { antall_personer: 'mange' }));
await feil('antall_personer over 10 000', pakke('motemat', { antall_personer: 10001 }));
await feil('pakker som tekst', { pakker: 'fredagskos' });
await feil('pakker som ett objekt, ikke en liste', { pakker: { pakke_id: 'motemat' } });
await feil('21 pakker', { pakker: Array.from({ length: 21 }, () => ({ pakke_id: 'motemat' })) });
await feil('nivå som pakken ikke har', pakke('fredagskos', { niva: 'mega' }));
await feil('nivå på en pakke uten nivåer', pakke('motemat', { niva: 'liten' }));
await feil('nivå som ikke er tekst', pakke('fredagskos', { niva: ['stor'] }));
await feil('ugyldig selgerkode', { ref: '<script>' }, 'amendo_b2b_soknad_ugyldig_ref');
await feil('selgerkode over 40 tegn', { ref: 'a'.repeat(41) }, 'amendo_b2b_soknad_ugyldig_ref');

console.log('\nSøknaden: lagres');
r = await soknad({ bruker_id: id('ok'), org_nr: '911 222 333', ref: 'ola-12', pakker: [{ pakke_id: 'fredagskos', navn: 'Fredagskos (fra nettsiden)', type: 'engang', niva: 'stor', antall_personer: 25, dag: 'fredag', hyppighet: 'annenhver_uke', pris_eks_per_levering: 1150, anslag_eks_per_maned: 2491.67, fra_eks_per_person: null }] });
const ONSKE_OK = { pakke_id: 'fredagskos', navn: 'Fredagskos', type: 'fast', niva: 'stor', niva_navn: 'Stor', antall_personer: 25, kake_produkt_id: null, kake_variant_id: null, kake_navn: '', dag: 'fredag', hyppighet: 'annenhver_uke',
  nettsiden: { navn: 'Fredagskos (fra nettsiden)', kake_navn: '', pris_eks_per_levering: 1150, anslag_eks_per_maned: 2491.67, fra_eks_per_person: null } };
sjekk('svaret: ok, status og org_nr som før, pluss pakker og ref slik de ble lagret', r.status === 200 && r.json?.ok === true && r.json.status === 'venter' && r.json.org_nr === '911222333' && JSON.stringify(r.json.pakker) === JSON.stringify([ONSKE_OK]) && r.json.ref === 'ola-12', r.tekst);
let u = (await tilstand([id('ok')])).brukere[id('ok')];
sjekk('på brukeren: navn og type fra VÅR pakke, nettsidens navn og anslag under «nettsiden»', JSON.stringify(u.onske) === JSON.stringify([ONSKE_OK]), u.onske);
sjekk('selgerkoden, tidspunktet og søknaden', u.ref === 'ola-12' && /^\d{4}-\d\d-\d\dT/.test(u.tid) && u.status === 'venter', u);
r = await soknad({ bruker_id: id('kake'), org_nr: '911222333', pakker: [{ pakke_id: 'kakeabonnement', antall_personer: '40', kake_produkt_id: P.kake, kake_variant_id: String(P.k12), kake_navn: 'Hva som helst', anslag_eks_per_maned: 1466.67 }] });
u = (await tilstand([id('kake')])).brukere[id('kake')];
sjekk('bursdag: kaken fra vår liste, kakenavnet vårt, nettsidens under «nettsiden»', r.status === 200 && u.onske?.[0]?.kake_produkt_id === P.kake && u.onske[0].kake_variant_id === P.k12 && u.onske[0].kake_navn === 'Sjokoladekake – 12 personer' && u.onske[0].nettsiden.kake_navn === 'Hva som helst' && u.onske[0].antall_personer === 40 && u.ref === '', [r.status, u]);
r = await soknad({ bruker_id: id('enkel'), org_nr: '911222333', pakker: [{ pakke_id: 'motemat', antall_personer: 12, fra_eks_per_person: 'mye', pris_eks_per_levering: -5, navn: '<b>Møtemat</b>' }] });
u = (await tilstand([id('enkel')])).brukere[id('enkel')];
sjekk('pakke som er av godtas; uten nivå = \'\'; anslag som ikke er tall blir null, navnet uten HTML', r.status === 200 && u.onske?.[0]?.pakke_id === 'motemat' && u.onske[0].niva === '' && u.onske[0].niva_navn === '' && u.onske[0].nettsiden.fra_eks_per_person === null && u.onske[0].nettsiden.pris_eks_per_levering === null && u.onske[0].nettsiden.navn === 'Møtemat', [r.status, u]);
r = await soknad({ bruker_id: id('ref'), org_nr: '911222333', ref: ' Kari Selger ' });
u = (await tilstand([id('ref')])).brukere[id('ref')];
sjekk('bare selgerkode (trimmet): svaret har pakker [] og ref', r.status === 200 && JSON.stringify(r.json?.pakker) === '[]' && r.json?.ref === 'Kari Selger' && u.ref === 'Kari Selger' && u.onske === '', [r.json, u]);
r = await soknad({ bruker_id: id('tom'), org_nr: '911222333' });
u = (await tilstand([id('tom')])).brukere[id('tom')];
sjekk('uten pakker og ref: svaret har pakker [] og ref \'\', ingenting lagret', r.tekst === '{"ok":true,"status":"venter","org_nr":"911222333","pakker":[],"ref":""}' && u.onske === '' && u.ref === '' && u.tid === '', [r.tekst, u]);
await soknad({ bruker_id: id('ok'), org_nr: '911222333' });
r = await soknad({ bruker_id: id('ok'), org_nr: '911222333', ref: 'PER-1' });
sjekk('nytt kall uten pakker beholder pakken; ny ref erstatter den gamle', r.json?.pakker?.[0]?.pakke_id === 'fredagskos' && r.json.ref === 'PER-1', r.json);

// ── Visning ─────────────────────────────────────────────────────────────────
console.log('\nSøknader og brukerprofilen');
html = await side();
const soknader = html.slice(html.indexOf('id="b2b-soknader"'), html.indexOf('id="b2b-kunder"'));
const rad = n => { const i = soknader.indexOf(`data-bruker="${id(n)}"`); return i < 0 ? '' : soknader.slice(i, soknader.indexOf('</tr>', i)); };
sjekk('kolonnen Pakke i Søknader', soknader.includes('<th>Pakke</th>'));
sjekk('pakken med personer, dag, hyppighet og anslaget, og selgerkoden', rad('ok').includes('Fredagskos – Stor · 25 personer · fredag · annenhver uke · anslag fra nettsiden ca. 2 492 kr/mnd eks. mva') && rad('ok').includes('Selgerkode: PER-1'), rad('ok'));
sjekk('kaken', rad('kake').includes('Kakeabonnement · 40 personer · Sjokoladekake – 12 personer · anslag fra nettsiden ca. 1 467 kr/mnd eks. mva'), rad('kake'));
sjekk('uten pakke: «—»', rad('tom').includes('<td class="b2b-pakke">—</td>'), rad('tom'));
const p1 = await profil(id('ok'));
sjekk('brukerprofilen (admin): pakken, selgerkoden, når, og at anslagene ikke er avtalt', p1.includes('Bedriftspakke fra søknaden') && p1.includes('Fredagskos – Stor · 25 personer') && p1.includes('Selgerkode: PER-1') && p1.includes('Sendt ') && p1.includes('ikke avtalte priser'), p1.slice(0, 400));
sjekk('brukerprofilen uten pakke: ingen seksjon', !(await profil(id('tom'))).includes('Bedriftspakke'));
sjekk('kunden selv ser den ikke', !(await profil(id('ok'), 'customer')).includes('Bedriftspakke'));

// ── admin.js i jsdom ────────────────────────────────────────────────────────
console.log('\nadmin.js i jsdom');
const dom = new JSDOM(await side(), { url: 'http://localhost/wp-admin/admin.php?page=amendo-settings#bedriftspakker', runScripts: 'outside-only' });
const w = dom.window;
w.confirm = () => true;
w.eval(readFileSync(JQUERY, 'utf8'));
w.eval('window.jQuery = jQuery.noConflict(true);');
let produktsok = 0;
w.jQuery(w.document.body).on('wc-enhanced-select-init', () => { produktsok++; });
w.eval(readFileSync(ADMIN_JS, 'utf8'));
await new Promise(res => w.jQuery(res));
const d = w.document;
sjekk('#bedriftspakker i adressen åpner fanen', d.querySelector('#tab-bedriftspakker').classList.contains('active'));
const navnFør = new Set([...d.querySelectorAll('#bp-liste [name]')].map(e => e.name));
d.querySelector('#bp-legg-til').click();
const ny = [...d.querySelectorAll('#bp-liste .bp-pakke')].at(-1);
const nr = ny.dataset.p;
sjekk('ny pakke fra malen, med et nummer ingen andre har', /^\d+$/.test(nr) && !!ny.querySelector(`input[name="bedriftspakker[pakker][${nr}][navn]"]`) && ![...ny.querySelectorAll('[name]')].some(e => navnFør.has(e.name)), nr);
sjekk('ny pakke: på, type engang, 10 personer, dager og kaker skjult', ny.querySelector('input[name$="[aktiv]"]').checked && ny.querySelector('.bp-type').value === 'engang' && ny.querySelector('input[name$="[per_personer]"]').value === '10' && ny.querySelector('.bp-bare-fast').hidden && ny.querySelector('.bp-bare-bursdag').hidden);
sjekk('malene er ikke en del av skjemaet', !d.querySelector('form [name*="__p__"]'));
sjekk('delen er merket som endret', d.querySelector('#bedriftspakker > .amendo-endret').value === '1');
ny.querySelector('.bp-legg-til-linje').click();
const linje = ny.querySelector('.bp-linjer select');
sjekk('ny linje i den nye pakken', !!linje && new RegExp(`^bedriftspakker\\[pakker\\]\\[${nr}\\]\\[linjer\\]\\[\\d+\\]\\[produkt\\]$`).test(linje.name), linje?.name);
const type = ny.querySelector('.bp-type');
type.value = 'fast'; type.dispatchEvent(new w.Event('change', { bubbles: true }));
sjekk('type fast: dager og hyppigheter vises, med Lørdag', !ny.querySelector('.bp-bare-fast').hidden && ny.querySelector('.bp-bare-bursdag').hidden && !!ny.querySelector('input[value="lørdag"]'));
type.value = 'bursdag'; type.dispatchEvent(new w.Event('change', { bubbles: true }));
sjekk('type bursdag: kakene vises', ny.querySelector('.bp-bare-fast').hidden && !ny.querySelector('.bp-bare-bursdag').hidden);
ny.querySelector('.bp-legg-til-kake').click();
const kake = ny.querySelector('.bp-kaker select'), kakePers = ny.querySelector('.bp-kaker input[name$="[personer]"]');
sjekk('ny kake med produktvelger og antall personer', !!kake && new RegExp(`^bedriftspakker\\[pakker\\]\\[${nr}\\]\\[kaker\\]\\[\\d+\\]\\[produkt\\]$`).test(kake.name) && !!kakePers, kake?.name);
sjekk('produktsøket startes for de nye feltene', produktsok >= 3, produktsok);
const navn = ny.querySelector('input[name$="[navn]"]');
navn.value = 'Lunsj'; navn.dispatchEvent(new w.Event('input', { bubbles: true }));
sjekk('tittelen følger navnet', ny.querySelector('.bp-tittel').textContent === 'Lunsj');
ny.querySelector('.bp-legg-til-niva').click();
const niva = ny.querySelector('.bp-nivaer .bp-niva');
const nivaNavn = niva?.querySelector('input[name$="[navn]"]');
sjekk('nytt nivå i den nye pakken', !!nivaNavn && new RegExp(`^bedriftspakker\\[pakker\\]\\[${nr}\\]\\[nivaer\\]\\[\\d+\\]\\[navn\\]$`).test(nivaNavn.name), nivaNavn?.name);
const egneFør = ny.querySelectorAll(':scope .avdeling-body > .amendo-field > .bp-oppskrift > .bp-linjer > .bp-linje').length;
niva.querySelector('.bp-legg-til-linje').click();
const nivaLinje = niva.querySelector('.bp-linjer select');
sjekk('ny linje i nivået havner i nivåets oppskrift, ikke pakkens', !!nivaLinje && new RegExp(`^bedriftspakker\\[pakker\\]\\[${nr}\\]\\[nivaer\\]\\[\\d+\\]\\[linjer\\]\\[\\d+\\]\\[produkt\\]$`).test(nivaLinje.name) && ny.querySelectorAll(':scope .avdeling-body > .amendo-field > .bp-oppskrift > .bp-linjer > .bp-linje').length === egneFør, nivaLinje?.name);
niva.querySelector('.bp-slett-niva').click();
sjekk('slett nivå', !ny.querySelector('.bp-niva'));
ny.querySelector('.bp-slett-kake').click();
ny.querySelector('.bp-slett-linje').click();
sjekk('slett kake og linje', !ny.querySelector('.bp-kaker select') && !ny.querySelector('.bp-linjer select'));
ny.querySelector('.bp-slett-pakke').click();
sjekk('slett pakke', !d.querySelector(`[data-p="${nr}"]`) && d.querySelectorAll('#bp-liste .bp-pakke').length === 4);

// ── Kildekoden ──────────────────────────────────────────────────────────────
console.log('\nKildekoden');
const kilde = readFileSync(resolve(REPO, 'includes/moduler/bedriftspakker.php'), 'utf8');
sjekk('ingen butikknavn i modulen', !/Aanerud|Garçon|Garcon|emmk|Jessheim|Dorothea/i.test(kilde));
sjekk('filteret amendo_bedriftspakker_tellix_reserve er borte', !kilde.includes('amendo_bedriftspakker_tellix_reserve'));

// ── Av igjen ────────────────────────────────────────────────────────────────
console.log('\nAv igjen');
await sett({ amendo_moduler: ['b2b'] });
r = await hent();
sjekk('GET: 404', r.status === 404, r.status);
Object.assign(ider, (await lag([{ epost: E('av2'), meta: {} }])).ider);
r = await soknad({ bruker_id: id('av2'), org_nr: '911222333', pakker: [{ pakke_id: 'finnes-ikke' }] });
sjekk('søknaden: pakker ignoreres igjen, svaret som før', r.status === 200 && r.tekst === VENTER, r.tekst);
t = await tilstand();
sjekk('pakkene ligger igjen til modulen slås på', t.pakker?.length === 4, t.pakker?.length);

// Rydd
await sett({ amendo_moduler: null, amendo_bedriftspakker: null });
await reserve({ drikke: null, standard: null });
ferdig();
