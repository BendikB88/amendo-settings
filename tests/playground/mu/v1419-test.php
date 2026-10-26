<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Hjelperute for suiten `tellix` (1.4.19): skriver kopien av Tellix-kundelista
// direkte, som en eldre versjon av pluginen lagret den (uten 'format'). Se
// amendo_tellix_liste_les() i includes/moduler/b2b-tellix.php.
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    /** { kunder: [...], format?: int } → kopien, hentet nå. */
    register_rest_route('amendo-test/v1', '/v1419-liste', ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => function($req) {
        $p = $req->get_json_params() ?: [];
        $data = ['hentet' => time(), 'kunder' => (array) ($p['kunder'] ?? [])];
        if (isset($p['format'])) $data['format'] = (int) $p['format'];
        update_option('amendo_tellix_kundeliste', $data, false);
        return ['ok' => true];
    }]);
});
