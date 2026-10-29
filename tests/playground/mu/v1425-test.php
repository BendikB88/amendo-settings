<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Instagram-feeden (1.4.25), suiten `instagram`. Ingen ekte kall: Instagram
// og Facebook Graph (graph.instagram.com, graph.facebook.com) besvares via
// pre_http_request ut fra optionen amendo_test_ig:
//   { media: { kode, svar }, forny: { kode, svar }, nede: bool }
// Kallene lagres i amendo_test_ig_kall som { vert, sti, token }. Tokenet
// lagres bare her i testen, for å sjekke hvilket token som ble brukt.
// Smash Balloon er stubben i v1425-sbi-stub.php.
if (!defined('ABSPATH')) exit;

add_filter('pre_http_request', function($svar, $args, $url) {
    $vert = parse_url($url, PHP_URL_HOST);
    if (!in_array($vert, ['graph.instagram.com', 'graph.facebook.com'], true)) return $svar;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $sti = (string) parse_url($url, PHP_URL_PATH);
    $kall = get_option('amendo_test_ig_kall', []);
    $kall[] = ['vert' => $vert, 'sti' => $sti, 'token' => $q['access_token'] ?? null, 'limit' => $q['limit'] ?? null];
    update_option('amendo_test_ig_kall', $kall, false);
    $c = json_decode((string) get_option('amendo_test_ig', '{}'), true) ?: [];
    if (!empty($c['nede'])) return new WP_Error('http_request_failed', 'cURL error 28: timeout');
    $del = $sti === '/refresh_access_token' ? ($c['forny'] ?? []) : ($c['media'] ?? []);
    return ['response' => ['code' => (int) ($del['kode'] ?? 200), 'message' => ''], 'body' => wp_json_encode($del['svar'] ?? []), 'headers' => [], 'cookies' => []];
}, 10, 3);

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };
    $som = function($rolle) {
        if ($rolle === 'shop_manager') {
            $u = get_user_by('login', 'sm1425') ?: get_user_by('id', wp_insert_user(['user_login' => 'sm1425', 'user_pass' => 'x', 'role' => 'shop_manager', 'user_email' => 'sm1425@example.com']));
            wp_set_current_user($u->ID);
        } else {
            wp_set_current_user(get_user_by('login', 'admin')->ID);
        }
    };

    /**
     * { option: verdi } — lister og objekter som JSON, null sletter. Med
     * `cache_tom: true` slettes mellomlageret, pausen og fornyingspausen.
     */
    $r('/v1425-sett', function($req) {
        $ok = true;
        foreach ($req->get_json_params() ?: [] as $opt => $v) {
            if ($opt === 'cache_tom') {
                foreach (['amendo_instagram_svar30', 'amendo_instagram_pause', 'amendo_instagram_forny_pause'] as $t) delete_transient($t);
                continue;
            }
            $ok = amendo_test_skriv_sikkert($opt, $v === null ? null : (is_array($v) ? wp_json_encode($v) : (string) $v)) && $ok;
        }
        return ['ok' => $ok];
    });

    /** Tabellen sbi_sources med kontoene { account_id, account_type, token, username, expires }. Tokenet krypteres. */
    $r('/v1425-sbi', function($req) {
        global $wpdb;
        $t = $wpdb->prefix . 'sbi_sources';
        $wpdb->query("CREATE TABLE IF NOT EXISTS $t (
            id bigint(20) unsigned NOT NULL auto_increment,
            account_id varchar(255) NOT NULL default '',
            account_type varchar(255) NOT NULL default '',
            privilege varchar(255) NOT NULL default '',
            access_token varchar(1000) NOT NULL default '',
            username varchar(255) NOT NULL default '',
            info text NOT NULL,
            error text NOT NULL,
            expires datetime NOT NULL,
            last_updated datetime NOT NULL,
            author bigint(20) unsigned NOT NULL default '1',
            connect_type varchar(100) NOT NULL default '',
            PRIMARY KEY  (id)
        )");
        $wpdb->query("DELETE FROM $t");
        $krypt = new SB_Instagram_Data_Encryption();
        foreach (($req->get_json_params() ?: [])['kontoer'] ?? [] as $k) {
            $wpdb->insert($t, [
                'account_id'   => $k['account_id'],
                'account_type' => $k['account_type'],
                'access_token' => $krypt->encrypt($k['token']),
                'username'     => $k['username'],
                'info'         => '{}',
                'error'        => '',
                'expires'      => $k['expires'],
                'last_updated' => gmdate('Y-m-d H:i:s'),
                'connect_type' => $k['connect_type'] ?? 'personal',
            ]);
        }
        $rader = $wpdb->get_results("SELECT account_id, access_token, expires FROM $t", ARRAY_A);
        return ['rader' => count($rader), 'kryptert' => array_map(function($r) { return $r['access_token']; }, $rader)];
    });

    $r('/v1425-tilstand', function() {
        global $wpdb;
        $sb = [];
        if (class_exists('SB_Instagram_Data_Encryption')) {
            $for = $wpdb->suppress_errors(true);
            foreach ((array) $wpdb->get_results("SELECT account_id, access_token, expires FROM {$wpdb->prefix}sbi_sources", ARRAY_A) as $rad) {
                $sb[$rad['account_id']] = ['token' => sbi_maybe_clean($rad['access_token']), 'expires' => $rad['expires']];
            }
            $wpdb->suppress_errors($for);
        }
        return [
            'kall'        => get_option('amendo_test_ig_kall', []),
            'token'       => get_option('amendo_instagram_token', null),
            'utloper'     => get_option('amendo_instagram_utloper', null),
            'fornyet'     => get_option('amendo_instagram_fornyet', null),
            'status'      => get_option('amendo_instagram_status', null),
            'siste'       => get_option('amendo_instagram_siste', null),
            'cache'       => get_transient('amendo_instagram_svar30'),
            'cache_tid'   => (int) get_option('_transient_timeout_amendo_instagram_svar30', 0) - time(),
            'pause'       => get_transient('amendo_instagram_pause'),
            'sb'          => $sb,
            'sb_fornyet'  => (int) get_option('amendo_test_sbi_fornyet', 0),
            'sb_lastet'   => function_exists('sbi_maybe_clean'),
            'autoload'    => $wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name = 'amendo_instagram_token'"),
        ];
    });

    /** Lagring fra admin: { post, rolle }. */
    $r('/v1425-lagre', function($req) use ($som) {
        $p = $req->get_json_params() ?: [];
        $som((string) ($p['rolle'] ?? 'admin'));
        $_POST = ($p['post'] ?? []) + ['action' => 'amendo_save_settings'];
        $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('amendo_settings_nonce'), '_wp_http_referer' => '/'];
        $kast = function() { throw new Exception('redirect'); };
        add_filter('wp_redirect', $kast);
        try { do_action('admin_post_amendo_save_settings'); } catch (Exception $e) {}
        remove_filter('wp_redirect', $kast);
        return [
            'token'   => get_option('amendo_instagram_token', null),
            'utloper' => get_option('amendo_instagram_utloper', null),
            'fornyet' => get_option('amendo_instagram_fornyet', null),
            'cache'   => get_transient('amendo_instagram_svar30'),
        ];
    });

    $r('/v1425-side', function($req) use ($som) {
        $som((string) $req->get_param('rolle'));
        ob_start(); amendo_settings_page(); $html = ob_get_clean();
        header('Content-Type: text/html; charset=utf-8');
        echo $html; exit;
    });
});
