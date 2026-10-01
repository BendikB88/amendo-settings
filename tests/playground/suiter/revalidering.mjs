import { B } from '../lib/felles.mjs';
const get = async (p, body) => (await fetch(B + p, { method: body ? 'POST' : 'GET', headers: { 'content-type': 'application/json' }, body: body && JSON.stringify(body) })).json();
let ok = 0, feil = 0;
const vis = o => {
  for (const [k, v] of Object.entries(o)) { if (typeof v !== 'boolean') continue; if (v) { ok++; console.log('  ✓', k); } else { feil++; console.log('  ✗', k); } }
  if (Object.values(o).some(v => v === false)) console.log(JSON.stringify(o, null, 1));
};
await get('/amendo-test/v1/setup');
for (const ls of ['0', '1']) {
  await get('/amendo-test/v1/opt', { stub_lscwp: ls });
  console.log(`\nRevalidering, LiteSpeed ${ls === '1' ? 'aktiv' : 'ikke aktiv'}`);
  const r = await get('/amendo-test/v1/reval');
  if (r.litespeed_aktiv !== (ls === '1' ? 'ja' : 'nei')) { feil++; console.log('  ✗ LiteSpeed-stubben', JSON.stringify(r).slice(0, 300)); }
  vis(r);
  console.log('   lagring tok', r.lagring_ms, 'ms');
}
await get('/amendo-test/v1/opt', { stub_lscwp: null });
console.log(`\n${ok} ok, ${feil} feil`);
process.exit(feil ? 1 : 0);
