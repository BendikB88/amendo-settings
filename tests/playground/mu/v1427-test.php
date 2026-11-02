<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Faktura uten kundegruppe (1.4.27), suiten `faktura`. Tellix-kundelista er
// stubben i tellix-stub.php (satt med /v1414-klargjor).
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };
    $som = function($rolle) {
        if ($rolle === 'admin') { wp_set_current_user(get_user_by('login', 'admin')->ID); return; }
        $login = 'f1427' . $rolle;
        $u = get_user_by('login', $login) ?: get_user_by('id', wp_insert_user(['user_login' => $login, 'user_pass' => 'x', 'role' => $rolle, 'user_email' => $login . '@example.com']));
        wp_set_current_user($u->ID);
    };

    /** Meta-feltene faktura avhenger av, for brukeren med e-posten. */
    $r('/v1427-meta', function($req) {
        $u = get_user_by('email', (string) $req->get_param('epost'));
        if (!$u) return ['finnes' => false];
        $m = [];
        foreach (['_amendo_ingen_faktura', '_b2b_status', '_tellixCustomerUuId', '_tellixGroupId'] as $k) $m[$k] = get_user_meta($u->ID, $k, true);
        return ['finnes' => true, 'id' => $u->ID, 'meta' => $m];
    });

    /** Bryteren i Aktive B2B-kunder: { verdi, rolle, nonce } → admin_post_amendo_b2b_faktura. */
    $r('/v1427-knapp', function($req) use ($som) {
        $p = $req->get_json_params() ?: [];
        $som((string) ($p['rolle'] ?? 'admin'));
        $_POST = ['ingen_faktura' => (string) ($p['verdi'] ?? ''), 'action' => 'amendo_b2b_faktura'];
        $_REQUEST = $_POST + ['_wp_http_referer' => '/'];
        if ($p['nonce'] ?? true) $_REQUEST['_wpnonce'] = wp_create_nonce('amendo_b2b_faktura');
        $til = null;
        $kast = function($url) use (&$til) { $til = $url; throw new Exception('redirect'); };
        $dø = function() { return function($m) { throw new Exception('die'); }; };
        // I REST går wp_die via wp_die_json_handler, ikke wp_die_handler.
        $dieFiltre = ['wp_die_handler', 'wp_die_json_handler', 'wp_die_ajax_handler'];
        add_filter('wp_redirect', $kast);
        foreach ($dieFiltre as $f) add_filter($f, $dø);
        $stoppet = false;
        try { do_action('admin_post_amendo_b2b_faktura'); } catch (Exception $e) { $stoppet = $e->getMessage() === 'die'; }
        remove_filter('wp_redirect', $kast);
        foreach ($dieFiltre as $f) remove_filter($f, $dø);
        return ['til' => $til, 'stoppet' => $stoppet];
    });

    /**
     * Brukerprofilen: { id, rolle, post } — med post kjøres lagringen
     * (edit_user_profile_update, som WordPress gjør etter nonce-sjekken),
     * så vises feltet. Svarer { html, sperret }.
     */
    $r('/v1427-profil', function($req) use ($som) {
        $p = $req->get_json_params() ?: [];
        $som((string) ($p['rolle'] ?? 'admin'));
        $id = ($p['id'] ?? '') === 'selv' ? get_current_user_id() : (int) ($p['id'] ?? 0);
        if (isset($p['post'])) {
            $_POST = (array) $p['post'];
            do_action(get_current_user_id() === $id ? 'personal_options_update' : 'edit_user_profile_update', $id);
        }
        ob_start();
        do_action(get_current_user_id() === $id ? 'show_user_profile' : 'edit_user_profile', get_userdata($id));
        $html = ob_get_clean();
        return ['html' => $html, 'sperret' => get_user_meta($id, '_amendo_ingen_faktura', true)];
    });
});
