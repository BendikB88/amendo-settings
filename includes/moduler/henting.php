<?php
/**
 * Modulen «Hentedato på ordrer» (1.4.14). Lastes bare når modulen er på — se
 * includes/moduler.php.
 *
 * Den nye kassen (Next.js) skriver ÉTT sett felt på ordren, uansett avdeling:
 *     _leveringstype   'henting' | 'levering'
 *     _hentested       avdelingens navn («Stasjonen»), bare ved henting
 *     _hentedato       Y-m-d
 *     _hentetid        HH:MM
 * Den gamle kassen skrev Checkout Field Editor-par per avdeling
 * (`hentedato_stasjonen`, `hentetid_helsebygget`, `hentetid_kaker` …, d/m/Y).
 *
 * ⚠⚠ ALT HER GJELDER BARE ORDRER MED `_hentedato`. Gamle ordrer vises,
 * skrives ut og sendes til Tellix akkurat som før. Unntaket er datofilteret,
 * som med vilje finner begge.
 *
 * Erstatter snuttene «Legg til felt i print» og «Orderfilter» på garcon.no,
 * som kan deaktiveres når denne er på.
 *
 * 1. Tellix: `amendo_tellix_delivery_fields` får paret _hentedato/_hentetid
 *    FØRST i `pairs`, så gatewayen leser det nye paret før de gamle.
 * 2. Hentelinjen: ordresiden i wp-admin, kolonne i ordrelisten, e-postene til
 *    butikk og kunde, og WP Overnight Order List (`wpo_wcol_custom_row`).
 * 3. Filter i ordrelisten (HPOS) på hentedato og hentested.
 */

if (!defined('ABSPATH')) exit;

/** De gamle datofeltene (d/m/Y) datofilteret også leter i. */
const AMENDO_HENTING_GAMLE_DATOFELT = ['hentedato_stasjonen', 'hentetid_helsebygget', 'hentetid_kaker'];

const AMENDO_HENTING_UKEDAGER = [1 => 'mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];

/** En gyldig Y-m-d-dato som DateTimeImmutable, ellers null. 2026-02-31 er ikke gyldig. */
function amendo_henting_dato($verdi) {
    $verdi = trim((string) $verdi);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $verdi)) return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $verdi, new DateTimeZone('UTC'));
    return ($d && $d->format('Y-m-d') === $verdi) ? $d : null;
}

function amendo_henting_ordre($ordre) {
    if ($ordre instanceof WC_Order) return $ordre;
    if (is_numeric($ordre)) { $o = wc_get_order((int) $ordre); return $o instanceof WC_Order ? $o : null; }
    if ($ordre instanceof WP_Post) { $o = wc_get_order($ordre->ID); return $o instanceof WC_Order ? $o : null; }
    return null;
}

/**
 * «Henting: Stasjonen, onsdag 21.10 kl. 11:15» / «Levering: onsdag 21.10 kl. 11:15».
 * null for ordrer uten gyldig `_hentedato` — de skal vises som før.
 */
function amendo_hentelinje($ordre) {
    $o = amendo_henting_ordre($ordre);
    if (!$o) return null;
    $d = amendo_henting_dato($o->get_meta('_hentedato'));
    if (!$d) return null;

    $levering = $o->get_meta('_leveringstype') === 'levering';
    $sted = $levering ? '' : trim((string) $o->get_meta('_hentested'));
    $tid  = trim((string) $o->get_meta('_hentetid'));

    $tekst = AMENDO_HENTING_UKEDAGER[(int) $d->format('N')] . ' ' . $d->format('d.m');
    if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $tid)) $tekst .= ' kl. ' . $tid;
    return ($levering ? 'Levering: ' : 'Henting: ') . ($sted !== '' ? $sted . ', ' : '') . $tekst;
}

// ── 1. Tellix ───────────────────────────────────────────────────────────────

/**
 * Legger paret _hentedato/_hentetid FØRST i `pairs`, men bare når ordren har
 * en gyldig `_hentedato`.
 *
 * Sjekket mot Tellix::get_delivery_datetime() i Amendo Gateway 1.36.13:
 *     ['pairs' => [['date' => …, 'time' => …], …], 'method' => 'hentested',
 *      'method_value' => 'Levering', 'delivery_time' => 'leveringstidspunkt']
 * - Gatewayen bruker det første paret med gyldig dato, og parse_date() godtar
 *   Y-m-d — så paret må stå først, ellers vinner et gammelt felt.
 * - `is_delivery` leser meta `hentested` (uten understrek). Nye ordrer har
 *   bare `_hentested`, så klokkeslettet hentes fra `_hentetid` og ikke fra
 *   `leveringstidspunkt`, også for leveringer.
 * Ser lista annerledes ut (ny gateway), returneres den urørt.
 */
add_filter('amendo_tellix_delivery_fields', 'amendo_henting_tellix_felt', 10, 2);

function amendo_henting_tellix_felt($config, $ordre = null) {
    $o = amendo_henting_ordre($ordre);
    if (!$o || !amendo_henting_dato($o->get_meta('_hentedato'))) return $config;
    if (!is_array($config) || !isset($config['pairs']) || !is_array($config['pairs'])) return $config;

    $par = array_values(array_filter($config['pairs'], function($p) {
        return !(is_array($p) && ($p['date'] ?? null) === '_hentedato');
    }));
    array_unshift($par, ['date' => '_hentedato', 'time' => '_hentetid']);
    $config['pairs'] = $par;
    return $config;
}

// ── 2. Hentelinjen ──────────────────────────────────────────────────────────

// Ordresiden i wp-admin.
add_action('woocommerce_admin_order_data_after_order_details', function($ordre) {
    $linje = amendo_hentelinje($ordre);
    if ($linje === null) return;
    echo '<p class="form-field form-field-wide amendo-hentelinje"><strong>' . esc_html($linje) . '</strong></p>';
});

// Kolonne i ordrelisten — HPOS og den gamle innleggslisten.
function amendo_henting_kolonner($kolonner) {
    $ut = [];
    foreach ($kolonner as $k => $v) {
        $ut[$k] = $v;
        if ($k === 'order_status') $ut['amendo_henting'] = 'Henting';
    }
    if (!isset($ut['amendo_henting'])) $ut['amendo_henting'] = 'Henting';
    return $ut;
}
add_filter('manage_woocommerce_page_wc-orders_columns', 'amendo_henting_kolonner');
add_filter('manage_edit-shop_order_columns', 'amendo_henting_kolonner');

function amendo_henting_kolonne($kolonne, $ordre) {
    if ($kolonne !== 'amendo_henting') return;
    $linje = amendo_hentelinje($ordre);
    echo $linje === null ? '–' : esc_html($linje);
}
add_action('manage_woocommerce_page_wc-orders_custom_column', 'amendo_henting_kolonne', 10, 2);
add_action('manage_shop_order_posts_custom_column', 'amendo_henting_kolonne', 10, 2);

// E-postene, til både butikk og kunde.
add_action('woocommerce_email_order_meta', function($ordre, $til_admin = false, $ren_tekst = false) {
    $linje = amendo_hentelinje($ordre);
    if ($linje === null) return;
    if ($ren_tekst) echo "\n" . $linje . "\n";
    else echo '<p class="amendo-hentelinje"><strong>' . esc_html($linje) . '</strong></p>';
}, 10, 3);

/**
 * WP Overnight Order List — samme krok som snutten «Legg til felt i print».
 * ⚠ Ordren finnes blant argumentene (objekt eller ID); vi leter i alle, så
 * rekkefølgen på argumentene ikke betyr noe.
 */
add_action('wpo_wcol_custom_row', function(...$argumenter) {
    foreach ($argumenter as $a) {
        $o = amendo_henting_ordre($a);
        if (!$o) continue;
        $linje = amendo_hentelinje($o);
        if ($linje !== null) echo '<div class="amendo-hentelinje"><strong>' . esc_html($linje) . '</strong></div>';
        return;
    }
}, 10, 9);

// ── 3. Filter i ordrelisten (HPOS) ──────────────────────────────────────────
// ⚠ Feltene heter amendo_hentedato og amendo_hentested — IKKE amendo_delivery_date,
// som snutten «Orderfilter» bruker. Begge kan da stå på samme dag uten å
// filtrere hverandres spørring.

add_action('woocommerce_order_list_table_restrict_manage_orders', function($type = 'shop_order', $hvor = 'top') {
    if ($hvor !== 'top') return;
    $dato = sanitize_text_field(wp_unslash((string) ($_GET['amendo_hentedato'] ?? '')));
    $sted = sanitize_text_field(wp_unslash((string) ($_GET['amendo_hentested'] ?? '')));
    ?>
    <input type="date" name="amendo_hentedato" value="<?php echo esc_attr($dato); ?>" aria-label="Hentedato" title="Hentedato">
    <select name="amendo_hentested" aria-label="Hentested">
        <option value="">Alle hentesteder</option>
        <?php foreach (amendo_stengte_avdelingsnavn() as $n): ?>
            <option value="<?php echo esc_attr($n); ?>" <?php selected($sted, $n); ?>><?php echo esc_html($n); ?></option>
        <?php endforeach; ?>
        <option value="__levering" <?php selected($sted, '__levering'); ?>>Levering</option>
    </select>
    <?php
}, 10, 2);

add_filter('woocommerce_order_list_table_prepare_items_query_args', 'amendo_henting_filter_args');

/** Legger hentedato/-sted til spørringen. Finner nye (Y-m-d) og gamle (d/m/Y) ordrer. */
function amendo_henting_filter_args($args) {
    $meta = [];
    $d = amendo_henting_dato(sanitize_text_field(wp_unslash((string) ($_GET['amendo_hentedato'] ?? ''))));
    if ($d) {
        $dato = ['relation' => 'OR', ['key' => '_hentedato', 'value' => $d->format('Y-m-d')]];
        foreach (AMENDO_HENTING_GAMLE_DATOFELT as $felt) $dato[] = ['key' => $felt, 'value' => $d->format('d/m/Y')];
        $meta[] = $dato;
    }
    $sted = sanitize_text_field(wp_unslash((string) ($_GET['amendo_hentested'] ?? '')));
    if ($sted === '__levering') $meta[] = ['key' => '_leveringstype', 'value' => 'levering'];
    elseif ($sted !== '') {
        // ⚠ En levering hentes ikke noe sted, selv om ordren skulle ha et
        // `_hentested` — samme regel som hentelinjen.
        $meta[] = ['key' => '_hentested', 'value' => $sted];
        $meta[] = ['relation' => 'OR',
            ['key' => '_leveringstype', 'compare' => 'NOT EXISTS'],
            ['key' => '_leveringstype', 'value' => 'levering', 'compare' => '!='],
        ];
    }

    if (!$meta) return $args;
    $finnes = isset($args['meta_query']) && is_array($args['meta_query']) ? $args['meta_query'] : [];
    $args['meta_query'] = array_merge(['relation' => 'AND'], $finnes ? [$finnes] : [], $meta);
    return $args;
}
