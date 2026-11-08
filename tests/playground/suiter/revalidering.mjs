import { B } from '../lib/felles.mjs';
const get = async (p, body) => (await fetch(B + p, { method: body ? 'POST' : 'GET', headers: { 'content-type': 'application/json' }, body: body && JSON.stringify(body) })).json();
let ok = 0, feil = 0;
const vis = o => {
  for (const [k, v] of Object.entries(o)) { if (typeof v !== 'boolean') continue; if (v) { ok++; console.log('  ✓', k); } else { feil++; console.log('  ✗', k); } }
  if (Object.values(o).some(v => v === false)) console.log(JSON.stringify(o, null, 1));
};
const sjekk = (n, b, i) => { if (b) { ok++; console.log('  ✓', n); } else { feil++; console.log('  ✗', n, i !== undefined ? JSON.stringify(i) : ''); } };
await get('/amendo-test/v1/setup');
for (const ls of ['0', '1']) {
  await get('/amendo-test/v1/opt', { stub_lscwp: ls });
  console.log(`\nRevalidering, LiteSpeed ${ls === '1' ? 'aktiv' : 'ikke aktiv'}`);
  const r = await get('/amendo-test/v1/reval');
  if (r.litespeed_aktiv !== (ls === '1' ? 'ja' : 'nei')) { feil++; console.log('  ✗ LiteSpeed-stubben', JSON.stringify(r).slice(0, 300)); }
  vis(r);
  console.log('   lagring tok', r.lagring_ms, 'ms');
}

// 1.4.35: innlegg varsler som sider (taggen «side»). Sakene i mu/v145-test.php
// med type=post — ett scenario per forespørsel (ett varsel per forespørsel).
await get('/amendo-test/v1/opt', { stub_lscwp: '1' });
const sak = s => get('/amendo-test/v1/side-reval&type=post&sak=' + s);
const ett = (r, tags = ['side']) => r.http?.length === 1 && JSON.stringify(r.http[0].tags) === JSON.stringify(tags) && r.http[0].url === 'https://emmk-frontend.vercel.app/api/revalidate';

console.log('\nInnlegg varsler frontenden');
await sak('klargjor');
await sak('lag_publisert');
let r = await sak('publiser_ny');
sjekk('publisering: ett varsel med ["side"]', ett(r), r);
sjekk('publisering: LiteSpeed REST tømt', JSON.stringify(r.purge) === '["REST"]', r.purge);
sjekk('publisering: én oppfølging planlagt', r.cron === 1, r.cron);
r = await sak('oppdater');
sjekk('lagring av publisert innlegg: ett varsel', ett(r), r);
r = await sak('to_oppdateringer');
sjekk('to lagringer i samme forespørsel: ett varsel', ett(r), r);
r = await sak('avpubliser');
sjekk('avpublisering (publish → draft): ett varsel', ett(r), r);
r = await sak('publiser_igjen');
sjekk('publisert igjen: ett varsel', ett(r), r);
r = await sak('papirkurv');
sjekk('papirkurv: ett varsel', ett(r), r);
r = await sak('slett_trashed');
sjekk('sletting fra papirkurven: ingen nytt varsel', r.http?.length === 0, r);
await sak('lag_ekstra');
r = await sak('slett_publisert_direkte');
sjekk('permanent sletting av publisert innlegg: ett varsel', ett(r), r);
r = await sak('cron');   // lag_ekstra tømte de ventende, så bare innlegget venter
sjekk('oppfølgingen sender ["side"]', ett(r), r);
r = await sak('utkast_aldri_publisert');
sjekk('utkast som aldri er publisert: ingen varsel', r.http?.length === 0 && r.purge?.length === 0, r);
await sak('lag_ekstra');
r = await sak('revisjon');
sjekk('revisjon og autosave: ingen varsel', r.http?.length === 0 && r.purge?.length === 0, r);
r = await sak('annen_posttype');
sjekk('annen posttype (gjenbrukbar blokk): ingen varsel', r.http?.length === 0 && r.purge?.length === 0, r);
r = await sak('ikke_satt_opp');
sjekk('frontend ikke satt opp: ingen HTTP, men LiteSpeed tømt', r.http?.length === 0 && JSON.stringify(r.purge) === '["REST"]', r);

await get('/amendo-test/v1/opt', { stub_lscwp: null });
console.log(`\n${ok} ok, ${feil} feil`);
process.exit(feil ? 1 : 0);
