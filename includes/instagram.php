<?php
/**
 * Instagram-feeden på forsiden (1.4.25): GET /amendo-settings/v1/instagram.
 *
 *     { brukernavn, innlegg: [{ bilde, lenke, tekst, dato, type }], oppdatert, reserve }
 *
 * - Kontoen er `ig_handle` fra forsiden (Amendo, ellers ACF), ellers
 *   «Instagram» under Butikk → Sosiale medier. Samme rekkefølge som emmk.
 * - `?antall=1–30`, standard 9 (som i 1.4.25). Utenfor 1–30 settes det til
 *   nærmeste grense; tekst eller desimaltall gir 9. Det hentes og mellomlagres
 *   alltid 30 innlegg, i ett kall, og svaret kuttes til antall — så ulike
 *   antall deler mellomlageret og gir aldri flere kall til Instagram.
 *   (Mellomlageret har et nytt navn i 1.4.26, så et gammelt med 9 brukes ikke.)
 * - `type` er 'bilde', 'video' eller 'karusell'. En
 *   video gir miniatyrbildet (thumbnail_url), aldri .mp4. En karusell gir
 *   første bilde, eller miniatyrbildet når det første er en video. Innlegg uten
 *   et https-bilde hoppes over.
 * - Mellomlagret i 1 time (transient). Feiler Instagram, er svaret det forrige
 *   gode (optionen amendo_instagram_siste) med `reserve: true`, og det gjøres
 *   ikke et nytt forsøk før om 5 minutter. Ruten er offentlig, så uten pausen
 *   kunne hver forespørsel mot en død token gått videre til Instagram.
 * - Ingen konto eller ingen tilkobling: 200 med tom liste. Forsiden skjuler da
 *   feeden, akkurat som før.
 *
 * ── Tilkoblingen ───────────────────────────────────────────────────────────
 *
 * 1. Instagram Feed (Smash Balloon, Pro eller gratis, 6.x) når den er
 *    installert og har kontoen i tabellen {prefix}sbi_sources (brukernavnet
 *    likt ig_handle). Tokenet er kryptert der; det dekrypteres med pluginens
 *    egen sbi_maybe_clean(). Smash Balloon fornyer selv tokenet (cron to
 *    ganger i døgnet). Som reserve gjør vi det samme som Smash Balloons egen
 *    feed og cron: er en 'basic'- eller 'personal'-konto innenfor
 *    fornyingsgrensen, kalles DERES SB_Instagram_Token_Refresher, som skriver
 *    nytt token og ny utløpsdato i deres tabell. Vi skriver aldri i tabellen
 *    selv. 'basic' og 'personal' går mot graph.instagram.com, 'business'
 *    (koblet via Facebook) mot graph.facebook.com — som Smash Balloon.
 * 2. Ellers et token lagt inn i Amendo-admin (Instagram API med Instagram-
 *    innlogging, graph.instagram.com/me/media). Det fornyes med
 *    refresh_access_token når det er over et døgn gammelt og har under 50
 *    dager igjen (eller utløpsdatoen ikke er kjent), og ny utløpsdato lagres.
 *
 * ⚠⚠ Tokenet sendes ALDRI ut: ikke i REST-svaret, ikke i admin-siden (bare
 * «Satt» og utløpsdatoen), og det logges ikke. Feltet i admin er tomt; tomt
 * felt ved lagring = urørt. Bare administrator kan bytte eller fjerne det.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_INSTAGRAM_ANTALL     = 9;  // standard for ?antall
const AMENDO_INSTAGRAM_MAKS       = 30; // hentes og mellomlagres
const AMENDO_INSTAGRAM_CACHE      = 3600;
const AMENDO_INSTAGRAM_PAUSE      = 300;
const AMENDO_INSTAGRAM_FORNY_DAGER = 50;

add_action('rest_api_init', function() {
    register_rest_route('amendo-settings/v1', '/instagram', [
        'methods'             => 'GET',
        'callback'            => 'amendo_instagram_rest',
        'permission_callback' => '__return_true',
    ]);
});

function amendo_instagram_rest(WP_REST_Request $req) {
    $svar = amendo_instagram_svar();
    $svar['innlegg'] = array_slice($svar['innlegg'], 0, amendo_instagram_antall($req->get_param('antall')));
    return new WP_REST_Response($svar, 200);
}

/** ?antall: heltall 1–30 (utenfor = nærmeste grense), ellers 9. */
function amendo_instagram_antall($v) {
    if (!is_scalar($v) || !preg_match('/^\s*-?\d+\s*$/', (string) $v)) return AMENDO_INSTAGRAM_ANTALL;
    return max(1, min(AMENDO_INSTAGRAM_MAKS, (int) trim((string) $v)));
}

// ── Kontoen ─────────────────────────────────────────────────────────────────

/** ig_handle fra forsiden (Amendo, ellers ACF), ellers sosiale medier. '' når ingen. */
function amendo_instagram_handle() {
    $forside = amendo_forside_rest_svar();
    $felter  = $forside ? $forside['fields'] : amendo_acf_felter(amendo_finn_side('forside'));
    foreach ([$felter['ig_handle'] ?? '', get_option('amendo_sosiale_instagram', '')] as $v) {
        $h = is_string($v) ? amendo_forside_saniter_verdi($v, 'instagram') : '';
        if ($h !== '') return $h;
    }
    return '';
}

// ── Smash Balloon ───────────────────────────────────────────────────────────

/** Raden i {prefix}sbi_sources for brukernavnet, eller null. */
function amendo_instagram_sb_rad($handle) {
    if (!function_exists('sbi_maybe_clean') || $handle === '') return null;
    global $wpdb;
    $tabell = $wpdb->prefix . 'sbi_sources';
    $for = $wpdb->suppress_errors(true);
    $rader = $wpdb->get_results("SELECT * FROM $tabell", ARRAY_A);
    $wpdb->suppress_errors($for);
    foreach (is_array($rader) ? $rader : [] as $r) {
        if (strcasecmp((string) ($r['username'] ?? ''), $handle) === 0 && (string) ($r['access_token'] ?? '') !== '') return $r;
    }
    return null;
}

/**
 * Smash Balloons egen fornyer, med deres grenser (40 dager før utløp, minst 10
 * minutter mellom forsøk). Som deres cron (SBI_Db::source_query 'expiring'):
 * bare 'personal' og 'basic'. true når et forsøk ble gjort.
 */
function amendo_instagram_sb_forny(array $rad) {
    if (!amendo_instagram_sb_ig_konto($rad)) return false;
    // 6.14 laster fila når pluginen starter; sikring for utgaver som ikke gjør det.
    if (!class_exists('SB_Instagram_Token_Refresher') && defined('SBI_PLUGIN_DIR') && is_file(trailingslashit(SBI_PLUGIN_DIR) . 'inc/class-sb-instagram-token-refresher.php')) {
        require_once trailingslashit(SBI_PLUGIN_DIR) . 'inc/class-sb-instagram-token-refresher.php';
    }
    if (!class_exists('SB_Instagram_Token_Refresher') || !class_exists('InstagramFeed\Builder\SBI_Source')) return false;
    try {
        $kontoer = \InstagramFeed\Builder\SBI_Source::convert_sources_to_connected_accounts([$rad]);
        $konto = is_array($kontoer) ? reset($kontoer) : null;
        if (!is_array($konto)
            || !SB_Instagram_Token_Refresher::refresh_time_has_passed_threshold($konto)
            || !SB_Instagram_Token_Refresher::minimum_time_interval_since_last_attempt_has_passed($konto)) return false;
        (new SB_Instagram_Token_Refresher($konto))->attempt_token_refresh();
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/** 'basic'/'personal' går mot graph.instagram.com, resten (business) mot graph.facebook.com — som buildUrl() hos dem. */
function amendo_instagram_sb_ig_konto(array $rad) {
    return in_array($rad['account_type'] ?? '', ['basic', 'personal'], true);
}

/** 'Y-m-d H:i:s' (UTC) fra Smash Balloon → tidsstempel. 2037 og senere = utløper ikke. */
function amendo_instagram_sb_utloper($expires) {
    $t = strtotime((string) $expires . ' UTC');
    return ($t && (int) gmdate('Y', $t) < 2037) ? $t : null;
}

// ── Kilden ──────────────────────────────────────────────────────────────────

/**
 * Hvor tokenet kommer fra, eller null.
 *   { kilde: 'smash_balloon'|'amendo', url (media-kallet UTEN token), token, utloper }
 * $forny = false: bare les (admin-siden).
 */
function amendo_instagram_kilde($handle, $forny = true) {
    $felt = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,children%7Bmedia_type,media_url,thumbnail_url%7D';
    $grense = '&limit=' . (AMENDO_INSTAGRAM_MAKS + 3);

    $rad = amendo_instagram_sb_rad($handle);
    if ($rad && $forny && amendo_instagram_sb_forny($rad)) $rad = amendo_instagram_sb_rad($handle) ?: $rad;
    if ($rad) {
        $vert = amendo_instagram_sb_ig_konto($rad) ? 'https://graph.instagram.com/' : 'https://graph.facebook.com/';
        return [
            'kilde'   => 'smash_balloon',
            'url'     => $vert . rawurlencode((string) $rad['account_id']) . '/media?fields=' . $felt . $grense,
            'token'   => (string) sbi_maybe_clean($rad['access_token']),
            'utloper' => amendo_instagram_sb_utloper($rad['expires'] ?? ''),
        ];
    }

    if ((string) get_option('amendo_instagram_token', '') === '') return null;
    if ($forny) amendo_instagram_forny_eget();
    $token = (string) get_option('amendo_instagram_token', '');
    if ($token === '') return null;
    return [
        'kilde'   => 'amendo',
        'url'     => 'https://graph.instagram.com/me/media?fields=' . $felt . $grense,
        'token'   => $token,
        'utloper' => ((int) get_option('amendo_instagram_utloper', 0)) ?: null,
    ];
}

/**
 * Forny tokenet fra Amendo-admin: over et døgn siden det ble lagt inn eller
 * fornyet, og under 50 dager igjen eller ukjent utløpsdato. Maks ett forsøk i
 * timen. Feiler det, står det gamle tokenet.
 */
function amendo_instagram_forny_eget() {
    $token = (string) get_option('amendo_instagram_token', '');
    if ($token === '') return;
    if (time() - (int) get_option('amendo_instagram_fornyet', 0) < DAY_IN_SECONDS) return;
    $utloper = (int) get_option('amendo_instagram_utloper', 0);
    if ($utloper && $utloper - time() > AMENDO_INSTAGRAM_FORNY_DAGER * DAY_IN_SECONDS) return;
    if (get_transient('amendo_instagram_forny_pause')) return;
    set_transient('amendo_instagram_forny_pause', 1, HOUR_IN_SECONDS);

    $svar = wp_remote_get('https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token&access_token=' . rawurlencode($token), ['timeout' => 10]);
    if (is_wp_error($svar) || (int) wp_remote_retrieve_response_code($svar) !== 200) return;
    $d = json_decode((string) wp_remote_retrieve_body($svar), true);
    if (!is_array($d) || !is_string($d['access_token'] ?? null) || $d['access_token'] === '' || !is_numeric($d['expires_in'] ?? null)) return;
    update_option('amendo_instagram_token', $d['access_token'], false);
    update_option('amendo_instagram_utloper', time() + (int) $d['expires_in'], false);
    update_option('amendo_instagram_fornyet', time(), false);
}

// ── Innleggene ──────────────────────────────────────────────────────────────

/** Innleggene fra Instagram, eller null når kallet feilet. */
function amendo_instagram_hent(array $kilde) {
    $svar = wp_remote_get($kilde['url'] . '&access_token=' . rawurlencode($kilde['token']), ['timeout' => 10]);
    $kode = is_wp_error($svar) ? 0 : (int) wp_remote_retrieve_response_code($svar);
    $d = $kode === 200 ? json_decode((string) wp_remote_retrieve_body($svar), true) : null;
    if (!is_array($d) || !is_array($d['data'] ?? null)) {
        // Bare HTTP-koden: svaret fra Instagram kan inneholde mer enn vi vil lagre.
        $for = get_option('amendo_instagram_status');
        update_option('amendo_instagram_status', ['ok' => is_array($for) ? ($for['ok'] ?? null) : null, 'feil' => time(), 'kode' => $kode, 'kilde' => $kilde['kilde']], false);
        return null;
    }
    $ut = [];
    foreach ($d['data'] as $p) {
        $i = is_array($p) ? amendo_instagram_innlegg($p) : null;
        if ($i) $ut[] = $i;
        if (count($ut) >= AMENDO_INSTAGRAM_MAKS) break;
    }
    update_option('amendo_instagram_status', ['ok' => time(), 'feil' => null, 'kode' => 200, 'kilde' => $kilde['kilde']], false);
    return $ut;
}

/** Ett innlegg fra Graph API → { bilde, lenke, tekst, dato, type }, eller null. */
function amendo_instagram_innlegg(array $p) {
    $typer = ['IMAGE' => 'bilde', 'VIDEO' => 'video', 'CAROUSEL_ALBUM' => 'karusell'];
    $type = $typer[strtoupper((string) ($p['media_type'] ?? ''))] ?? null;
    if (!$type) return null;

    $forste = $p['children']['data'][0] ?? [];
    if ($type === 'video') {
        $bilde = $p['thumbnail_url'] ?? '';
    } elseif ($type === 'karusell' && strtoupper((string) ($forste['media_type'] ?? '')) === 'VIDEO') {
        $bilde = $forste['thumbnail_url'] ?? ($p['thumbnail_url'] ?? '');
    } else {
        $bilde = $p['media_url'] ?? ($forste['media_url'] ?? '');
    }
    $bilde = amendo_instagram_https($bilde);
    if ($bilde === '' || preg_match('#\.(mp4|mov)(\?|$)#i', $bilde)) return null;

    $tid = is_string($p['timestamp'] ?? null) ? strtotime($p['timestamp']) : false;
    return [
        'bilde' => $bilde,
        'lenke' => amendo_instagram_https($p['permalink'] ?? ''),
        'tekst' => is_string($p['caption'] ?? null) ? wp_check_invalid_utf8($p['caption']) : '',
        'dato'  => $tid ? gmdate('c', $tid) : null,
        'type'  => $type,
    ];
}

function amendo_instagram_https($url) {
    return is_string($url) && stripos($url, 'https://') === 0 ? esc_url_raw($url, ['https']) : '';
}

// ── Svaret ──────────────────────────────────────────────────────────────────

function amendo_instagram_svar() {
    $handle = amendo_instagram_handle();
    if ($handle === '') return ['brukernavn' => null, 'innlegg' => [], 'oppdatert' => null, 'reserve' => false];

    $cache = get_transient('amendo_instagram_svar30');
    if (is_array($cache) && ($cache['brukernavn'] ?? null) === $handle) return $cache;

    $reserve = function() use ($handle) {
        $siste = get_option('amendo_instagram_siste');
        if (is_array($siste) && ($siste['brukernavn'] ?? null) === $handle) return ['reserve' => true] + $siste;
        return ['brukernavn' => $handle, 'innlegg' => [], 'oppdatert' => null, 'reserve' => true];
    };
    if (get_transient('amendo_instagram_pause') === $handle) return $reserve();

    $kilde = amendo_instagram_kilde($handle);
    $innlegg = $kilde ? amendo_instagram_hent($kilde) : null;
    if ($innlegg === null) {
        set_transient('amendo_instagram_pause', $handle, AMENDO_INSTAGRAM_PAUSE);
        return $kilde ? $reserve() : ['brukernavn' => $handle, 'innlegg' => [], 'oppdatert' => null, 'reserve' => false];
    }

    $svar = ['brukernavn' => $handle, 'innlegg' => $innlegg, 'oppdatert' => gmdate('c'), 'reserve' => false];
    set_transient('amendo_instagram_svar30', $svar, AMENDO_INSTAGRAM_CACHE);
    update_option('amendo_instagram_siste', $svar, false);
    return $svar;
}

function amendo_instagram_tom_cache() {
    delete_transient('amendo_instagram_svar30');
    delete_transient('amendo_instagram_pause');
}

// ── Admin ───────────────────────────────────────────────────────────────────

/**
 * Fra lagringen ($_POST, wp_unslash-et). Krever markøren instagram_finnes og
 * administrator. Tomt felt = urørt; «Fjern» sletter.
 */
function amendo_instagram_lagre(array $post) {
    if (empty($post['instagram_finnes']) || !current_user_can('manage_options')) return;
    if (!empty($post['instagram_token_fjern'])) {
        foreach (['amendo_instagram_token', 'amendo_instagram_utloper', 'amendo_instagram_fornyet'] as $o) delete_option($o);
        amendo_instagram_tom_cache();
        return;
    }
    $ny = preg_replace('/\s+/', '', (string) ($post['instagram_token'] ?? ''));
    if ($ny === '' || !preg_match('/^[A-Za-z0-9._\-|]{20,1000}$/', $ny)) return;
    update_option('amendo_instagram_token', $ny, false);
    delete_option('amendo_instagram_utloper');
    update_option('amendo_instagram_fornyet', time(), false);
    amendo_instagram_tom_cache();
}

/** Kortet i Butikk-fanen. Viser aldri tokenet. */
function amendo_instagram_kort() {
    $handle = amendo_instagram_handle();
    $kilde  = $handle !== '' ? amendo_instagram_kilde($handle, false) : null;
    $sb     = $kilde && $kilde['kilde'] === 'smash_balloon';
    $eget   = (string) get_option('amendo_instagram_token', '') !== '';
    $status = get_option('amendo_instagram_status');
    $admin  = current_user_can('manage_options');
    $dato   = function($t) { return $t ? wp_date('j.n.Y H:i', (int) $t) : '–'; };
    ?>
    <div class="amendo-card" id="amendo-instagram">
        <h2>Instagram-feed</h2>
        <p class="amendo-desc">De siste 9 innleggene til forsiden (<code>/instagram</code>), mellomlagret i én time. Kontoen er brukernavnet under Forside → Instagram, ellers Instagram under sosiale medier.</p>
        <input type="hidden" name="instagram_finnes" value="1">
        <p class="field-help">
            Konto: <strong><?php echo $handle !== '' ? '@' . esc_html($handle) : 'ikke satt'; ?></strong><br>
            Tilkobling: <strong><?php echo $sb ? 'Instagram Feed (Smash Balloon)' : ($eget ? 'token lagt inn her' : 'ingen'); ?></strong>
            <?php if ($kilde): ?><br>Tokenet utløper: <strong><?php echo $kilde['utloper'] ? esc_html(wp_date('j.n.Y', $kilde['utloper'])) : ($sb ? 'utløper ikke' : 'ikke kjent ennå (hentes når tokenet fornyes, tidligst etter ett døgn)'); ?></strong><?php endif; ?>
            <?php if (is_array($status)): ?><br>Sist hentet: <?php echo esc_html($dato($status['ok'] ?? null)); ?><?php if (!empty($status['feil'])): ?> · Sist feilet: <?php echo esc_html($dato($status['feil']) . ' (HTTP ' . (int) $status['kode'] . ')'); ?><?php endif; ?><?php endif; ?>
        </p>
        <?php if ($sb): ?>
            <p class="field-help">Smash Balloon fornyer tokenet selv. Et token lagt inn her brukes bare når Smash Balloon ikke har kontoen.</p>
        <?php endif; ?>
        <?php if ($admin): ?>
            <div class="amendo-field">
                <label for="instagram_token"><?php echo $eget ? 'Bytt token' : 'Token'; ?> (Instagram API med Instagram-innlogging)</label>
                <input type="password" id="instagram_token" name="instagram_token" value="" autocomplete="off" placeholder="<?php echo $eget ? 'Satt — tomt felt beholder det' : 'IGAA…'; ?>">
                <p class="field-help">Langtidstoken fra Meta for utviklere. Lagres bare her og sendes aldri til nettsiden. Fornyes automatisk.</p>
            </div>
            <?php if ($eget): ?>
                <label><input type="checkbox" name="instagram_token_fjern" value="1"> Fjern tokenet</label>
            <?php endif; ?>
        <?php else: ?>
            <p class="field-help">Bare administrator kan legge inn eller bytte token.</p>
        <?php endif; ?>
    </div>
    <?php
}
