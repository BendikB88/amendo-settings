<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Hjelperuter for suiten `moduler`. ⚠ En modul lastes når pluginen lastes, så
// «av» og «på» må sjekkes i HVER SIN forespørsel: sett tilstanden med
// /moduler-klargjor, og se på den i neste kall.
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    $r = function($path, $cb) {
        register_rest_route('amendo-test/v1', $path, ['methods' => 'GET,POST', 'callback' => $cb, 'permission_callback' => '__return_true']);
    };

    /**
     * { aktive: [..]|null, kundegrupper: {..}|null, slett_brukere: bool }
     * null sletter optionen.
     */
    $r('/moduler-klargjor', function($req) {
        $p = $req->get_json_params() ?: [];
        foreach (['aktive' => 'amendo_moduler', 'kundegrupper' => 'amendo_kundegruppe_config'] as $k => $opt) {
            if (!array_key_exists($k, $p)) continue;
            if ($p[$k] === null) delete_option($opt); else update_option($opt, wp_json_encode($p[$k]));
        }
        // Brukerne fra forrige sak: alle med org.nr eller søknad.
        if (!empty($p['slett_brukere'])) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach (['billing_org_number', '_b2b_status', '_tellixGroupId'] as $nokkel) {
                foreach (get_users(['meta_key' => $nokkel, 'fields' => 'ID']) as $id) wp_delete_user($id);
            }
        }
        return ['ok' => true];
    });

    /** Hva som er registrert i DENNE forespørselen. */
    $r('/moduler-inspiser', function() {
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        ob_start(); amendo_settings_page(); $html = ob_get_clean();
        $settings = rest_do_request(new WP_REST_Request('GET', '/amendo-settings/v1/settings'))->get_data();
        return [
            'aktive'             => amendo_moduler_aktive(),
            'b2b_lastet'         => function_exists('amendo_b2b_behandle'),
            'krok_faner'         => (bool) has_action('amendo_admin_faner'),
            'krok_paneler'       => (bool) has_action('amendo_admin_paneler'),
            'krok_etter_skjema'  => (bool) has_action('amendo_admin_etter_skjema'),
            'krok_lagre'         => (bool) has_action('amendo_lagre_innstillinger'),
            'krok_settings'      => (bool) has_filter('amendo_settings_svar'),
            'krok_admin_post'    => (bool) has_action('admin_post_amendo_b2b_soknad'),
            'html_fane_b2b'      => strpos($html, 'data-tab="b2b"') !== false,
            'html_panel_b2b'     => strpos($html, 'id="tab-b2b"') !== false,
            'html_kundegrupper'  => strpos($html, 'kundegruppe_config') !== false,
            'html_skjema_b2b'    => strpos($html, 'amendo-b2b-behandle') !== false,
            'html_fane_moduler'  => strpos($html, 'data-tab="moduler"') !== false,
            'html_b2b_avkrysset' => (bool) preg_match('/name="moduler\[paa\]\[b2b\]" value="1"\s+checked/', $html),
            'settings_har_kundegruppe_config' => array_key_exists('kundegruppe_config', $settings),
            // Gjennom JSON, slik frontenden ser det.
            'settings_json'      => json_decode(wp_json_encode($settings), true),
            'kundegruppe_config_raa' => array_key_exists('kundegruppe_config', $settings) ? wp_json_encode($settings['kundegruppe_config']) : null,
        ];
    });

    /** Lagring via admin_post_amendo_save_settings med { post: {...} }. */
    $r('/moduler-lagre', function($req) {
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        $post = ($req->get_json_params() ?: [])['post'] ?? [];
        $_POST = $post + ['action' => 'amendo_save_settings'];
        $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('amendo_settings_nonce'), '_wp_http_referer' => '/'];
        $kast = function() { throw new Exception('redirect'); };
        add_filter('wp_redirect', $kast);
        try { do_action('admin_post_amendo_save_settings'); } catch (Exception $e) {}
        remove_filter('wp_redirect', $kast);
        return [
            'aktive'       => json_decode((string) get_option('amendo_moduler', 'null'), true),
            'kundegrupper' => json_decode((string) get_option('amendo_kundegruppe_config', 'null'), true),
            // Som lagret: json_decode + REST gjør `{}` om til `[]`.
            'kundegrupper_raa' => get_option('amendo_kundegruppe_config', null),
        ];
    });

    /**
     * Søknadshandlingen via admin_post_amendo_b2b_soknad.
     * { post: {...}, rolle: 'admin'|'customer', nonce: bool }
     */
    $r('/b2b-behandle', function($req) {
        $p = $req->get_json_params() ?: [];
        if (($p['rolle'] ?? 'admin') === 'admin') {
            wp_set_current_user(get_user_by('login', 'admin')->ID);
        } else {
            wp_set_current_user(wp_insert_user(['user_login' => 'k' . wp_rand(), 'user_pass' => 'x', 'role' => 'customer']));
        }
        $_POST = ($p['post'] ?? []) + ['action' => 'amendo_b2b_soknad'];
        $_REQUEST = $_POST + ['_wp_http_referer' => '/'];
        if ($p['nonce'] ?? true) $_REQUEST['_wpnonce'] = wp_create_nonce('amendo_b2b_soknad');

        $til = null;
        $kast = function($url) use (&$til) { $til = $url; throw new Exception('redirect'); };
        $dø = function() { return function($m) { throw new Exception('die'); }; };
        add_filter('wp_redirect', $kast);
        add_filter('wp_die_handler', $dø);
        add_filter('wp_die_json_handler', $dø);
        $stoppet = false;
        try { do_action('admin_post_amendo_b2b_soknad'); } catch (Exception $e) { $stoppet = $e->getMessage() === 'die'; }
        remove_filter('wp_redirect', $kast);
        return ['til' => $til, 'stoppet' => $stoppet];
    });

    /** Meta på brukeren med e-posten. */
    $r('/b2b-meta', function($req) {
        $u = get_user_by('email', (string) $req->get_param('epost'));
        if (!$u) return ['finnes' => false];
        $m = [];
        foreach (['billing_org_number', '_orgnr_soknad', '_b2b_status', '_b2b_avvist_orgnr', '_b2b_behandlet', '_b2b_behandlet_av'] as $k) {
            $m[$k] = get_user_meta($u->ID, $k, true);
        }
        return ['finnes' => true, 'id' => $u->ID, 'meta' => $m];
    });

    /** Admin-siden som HTML, med GET-parametrene fra kallet (b2b_sok, b2b). */
    $r('/moduler-side', function() {
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        ob_start(); amendo_settings_page(); $html = ob_get_clean();
        header('Content-Type: text/html; charset=utf-8');
        echo $html; exit;
    });
});
