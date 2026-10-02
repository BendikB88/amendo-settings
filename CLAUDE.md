# amendo-settings

WordPress-plugin («Amendo Innstillinger») som gir headless-frontendene (Next.js
på Vercel) innhold og innstillinger via REST under `amendo-settings/v1`. Brukes
av flere butikker (emmk, Garçon, Aanerud), så **alt må være bakoverkompatibelt**:
nye felter i REST-svar er greit, endrede eller fjernede er det ikke.

## Struktur

- `amendo-settings.php` — header/versjon, admin-meny, lagring (`admin_post_amendo_save_settings`), REST-ruter, admin-siden.
- `includes/` — én fil per område: `forside.php`, `frakt.php`, `cargonizer.php`, `hemmeligheter.php`, `revalidering.php`, `kategorirad.php`, `betaling.php`. Hver fil har en toppkommentar som forklarer hvorfor den ser ut som den gjør — les den før du endrer.
- `assets/admin.js` — kjører i wp-admin der jQuery er i noConflict-modus: all kode inne i `jQuery(function($) { … })`.
- `tests/playground/` — testene (under). Er `export-ignore` og skal aldri med i pluginen hos butikkene.

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
| `frakt-beregning` | `/frakt`: priser, MVA, gratis-frakt-grense, skjul betalt frakt, avviste varer |
| `frakt-validering` | `/frakt`: input-validering, ingen sesjon/cookie/persistent kurv, ekte kurv urørt |
| `rate-limit` | `/frakt`: grense per IP, unntak med `X-Amendo-Kasse-Secret` |
| `sporing-og-secret` | sporingslyttere stille under `/frakt`; kasse-secret i admin |
| `admin` | «Skjul betalt frakt», tilgang (`manage_woocommerce`) |
| `admin-js` | `admin.js` i jsdom: secrets, mediebiblioteket |
| `revalidering` | LiteSpeed-tømming og `/api/revalidate` ved lagring |
| `bilder-og-sider` | bilde-ID/-mål på forsiden; WP-sider varsler frontenden |
| `kategorirad` | kategoriraden: lagring, `/settings`, admin, jsdom, varsel ved endring |
| `cargonizer` | `_lcfwc_*` på REST-ordrer, mot stubber |
| `betaling` | `/adyen-session` og `/gateway-redirect` mot stubber av Amendo Gateway og Vipps |

Ny suite: legg en fil i `suiter/`, og sett den inn i `REKKEFOLGE` i `kjor.mjs`
(ukjente filer kjøres sist i alfabetisk rekkefølge).

### Fallgruver (alle har gitt falske feil før)

- **Ett varsel per forespørsel.** Revalidering fra sider og kategorier sendes maks én gang per PHP-forespørsel (`static`). Hvert scenario som sjekker et varsel må derfor være sin egen HTTP-forespørsel — lag og endre ikke i samme kall.
- **Tidsvinduer.** Rate limit-vinduet er 60 s, men Playground er treg. Senk grensen med `amendo_test_rl` og gi hver sak egen kvote.
- **`woocommerce_logger_log_message`** kjøres én gang per logg-handler; tell bare første handler.
- **jQuery `ready`** kjører asynkront i jsdom når dokumentet er lastet. Vent på `new Promise(r => w.jQuery(r))` før du klikker.
- **REST er ikke wp-admin.** `get_current_screen()` og `wp-admin/includes/*` (f.eks. `wp_create_post_autosave`) finnes ikke; `require_once` det du trenger, og kall `admin_enqueue_scripts` bare på vår egen krok.
- **Sletting i en tidligere sak** fjerner testdata senere saker trenger; lag fersk data i et eget steg.
- **Ikke rediger `mu/` mens en suite kjører** — en halvskrevet fil gir fatal feil i alle forespørsler.
- **Shell:** skriv redigeringsskript som filer (ikke heredoc i Bash) — `\n` og `\\` blir spist, og Windows-stier (`/wordpress/...`) blir gjort om av Git Bash uten `MSYS_NO_PATHCONV=1`.
