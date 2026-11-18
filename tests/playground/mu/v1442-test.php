<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Bedriftspakker: egen forespørsel (1.4.42), suiten `bp-egen`. Brukerne fra
// /v1428-klargjor, e-post fanget i mu/v1429-test.php, siden fra /v1437-side,
// profilen fra /v1431-profil.
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    /** Det som er lagret på { ider }, og antall ordrer i butikken. */
    register_rest_route('amendo-test/v1', '/v1442-tilstand', ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => function($req) {
        $ut = [];
        foreach ((array) $req->get_param('ider') as $id) {
            wp_cache_delete((int) $id, 'user_meta');
            $ut[$id] = [
                'egen'   => get_user_meta((int) $id, '_amendo_bp_egen', true),
                'onske'  => get_user_meta((int) $id, '_amendo_bp_onske', true),
                'ref'    => get_user_meta((int) $id, '_amendo_bp_ref', true),
                'tid'    => get_user_meta((int) $id, '_amendo_bp_tid', true),
                'status' => get_user_meta((int) $id, '_b2b_status', true),
            ];
        }
        return ['brukere' => $ut, 'ordrer' => count(wc_get_orders(['limit' => -1, 'return' => 'ids', 'status' => 'any']))];
    }]);
});
