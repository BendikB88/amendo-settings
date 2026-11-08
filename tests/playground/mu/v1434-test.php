<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Sesongseksjoner og kontaktskjemaet (1.4.34), suiten `sesong-kontakt`.
// E-post fanges i v1429-test.php (amendo_test_epost_fang), innstillinger
// settes med v1423-sett, admin-siden via v1414-side.
if (!defined('ABSPATH')) exit;

// Lavere bremser for testen.
add_filter('amendo_kontakt_brems', function($v) { return (int) get_option('amendo_test_kontakt_brems', 0) ?: $v; });
add_filter('amendo_kontakt_brems_totalt', function($v) { return (int) get_option('amendo_test_kontakt_totalt', 0) ?: $v; });

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };

    /** Kategorien, etiketten og en kategori som kan slettes (én gang), og dagens dato i butikkens tidssone. */
    $r('/v1434-termer', function() {
        $lag = function($navn, $taks) {
            $t = term_exists($navn, $taks) ?: wp_insert_term($navn, $taks);
            return (int) (is_array($t) ? $t['term_id'] : $t);
        };
        return [
            'jul'    => $lag('Jul 1434', 'product_cat'),
            'paske'  => $lag('Påske 1434', 'product_tag'),
            'borte'  => $lag('Borte 1434', 'product_cat'),
            'idag'   => wp_date('Y-m-d'),
        ];
    });

    $r('/v1434-slett-term', function($req) {
        return ['ok' => (bool) wp_delete_term((int) $req->get_param('id'), (string) $req->get_param('taks'))];
    });

    /** Lagring som admin, med kallene til frontenden fanget: { post } → { kall, sesong }. */
    $r('/v1434-lagre', function($req) {
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        $kall = [];
        $fang = function($pre, $args, $url) use (&$kall) {
            $kall[] = ['url' => $url, 'tags' => json_decode((string) ($args['body'] ?? ''), true)['tags'] ?? null];
            return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        };
        add_filter('pre_http_request', $fang, 10, 3);
        $_POST = (($req->get_json_params() ?: [])['post'] ?? []) + ['action' => 'amendo_save_settings'];
        $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('amendo_settings_nonce'), '_wp_http_referer' => '/'];
        $kast = function() { throw new Exception('redirect'); };
        add_filter('wp_redirect', $kast);
        try { do_action('admin_post_amendo_save_settings'); } catch (Exception $e) {}
        remove_filter('wp_redirect', $kast);
        remove_filter('pre_http_request', $fang, 10);
        wp_clear_scheduled_hook('amendo_revalider_frontend');
        return [
            'kall'    => $kall,
            'sesong'  => json_decode((string) get_option('amendo_sesongseksjoner', 'null'), true),
            'kontakt' => get_option('amendo_kontaktskjema_epost', null),
        ];
    });

    /** E-postene som er fanget (v1429-test.php). */
    $r('/v1434-epost', function() {
        return ['epost' => get_option('amendo_test_epost', []), 'admin' => get_option('admin_email')];
    });

    /** Nullstiller bremsene for kontaktskjemaet. */
    $r('/v1434-nullstill', function() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%amendo_kontakt_t_%' OR option_name LIKE '%amendo_kontakt_k_%'");
        wp_cache_flush();
        return ['ok' => true];
    });
});
