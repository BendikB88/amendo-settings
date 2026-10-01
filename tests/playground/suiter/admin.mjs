// Admin: innstillingen «Skjul betalt frakt», og tilgang (manage_woocommerce).
import { sjekk, ferdig, get } from '../lib/felles.mjs';

await get('/amendo-test/v1/setup');

console.log('Admin');
const adm = await get('/amendo-test/v1/admin');
sjekk('«Skjul betalt frakt» vises avkrysset som standard', adm.felt_vises_avkrysset === true, adm);
sjekk('lagring uten avkrysning → 0', adm.lagret_av === '0', adm);
sjekk('lagring med avkrysning → 1', adm.lagret_paa === '1', adm);
sjekk('kunde har ikke tilgang', adm.kunde_har_tilgang === false, adm);
sjekk('butikkadministrator har tilgang', adm.shop_manager_har_tilgang === true, adm);

ferdig();
