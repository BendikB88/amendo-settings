import { B } from '../lib/felles.mjs';
const get = async (p, body) => (await fetch(B + p, { method: body ? 'POST' : 'GET', headers: { 'content-type': 'application/json' }, body: body && JSON.stringify(body) })).json();
let ok = 0, feil = 0;
const sjekk = (n, b, i) => { if (b) { ok++; console.log('  ✓', n); } else { feil++; console.log('  ✗', n, i !== undefined ? JSON.stringify(i) : ''); } };
const vis = o => { for (const [k, v] of Object.entries(o)) sjekk(k, v === true, v === true ? undefined : v); };

await get('/amendo-test/v1/setup');

console.log('Bildedimensjoner på forsiden');
vis(await get('/amendo-test/v1/bilder'));
console.log('\nForside lagret før 1.4.5');
vis(await get('/amendo-test/v1/bilder-legacy'));

// LiteSpeed-stubben (LSCWP_V) gjelder fra neste forespørsel.
await get('/amendo-test/v1/opt', { stub_lscwp: '1' });
const sak = s => get('/amendo-test/v1/side-reval&sak=' + s);
const ett = (r, tags = ['side']) => r.http?.length === 1 && JSON.stringify(r.http[0].tags) === JSON.stringify(tags) && r.http[0].url === 'https://emmk-frontend.vercel.app/api/revalidate';

console.log('\nSider varsler frontenden');
await sak('klargjor');
await sak('lag_publisert');
let r = await sak('publiser_ny');
sjekk('publisering: ett varsel med ["side"]', ett(r), r);
sjekk('publisering: LiteSpeed REST tømt', JSON.stringify(r.purge) === '["REST"]', r.purge);
sjekk('publisering: én oppfølging planlagt', r.cron === 1, r.cron);
r = await sak('oppdater');
sjekk('oppdatering: ett varsel', ett(r), r);
sjekk('oppdatering: fortsatt bare én oppfølging', r.cron === 1, r.cron);
r = await sak('to_oppdateringer');
sjekk('to oppdateringer i samme forespørsel: ett varsel', ett(r), r);
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
sjekk('permanent sletting av publisert side: ett varsel', ett(r), r);
r = await sak('utkast_aldri_publisert');
sjekk('utkast som aldri er publisert: ingen varsel', r.http?.length === 0 && r.purge?.length === 0, r);
await sak('lag_ekstra');
r = await sak('revisjon');
sjekk('revisjon og autosave: ingen varsel', r.http?.length === 0 && r.purge?.length === 0, r);
r = await sak('innlegg');
sjekk('innlegg (ikke side): ingen varsel', r.http?.length === 0, r);

console.log('\nSammenslåing med lagring av innstillinger');
await sak('klargjor');
await sak('lag_ekstra');
r = await sak('innstillinger');
sjekk('innstillinger: alle tagger', ett(r, ['innstillinger', 'forside', 'side']), r);
r = await sak('oppdater_ekstra');
sjekk('side etterpå: ["side"] nå', ett(r), r);
sjekk('fortsatt én oppfølging', r.cron === 1, r.cron);
sjekk('ventende tagger slått sammen', JSON.stringify(r.ventende) === '["innstillinger","forside","side"]', r.ventende);
r = await sak('cron');
sjekk('oppfølgingen sender de ventende taggene', ett(r, ['innstillinger', 'forside', 'side']), r);
sjekk('ventende tømt etter oppfølging', r.ventende === null, r.ventende);
await sak('klargjor');
await sak('oppdater_ekstra');
r = await sak('cron');
sjekk('bare side-endring: oppfølgingen sender ["side"]', ett(r), r);

r = await sak('ikke_satt_opp');
sjekk('frontend ikke satt opp: ingen HTTP, men LiteSpeed tømt', r.http?.length === 0 && JSON.stringify(r.purge) === '["REST"]', r);

await get('/amendo-test/v1/opt', { stub_lscwp: null });
console.log(`\n${ok} ok, ${feil} feil`);
process.exit(feil ? 1 : 0);
