// 1.4.27: faktura uten kundegruppe. rabatt/kunde svarer `faktura`, sperren
// «Ikke faktura» (_amendo_ingen_faktura) på brukerprofilen og i B2B → Aktive
// B2B-kunder, og godkjenning uten gruppevalg. Mot Tellix-stubben i
// mu/tellix-stub.php. Se amendo_faktura_for_bruker() i
// includes/moduler/b2b-tellix.php.
import { B, get, sjekk, ferdig } from '../lib/felles.mjs';

const SECRET = 'faktura-hemmelig-1427';
const klargjor = body => get('/amendo-test/v1/v1414-klargjor', body);
const opt = o => get('/amendo-test/v1/opt', o);
const bruker = (epost, meta) => get('/amendo-test/v1/bedrift-bruker', { epost, meta });
const meta = async epost => (await fetch(B + '/amendo-test/v1/v1427-meta&epost=' + encodeURIComponent(epost))).json();
const knapp = body => get('/amendo-test/v1/v1427-knapp', body);
const profil = body => get('/amendo-test/v1/v1427-profil', body);
const side = async () => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=admin')).text();
const rute = async (sti, secret = SECRET) => {
  const r = await fetch(B + '/amendo-settings/v1/' + sti, { headers: secret === null ? {} : { 'X-Amendo-Secret': secret } });
  const tekst = await r.text();
  let json = null; try { json = JSON.parse(tekst); } catch {}
  return { status: r.status, json, tekst };
};
const kunde = async epost => rute('rabatt/kunde/' + (await meta(epost)).id);

await get('/amendo-test/v1/setup');
const GRUPPER = [
  { id: 14, name: 'Bedriftskunder', discountPercent: 10 },
  { id: 4, name: 'NAV Lillestrøm', discountPercent: 40 },
  { id: 7, name: 'Uten innstilling', discountPercent: 5 },
];
const KUNDER = [
  { id: 'T1', uuid: 'U1', groupId: 14, email: 'gruppe14@f1427.no', name: 'Gruppe Fjorten' },
  { id: 'T2', uuid: 'U2', groupId: 0, email: 'godkjent@f1427.no', name: 'Godkjent AS' },
  // organizationNumber er feltnavnet Tellix bruker (bekreftet 1.4.27).
  { raa: { customerId: 'T3', customerUuid: 'U3', customerGroupId: null, name: 'Orgnr AS', email: 'orgnr@f1427.no', phoneNumber: '', organizationNumber: '923 207 074', isActive: true, isDeleted: false } },
  { id: 'T4', uuid: 'U4', groupId: 0, email: 'privat@f1427.no', name: 'Privat Person' },
  { id: 'T5', uuid: 'U5', groupId: 4, email: 'nav@f1427.no', name: 'NAV Ansatt' },
  { raa: { customerId: 'T6', customerUuid: 'U6', customerGroupId: 0, name: 'Kort Orgnr', email: 'kort@f1427.no', phoneNumber: '', organizationNumber: '12345', isActive: true, isDeleted: false } },
  { id: 'T8', uuid: '', groupId: 0, email: 'utenuuid@f1427.no', name: 'Uten UUID AS' },
];
await opt({ amendo_kasse_secret: SECRET });
await klargjor({
  moduler: ['b2b'], tellix: '1', grupper: GRUPPER, kunder: KUNDER, helsebygget: null, store_groups: null, kundegrupper: null, sov: null, kast: null, api_feil: null, side: null,
  kundegrupper_ny: { 14: { gjelder: 'nettbutikk', b2b: 1, levering: 0 }, 4: { gjelder: 'nettbutikk', b2b: 0, levering: 0 }, 7: { gjelder: 'nettbutikk', b2b: 0, levering: 0 } },
  faktura: { enabled: 'yes', enabled_customer_group_14: 'yes', enabled_customer_group_4: 'no' },
  liste_slett: true, nullstill: true,
});
await bruker('gruppe14@f1427.no', {});
await bruker('godkjent@f1427.no', { _b2b_status: 'godkjent', billing_org_number: '988000001', billing_company: 'Godkjent AS' });
await bruker('orgnr@f1427.no', {});
await bruker('privat@f1427.no', {});
await bruker('nav@f1427.no', { _b2b_status: 'godkjent', billing_org_number: '988000005' });
await bruker('kort@f1427.no', {});
await bruker('utenuuid@f1427.no', { _b2b_status: 'godkjent', billing_org_number: '988000008' });
await bruker('ikketellix@f1427.no', { _b2b_status: 'godkjent', billing_org_number: '988000009' });
await bruker('nulluuid@f1427.no', { _b2b_status: 'godkjent', _tellixCustomerUuId: '0' });
await bruker('reserve14@f1427.no', { _tellixGroupId: '14' });
await bruker('venter@f1427.no', { _b2b_status: 'venter' });

// ── rabatt/kunde: faktura ───────────────────────────────────────────────────
console.log('rabatt/kunde: faktura');
let r = await kunde('godkjent@f1427.no');
sjekk('svaret: de gamle feltene, tellix_koblet og faktura', r.status === 200 && JSON.stringify(Object.keys(r.json || {}).sort()) === '["faktura","gruppe_id","gruppe_navn","helsebygget","nettbutikk","tellix_koblet"]', r.tekst);
sjekk('godkjent bedrift, koblet, UTEN kundegruppe: faktura true', r.json?.faktura === true && r.json.tellix_koblet === true && r.json.gruppe_id === null, r.json);
r = await kunde('orgnr@f1427.no');
sjekk('ikke godkjent, men Tellix-kunden har organizationNumber (ni siffer): faktura true', r.json?.faktura === true && r.json.tellix_koblet === true, r.json);
r = await kunde('kort@f1427.no');
sjekk('organizationNumber som ikke er ni siffer: faktura false', r.json?.faktura === false && r.json.tellix_koblet === true, r.json);
r = await kunde('privat@f1427.no');
sjekk('koblet, men verken godkjent bedrift eller org.nr: faktura false', r.json?.faktura === false && r.json.tellix_koblet === true, r.json);
r = await kunde('gruppe14@f1427.no');
sjekk('gruppen har faktura i kundegruppetabellen: faktura true (som før)', r.json?.faktura === true && r.json.gruppe_id === 14, r.json);
r = await kunde('reserve14@f1427.no');
sjekk('… også uten Tellix-kobling (lagret gruppe 14, Tellix finner ikke kunden): som før', r.json?.faktura === true && r.json.tellix_koblet === false && r.json.gruppe_id === 14, r.json);
r = await kunde('nav@f1427.no');
sjekk('gruppe uten faktura (NAV) stopper ikke en godkjent, koblet bedrift: faktura true', r.json?.faktura === true && r.json.gruppe_id === 4, r.json);
r = await kunde('utenuuid@f1427.no');
sjekk('godkjent, men Tellix-kunden har ingen UUID: ikke koblet, faktura false', r.json?.faktura === false && r.json.tellix_koblet === false, r.json);
r = await kunde('ikketellix@f1427.no');
sjekk('godkjent, men ikke i Tellix: faktura false', r.json?.faktura === false && r.json.tellix_koblet === false, r.json);
r = await kunde('nulluuid@f1427.no');
sjekk("godkjent med _tellixCustomerUuId '0': faktura false", r.json?.faktura === false && r.json.tellix_koblet === false, r.json);
r = await kunde('venter@f1427.no');
sjekk('søknad som venter og ikke koblet: faktura false', r.json?.faktura === false, r.json);
r = await rute('rabatt/kunde/999999');
sjekk('ukjent WordPress-bruker: faktura false', r.status === 200 && r.json?.faktura === false && r.json.tellix_koblet === false, r.json);

// ── Sperren «Ikke faktura» ──────────────────────────────────────────────────
console.log('\n«Ikke faktura»');
const godkjent = (await meta('godkjent@f1427.no')).id;
let k = await knapp({ verdi: godkjent + ':1' });
sjekk('Aktive B2B-kunder: «Ikke faktura» settes, tilbake til fanen med beskjed', (await meta('godkjent@f1427.no')).meta._amendo_ingen_faktura === '1' && /b2b=faktura_sperret#b2b$/.test(k.til || ''), k);
r = await kunde('godkjent@f1427.no');
sjekk('… rabatt/kunde: faktura false, resten som før', r.json?.faktura === false && r.json.tellix_koblet === true, r.json);
const g14 = (await meta('gruppe14@f1427.no')).id;
await knapp({ verdi: g14 + ':1' });
r = await kunde('gruppe14@f1427.no');
sjekk('sperren gjelder også når gruppen har faktura', r.json?.faktura === false && r.json.gruppe_id === 14 && r.json.nettbutikk?.prosent === 10, r.json);
await knapp({ verdi: g14 + ':0' });
k = await knapp({ verdi: godkjent + ':0' });
sjekk('… fjernes igjen: faktura true', (await meta('godkjent@f1427.no')).meta._amendo_ingen_faktura === '' && /b2b=faktura_tillatt/.test(k.til || '') && (await kunde('godkjent@f1427.no')).json?.faktura === true, k);
k = await knapp({ verdi: godkjent + ':1', nonce: false });
sjekk('uten nonce: stoppet, ingenting endret', k.stoppet === true && (await meta('godkjent@f1427.no')).meta._amendo_ingen_faktura === '', k);
k = await knapp({ verdi: godkjent + ':1', rolle: 'customer' });
sjekk('kunde-rolle: stoppet, ingenting endret', k.stoppet === true && (await meta('godkjent@f1427.no')).meta._amendo_ingen_faktura === '', k);
k = await knapp({ verdi: godkjent + ':1', rolle: 'shop_manager' });
sjekk('butikkadministrator kan sette den', (await meta('godkjent@f1427.no')).meta._amendo_ingen_faktura === '1', k);
await knapp({ verdi: godkjent + ':0' });
k = await knapp({ verdi: 'abc' });
sjekk('ugyldig verdi: ingenting endret, «Fant ikke brukeren»', /b2b=ukjent_bruker/.test(k.til || ''), k);

let html = await side();
const rad = (epost) => (new RegExp(`<tr class="b2b-kunde">(?:(?!</tr>)[\\s\\S])*?${epost.replace('.', '\\.')}[\\s\\S]*?</tr>`).exec(html) || [])[0] || '';
sjekk('Aktive B2B-kunder: kolonnen Faktura', html.includes('<th>Faktura</th>'));
sjekk('… godkjent bedrift: «Ja – koblet til Tellix og bedrift» og knappen «Ikke faktura»', rad('godkjent@f1427.no').includes('Ja – koblet til Tellix og bedrift') && new RegExp(`name="ingen_faktura" value="${godkjent}:1"`).test(rad('godkjent@f1427.no')), rad('godkjent@f1427.no'));
sjekk('… ikke i Tellix: «Nei – ikke koblet til Tellix»', rad('ikketellix@f1427.no').includes('Nei – ikke koblet til Tellix'), rad('ikketellix@f1427.no'));
sjekk('… eget skjema med nonce for bryteren', html.includes('id="amendo-b2b-faktura"') && html.includes('value="amendo_b2b_faktura"'));
await knapp({ verdi: godkjent + ':1' });
html = await side();
sjekk('… med sperren: «Nei – «Ikke faktura» er satt» og knappen fjerner den', rad('godkjent@f1427.no').includes('Nei – «Ikke faktura» er satt') && new RegExp(`value="${godkjent}:0"[^>]*aria-pressed="true"`).test(rad('godkjent@f1427.no')), rad('godkjent@f1427.no'));
await knapp({ verdi: godkjent + ':0' });

// ── Brukerprofilen ──────────────────────────────────────────────────────────
console.log('\nBrukerprofilen');
let p = await profil({ id: godkjent });
sjekk('feltet «Ikke faktura» med markør og status', p.html.includes('id="amendo-faktura-profil"') && p.html.includes('name="amendo_ingen_faktura"') && p.html.includes('name="amendo_ingen_faktura_finnes"') && p.html.includes('Nå: Ja – koblet til Tellix og bedrift'), p.html.slice(0, 600));
p = await profil({ id: godkjent, post: { amendo_ingen_faktura_finnes: '1', amendo_ingen_faktura: '1' } });
sjekk('avkrysset og lagret: sperret, og vist som avkrysset', p.sperret === '1' && /name="amendo_ingen_faktura" value="1"\s+checked/.test(p.html), p.sperret);
p = await profil({ id: godkjent, post: { first_name: 'Kari' } });
sjekk('lagring uten markøren (annen plugin, annet skjema): urørt', p.sperret === '1');
p = await profil({ id: godkjent, post: { amendo_ingen_faktura_finnes: '1' } });
sjekk('ikke avkrysset: sperren fjernet', p.sperret === '');
p = await profil({ id: 'selv', rolle: 'customer', post: { amendo_ingen_faktura_finnes: '1', amendo_ingen_faktura: '1' } });
sjekk('kunden ser ikke feltet på sin egen profil, og kan ikke sette det', !p.html.includes('amendo_ingen_faktura') && p.sperret === '', p);

// ── Godkjenning uten gruppevalg ─────────────────────────────────────────────
console.log('\nGodkjenning uten kundegruppe');
html = await side();
sjekk('søknader: gruppevalget er valgfritt og styrer ikke faktura', html.includes('Valgfritt. Brukes hvis kunden opprettes i Tellix') && html.includes('Styrer rabatt og levering, ikke faktura.'));
sjekk('Tellix-kobling: forklarer at faktura virker uten kundegruppe', html.includes('Faktura virker når kunden er koblet og er godkjent bedrift (eller har org.nr i Tellix), uten kundegruppe'));

await klargjor({ moduler: null, tellix: null, kundegrupper_ny: null, faktura: null, nullstill: true });
ferdig();
