<?php
/**
 * Kontaktskjemaet på nettsiden (1.4.34): POST amendo-settings/v1/kontakt.
 *
 *   Inn: { navn, epost, telefon?, emne?, melding, dato? ('Y-m-d'),
 *          antall_personer?, nettside? }
 *   Ut:  200 { ok: true }
 *        400 { ok: false, feil: 'ugyldig', felt: [feltene som er feil] }
 *        429 { ok: false, feil: 'brems', vent }      (sekunder)
 *        502 { ok: false, feil: 'ikke_sendt' }       (wp_mail feilet)
 *        401/403 uten riktig X-Amendo-Secret, også når secreten er tom
 *
 * Sendes med wp_mail til «Kontaktskjema sendes til» i fanen Kontakt (flere
 * skilt med komma), ellers kontakt-e-posten, ellers admin-e-posten. Emnet er
 * «Kontaktskjema: <emne>» (eller «Kontaktskjema fra <navn>»), og Reply-To er
 * avsenderen, så butikken kan svare rett fra e-posten. Ren tekst.
 *
 * ── Honningkrukke ─────────────────────────────────────────────────────────
 * `nettside` er et felt frontenden skjuler for mennesker. Er det fylt ut, er
 * avsenderen en robot: svaret er 200 { ok: true } som ellers, men ingenting
 * sendes, så roboten ikke lærer noe.
 *
 * ── Brems ─────────────────────────────────────────────────────────────────
 * ⚠⚠ Frontenden kaller fra serveren sin (Vercel), der mange kunder deler én
 * IP. Bremsen per kunde bruker derfor `X-Amendo-Klient-IP` når den er satt
 * (kundens IP slik frontenden så den). Den kan vi stole på fordi kallet
 * allerede er autentisert med secreten. Uten headeren gjelder REMOTE_ADDR.
 *
 *     per kunde-IP      5 per 10 minutter   (filter amendo_kontakt_brems)
 *     per REMOTE_ADDR  60 per 10 minutter   (filter amendo_kontakt_brems_totalt)
 *
 * Bare innsendinger som er gyldige (og honningkrukka), teller.
 *
 * ⚠ Ingenting fra skjemaet logges.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_KONTAKT_BREMS = 5;
const AMENDO_KONTAKT_BREMS_TOTALT = 60;
const AMENDO_KONTAKT_VINDU = 10 * MINUTE_IN_SECONDS;

add_action('rest_api_init', function() {
    register_rest_route('amendo-settings/v1', '/kontakt', [
        'methods'             => 'POST',
        'callback'            => 'amendo_kontakt_rest',
        'permission_callback' => 'amendo_bedrift_sok_tilgang', // fail-closed X-Amendo-Secret, se includes/bedrift.php
    ]);
});

/** Mottakerne: feltet i admin, ellers kontakt-e-posten, ellers admin-e-posten. */
function amendo_kontakt_mottakere() {
    foreach ([get_option('amendo_kontaktskjema_epost', ''), get_option('amendo_kontakt_epost', ''), get_option('admin_email', '')] as $kilde) {
        $ut = [];
        foreach (preg_split('/[\s,;]+/', (string) $kilde, -1, PREG_SPLIT_NO_EMPTY) as $a) {
            if (is_email($a)) $ut[] = $a;
        }
        if ($ut) return array_values(array_unique($ut));
    }
    return [];
}

/**
 * Teller ett kall mot en grense. Returnerer sekunder til vinduet er over når
 * grensen er nådd, ellers 0 (og kallet er talt).
 */
function amendo_kontakt_tell($nokkel, $maks) {
    $naa = time();
    $t = get_transient($nokkel);
    if (!is_array($t) || ($naa - (int) $t['start']) >= AMENDO_KONTAKT_VINDU) $t = ['start' => $naa, 'antall' => 0];
    if ($t['antall'] >= $maks) return max(1, AMENDO_KONTAKT_VINDU - ($naa - (int) $t['start']));
    $t['antall']++;
    set_transient($nokkel, $t, AMENDO_KONTAKT_VINDU);
    return 0;
}

/** Bremsen. Se toppen. Returnerer ventetiden, eller 0. */
function amendo_kontakt_brems(WP_REST_Request $req) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $klient = trim((string) $req->get_header('X-Amendo-Klient-IP'));
    if ($klient === '' || !filter_var($klient, FILTER_VALIDATE_IP)) $klient = $ip;
    $vent = amendo_kontakt_tell('amendo_kontakt_t_' . md5($ip), max(1, (int) apply_filters('amendo_kontakt_brems_totalt', AMENDO_KONTAKT_BREMS_TOTALT)));
    if ($vent) return $vent;
    return amendo_kontakt_tell('amendo_kontakt_k_' . md5($klient), max(1, (int) apply_filters('amendo_kontakt_brems', AMENDO_KONTAKT_BREMS)));
}

/** En linje uten linjeskift (emne og navn havner i e-posthodet). */
function amendo_kontakt_linje($v, $maks) {
    $v = is_scalar($v) ? (string) $v : '';
    return mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', sanitize_text_field($v))), 0, $maks);
}

function amendo_kontakt_rest(WP_REST_Request $req) {
    $p = $req->get_json_params();
    $p = is_array($p) ? $p : $req->get_params();

    $navn    = amendo_kontakt_linje($p['navn'] ?? '', 100);
    $epost   = is_scalar($p['epost'] ?? null) ? sanitize_email((string) $p['epost']) : '';
    $telefon = amendo_kontakt_linje($p['telefon'] ?? '', 30);
    $emne    = amendo_kontakt_linje($p['emne'] ?? '', 150);
    $melding = is_scalar($p['melding'] ?? null) ? trim(sanitize_textarea_field((string) $p['melding'])) : '';
    $dato    = is_scalar($p['dato'] ?? null) ? trim((string) $p['dato']) : '';
    $antall  = $p['antall_personer'] ?? null;

    $feil = [];
    if ($navn === '') $feil[] = 'navn';
    if ($epost === '' || !is_email($epost)) $feil[] = 'epost';
    if ($telefon !== '' && !preg_match('/^[0-9+()\s.-]{4,30}$/', $telefon)) $feil[] = 'telefon';
    if ($melding === '' || mb_strlen($melding) > 5000) $feil[] = 'melding';
    if ($dato !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $dato);
        if (!$d || $d->format('Y-m-d') !== $dato) $feil[] = 'dato';
    }
    if ($antall !== null && $antall !== '') {
        $ok = (is_int($antall) || (is_string($antall) && ctype_digit($antall))) && (int) $antall >= 1 && (int) $antall <= 10000;
        if (!$ok) $feil[] = 'antall_personer';
        $antall = (int) $antall;
    } else {
        $antall = null;
    }
    if ($feil) return new WP_REST_Response(['ok' => false, 'feil' => 'ugyldig', 'felt' => $feil], 400);

    $vent = amendo_kontakt_brems($req);
    if ($vent) return new WP_REST_Response(['ok' => false, 'feil' => 'brems', 'vent' => $vent], 429);

    // Honningkrukka: som om alt gikk bra.
    $hp = $p['nettside'] ?? '';
    if (!is_scalar($hp) || trim((string) $hp) !== '') return ['ok' => true];

    $til = amendo_kontakt_mottakere();
    $linjer = [
        'Navn: ' . $navn,
        'E-post: ' . $epost,
    ];
    if ($telefon !== '') $linjer[] = 'Telefon: ' . $telefon;
    if ($dato !== '') $linjer[] = 'Dato: ' . wp_date('d.m.Y', DateTimeImmutable::createFromFormat('!Y-m-d', $dato, wp_timezone())->getTimestamp());
    if ($antall !== null) $linjer[] = 'Antall personer: ' . $antall;
    $tekst = implode("\n", $linjer) . "\n\n" . $melding . "\n\n— Sendt fra kontaktskjemaet på nettsiden. Svar på denne e-posten for å svare avsenderen.\n";

    $emnelinje = $emne !== '' ? 'Kontaktskjema: ' . $emne : 'Kontaktskjema fra ' . $navn;
    $hoder = ['Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . str_replace(['<', '>', '"'], '', $navn) . ' <' . $epost . '>'];

    $sendt = $til && wp_mail($til, $emnelinje, $tekst, $hoder);
    if (!$sendt) return new WP_REST_Response(['ok' => false, 'feil' => 'ikke_sendt'], 502);
    return ['ok' => true];
}

// ── Admin: feltet i fanen Kontakt ───────────────────────────────────────────

function amendo_kontakt_felt() {
    ?>
    <div class="amendo-field" id="kontaktskjema">
        <input type="hidden" name="kontaktskjema_finnes" value="1">
        <label for="kontaktskjema_epost">Kontaktskjema sendes til</label>
        <input type="text" id="kontaktskjema_epost" name="kontaktskjema_epost" value="<?php echo esc_attr((string) get_option('amendo_kontaktskjema_epost', '')); ?>" placeholder="<?php echo esc_attr((string) (get_option('amendo_kontakt_epost', '') ?: get_option('admin_email', ''))); ?>">
        <p class="field-help">Hvem som får henvendelsene fra kontaktskjemaet på nettsiden (flere skilt med komma). Tomt = e-posten over, ellers admin-e-posten.</p>
    </div>
    <?php
}

add_action('amendo_lagre_innstillinger', function(array $post) {
    if (empty($post['kontaktskjema_finnes'])) return;
    $ut = [];
    foreach (preg_split('/[\s,;]+/', (string) ($post['kontaktskjema_epost'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $a) {
        if (is_email($a)) $ut[] = $a;
    }
    update_option('amendo_kontaktskjema_epost', implode(', ', array_unique($ut)), false);
});
