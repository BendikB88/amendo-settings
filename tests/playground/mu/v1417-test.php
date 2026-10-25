<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Hjelperuter for suiten `helsebygget` (1.4.17): ansatte med PIN, token og
// loggen. Se includes/helsebygget.php og includes/moduler/b2b-helsebygget.php.
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    $r = function($path, $cb) {
        register_rest_route('amendo-test/v1', $path, ['methods' => 'GET,POST', 'callback' => $cb, 'permission_callback' => '__return_true']);
    };

    /** Tøm ansatte, logg, grensen og PIN-visningen. */
    $r('/v1417-nullstill', function() {
        global $wpdb;
        delete_option('amendo_helsebygget_ansatte');
        delete_option('amendo_helsebygget_logg');
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%amendo_hb_pin_rl_%' OR option_name LIKE '%amendo_hb_ny_pin_%'");
        wp_cache_flush();
        return ['ok' => true];
    });

    /** Bare grensen for feil forsøk. */
    $r('/v1417-nullstill-rl', function() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%amendo_hb_pin_rl_%'");
        wp_cache_flush();
        return ['ok' => true];
    });

    /**
     * Handlingene i fanen via admin_post_amendo_hb_ansatt, som admin.
     * { post: {...}, nonce: bool } → hvor den sendte deg, og siden etterpå.
     */
    $r('/v1417-handling', function($req) {
        $p = $req->get_json_params() ?: [];
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        $_POST = ($p['post'] ?? []) + ['action' => 'amendo_hb_ansatt'];
        $_REQUEST = $_POST + ['_wp_http_referer' => '/'];
        if ($p['nonce'] ?? true) $_REQUEST['_wpnonce'] = wp_create_nonce('amendo_hb_ansatt');
        $til = null;
        $kast = function($url) use (&$til) { $til = $url; throw new Exception('redirect'); };
        $dø = function() { return function($m) { throw new Exception('die'); }; };
        // I REST går wp_die() via wp_die_json_handler, ikke wp_die_handler.
        add_filter('wp_redirect', $kast);
        add_filter('wp_die_handler', $dø);
        add_filter('wp_die_json_handler', $dø);
        $stoppet = false;
        try { do_action('admin_post_amendo_hb_ansatt'); } catch (Exception $e) { $stoppet = $e->getMessage() === 'die'; }
        remove_filter('wp_redirect', $kast);
        remove_filter('wp_die_handler', $dø);
        remove_filter('wp_die_json_handler', $dø);
        return ['til' => $til, 'stoppet' => $stoppet];
    });

    /** Lagret som det står i databasen. */
    $r('/v1417-tilstand', function() {
        return [
            'ansatte_raa' => get_option('amendo_helsebygget_ansatte', null),
            'ansatte'     => array_values(array_map(function($a) { return ['id' => $a['id'], 'navn' => $a['navn'], 'v' => $a['v']]; }, function_exists('amendo_hb_ansatte') ? amendo_hb_ansatte() : [])),
            'logg'        => function_exists('amendo_hb_logg') ? amendo_hb_logg() : null,
            'produkter'   => json_decode((string) get_option('amendo_helsebygget_produkter', '[]'), true),
        ];
    });

    /** { id, alder } → token for den ansatte som gikk ut for `alder` sekunder siden. */
    $r('/v1417-utlopt-token', function($req) {
        $p = $req->get_json_params() ?: [];
        $a = amendo_hb_ansatte()[(string) ($p['id'] ?? '')] ?? null;
        if (!$a) return ['token' => null];
        $data = amendo_hb_b64(wp_json_encode(['a' => $a['id'], 'v' => (int) $a['v'], 'exp' => time() - (int) ($p['alder'] ?? 1)]));
        return ['token' => $data . '.' . amendo_hb_b64(hash_hmac('sha256', $data, amendo_hb_token_nokkel(), true))];
    });

    /** { id, antall } → skriver så mange endringer i loggen. */
    $r('/v1417-fyll-logg', function($req) {
        $p = $req->get_json_params() ?: [];
        $a = amendo_hb_ansatte()[(string) ($p['id'] ?? '')] ?? null;
        for ($i = 0; $a && $i < (int) ($p['antall'] ?? 0); $i++) amendo_hb_logg_skriv($a, [], [$i + 1]);
        return ['antall' => count(amendo_hb_logg())];
    });
});
