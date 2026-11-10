<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Stengt for henting (1.4.39), suiten `henting-stengt`. Lagring via
// /v1414-lagre, admin-siden via /v1414-side, optionene via /opt.
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    $r = function($path, $cb) {
        register_rest_route('amendo-test/v1', $path, ['methods' => 'GET,POST', 'callback' => $cb, 'permission_callback' => '__return_true']);
    };

    /**
     * En ordre via ekte wc/v3/orders, som kassen: { meta: {nøkkel: verdi}, id? }.
     * Med id: PUT på ordren som finnes. → { status, kode, melding, data, id, nye }
     */
    $r('/v1439-ordre', function($req) {
        $p = $req->get_json_params() ?: [];
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        $meta = [];
        foreach ((array) ($p['meta'] ?? []) as $k => $v) $meta[] = ['key' => $k, 'value' => $v];
        $foer = wc_get_orders(['limit' => -1, 'return' => 'ids']);
        $id = (int) ($p['id'] ?? 0);
        $kall = new WP_REST_Request($id ? 'PUT' : 'POST', '/wc/v3/orders' . ($id ? '/' . $id : ''));
        $kall->set_header('content-type', 'application/json');
        $kall->set_body(wp_json_encode(['status' => 'pending', 'meta_data' => $meta]));
        $svar = rest_do_request($kall);
        $data = $svar->get_data();
        $etter = wc_get_orders(['limit' => -1, 'return' => 'ids']);
        wp_set_current_user(0);
        $ny = $svar->get_status() < 300 ? (int) ($data['id'] ?? 0) : null;
        return [
            'status'  => $svar->get_status(),
            'kode'    => $data['code'] ?? null,
            'melding' => $data['message'] ?? null,
            'data'    => $data['data'] ?? null,
            'id'      => $ny,
            'hentedato' => $ny ? (string) wc_get_order($ny)->get_meta('_hentedato') : null,
            'nye'     => count(array_diff($etter, $foer)),
        ];
    });

    /** Optionene som de står i databasen. */
    $r('/v1439-tilstand', function() {
        return [
            'ukedager'  => get_option('amendo_henting_stengte_ukedager', null),
            'datoer'    => get_option('amendo_henting_stengte_datoer', null),
            'ikke_dager' => get_option('amendo_henting_ikke_dager', null),
        ];
    });
});
