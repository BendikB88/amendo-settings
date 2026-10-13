<?php
/**
 * Testruter for kupong i /frakt (1.4.9). Bare for Playground — ingen
 * tilgangskontroll. Se tests/playground/suiter/kupong.mjs.
 */
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    register_rest_route('amendo-test/v1', '/ubetalt-filter', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => 'amendo_test_ubetalt_filter',
    ]);
    register_rest_route('amendo-test/v1', '/kuponger', [
        'methods'             => 'GET,POST',
        'permission_callback' => '__return_true',
        'callback'            => 'amendo_test_kuponger',
    ]);
});

/** Lager testkupongene (idempotent) og kan sette gratisfraktens krav. */
function amendo_test_kuponger(WP_REST_Request $req) {
    $lag = function($kode, callable $sett) {
        $id = wc_get_coupon_id_by_code($kode);
        $k = new WC_Coupon($id ?: 0);
        $k->set_code($kode);
        $sett($k);
        $k->save();
        return $k->get_id();
    };
    $ider = [
        'ti'        => $lag('ti', function($k) { $k->set_discount_type('percent'); $k->set_amount(10); }),
        'utlopt'    => $lag('utlopt', function($k) { $k->set_discount_type('percent'); $k->set_amount(10); $k->set_date_expires(time() - DAY_IN_SECONDS); }),
        'min2000'   => $lag('min2000', function($k) { $k->set_discount_type('percent'); $k->set_amount(10); $k->set_minimum_amount(2000); }),
        'oppbrukt'  => $lag('oppbrukt', function($k) { $k->set_discount_type('percent'); $k->set_amount(10); $k->set_usage_limit(1); $k->set_usage_count(1); }),
        'perkunde'  => $lag('perkunde', function($k) { $k->set_discount_type('percent'); $k->set_amount(10); $k->set_usage_limit_per_user(1); }),
        'bare-kari' => $lag('bare-kari', function($k) { $k->set_discount_type('percent'); $k->set_amount(10); $k->set_email_restrictions(['kari@example.com']); }),
        'fraktfri'  => $lag('fraktfri', function($k) { $k->set_discount_type('fixed_cart'); $k->set_amount(0); $k->set_free_shipping(true); }),
    ];
    /*
     * «perkunde» (én per kunde) med EKTE ordrer i hver status (1.4.10: bare
     * betalte teller). brukt@ = completed, venter@ = on-hold, ubetalt@ =
     * pending, feilet@ = failed, avbrutt@ = cancelled. Og spokelse@ står bare
     * i `_used_by`, uten ordre — teller heller ikke.
     */
    if (!get_option('amendo_test_perkunde_ordrer')) {
        foreach (['brukt' => 'completed', 'venter' => 'on-hold', 'ubetalt' => 'pending', 'feilet' => 'failed', 'avbrutt' => 'cancelled'] as $navn => $status) {
            $o = wc_create_order();
            $produkter = wc_get_products(['limit' => 1, 'status' => 'publish', 'type' => 'simple']);
            if ($produkter) $o->add_product($produkter[0], 1);
            $o->set_billing_email("$navn@example.com");
            $o->apply_coupon('perkunde');
            $o->calculate_totals();
            $o->set_status($status);
            $o->save();
        }
        add_post_meta($ider['perkunde'], '_used_by', 'spokelse@example.com');
        update_option('amendo_test_perkunde_ordrer', 1);
    }

    $krav = $req->get_param('fs_requires');
    if (is_string($krav) && in_array($krav, ['min_amount', 'either', 'coupon', 'both'], true)) {
        foreach (WC_Shipping_Zones::get_zones() as $sone) {
            foreach ($sone['shipping_methods'] as $metode) {
                if ($metode->id !== 'free_shipping') continue;
                $navn = "woocommerce_free_shipping_{$metode->instance_id}_settings";
                $innst = get_option($navn, []);
                $innst['requires'] = $krav;
                update_option($navn, $innst);
            }
        }
    }
    return $ider;
}

/** Hva `woocommerce_cancel_unpaid_order` svarer for ulike ordrer (1.4.10). */
function amendo_test_ubetalt_filter() {
    $lag = function(array $meta, string $via, string $transaksjon = '') {
        $o = wc_create_order();
        $o->set_created_via($via);
        foreach ($meta as $k => $v) $o->update_meta_data($k, $v);
        if ($transaksjon !== '') $o->set_transaction_id($transaksjon);
        $o->save();
        return $o;
    };
    $svar = function($o) { return apply_filters('woocommerce_cancel_unpaid_order', 'checkout' === $o->get_created_via(), $o); };
    return [
        'headless'           => $svar($lag(['_nextjs_kasse' => '1'], 'rest-api')),
        'rest_uten_merke'    => $svar($lag([], 'rest-api')),
        'kasse'              => $svar($lag([], 'checkout')),
        'headless_transaksjon' => $svar($lag(['_nextjs_kasse' => '1'], 'rest-api', 'PSP123')),
    ];
}
