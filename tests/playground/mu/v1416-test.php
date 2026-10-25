<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Hjelperuter for suiten `lagring` (1.4.16): faner som lagres som én samlet
// option skal bare lagres når de er endret, og avvises når optionen er endret
// på serveren etter at siden ble lastet. Se includes/lagring.php.
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    $r = function($path, $cb) {
        register_rest_route('amendo-test/v1', $path, ['methods' => 'GET,POST', 'callback' => $cb, 'permission_callback' => '__return_true']);
    };

    /** { option: verdi } — lister og objekter som JSON, null sletter. */
    $r('/v1416-sett', function($req) {
        foreach ($req->get_json_params() ?: [] as $opt => $v) {
            if ($v === null) delete_option($opt);
            elseif (is_array($v)) update_option($opt, wp_json_encode($v));
            else update_option($opt, (string) $v);
        }
        return ['ok' => true];
    });

    /** Versjonen av hver del nå. */
    $r('/v1416-versjon', function() {
        $ut = [];
        foreach (array_keys(amendo_lagring_deler()) as $del) $ut[$del] = amendo_lagring_versjon($del);
        return $ut;
    });

    /** Lagring som admin: { post } → hvor den sendte deg, og optionene etterpå. */
    $r('/v1416-lagre', function($req) {
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        $_POST = (($req->get_json_params() ?: [])['post'] ?? []) + ['action' => 'amendo_save_settings'];
        $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('amendo_settings_nonce'), '_wp_http_referer' => '/'];
        $til = null;
        $kast = function($url) use (&$til) { $til = $url; throw new Exception('redirect'); };
        add_filter('wp_redirect', $kast);
        try { do_action('admin_post_amendo_save_settings'); } catch (Exception $e) {}
        remove_filter('wp_redirect', $kast);
        $les = function($o) { return json_decode((string) get_option($o, 'null'), true); };
        return [
            'til'          => $til,
            'butikk_navn'  => get_option('amendo_butikk_navn'),
            'forside'      => $les('amendo_forside'),
            'meny'         => $les('amendo_meny'),
            'kategorirad'  => $les('amendo_kategorirad'),
            'stengte'      => $les('amendo_stengte_perioder'),
            'regler'       => $les('amendo_kategori_regler'),
            'avdelinger'   => $les('amendo_avdelinger'),
            'kundegrupper' => $les('amendo_kundegrupper'),
            'antall_regler' => $les('amendo_antall_regler'),
            'stengt_helligdager' => get_option('amendo_stengt_helligdager', null),
        ];
    });
});
