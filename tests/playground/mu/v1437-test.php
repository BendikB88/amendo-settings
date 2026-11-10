<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Bedriftspakker: e-post og oppfølging (1.4.37), suiten `bp-oppfolging`.
// Produktene fra /v1431-produkter, brukerne fra /v1428-klargjor, e-post
// fanget i mu/v1429-test.php, innstillinger med /v1423-sett.
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };
    $som = function($rolle) {
        if ($rolle === 'admin') { wp_set_current_user(get_user_by('login', 'admin')->ID); return 0; }
        if ($rolle === 'ingen') { wp_set_current_user(0); return 0; }
        $login = 'f1437' . $rolle;
        $u = get_user_by('login', $login) ?: get_user_by('id', wp_insert_user(['user_login' => $login, 'user_pass' => 'x', 'role' => $rolle, 'user_email' => $login . '@example.com']));
        wp_set_current_user($u->ID);
        return $u->ID;
    };

    /** Oppfølgingen, varselet og fangede e-poster for { ider }. */
    $r('/v1437-tilstand', function($req) {
        $ut = [];
        foreach ((array) $req->get_param('ider') as $id) {
            wp_cache_delete((int) $id, 'user_meta');
            $ut[$id] = [
                'oppfolging' => get_user_meta((int) $id, '_amendo_bp_oppfolging', true),
                'varslet'    => get_user_meta((int) $id, '_amendo_bp_varslet', true),
            ];
        }
        return ['brukere' => $ut, 'epost' => get_option('amendo_test_epost', [])];
    });

    /** Flytt tidspunktet for siste varsel { id, sekunder } bakover. */
    $r('/v1437-eldre-varsel', function($req) {
        $id = (int) $req->get_param('id');
        $v = get_user_meta($id, '_amendo_bp_varslet', true);
        if (is_array($v)) { $v['tid'] -= (int) $req->get_param('sekunder'); update_user_meta($id, '_amendo_bp_varslet', $v); }
        return ['ok' => is_array($v)];
    });

    /** «Lagre» i admin: { post, rolle, nonce } → admin_post_amendo_bp_oppfolging, med omdirigeringen. */
    $r('/v1437-handling', function($req) use ($som) {
        $p = $req->get_json_params() ?: [];
        $som($p['rolle'] ?? 'admin');
        $_POST = wp_slash((array) ($p['post'] ?? [])) + ['action' => 'amendo_bp_oppfolging'];
        $_REQUEST = $_POST + ['_wp_http_referer' => '/'];
        if ($p['nonce'] ?? true) $_REQUEST['_wpnonce'] = wp_create_nonce('amendo_bp_oppfolging');
        $til = null;
        $kast = function($url) use (&$til) { $til = $url; throw new Exception('redirect'); };
        $dø = function() { return function($m) { throw new Exception('die'); }; };
        add_filter('wp_redirect', $kast);
        add_filter('wp_die_handler', $dø);
        add_filter('wp_die_ajax_handler', $dø);
        add_filter('wp_die_json_handler', $dø);
        $stoppet = false;
        try { do_action('admin_post_amendo_bp_oppfolging'); } catch (Exception $e) { $stoppet = $e->getMessage() === 'die'; }
        remove_filter('wp_redirect', $kast);
        remove_filter('wp_die_handler', $dø);
        remove_filter('wp_die_ajax_handler', $dø);
        remove_filter('wp_die_json_handler', $dø);
        wp_set_current_user(0);
        return ['til' => $til, 'stoppet' => $stoppet];
    });

    /** Lagring fra brukerprofilen: { id, post, rolle, egen } (egen = personal_options_update). */
    $r('/v1437-profil-lagre', function($req) use ($som) {
        $p = $req->get_json_params() ?: [];
        $som($p['rolle'] ?? 'admin');
        $_POST = wp_slash((array) ($p['post'] ?? []));
        do_action(!empty($p['egen']) ? 'personal_options_update' : 'edit_user_profile_update', (int) $p['id']);
        $_POST = [];
        wp_set_current_user(0);
        wp_cache_delete((int) $p['id'], 'user_meta');
        return ['oppfolging' => get_user_meta((int) $p['id'], '_amendo_bp_oppfolging', true)];
    });

    /** Admin-siden med { get } i $_GET (filteret og meldingen). */
    $r('/v1437-side', function($req) {
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        $_GET = (array) ($req->get_json_params()['get'] ?? []);
        ob_start(); amendo_settings_page(); $html = ob_get_clean();
        header('Content-Type: text/html; charset=utf-8');
        echo $html; exit;
    });
});
