// 1.4.43: org.nr. på ordren fra WooCommerce sin egen kasse for butikker uten
// frontend. En godkjent bedrift (`billing_org_number` med ni siffer på kunden)
// får nummeret kopiert til `_billing_org_number` når ordren lages i den EKTE
// klassiske kassen (WC_Checkout::create_order) og i Store API-kassen
// (kroken woocommerce_store_api_checkout_update_order_from_request), og det
// vises i fakturaadressen som før (1.4.28). Aldri overskrevet, ikke for
// gjester, søknader som venter, private, ugyldige numre, ordrer uten kasse,
// med Frontend-URL eller B2B av. Rutene i mu/v1443-test.php. Se
// includes/moduler/b2b-orgnr-ordre.php.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { REPO, get, sjekk, ferdig } from '../lib/felles.mjs';

const opt = o => get('/amendo-test/v1/opt', o);
const klargjor = body => get('/amendo-test/v1/v1414-klargjor', body);
const bruker = (epost, meta) => get('/amendo-test/v1/bedrift-bruker', { epost, meta });
const kasse = (epost, forhand = '') => get('/amendo-test/v1/v1443-kasse', { epost, forhand });
const storeapi = (epost, forhand = '') => get('/amendo-test/v1/v1443-storeapi', { epost, forhand });
const utenKasse = epost => get('/amendo-test/v1/v1443-uten-kasse', { epost });
const E = n => `${n}@ok1443.no`;

await get('/amendo-test/v1/setup');
await opt({ amendo_frontend_url: '', woocommerce_bacs_settings: { enabled: 'yes' } });
await klargjor({ moduler: ['b2b'] });
await bruker(E('bedrift'), { _b2b_status: 'godkjent', billing_org_number: '911 222 333', billing_company: 'Bedrift AS' });
await bruker(E('venter'), { _b2b_status: 'venter', _orgnr_soknad: '911222444' });
await bruker(E('privat'), {});
await bruker(E('kort'), { _b2b_status: 'godkjent', billing_org_number: '12345' });

// ── Den klassiske kassen ────────────────────────────────────────────────────
console.log('Den klassiske kassen (WC_Checkout::create_order)');
let r = await kasse(E('bedrift'));
sjekk('godkjent bedrift: org.nr. (ni siffer, uten mellomrom) på ordren', r.id > 0 && r.orgnr === '911222333' && r.kunde > 0, r);
sjekk('… og i fakturaadressen under firmanavnet', /Bedrift AS|Testfirma AS/.test(r.adresse || '') && (r.adresse || '').includes('Org.nr. 911 222 333'), r.adresse);
r = await kasse(E('bedrift'), '999888777');
sjekk('nummer som alt står på ordren: overskrives ikke', r.orgnr === '999888777', r);
r = await kasse(E('venter'));
sjekk('søknad som venter: ingenting (bare godkjente)', r.id > 0 && r.orgnr === '', r);
r = await kasse(E('privat'));
sjekk('privat kunde: ingenting', r.id > 0 && r.orgnr === '' && !(r.adresse || '').includes('Org.nr.'), r);
r = await kasse(E('kort'));
sjekk('ugyldig nummer på kunden (ikke ni siffer): ingenting', r.orgnr === '', r);
r = await kasse(null);
sjekk('gjest: ingenting', r.id > 0 && r.kunde === 0 && r.orgnr === '', r);

// ── Store API og ordrer uten kasse ──────────────────────────────────────────
console.log('\nStore API-kassen og ordrer uten kasse');
r = await storeapi(E('bedrift'));
sjekk('Store API: godkjent bedrift får nummeret', r.orgnr === '911222333', r);
r = await storeapi(E('bedrift'), '999888777');
sjekk('Store API: eksisterende nummer står', r.orgnr === '999888777', r);
r = await storeapi(E('privat'));
sjekk('Store API: privat, ingenting', r.orgnr === '', r);
r = await utenKasse(E('bedrift'));
sjekk('ordre uten kasse (wp-admin, wc/v3): urørt', r.orgnr === '', r);

// ── Ingen endring ───────────────────────────────────────────────────────────
console.log('\nIngen endring med Frontend-URL eller B2B av');
await opt({ amendo_frontend_url: 'https://front.example.no' });
r = await kasse(E('bedrift'));
sjekk('Frontend-URL satt: kassen skriver ikke nummeret', r.id > 0 && r.orgnr === '', r);
r = await storeapi(E('bedrift'));
sjekk('Frontend-URL satt: Store API heller ikke', r.orgnr === '', r);
await opt({ amendo_frontend_url: '' });
await klargjor({ moduler: [] });
r = await kasse(E('bedrift'));
sjekk('B2B av: ingenting', r.id > 0 && r.orgnr === '', r);
await klargjor({ moduler: ['b2b'] });

const kilde = readFileSync(resolve(REPO, 'includes/moduler/b2b-orgnr-ordre.php'), 'utf8');
sjekk('ingen butikknavn eller logging', !/Haugli|Aanerud|emmk/i.test(kilde.replace(/Garçon-økta/g, '')) && !/error_log|wc_get_logger/.test(kilde));

await opt({ woocommerce_bacs_settings: null, amendo_frontend_url: null });
ferdig();
