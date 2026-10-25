<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Hjelperuter for suiten `leveringsregler` (1.4.18): helligdagene regnet ut
// fra en fast dato. Se includes/leveringsregler.php.
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    /** { fra: 'Y-m-d', aar: [..] } → de neste 12 månedene fra `fra`, og hele år. */
    register_rest_route('amendo-test/v1', '/v1418-helligdager', ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => function($req) {
        $p = $req->get_json_params() ?: [];
        $aar = [];
        foreach ((array) ($p['aar'] ?? []) as $a) $aar[(string) $a] = amendo_helligdager_aar((int) $a);
        return [
            'neste' => amendo_helligdager_neste($p['fra'] ?? null),
            'aar'   => (object) $aar,
            'i_dag' => wp_date('Y-m-d'),
        ];
    }]);
});
