<?php
/**
 * Oppslag på ordre-meta (1.4.32).
 *
 * ⚠⚠ wc_get_orders() med meta-filter er IKKE til å stole på. Med HPOS virker
 * `meta_query`, men når ordrene lagres som innlegg (den gamle lagringen, som
 * Playground bruker), hopper WooCommerce over `meta_query` og egne nøkler
 * (`'_min_nokkel' => 'x'`) og gir de nyeste ordrene uansett meta. Bare
 * `meta_key`/`meta_value` filtreres der (WooCommerce 11.2.1, suiten
 * meta-oppslag). I 1.4.30 traff en Wolt-melding derfor feil ordre i testen.
 * Ingen feilmelding, bare feil svar.
 *
 * amendo_ordrer_med_meta() går rett mot tabellen lagringsmåten bruker
 * (wc_orders_meta eller postmeta), og hver ordre sjekkes med get_meta()
 * før den gis tilbake. Bruk den for alle oppslag på ordre-meta.
 */

if (!defined('ABSPATH')) exit;

/** Lagres ordrene i HPOS-tabellene? */
function amendo_ordrer_hpos() {
    $util = 'Automattic\WooCommerce\Utilities\OrderUtil';
    return class_exists($util) && $util::custom_orders_table_usage_is_enabled();
}

/**
 * ID-ene til ordrene (shop_order) med meta $nokkel = $verdi, nyeste først,
 * høyst $antall (1–200). Tom nøkkel eller verdi gir [].
 *
 * @return int[]
 */
function amendo_ordrer_med_meta($nokkel, $verdi, $antall = 20) {
    global $wpdb;
    $nokkel = (string) $nokkel;
    $verdi  = (string) $verdi;
    if ($nokkel === '' || $verdi === '') return [];
    $antall = max(1, min(200, (int) $antall));

    $ider = amendo_ordrer_hpos()
        ? $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT m.order_id FROM {$wpdb->prefix}wc_orders_meta m JOIN {$wpdb->prefix}wc_orders o ON o.id = m.order_id
             WHERE o.type = 'shop_order' AND m.meta_key = %s AND m.meta_value = %s ORDER BY m.order_id DESC LIMIT %d",
            $nokkel, $verdi, $antall))
        : $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'shop_order' AND pm.meta_key = %s AND pm.meta_value = %s ORDER BY pm.post_id DESC LIMIT %d",
            $nokkel, $verdi, $antall));

    $ut = [];
    foreach ((array) $ider as $id) {
        $o = wc_get_order((int) $id);
        if ($o instanceof WC_Order && (string) $o->get_meta($nokkel) === $verdi) $ut[] = $o->get_id();
    }
    return $ut;
}
