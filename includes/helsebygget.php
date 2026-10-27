<?php
/**
 * Helsebygget-admin med personlige PIN-koder (1.4.17).
 *
 * Før: frontenden hadde ÉN delt kode (HELSEBYGGET_ADMIN_PIN i Vercel), og
 * POST /helsebygget-produkter tok imot alt med X-Amendo-Secret. Ingen visste
 * hvem som hadde slått av en vare.
 *
 * Nå:
 *   - Ansatte legges inn i Amendo → B2B → Helsebygget (navn + PIN). PIN-en
 *     lages av serveren (6 sifre), vises ÉN gang når den lages, og lagres bare
 *     som HMAC med en nøkkel fra wp-config (wp_salt), ikke i databasen. Uten
 *     nøkkelen hjelper ikke en kopi av databasen til å finne kodene.
 *   - POST /helsebygget/admin-innlogging {pin} → {navn, token}. Tokenet er
 *     signert og varer i 12 timer. Ny PIN eller sletting av den ansatte gjør
 *     tokenene til den ansatte ugyldige med en gang (versjonsnummer i tokenet).
 *   - POST /helsebygget-produkter krever et gyldig token (X-Helsebygget-Token)
 *     i tillegg til X-Amendo-Secret, og logger navn, tid og endring. De siste
 *     200 vises i fanen.
 *   - Maks 5 FEIL forsøk per 15 minutter per IP. Riktige forsøk teller ikke.
 *
 * ⚠⚠ Fail-closed: er amendo_kasse_secret tom, avvises ALT (begge rutene). Før
 * slapp POST /helsebygget-produkter alt gjennom når secret var tom.
 *
 * ⚠ IP er REMOTE_ADDR, som i /bedrift-sok. Kalles ruten fra frontendens
 * server, deler alle én IP — grensen beskytter da mot gjetting gjennom
 * frontenden, ikke per ansatt. X-Forwarded-For brukes ikke: den kan settes av
 * hvem som helst.
 *
 * GET /helsebygget-produkter er uendret (åpen).
 *
 * Skjulte varianter (1.4.21): et produkt kan være aktivt på Helsebygget mens
 * bare noen av variantene kan bestilles der (Muffins: bare Sjokolade og
 * Vanilje). Lista over skjulte variasjons-ID-er står i
 * `amendo_helsebygget_skjulte_varianter` (JSON, som amendo_helsebygget_
 * produkter) og går ut som `skjulte_varianter` i GET. POST tar den imot
 * valgfritt: UTELATT = urørt (eldre frontender sender den ikke), [] = tøm,
 * noe annet enn en liste = 400. Bare ID-er som er en product_variation
 * lagres; resten droppes stille.
 * ⚠ Lista gjelder BARE Helsebygget. Den påvirker ikke WooCommerce (kjøpbar,
 * lager, nettbutikken) — frontenden håndhever den på produktsiden og i
 * /api/ordre.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_HB_TOKEN_VARER = 12 * HOUR_IN_SECONDS;
const AMENDO_HB_FEIL_GRENSE = 5;
const AMENDO_HB_FEIL_VINDU  = 15 * MINUTE_IN_SECONDS;
const AMENDO_HB_LOGG_MAKS   = 200;

// ── Ansatte ─────────────────────────────────────────────────────────────────

/** [id => ['id', 'navn', 'pin' (HMAC), 'v', 'opprettet']] */
function amendo_hb_ansatte() {
    $data = json_decode((string) get_option('amendo_helsebygget_ansatte', '[]'), true);
    $ut = [];
    foreach (is_array($data) ? $data : [] as $a) {
        if (is_array($a) && !empty($a['id']) && isset($a['pin'])) $ut[(string) $a['id']] = $a;
    }
    return $ut;
}

function amendo_hb_ansatte_lagre(array $ansatte) {
    update_option('amendo_helsebygget_ansatte', wp_json_encode(array_values($ansatte)), false);
}

/** HMAC av PIN-en. Nøkkelen står i wp-config (salts), ikke i databasen. */
function amendo_hb_pin_hash($pin) {
    return hash_hmac('sha256', (string) $pin, wp_salt('auth') . '|amendo-helsebygget-pin');
}

/** Ny, unik PIN på 6 sifre. */
function amendo_hb_ny_pin(array $ansatte) {
    $brukt = array_column($ansatte, 'pin');
    do {
        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    } while (in_array(amendo_hb_pin_hash($pin), $brukt, true));
    return $pin;
}

/** Legger til en ansatt. Returnerer [ansatt, pin]. */
function amendo_hb_legg_til($navn) {
    $navn = mb_substr(trim(sanitize_text_field((string) $navn)), 0, 80);
    if ($navn === '') return null;
    $ansatte = amendo_hb_ansatte();
    $pin = amendo_hb_ny_pin($ansatte);
    $a = ['id' => wp_generate_password(12, false), 'navn' => $navn, 'pin' => amendo_hb_pin_hash($pin), 'v' => 1, 'opprettet' => time()];
    $ansatte[$a['id']] = $a;
    amendo_hb_ansatte_lagre($ansatte);
    return [$a, $pin];
}

/** Ny PIN for en ansatt. Gamle tokener slutter å virke. Returnerer [ansatt, pin]. */
function amendo_hb_bytt_pin($id) {
    $ansatte = amendo_hb_ansatte();
    if (!isset($ansatte[$id])) return null;
    unset($ansatte[$id]['pin']);
    $pin = amendo_hb_ny_pin($ansatte);
    $ansatte[$id]['pin'] = amendo_hb_pin_hash($pin);
    $ansatte[$id]['v'] = (int) ($ansatte[$id]['v'] ?? 1) + 1;
    amendo_hb_ansatte_lagre($ansatte);
    return [$ansatte[$id], $pin];
}

function amendo_hb_slett($id) {
    $ansatte = amendo_hb_ansatte();
    if (!isset($ansatte[$id])) return false;
    unset($ansatte[$id]);
    amendo_hb_ansatte_lagre($ansatte);
    return true;
}

/** Den ansatte med PIN-en, eller null. */
function amendo_hb_finn_pin($pin) {
    $pin = preg_replace('/\D/', '', (string) $pin);
    if (strlen($pin) !== 6) return null;
    $hash = amendo_hb_pin_hash($pin);
    foreach (amendo_hb_ansatte() as $a) {
        if (hash_equals((string) $a['pin'], $hash)) return $a;
    }
    return null;
}

// ── Token ───────────────────────────────────────────────────────────────────

function amendo_hb_b64($s) {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function amendo_hb_unb64($s) {
    return base64_decode(strtr((string) $s, '-_', '+/'), true);
}

/** Nøkkelen: kasse-secret + wp_salt. Ny secret gjør alle tokener ugyldige. */
function amendo_hb_token_nokkel() {
    $secret = (string) get_option('amendo_kasse_secret', '');
    return $secret === '' ? '' : hash_hmac('sha256', 'amendo-helsebygget-token', $secret . '|' . wp_salt('auth'));
}

function amendo_hb_token_lag(array $ansatt) {
    $nokkel = amendo_hb_token_nokkel();
    $data = amendo_hb_b64(wp_json_encode(['a' => $ansatt['id'], 'v' => (int) $ansatt['v'], 'exp' => time() + AMENDO_HB_TOKEN_VARER]));
    return $data . '.' . amendo_hb_b64(hash_hmac('sha256', $data, $nokkel, true));
}

/** Den ansatte tokenet hører til, eller null (ugyldig, utløpt, ny PIN, slettet). */
function amendo_hb_token_sjekk($token) {
    $nokkel = amendo_hb_token_nokkel();
    $deler = explode('.', (string) $token);
    if ($nokkel === '' || count($deler) !== 2) return null;
    $forventet = amendo_hb_b64(hash_hmac('sha256', $deler[0], $nokkel, true));
    if (!hash_equals($forventet, $deler[1])) return null;
    $data = json_decode((string) amendo_hb_unb64($deler[0]), true);
    if (!is_array($data) || (int) ($data['exp'] ?? 0) < time()) return null;
    $a = amendo_hb_ansatte()[(string) ($data['a'] ?? '')] ?? null;
    return $a && (int) $a['v'] === (int) ($data['v'] ?? 0) ? $a : null;
}

// ── Grense for feil forsøk ──────────────────────────────────────────────────

function amendo_hb_feil_nokkel() {
    return 'amendo_hb_pin_rl_' . md5(isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
}

/** Sekunder til neste forsøk er lov, eller 0. */
function amendo_hb_sperret() {
    $t = get_transient(amendo_hb_feil_nokkel());
    if (!is_array($t) || (time() - (int) $t['start']) >= AMENDO_HB_FEIL_VINDU) return 0;
    return (int) $t['feil'] >= AMENDO_HB_FEIL_GRENSE ? max(1, AMENDO_HB_FEIL_VINDU - (time() - (int) $t['start'])) : 0;
}

function amendo_hb_registrer_feil() {
    $t = get_transient(amendo_hb_feil_nokkel());
    if (!is_array($t) || (time() - (int) $t['start']) >= AMENDO_HB_FEIL_VINDU) $t = ['start' => time(), 'feil' => 0];
    $t['feil']++;
    set_transient(amendo_hb_feil_nokkel(), $t, AMENDO_HB_FEIL_VINDU);
}

// ── Skjulte varianter (1.4.21) ──────────────────────────────────────────────

/** De lagrede variasjons-ID-ene, alltid en liste med int. */
function amendo_hb_skjulte_varianter() {
    $raa = get_option('amendo_helsebygget_skjulte_varianter', '[]');
    $data = is_array($raa) ? $raa : json_decode((string) $raa, true);
    return is_array($data) ? array_values(array_map('intval', $data)) : [];
}

/** En JSON-liste ([…]), ikke et objekt ({…}), en streng eller null. */
function amendo_hb_er_liste($v) {
    return is_array($v) && ($v === [] || array_keys($v) === range(0, count($v) - 1));
}

/** Bare heltall > 0, unike, og bare variasjoner. Resten droppes stille. */
function amendo_hb_skjulte_rens(array $liste) {
    $ut = [];
    foreach ($liste as $v) {
        if (is_int($v)) $id = $v;
        elseif (is_string($v) && ctype_digit($v)) $id = (int) $v;
        else continue;
        if ($id > 0 && !in_array($id, $ut, true) && get_post_type($id) === 'product_variation') $ut[] = $id;
    }
    return $ut;
}

// ── Logg ────────────────────────────────────────────────────────────────────

/**
 * De siste endringene, nyeste først: [tid, navn, lagt_til, fjernet, antall],
 * og fra 1.4.21 [skjulte_foer, skjulte_etter] (antall) når skjulte varianter
 * ble endret.
 */
function amendo_hb_logg() {
    $data = json_decode((string) get_option('amendo_helsebygget_logg', '[]'), true);
    return is_array($data) ? $data : [];
}

/** $skjulte: [før, etter] (lister med ID-er) når POST hadde skjulte_varianter, ellers null. */
function amendo_hb_logg_skriv(array $ansatt, array $foer, array $etter, ?array $skjulte = null) {
    $logg = amendo_hb_logg();
    $rad = [
        'tid'      => time(),
        'navn'     => (string) $ansatt['navn'],
        'ansatt'   => (string) $ansatt['id'],
        'lagt_til' => array_values(array_diff($etter, $foer)),
        'fjernet'  => array_values(array_diff($foer, $etter)),
        'antall'   => count($etter),
    ];
    if ($skjulte && (array_diff($skjulte[0], $skjulte[1]) || array_diff($skjulte[1], $skjulte[0]))) {
        $rad['skjulte_foer']  = count($skjulte[0]);
        $rad['skjulte_etter'] = count($skjulte[1]);
    }
    array_unshift($logg, $rad);
    update_option('amendo_helsebygget_logg', wp_json_encode(array_slice($logg, 0, AMENDO_HB_LOGG_MAKS)), false);
}

// ── REST ────────────────────────────────────────────────────────────────────

add_action('rest_api_init', function() {
    // Samme fail-closed secret som /bedrift-sok: tom secret avviser alt.
    register_rest_route('amendo-settings/v1', '/helsebygget/admin-innlogging', [
        'methods'             => 'POST',
        'callback'            => 'amendo_hb_rest_innlogging',
        'permission_callback' => 'amendo_bedrift_sok_tilgang',
    ]);
});

function amendo_hb_rest_innlogging(WP_REST_Request $req) {
    $vent = amendo_hb_sperret();
    if ($vent) {
        $svar = new WP_REST_Response(['code' => 'amendo_helsebygget_sperret', 'message' => 'For mange feil forsøk. Prøv igjen senere.'], 429);
        $svar->header('Retry-After', (string) $vent);
        return $svar;
    }
    $a = amendo_hb_finn_pin($req->get_param('pin'));
    if (!$a) {
        amendo_hb_registrer_feil();
        return new WP_REST_Response(['code' => 'amendo_helsebygget_feil_pin', 'message' => 'Feil PIN-kode.'], 401);
    }
    return ['navn' => $a['navn'], 'token' => amendo_hb_token_lag($a), 'utloper' => gmdate('c', time() + AMENDO_HB_TOKEN_VARER)];
}

/** Tilgang for POST /helsebygget-produkter: secret OG et gyldig token. */
function amendo_hb_produkter_tilgang(WP_REST_Request $req) {
    if (!amendo_bedrift_sok_tilgang($req)) return false;
    $token = (string) ($req->get_header('X-Helsebygget-Token') ?: '');
    if (!amendo_hb_token_sjekk($token)) {
        return new WP_Error('amendo_helsebygget_token', 'Logg inn med PIN-koden din på nytt.', ['status' => 401]);
    }
    return true;
}
