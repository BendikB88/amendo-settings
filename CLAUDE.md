# amendo-settings

WordPress-plugin («Amendo Innstillinger») som gir headless-frontendene (Next.js
på Vercel) innhold og innstillinger via REST under `amendo-settings/v1`. Brukes
av flere butikker (emmk, Garçon, Aanerud), så **alt må være bakoverkompatibelt**:
nye felter i REST-svar er greit, endrede eller fjernede er det ikke.

## Struktur

- `amendo-settings.php` — header/versjon, admin-meny, lagring (`admin_post_amendo_save_settings`), REST-ruter, admin-siden.
- `includes/` — én fil per område: `forside.php`, `frakt.php`, `cargonizer.php`, `hemmeligheter.php`, `revalidering.php`, `kategorirad.php`, `betaling.php`, `bedrift.php`, `stengte.php`, `lagring.php` (hvilke faner som lagres — se under), `kategorivelger.php` (nedtrekkslisten med søk overalt der man velger en WooCommerce-kategori), `helsebygget.php` (PIN-innlogging og token for Helsebygget-admin, logg over sortimentet), `leveringsregler.php` (bestillingsvindu per avdeling, antallsregler og norske helligdager i `/leveringsregler`). Hver fil har en toppkommentar som forklarer hvorfor den ser ut som den gjør — les den før du endrer.
- `includes/moduler.php` + `includes/moduler/<nøkkel>.php` — moduler som slås av og på under ⚙️ Moduler (se under). I dag: `b2b` (med `b2b-tellix.php`: rabatt fra Tellix via amendo-core, `b2b-kundegrupper.php`: kundegruppetabellen og hvor rabatten gjelder, `b2b-helsebygget.php`: ansatte med PIN og loggen) og `henting` (hentelinje, ordrefilter og hentedato til Tellix).
- `assets/admin.js` — kjører i wp-admin der jQuery er i noConflict-modus: all kode inne i `jQuery(function($) { … })`.
- `tests/playground/` — testene (under). Er `export-ignore` og skal aldri med i pluginen hos butikkene.

## Moduler

Funksjonalitet bare noen butikker skal ha (først: B2B) er en **modul**, slått av
og på under Amendo → ⚙️ Moduler. Bakgrunnen står øverst i `includes/moduler.php`.

**Hovedregelen: en modul som er av, finnes ikke.** Fila lastes bare når modulen er
på, så den skal ikke ha én eneste `if (amendo_modul_aktiv(...))`. Alt den gjør
(kroker, faner, felter, REST-ruter, felter i `/settings`) registreres øverst i fila
og forsvinner sammen med den. `/settings` har alltid `moduler: [...]` med
nøklene som er på.

**Alt er av til noen slår det på i admin.** Pluginen oppdateres hos alle
butikkene, og en oppdatering skal aldri gi en butikk noe den ikke har bedt om.
Ingen modul aktiverer seg selv — ikke legg inn auto-aktivering (se
`includes/moduler.php` for hvorfor den ble fjernet).

### Ny modul

1. **Registrer den** i `amendo_moduler_alle()` i `includes/moduler.php`:
   `navn`, `ikon`, `beskrivelse` og `fil` (`includes/moduler/<nøkkel>.php`).
   ⚠ Nøkkelen er det frontendene ser i `moduler`
   — den kan aldri endres når en butikk har tatt modulen i bruk.
2. **Lag fila** `includes/moduler/<nøkkel>.php` med toppkommentar og
   `if (!defined('ABSPATH')) exit;`. Bruk krokene fra kjernen:
   | Krok | Til |
   |---|---|
   | `amendo_admin_faner` | `<button type="button" class="amendo-tab" data-tab="<nøkkel>">` |
   | `amendo_admin_paneler` | `<div class="amendo-panel" id="tab-<nøkkel>">`, **inne i** hovedskjemaet |
   | `amendo_admin_etter_skjema` | egne `<form>`-er (handlinger, søk) — knapper i panelet peker hit med `form="…"`; HTML tillater ikke nøstede skjemaer |
   | `amendo_lagre_innstillinger` | `($post)` ved lagring, allerede `wp_unslash`-et. Krev en markør (`<felt>[finnes]`) som kategoriraden, ellers tømmer lagring fra en annen side feltet |
   | `amendo_settings_svar` (filter) | legg til felter i `/settings` |
   REST-ruter registreres i fila med `rest_api_init` som ellers.
3. **JS** i `assets/admin.js` som vanlig — den må tåle at elementene ikke finnes.
4. **Test** i egen suite: tilstanden settes i ett kall og sjekkes i det neste
   (modulen lastes når pluginen lastes). Sjekk både av (ingen kroker, ingen
   felter i `/settings`, ingen ruter) og på. Se `suiter/moduler.mjs`.

⚠ Eksisterende ruter og felter blir **ikke** flyttet inn i en modul: da forsvinner
de hos butikker der modulen er av, og det bryter bakoverkompatibiliteten. Derfor
ligger `/bedrift-sok` fortsatt i `includes/bedrift.php`.

## Ny versjon

1. Øk versjonen begge steder i `amendo-settings.php`: ` * Version:` og `AMENDO_SETTINGS_VERSION`.
2. Test (under). Commit først når alt er grønt.
3. Commit-melding på norsk: `vX.Y.Z: kort tittel`, så hva som er endret og hvorfor, og til slutt hva som er testet (antall kontroller per suite).

## Tester: WordPress Playground

Ekte WordPress + WooCommerce i PHP-WASM. Ingen lokal PHP trengs.

```sh
cd tests/playground
npm install                          # første gang
node kjor.mjs                        # alle suitene
node kjor.mjs kategorirad admin      # bare noen (filnavn i suiter/ uten .mjs)
```

`kjor.mjs` kjører **én suite om gangen**, hver med sin egen Playground-server
som stoppes før neste starter, og skriver en samlet tabell til slutt (exit-kode
0 bare når alt er grønt). Logger havner i `logg/` (ignorert av git).

**Hvorfor én server per suite:** Playground bruker mye minne, og maskinen har
gått tom flere ganger når alt ble kjørt mot én server. Ikke kjør suiter
parallelt. Hver suite tar 2–10 minutter (Playground bruker 10–25 s per kall).

### Oppsett

- `blueprint.json` — installerer WooCommerce og aktiverer pluginen. Repoet monteres som `wp-content/plugins/amendo-settings`, `mu/` som `wp-content/mu-plugins`.
- `mu/*.php` — testruter under `amendo-test/v1/*` og stubber (bl.a. cargonizer-connect). Ingen tilgangskontroll: bare for Playground. Hver fil starter med `if (!defined('ABSPATH')) exit;`.
- `lib/felles.mjs` — `B` (REST-base fra `PG_URL`), `get`, `frakt`, `sjekk`, `ferdig`, stier til `admin.js` og jQuery.
- `suiter/*.mjs` — én fil per område. Hver suite må avslutte med linjen `N ok, M feil` (bruk `ferdig()`); det er den `kjor.mjs` leser.

| Suite | Dekker |
|---|---|
| `kupong` | `/frakt` med `kupong`/`epost` (1.4.9): gyldig/utløpt/minstebeløp/bruksgrense/per e-post/e-postbegrensning, rabatt, gratis-frakt-grensen MED rabatten, kupong-gratisfrakt, 403 uten secret. 1.4.10: grense per kunde teller bare betalte ordrer (processing/completed/on-hold — ikke pending/failed/cancelled eller `_used_by` alene), og `woocommerce_cancel_unpaid_order` for `_nextjs_kasse`-ordrer |
| `frakt-beregning` | `/frakt`: priser, MVA, gratis-frakt-grense, skjul betalt frakt, avviste varer |
| `frakt-validering` | `/frakt`: input-validering, ingen sesjon/cookie/persistent kurv, ekte kurv urørt |
| `rate-limit` | `/frakt`: grense per IP, unntak med `X-Amendo-Kasse-Secret` |
| `sporing-og-secret` | sporingslyttere stille under `/frakt`; kasse-secret i admin |
| `admin` | «Skjul betalt frakt», tilgang (`manage_woocommerce`) |
| `admin-js` | `admin.js` i jsdom: secrets, mediebiblioteket |
| `revalidering` | LiteSpeed-tømming og `/api/revalidate` ved lagring |
| `bilder-og-sider` | bilde-ID/-mål på forsiden; WP-sider varsler frontenden |
| `forside` | 1.4.13: forsideseksjonene (kategorifliser, historie, lokasjoner, galleri, tall-merke, nyhetsoverskrift, nyhetsbrev) og `seksjoner`: rensing, maks rader, kategorier validert, bildemål, ACF-reserve FELT FOR FELT (mot en ACF-stubb som bare gjelder denne suiten), at en lagring hos en ACF-butikk ikke endrer svaret, og at svaret uten nye felter er som i 1.4.12; admin-fanen og admin.js i jsdom |
| `kategorirad` | kategoriraden: lagring, `/settings`, admin, jsdom, varsel ved endring |
| `cargonizer` | `_lcfwc_*` på REST-ordrer, mot stubber |
| `betaling` | `/adyen-session` og `/gateway-redirect` mot stubber av Amendo Gateway og Vipps |
| `kortbetaling` | 1.4.11: `/adyen-metoder` (bare kort, cache), `/adyen-betal` (beløp fra ordren i øre, reference `<host>/<id>`, splitt, Authorised/Refused/3DS, beløpsavvik), `/adyen-detaljer` (merchantReference må høre til ordren), fail-closed secret (503/403), alltid JSON |
| `bedrift-sok` | `/bedrift-sok`: oppslag på org.nr, tilgang (fail-closed), rate limit, og at svaret ikke lekker personopplysninger |
| `moduler` | ⚙️ Moduler: av/på (ingen kroker/felter/ruter når av), bryteren, av som standard også når kundene har org.nr (oppdatering hos emmk o.l.); B2B: søknader (godkjenn/avvis, tilgang, nonce), aktive kunder og søk, rettigheter per kundegruppe i `/settings`, jsdom |
| `tellix` | 1.4.14: `rabatt/kunde/{id}` og `rabatt/telefon` mot en stubb av `\Amendo\Customers` med samme svarform som amendo-core 3.0.1 (`mu/tellix-stub.php`): fail-closed secret, gruppe fra Tellix og ikke den lagrede (og skrevet tilbake), aldri tømt ved feil, Helsebygget-grupper uten nettbutikk-rabatt, 503 når de ikke er satt opp, `amendo-store-groups` («helsebygget»), gruppenavn bare via transient, de to Helsebygget-kildene (lagret liste vinner, tom tabell forblir {}), grense per nummer, egen kopi av hele kundelista (alle sider, ≤ 5 min, ny henting når kunden mangler og kopien er > 30 s, én henting per 30 s, forrige kopi står ved feil), koblet kunde = Tellix er sannheten (også 0), navn og e-post på rabatt/telefon, uten amendo-core, B2B av, admin (skrivebeskyttet, feltet bare for administrator), ingen kundedata i loggen. 1.4.15: reserve når Tellix ikke finner kunden (lagret `_tellixGroupId` > 0, aldri overskrevet med 0) eller nummeret (WordPress-brukerne med nummeret: nøyaktig én gruppe), Helsebygget-satsen fylles aldri inn (bare ID-ene fra `amendo-store-groups`, lagring uten sats avvises), advarsel når Tellix-lista kan være avkortet (`tellix_customers3` mot `AMENDO_CUSTOMER_SYNC_SIZE`). 1.4.17: e-post sammenlignes uten store/små bokstaver og mellomrom (også hardt mellomrom). 1.4.19: gruppen leses fra både `customerGroupId` (rått Tellix-svar) og `groupId` (amendo-core); et felt som mangler gir aldri 0 over en gruppe, bare et eksplisitt tomt felt gjør det; kopier fra før 1.4.19 (uten `format`) skriver aldri 0. 1.4.20: samme telefonnummer på flere Tellix-kunder (ett med gruppe vinner, rabatt/telefon foretrekker Bare Helsebygget, ellers ingen gruppe og ingen kobling — heller ikke WordPress-reserven) |
| `henting` | 1.4.14: stengte perioder per avdeling (lagring, `/leveringsregler`, admin, jsdom); modulen «Hentedato på ordrer»: av = ingenting endres, hentelinjen på ordreside/kolonne/e-post/WP Overnight, `amendo_tellix_delivery_fields` (pairs, sjekket mot en kopi av get_delivery_datetime() fra gatewayen 1.36.13), datofilter (nye Y-m-d og gamle d/m/Y) og hentested i HPOS-ordrelisten |
| `kategorivelger` | 1.4.17: kategori-regler, kategoriflisene og kategoriraden bruker samme nedtrekksliste (navn med forelder, antall produkter, slettet kategori beholdes), søk i jsdom (filter, Enter, ikke en endring), nye rader fra mal, lagret verdi er term_id |
| `kundegrupper` | 1.4.17: én rad per aktiv Tellix-gruppe, rabatt som setning («40 % på …», «10 % på alt»), Helsebygget-rabatten følger Tellix ({prosent, kategorier}, fasit fra garcon.no), «Hvor gjelder rabatten» (bare administrator), faktura i gatewayens `enabled_customer_group_<id>`, migrering (Helsebygget-lista, amendo_kundegruppe_config, amendo-store-groups), `/settings` (`{}` til tabellen er lagret), 503 når ingenting er satt opp, jsdom |
| `helsebygget` | 1.4.17: ansatte med PIN (HMAC, vist én gang, nonce), admin-innlogging → token (12 t), POST helsebygget-produkter krever token + secret og logger navn/tid/endring (maks 200), ny PIN/sletting gjør token ugyldig, maks 5 feil forsøk per 15 min, tom secret avviser alt. 1.4.21: skjulte varianter (`skjulte_varianter` i GET, valgfritt i POST: utelatt = urørt, [] = tøm, ikke-liste = 400, bare product_variation), logget med antall før/etter, antall per produkt i fanen, nettbutikken urørt |
| `leveringsregler` | 1.4.18: `/leveringsregler` med bestillingsvindu per avdeling (standard 60, også for gamle avdelinger), antallsregler (rensing, kategori_navn, unntatte avdelinger, lagres bare med markør + flagg), norske helligdager (fasit for 2024/2026/2027/2038, 12 måneder fram, bryteren av = tom liste), `kategori_regler[].kategori_navn`, admin og admin.js i jsdom |
| `lagring` | 1.4.16: faner som lagres som én option (forside, meny, kategorirad, avdelinger, stengte perioder, kategori-regler, B2B-kundegrupper) lagres bare når `amendo_endret[<del>]` er 1, avvises (hele lagringen) når optionen er endret på serveren etter at siden ble lastet, som før uten flaggene; admin.js setter flagget i jsdom |

Ny suite: legg en fil i `suiter/`, og sett den inn i `REKKEFOLGE` i `kjor.mjs`
(ukjente filer kjøres sist i alfabetisk rekkefølge).

### Fallgruver (alle har gitt falske feil før)

- **Hele admin-skjemaet sendes ved hver lagring.** En ny fane som lagres som ÉN option må ha `data-amendo-del` + `amendo_lagring_felt()` og sjekke `amendo_lagring_skal_lagre()` — ellers overskriver en lagring fra en annen fane den med det siden hadde da den ble åpnet (skjedde med forsiden på garcon.no). Se `includes/lagring.php`.

- **Ett varsel per forespørsel.** Revalidering fra sider og kategorier sendes maks én gang per PHP-forespørsel (`static`). Hvert scenario som sjekker et varsel må derfor være sin egen HTTP-forespørsel — lag og endre ikke i samme kall.
- **Tidsvinduer.** Rate limit-vinduet er 60 s, men Playground er treg. Senk grensen med `amendo_test_rl` og gi hver sak egen kvote.
- **`woocommerce_logger_log_message`** kjøres én gang per logg-handler; tell bare første handler.
- **jQuery `ready`** kjører asynkront i jsdom når dokumentet er lastet. Vent på `new Promise(r => w.jQuery(r))` før du klikker.
- **REST er ikke wp-admin.** `get_current_screen()` og `wp-admin/includes/*` (f.eks. `wp_create_post_autosave`) finnes ikke; `require_once` det du trenger, og kall `admin_enqueue_scripts` bare på vår egen krok.
- **Sletting i en tidligere sak** fjerner testdata senere saker trenger; lag fersk data i et eget steg.
- **Ikke rediger `mu/` mens en suite kjører** — en halvskrevet fil gir fatal feil i alle forespørsler.
- **Shell:** skriv redigeringsskript som filer (ikke heredoc i Bash) — `\n` og `\\` blir spist, og Windows-stier (`/wordpress/...`) blir gjort om av Git Bash uten `MSYS_NO_PATHCONV=1`.
