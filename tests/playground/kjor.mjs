#!/usr/bin/env node
// Kjører suitene i tests/playground/suiter ÉN OM GANGEN, hver med sin egen
// WordPress Playground-server som stoppes før neste starter, og skriver en
// samlet tabell til slutt. Se CLAUDE.md.
//
//   node kjor.mjs                       alle suitene, i fast rekkefølge
//   node kjor.mjs kategorirad admin     bare disse (filnavn uten .mjs)
//
// Logger: logg/<suite>.txt (suitens utdata) og logg/<suite>.server.txt.
import { spawn, spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { createWriteStream, mkdirSync, readdirSync, readFileSync } from 'node:fs';
import { connect } from 'node:net';
import { freemem, totalmem } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HER = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(HER, '../..');
const PORT = Number(process.env.PG_PORT || 9411);
const LOGG = join(HER, 'logg');
mkdirSync(LOGG, { recursive: true });

// Raskeste og mest grunnleggende først; hovedsuitens deler før de nyere.
const REKKEFOLGE = [
  'frakt-beregning', 'frakt-validering', 'rate-limit', 'sporing-og-secret', 'admin',
  'admin-js', 'revalidering', 'bilder-og-sider', 'kategorirad', 'cargonizer', 'betaling',
];
const finnes = readdirSync(join(HER, 'suiter')).filter(f => f.endsWith('.mjs')).map(f => f.slice(0, -4));
const ukjente = finnes.filter(s => !REKKEFOLGE.includes(s));
const alle = [...REKKEFOLGE.filter(s => finnes.includes(s)), ...ukjente.sort()];
const valgt = process.argv.slice(2);
const feilNavn = valgt.filter(s => !finnes.includes(s));
if (feilNavn.length) { console.error('Ukjent suite:', feilNavn.join(', '), '\nFinnes:', alle.join(', ')); process.exit(2); }
const suiter = valgt.length ? alle.filter(s => valgt.includes(s)) : alle;

const req = createRequire(import.meta.url);
const cliPakke = req.resolve('@wp-playground/cli/package.json');
const cliBin = (() => { const b = JSON.parse(readFileSync(cliPakke, 'utf8')).bin; return join(dirname(cliPakke), typeof b === 'string' ? b : Object.values(b)[0]); })();

const gb = n => (n / 1024 ** 3).toFixed(1);
const portLedig = () => new Promise(res => {
  const s = connect(PORT, '127.0.0.1');
  s.once('connect', () => { s.destroy(); res(false); });
  s.once('error', () => res(true));
});
const vent = ms => new Promise(r => setTimeout(r, ms));

function drep(barn) {
  if (!barn || barn.exitCode !== null) return;
  if (process.platform === 'win32') spawnSync('taskkill', ['/pid', String(barn.pid), '/T', '/F'], { stdio: 'ignore' });
  else try { process.kill(-barn.pid, 'SIGTERM'); } catch { barn.kill('SIGTERM'); }
}

async function startServer(suite) {
  for (let i = 0; i < 30 && !(await portLedig()); i++) await vent(1000);
  if (!(await portLedig())) throw new Error(`port ${PORT} er opptatt`);
  const logg = createWriteStream(join(LOGG, `${suite}.server.txt`));
  const server = spawn(process.execPath, [cliBin, 'server', `--port=${PORT}`,
    '--mount-dir', REPO, '/wordpress/wp-content/plugins/amendo-settings',
    '--mount-dir', join(HER, 'mu'), '/wordpress/wp-content/mu-plugins',
    `--blueprint=${join(HER, 'blueprint.json')}`,
  ], { cwd: HER, detached: process.platform !== 'win32' });
  server.stdout.pipe(logg); server.stderr.pipe(logg);
  await new Promise((res, rej) => {
    const tid = setTimeout(() => rej(new Error('serveren ble ikke klar innen 10 min')), 600_000);
    const se = d => { if (/^Ready!/m.test(String(d))) { clearTimeout(tid); res(); } };
    server.stdout.on('data', se); server.stderr.on('data', se);
    server.once('exit', k => { clearTimeout(tid); rej(new Error(`serveren stoppet (kode ${k}) — se logg/${suite}.server.txt`)); });
  });
  return server;
}

function kjorSuite(suite) {
  return new Promise(res => {
    const logg = createWriteStream(join(LOGG, `${suite}.txt`));
    const p = spawn(process.execPath, [join(HER, 'suiter', `${suite}.mjs`)], { cwd: HER, env: { ...process.env, PG_URL: `http://127.0.0.1:${PORT}` } });
    let ut = '';
    const ta = d => { ut += d; logg.write(d); process.stdout.write(d); };
    p.stdout.on('data', ta); p.stderr.on('data', ta);
    p.once('exit', kode => { logg.end(); res({ kode, ut }); });
  });
}

const resultater = [];
let server = null;
const rydd = () => { drep(server); server = null; };
process.on('SIGINT', () => { rydd(); process.exit(130); });

for (const suite of suiter) {
  const start = Date.now();
  console.log(`\n=== ${suite}   (ledig minne ${gb(freemem())} av ${gb(totalmem())} GB)`);
  let rad = { suite, ok: 0, feil: 0, status: 'krasjet', merknad: '' };
  try {
    server = await startServer(suite);
    const { kode, ut } = await kjorSuite(suite);
    const m = [...ut.matchAll(/(\d+) ok, (\d+) feil/g)].at(-1);
    if (m) {
      rad.ok = Number(m[1]); rad.feil = Number(m[2]);
      rad.status = rad.feil === 0 && kode === 0 ? 'grønn' : 'rød';
    } else {
      rad.merknad = `ingen «N ok, M feil» (kode ${kode}) — se logg/${suite}.txt`;
    }
  } catch (e) {
    rad.merknad = e.message;
  } finally {
    rydd();
    for (let i = 0; i < 30 && !(await portLedig()); i++) await vent(1000);
  }
  rad.tid = Math.round((Date.now() - start) / 1000) + ' s';
  resultater.push(rad);
}

const kol = ['suite', 'ok', 'feil', 'status', 'tid', 'merknad'];
const bredde = kol.map(k => Math.max(k.length, ...resultater.map(r => String(r[k] ?? '').length)));
const linje = r => kol.map((k, i) => String(r[k] ?? '').padEnd(bredde[i])).join('  ').trimEnd();
console.log('\n' + linje(Object.fromEntries(kol.map(k => [k, k]))));
console.log(bredde.map(b => '-'.repeat(b)).join('  '));
resultater.forEach(r => console.log(linje(r)));
const sum = resultater.reduce((s, r) => ({ ok: s.ok + r.ok, feil: s.feil + r.feil }), { ok: 0, feil: 0 });
const alleGronne = resultater.every(r => r.status === 'grønn');
console.log(`\nTotalt: ${sum.ok} ok, ${sum.feil} feil — ${alleGronne ? 'ALT GRØNT' : 'IKKE GRØNT'}`);
process.exit(alleGronne ? 0 : 1);
