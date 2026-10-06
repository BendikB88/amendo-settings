<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Hjelperuter for suiten `bedrift-sok`: lag en bruker med org.nr-meta, tøm
// brukerne igjen, og nullstill rate limit-transientene for endepunktet.
if (!defined('ABSPATH')) exit;

// Lar suiten senke grensen, slik rate-limit-suiten gjør for /frakt.
add_filter('amendo_bedrift_sok_rate_limit', function($n) {
    $satt = get_option('amendo_test_bedrift_rl', null);
    return $satt === null ? $n : (int) $satt;
});

add_action('rest_api_init', function() {
    $r = function($path, $cb) {
        register_rest_route('amendo-test/v1', $path, ['methods' => 'GET,POST', 'callback' => $cb, 'permission_callback' => '__return_true']);
    };

    /** { epost, meta: { nøkkel: verdi } } → oppretter brukeren på nytt hver gang. */
    $r('/bedrift-bruker', function($req) {
        $p     = $req->get_json_params() ?: [];
        $epost = (string) ($p['epost'] ?? 'test@example.com');
        $meta  = (array) ($p['meta'] ?? []);

        if ($gammel = get_user_by('email', $epost)) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($gammel->ID);
        }

        $id = wp_insert_user([
            'user_login' => 'b' . substr(md5($epost), 0, 10),
            'user_email' => $epost,
            'user_pass'  => wp_generate_password(),
            'role'       => 'customer',
        ]);
        if (is_wp_error($id)) return ['feil' => $id->get_error_message()];

        foreach ($meta as $k => $v) update_user_meta($id, $k, $v);
        return ['id' => $id];
    });

    $r('/bedrift-nullstill', function() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%amendo_bedrift_sok_rl_%'");
        wp_cache_flush();
        return ['ok' => true];
    });
});
