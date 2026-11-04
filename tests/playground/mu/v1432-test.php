<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Oppslag på ordre-meta (1.4.32), suiten `meta-oppslag`: hvordan
// wc_get_orders() behandler meta-filtrene i lagringsmåten Playground bruker,
// og at amendo_ordrer_med_meta() bare gir ordrene med metaen.
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };

    /** { nokkel, verdier: [..] } → én ordre per verdi, og én ordre uten metaen (nyest). */
    $r('/v1432-ordrer', function($req) {
        $p = $req->get_json_params() ?: [];
        $nokkel = (string) ($p['nokkel'] ?? '_amendo_test_1432');
        $ider = [];
        foreach ((array) ($p['verdier'] ?? []) as $v) {
            $o = wc_create_order(['status' => 'processing']);
            $o->update_meta_data($nokkel, (string) $v);
            $o->save();
            $ider[(string) $v] = $o->get_id();
        }
        $uten = wc_create_order(['status' => 'processing']);
        $uten->save();
        return ['ider' => $ider, 'uten' => $uten->get_id()];
    });

    /** Hva hver spørringsform gir for { nokkel, verdi }, og lagringsmåten. */
    $r('/v1432-diagnose', function($req) {
        $nokkel = (string) $req->get_param('nokkel');
        $verdi  = (string) $req->get_param('verdi');
        $util = 'Automattic\WooCommerce\Utilities\OrderUtil';
        $q = function($args) { return array_map('intval', wc_get_orders($args + ['limit' => 3, 'return' => 'ids', 'type' => 'shop_order'])); };
        return [
            'wc'          => defined('WC_VERSION') ? WC_VERSION : null,
            'hpos'        => class_exists($util) ? (bool) $util::custom_orders_table_usage_is_enabled() : null,
            'meta_query'  => $q(['meta_query' => [['key' => $nokkel, 'value' => $verdi]]]),
            'meta_key'    => $q(['meta_key' => $nokkel, 'meta_value' => $verdi]),
            'egen_nokkel' => $q([$nokkel => $verdi]),
            'hjelper'     => function_exists('amendo_ordrer_med_meta') ? amendo_ordrer_med_meta($nokkel, $verdi, 3) : null,
        ];
    });
});
