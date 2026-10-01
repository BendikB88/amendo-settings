// /frakt holder sporingslyttere stille; kasse-secret i admin (lagring, visning én gang, tilgang).
import { sjekk, ferdig, get, klargjorFrakt } from '../lib/felles.mjs';

await klargjorFrakt();

console.log('Sporingslyttere (dummy på alle fem kurvhendelser)');
const st = await get('/amendo-test/v1/stille');
const null5 = o => o && Object.values(o).length === 5 && Object.values(o).every(v => v === 0);
const en5 = o => o && Object.values(o).length === 5 && Object.values(o).every(v => v === 1);
sjekk('beregningen ga svar', st.res_ok === true, st);
sjekk('ingen lytter kalt under beregningen', null5(st.kall_under), st.kall_under);
sjekk('woocommerce_add_cart_item_data (filter) kalt under beregningen', st.filter_kalt_under === 2, st.filter_kalt_under);
sjekk('samme lyttere tilbake etterpå', st.lyttere_tilbake === true, st);
sjekk('lytterne virker igjen etterpå', en5(st.kall_etter), st.kall_etter);
sjekk('feil midt i beregningen kastes videre', st.feil_kastet === true, st);
sjekk('lyttere tilbake etter feil', st.lyttere_tilbake_etter_feil === true, st);
sjekk('lytterne virker etter feil', en5(st.kall_etter_feil), st.kall_etter_feil);
sjekk('sesjon/kurv tilbake etter feil', st.sesjon_tilbake_etter_feil === true, st);

console.log('\nKasse-secret i admin');
const sc = await get('/amendo-test/v1/secret');
for (const [k, v] of Object.entries(sc)) sjekk(k, v === true, v);

ferdig();
