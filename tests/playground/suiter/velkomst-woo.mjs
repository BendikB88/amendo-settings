// 1.4.43: velkomst-e-posten og «Send invitasjon» for butikker uten frontend
// (Frontend-URL tom). Lenken går til WooCommerce sin egen «Velg passord»
// (<Min konto>/lost-password/?key=…&id=…&login=…, samme form som
// WooCommerce sin e-post), og suiten følger den gjennom det EKTE skjemaet:
// omdirigering med informasjonskapsel, skjemaet, nytt passord, innlogging.
// Admin får ikke «Passord endret» for kontoer fra Tellix, men som før for
// andre. Uten Min konto-side: wp-login.php. «Glemt passord» og alt med
// Frontend-URL er som før. Tellix er mu/tellix-stub.php, e-post fanget i
// mu/v1429-test.php, Min konto av/på i mu/v1443-test.php. Se
// includes/moduler/b2b-tellix-bedrifter.php.
import { B, URL_, get, sjekk, ferdig } from '../lib/felles.mjs';

const opt = o => get('/amendo-test/v1/opt', o);
const klargjor = body => get('/amendo-test/v1/v1414-klargjor', body);
const k1429 = body => get('/amendo-test/v1/v1429-klargjor', body);
const m1 = async e => (await get('/amendo-test/v1/v1429-meta', { eposter: [e] }))[e];
const tilstand = () => get('/amendo-test/v1/v1429-tilstand');
const jobb = () => get('/amendo-test/v1/v1429-jobb');
const handling = (post, rolle = 'admin') => get('/amendo-test/v1/v1429-handling', { post, rolle, nonce: true });
const svar = til => (String(til || '').match(/b2b_auto=([a-z_]+)/) || [])[1];
const logInn = (epost, passord) => get('/amendo-test/v1/v1429-logg-inn', { epost, passord });
const tb = body => get('/amendo-test/v1/v1429-tilbakestill', body);
const minKonto = av => get('/amendo-test/v1/v1443-min-konto', { av });
const side = async () => (await fetch(B + '/amendo-test/v1/v1414-side&rolle=admin')).text();
const k = (id, email, orgnr, name) => ({ id, uuid: 'U' + id, groupId: 0, email, name, orgnr });
const lenkeI = html => ((html || '').match(/<a href="([^"]+)"[^>]*>Lag passord<\/a>/) || [])[1]?.replace(/&#038;|&amp;/g, '&') || '';
const epostTil = async e => (await tilstand()).epost.filter(x => x.til[0] === e);
const informasjonskapsler = r => (r.headers.getSetCookie?.() || []).map(c => c.split(';')[0]).filter(c => !/=\s*$/.test(c) && !c.endsWith('= ')).join('; ');

const KUNDER = [k('W1', 'w1@woo1443.no', '912000001', 'Woo En AS'), k('W2', 'w2@woo1443.no', '912000002', 'Woo To AS')];
const TELLIX = { moduler: ['b2b'], tellix: '1', grupper: [{ id: 14, name: 'Bedriftskunder', discountPercent: 10 }], kundegrupper_ny: { 14: { gjelder: 'nettbutikk', b2b: 1, levering: 0 } },
  helsebygget: null, store_groups: null, kundegrupper: null, sov: null, kast: null, api_feil: null, side: null, faktura: null };

await get('/amendo-test/v1/setup');
await opt({ amendo_frontend_url: '', amendo_butikk_navn: 'Testbakeriet', amendo_test_epost_fang: '1' });
await minKonto(false);
await klargjor({ ...TELLIX, kunder: KUNDER, liste_slett: true, nullstill: true });
await k1429({ nullstill: true, slett_epost: ['w1@woo1443.no', 'w3@woo1443.no', 'w4@woo1443.no', 'w5@woo1443.no'], brukere: [{ epost: 'vanlig@woo1443.no', passord: 'Gammelt-passord-1' }] });

// ── Admin-kortet ────────────────────────────────────────────────────────────
console.log('Admin-kortet uten Frontend-URL');
let html = await side();
const kort = html.slice(html.indexOf('id="b2b-tellix-bedrifter"'), html.indexOf('id="b2b-tellix-bedrifter"') + 8000);
sjekk('lenken går til WooCommerce sin «Velg passord», ingen advarsel om Frontend-URL', kort.includes('WooCommerce sin «Velg passord» under Min konto') && !kort.includes('e-posten kan ikke sendes'), kort.slice(0, 400));

// ── Jobben: ny Tellix-bedrift ───────────────────────────────────────────────
console.log('\nVelkomst-e-posten fra jobben');
await handling({ b2b_auto: 'lagre', b2b_bryter_auto: '1', b2b_bryter_jobb: '1', b2b_bryter_epost: '1', b2b_velkomst_knapp: 'Lag passord' });
let r = await jobb();
sjekk('grunnlinje', r.svar?.status === 'grunnlinje', r.svar);
await klargjor({ kunder: [...KUNDER, k('W3', 'w3@woo1443.no', '912000003', 'Woo Tre AS')], liste_slett: true });
r = await jobb();
let m = await m1('w3@woo1443.no');
let ep = await epostTil('w3@woo1443.no');
sjekk('konto og velkomst-e-post (ikke stoppet av manglende Frontend-URL)', r.svar?.invitert === 1 && ep.length === 1 && m?._amendo_invitasjon === 'sendt', [r.svar, ep.length, m?._amendo_invitasjon]);
const lenke = lenkeI(ep[0]?.html);
const u = new URL(lenke || 'http://x/');
sjekk('lenken: samme nettsted, lost-password, key + id + login som WooCommerce sin e-post', u.origin === new URL(URL_).origin && /lost-password/.test(lenke) && u.searchParams.get('key')?.length >= 20 && u.searchParams.get('id') === String(m?.id) && u.searchParams.get('login') === m?.login, lenke);
sjekk('ikke /sett-passord og ikke wp-login.php', !lenke.includes('sett-passord') && !lenke.includes('wp-login.php'), lenke);

// ── WooCommerce sitt skjema ─────────────────────────────────────────────────
console.log('\nGjennom WooCommerce sitt «Velg passord»');
let s = await fetch(lenke, { redirect: 'manual' });
const til = s.headers.get('location') || '';
const kaker = informasjonskapsler(s);
sjekk('lenken: omdirigering til skjemaet med informasjonskapselen', [301, 302, 303, 307].includes(s.status) && /show-reset-form=true/.test(til) && /wp-resetpass-/.test(kaker), [s.status, til, kaker]);
s = await fetch(new URL(til, lenke), { headers: { cookie: kaker }, redirect: 'manual' });
html = await s.text();
const felt = n => (html.match(new RegExp(`name="${n}" value="([^"]*)"`)) || [])[1] || '';
sjekk('skjemaet vises med nøkkel, brukernavn og nonce', s.status === 200 && felt('reset_key') === u.searchParams.get('key') && felt('reset_login') === m?.login && felt('woocommerce-reset-password-nonce') !== '', [s.status, felt('reset_key'), felt('reset_login')]);
const før = (await tilstand()).epost.length;
s = await fetch(new URL(til, lenke), {
  method: 'POST', redirect: 'manual', headers: { cookie: kaker, 'content-type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({ password_1: 'Woo-passord-789', password_2: 'Woo-passord-789', reset_key: felt('reset_key'), reset_login: felt('reset_login'), wc_reset_password: 'true', 'woocommerce-reset-password-nonce': felt('woocommerce-reset-password-nonce') }),
});
sjekk('nytt passord: WooCommerce sender kunden til Min konto (password-reset=true)', [301, 302, 303].includes(s.status) && /password-reset=true/.test(s.headers.get('location') || ''), [s.status, s.headers.get('location')]);
sjekk('kunden kan logge inn med det nye passordet', (await logInn('w3@woo1443.no', 'Woo-passord-789')).ok === true);
let t = await tilstand();
sjekk('admin får ikke «Passord endret» for kontoen fra Tellix', t.epost.length === før, t.epost.slice(før).map(e => [e.til[0], e.emne]));
r = await tb({ login: m?.login, key: u.searchParams.get('key') });
sjekk('samme lenke to ganger: avvist', r.ok === false, r);

// Vanlig kunde (ikke fra Tellix) gjennom samme skjema: admin-varselet som før.
await get('/amendo-test/v1/v1429-glemt', { epost: 'vanlig@woo1443.no' });
const glemt = (await epostTil('vanlig@woo1443.no')).at(-1)?.html || '';
sjekk('«Glemt passord» uten Frontend-URL: WordPress sin lenke som før', /wp-login\.php\?\S*action=rp/.test(glemt) && !glemt.includes('lost-password') && !glemt.includes('sett-passord'), glemt.slice(0, 300));
const mv = await m1('vanlig@woo1443.no');
const vkey = (glemt.match(/key=([A-Za-z0-9]+)/) || [])[1] || '';
const vlenke = new URL(lenke);
vlenke.searchParams.set('key', vkey); vlenke.searchParams.set('id', String(mv.id)); vlenke.searchParams.set('login', mv.login);
s = await fetch(vlenke, { redirect: 'manual' });
const vtil = s.headers.get('location') || '';
const vkaker = informasjonskapsler(s);
html = await (await fetch(new URL(vtil, lenke), { headers: { cookie: vkaker } })).text();
const før2 = (await tilstand()).epost.length;
await fetch(new URL(vtil, lenke), {
  method: 'POST', redirect: 'manual', headers: { cookie: vkaker, 'content-type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({ password_1: 'Vanlig-passord-2', password_2: 'Vanlig-passord-2', reset_key: felt('reset_key'), reset_login: felt('reset_login'), wc_reset_password: 'true', 'woocommerce-reset-password-nonce': felt('woocommerce-reset-password-nonce') }),
});
t = await tilstand();
sjekk('vanlig kunde: passordet satt, admin får «Passord endret» som før', (await logInn('vanlig@woo1443.no', 'Vanlig-passord-2')).ok === true && t.epost.length === før2 + 1 && t.epost.at(-1).til[0] !== 'vanlig@woo1443.no', t.epost.slice(før2).map(e => [e.til[0], e.emne]));

// ── «Send invitasjon» ───────────────────────────────────────────────────────
console.log('\nSend invitasjon uten Frontend-URL');
r = await handling({ b2b_auto: 'torrkjor' });
t = await tilstand();
const plan = t.plan || { kunder: {} };
sjekk('tørrkjørt: W1 uten konto skal inviteres', svar(r.til) === 'torrkjort' && plan.kunder?.W1?.handling === 'inviter', [svar(r.til), plan.kunder]);
r = await handling({ b2b_auto: 'send', plan: plan.nokkel });
ep = await epostTil('w1@woo1443.no');
const l1 = lenkeI(ep[0]?.html);
sjekk('sendt (ikke «Frontend-URL mangler»), med WooCommerce-lenken', svar(r.til) === 'sendt' && ep.length === 1 && /lost-password/.test(l1) && new URL(l1).searchParams.get('id') === String((await m1('w1@woo1443.no'))?.id), [svar(r.til), l1]);

// ── Uten Min konto-side ─────────────────────────────────────────────────────
console.log('\nUten Min konto-side');
await minKonto(true);
await klargjor({ kunder: [...KUNDER, k('W3', 'w3@woo1443.no', '912000003', 'Woo Tre AS'), k('W4', 'w4@woo1443.no', '912000004', 'Woo Fire AS')], liste_slett: true });
await jobb();
const l4 = lenkeI((await epostTil('w4@woo1443.no'))[0]?.html);
const q4 = new URL(l4 || 'http://x/').searchParams;
sjekk('lenken går til wp-login.php?action=rp med key og login', /wp-login\.php\?action=rp&key=/.test(l4) && q4.get('login') === (await m1('w4@woo1443.no'))?.login, l4);
sjekk('… og nøkkelen er gyldig', (await tb({ login: q4.get('login'), key: q4.get('key') })).ok === true);
await minKonto(false);

// ── Med Frontend-URL: som før ───────────────────────────────────────────────
console.log('\nMed Frontend-URL: som før');
// 1.4.46: /sett-passord bare med «Send passordlenker til fronten» på (se suiten passordlenker).
await opt({ amendo_frontend_url: 'https://front.example.no', amendo_b2b_passordlenker: '1' });
await klargjor({ kunder: [...KUNDER, k('W3', 'w3@woo1443.no', '912000003', 'Woo Tre AS'), k('W4', 'w4@woo1443.no', '912000004', 'Woo Fire AS'), k('W5', 'w5@woo1443.no', '912000005', 'Woo Fem AS')], liste_slett: true });
await jobb();
const l5 = ((await epostTil('w5@woo1443.no'))[0]?.html || '').match(/href="([^"]+)"/)?.[1]?.replace(/&#038;|&amp;/g, '&') || '';
sjekk('lenken: <Frontend-URL>/sett-passord?key=…&login=…', l5.startsWith('https://front.example.no/sett-passord?key=') && !l5.includes('lost-password'), l5);

await handling({ b2b_auto: 'lagre' });
await opt({ amendo_frontend_url: null, amendo_test_epost_fang: null, amendo_b2b_passordlenker: '' });
ferdig();
