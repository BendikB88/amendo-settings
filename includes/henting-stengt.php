<?php
/**
 * Stengt for henting (1.4.39): ukedager og enkeltdatoer da ingen kan hente.
 *
 * ⚠ Ikke det samme som «Dager som ikke teller i prosesseringstid»
 * (`amendo_henting_ikke_dager`). De gjør bare prosesseringstiden lengre: en
 * kake bestilt fredag med 1 virkedag og lørdag/søndag som ikke-dager er klar
 * mandag, men kunden kan fortsatt hente på en lørdag. Dagene her kan ikke
 * velges som hentedato i det hele tatt.
 *
 *   amendo_henting_stengte_ukedager  JSON-liste med ukedagnavn som i ikke-dager
 *                                    («mandag» … «søndag»), i ukerekkefølge
 *   amendo_henting_stengte_datoer    JSON-liste med «dd.mm» (hvert år, f.eks.
 *                                    24.12 og 17.05) eller «dd.mm.åååå» (bare
 *                                    den dagen), sortert
 *
 * Ut som lister i `/settings` (`henting.stengte_ukedager`,
 * `henting.stengte_datoer`) og i `/leveringsregler` (`global.` med samme navn),
 * alltid med, tomme til noe er krysset av. Ingen butikk får noe nytt før
 * listene fylles ut.
 *
 * Kassen: en ordre som LAGES via REST (wc/v3/orders) med `_hentedato` (Y-m-d,
 * som den nye kassen skriver — se includes/moduler/henting.php) på en stengt dag
 * avvises med 400 `amendo_henting_stengt` før den lagres. Gjelder ikke
 * `_leveringstype = levering`, ikke ordrer uten `_hentedato` (den gamle kassen)
 * og ikke endringer av ordrer som finnes (butikken kan flytte en ordre selv).
 * Frontenden bør skjule dagene i datovelgeren; sjekken her er sikringen.
 *
 * Lagres bare med markøren `henting_stengt[finnes]`: en lagring fra et skjema
 * uten feltene skal ikke tømme listene.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_HENTING_STENGT_UKEDAGER = [1 => 'mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
const AMENDO_HENTING_STENGT_MAKS = 200;

/** De lagrede ukedagene, bare gyldige navn, i ukerekkefølge. */
function amendo_henting_stengte_ukedager() {
    $data = json_decode((string) get_option('amendo_henting_stengte_ukedager', '[]'), true);
    return amendo_henting_stengt_rens_ukedager(is_array($data) ? $data : []);
}

/** De lagrede datoene, bare gyldige, normalisert og sortert. */
function amendo_henting_stengte_datoer() {
    $data = json_decode((string) get_option('amendo_henting_stengte_datoer', '[]'), true);
    return amendo_henting_stengt_rens_datoer(is_array($data) ? $data : []);
}

function amendo_henting_stengt_rens_ukedager(array $inn) {
    $inn = array_map(function($d) { return is_string($d) ? mb_strtolower(trim($d)) : ''; }, $inn);
    return array_values(array_filter(AMENDO_HENTING_STENGT_UKEDAGER, function($dag) use ($inn) { return in_array($dag, $inn, true); }));
}

/**
 * «24.12», «24/12», «1.5.» → «24.12»/«01.05»; «24.12.2026», «2026-12-24» →
 * «24.12.2026». null når den ikke er en dato (31.02, 29.02.2027, tekst).
 */
function amendo_henting_stengt_dato($verdi) {
    $v = trim((string) $verdi);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) $m = [0, $m[3], $m[2], $m[1]];
    elseif (!preg_match('/^(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{4})?)?\.?$/', $v, $m)) return null;
    $d = (int) $m[1]; $mnd = (int) $m[2]; $aar = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
    // Uten år: 29.02 er gyldig (skuddår), så sjekk mot et skuddår.
    if (!checkdate($mnd, $d, $aar ?: 2024)) return null;
    return sprintf('%02d.%02d', $d, $mnd) . ($aar ? '.' . $aar : '');
}

/** Gyldige og unike, sortert på måned/dag (og år). Resten droppes. */
function amendo_henting_stengt_rens_datoer(array $inn) {
    $ut = [];
    foreach ($inn as $v) {
        $d = is_string($v) ? amendo_henting_stengt_dato($v) : null;
        if ($d !== null && !in_array($d, $ut, true)) $ut[] = $d;
        if (count($ut) >= AMENDO_HENTING_STENGT_MAKS) break;
    }
    $nokkel = function($d) { $p = explode('.', $d); return sprintf('%02d%02d%04d', $p[1], $p[0], $p[2] ?? 0); };
    usort($ut, function($a, $b) use ($nokkel) { return strcmp($nokkel($a), $nokkel($b)); });
    return $ut;
}

/**
 * Er henting stengt på Y-m-d-datoen? Gir grunnen («søndag», «24.12»,
 * «24.12.2026») eller null. Ugyldig dato = null (ikke denne sjekkens sak).
 */
function amendo_henting_stengt($ymd) {
    $ymd = trim((string) $ymd);
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) ? DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC')) : false;
    if (!$d || $d->format('Y-m-d') !== $ymd) return null;
    $dag = AMENDO_HENTING_STENGT_UKEDAGER[(int) $d->format('N')];
    if (in_array($dag, amendo_henting_stengte_ukedager(), true)) return $dag;
    $datoer = amendo_henting_stengte_datoer();
    foreach ([$d->format('d.m'), $d->format('d.m.Y')] as $k) {
        if (in_array($k, $datoer, true)) return $k;
    }
    return null;
}

// ── Kassen ──────────────────────────────────────────────────────────────────

add_filter('woocommerce_rest_pre_insert_shop_order_object', function($ordre, $request, $ny) {
    if (!$ny || !$ordre instanceof WC_Order) return $ordre;
    if ($ordre->get_meta('_leveringstype') === 'levering') return $ordre;
    $dato = (string) $ordre->get_meta('_hentedato');
    if ($dato === '' || amendo_henting_stengt($dato) === null) return $ordre;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $dato, new DateTimeZone('UTC'));
    $tekst = AMENDO_HENTING_STENGT_UKEDAGER[(int) $d->format('N')] . ' ' . $d->format('d.m');
    return new WP_Error('amendo_henting_stengt', 'Det er stengt for henting ' . $tekst . '. Velg en annen dag.', ['status' => 400, 'hentedato' => $dato]);
}, 5, 3);

// ── /settings og /leveringsregler ───────────────────────────────────────────

add_filter('amendo_settings_svar', function($svar) {
    $svar['henting'] = (is_array($svar['henting'] ?? null) ? $svar['henting'] : []) + [
        'stengte_ukedager' => amendo_henting_stengte_ukedager(),
        'stengte_datoer'   => amendo_henting_stengte_datoer(),
    ];
    return $svar;
}, 5);

// ── Lagring ─────────────────────────────────────────────────────────────────

/** Fra $_POST (allerede wp_unslash-et). Krever markøren `henting_stengt[finnes]`. */
add_action('amendo_lagre_innstillinger', function(array $post) {
    $inn = $post['henting_stengt'] ?? null;
    if (!is_array($inn) || empty($inn['finnes'])) return;
    $dager = amendo_henting_stengt_rens_ukedager(is_array($inn['ukedager'] ?? null) ? $inn['ukedager'] : []);
    $datoer = amendo_henting_stengt_rens_datoer(preg_split('/[\s,;]+/', (string) ($inn['datoer'] ?? ''), -1, PREG_SPLIT_NO_EMPTY));
    update_option('amendo_henting_stengte_ukedager', wp_json_encode($dager, JSON_UNESCAPED_UNICODE));
    update_option('amendo_henting_stengte_datoer', wp_json_encode($datoer));
});

// ── Admin ───────────────────────────────────────────────────────────────────

/** Feltet i kortet Henting, rett under ikke-dagene. */
function amendo_henting_stengt_felt() {
    $dager = amendo_henting_stengte_ukedager();
    ?>
    <div class="amendo-field" id="henting-stengt">
        <input type="hidden" name="henting_stengt[finnes]" value="1">
        <label>Stengt for henting</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">
            <?php foreach (AMENDO_HENTING_STENGT_UKEDAGER as $dag): ?>
            <label style="display:flex;align-items:center;gap:4px;font-size:13px;font-weight:normal;text-transform:none;letter-spacing:0;">
                <input type="checkbox" name="henting_stengt[ukedager][]" value="<?php echo esc_attr($dag); ?>" <?php checked(in_array($dag, $dager, true)); ?>>
                <?php echo esc_html(ucfirst($dag)); ?>
            </label>
            <?php endforeach; ?>
        </div>
        <label for="henting_stengt_datoer" style="margin-top:10px;">Stengte datoer</label>
        <textarea id="henting_stengt_datoer" name="henting_stengt[datoer]" rows="3" placeholder="24.12, 17.05"><?php echo esc_textarea(implode(', ', amendo_henting_stengte_datoer())); ?></textarea>
        <p class="field-help">Dager kunden ikke kan velge som hentedato. «24.12» gjelder hvert år, «24.12.2026» bare det året. Skill med komma eller linjeskift; det som ikke er en dato, lagres ikke. Ikke det samme som dagene over, som bare gjør prosesseringstiden lengre. Kassen avviser en ordre med henting på en stengt dag.</p>
    </div>
    <?php
}
