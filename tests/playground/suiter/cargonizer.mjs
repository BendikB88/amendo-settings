import { B } from '../lib/felles.mjs';
const get = async (p, body) => (await fetch(B + p, { method: body ? 'POST' : 'GET', headers: { 'content-type': 'application/json' }, body: body && JSON.stringify(body) })).json();
const kunSen = process.argv.includes('--kun-sen');
let ok = 0, feil = 0;
const vis = (o, prefiks = '') => {
  for (const [k, v] of Object.entries(o)) { if (typeof v !== 'boolean') continue; if (v) { ok++; console.log('  ✓', prefiks + k); } else { feil++; console.log('  ✗', prefiks + k); } }
  if (Object.values(o).some(v => v === false)) console.log(JSON.stringify(o, null, 1));
};
const sjekk = (n, b, i) => vis({ [n]: b }) || (!b && i !== undefined && console.log('   ', JSON.stringify(i)));
await get('/amendo-test/v1/setup');

if (!kunSen) {
  await get('/amendo-test/v1/opt', { stub_lcfwc_modus: 'tidlig', stub_lcfwc_hook: '1', stub_lcfwc_funksjon: '1' });
  console.log('Stubber ved lasting');
  vis(await get('/amendo-test/v1/cargo'));
  await get('/amendo-test/v1/opt', { stub_lcfwc_hook: '0', stub_lcfwc_funksjon: '0' });
  console.log('Klassen finnes, men verken metode på kroken eller funksjon');
  vis(await get('/amendo-test/v1/cargo-ingen'));
}

await get('/amendo-test/v1/opt', { stub_lcfwc_modus: 'sen', stub_lcfwc_hook: '1', stub_lcfwc_funksjon: '1' });
console.log('\nStubber på plugins_loaded 20 — etter amendo-settings, som på emmk');
const m = await get('/amendo-test/v1/cargo-modus');
sjekk('realistisk: klassen finnes IKKE på plugins_loaded 10', m.klasse_paa_plugins_loaded_10 === false, m);
sjekk('klassen og funksjonen finnes når ordren lages', m.klasse_naa === true && m.funksjon_naa === true, m);
sjekk('kroken er registrert', m.krok_registrert === true, m);
vis(await get('/amendo-test/v1/cargo'), 'sen: ');

if (!kunSen) {
  await get('/amendo-test/v1/opt', { stub_lcfwc_modus: 'av' });
  console.log('\nUten Cargonizer i det hele tatt');
  const a = await get('/amendo-test/v1/cargo-modus');
  sjekk('kroken er registrert likevel', a.krok_registrert === true, a);
  sjekk('ingen klasse, ingen funksjon', a.klasse_naa === false && a.funksjon_naa === false, a);
  vis(await get('/amendo-test/v1/cargo-av'));
}

await get('/amendo-test/v1/opt', { stub_lcfwc_modus: null, stub_lcfwc_hook: null, stub_lcfwc_funksjon: null });
console.log(`\n${ok} ok, ${feil} feil`);
process.exit(feil ? 1 : 0);
