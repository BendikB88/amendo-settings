<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// 1.4.46, suiten `passordlenker`: bryteren «Send passordlenker til fronten» og
// engangsmigreringen i includes/moduler.php (amendo_moduler_migrer_1446()).
//
// Migreringen kjøres når pluginen LASTES. /v1446-spor setter derfor bare opp
// databasen slik en butikk på en eldre versjon har den (bryteren finnes ikke,
// sporene og oppdateringssjekken), og den NESTE forespørselen migrerer, akkurat
// som den første forespørselen etter en oppdatering. /v1446-tilstand leser
// resultatet.
if (!defined('ABSPATH')) exit;

const AMENDO_TEST_1446_SPOR_OPTIONS = ['amendo_b2b_auto', 'amendo_b2b_jobb', 'amendo_b2b_epost', 'amendo_b2b_velkomst', 'amendo_b2b_invitasjon',
    'amendo_b2b_tellix_kjente', 'amendo_b2b_tellix_manuell', 'amendo_b2b_tellix_jobb_sist', 'amendo_bedriftspakker', 'amendo_sesongseksjoner',
    'amendo_kontaktskjema_epost', 'amendo_henting_stengte_ukedager', 'amendo_henting_stengte_datoer', 'amendo_henting_ukedagsregler',
    'amendo_tellix_kopi_versjon', 'amendo_helsebygget_ansatte'];
const AMENDO_TEST_1446_SPOR_META = ['_amendo_fra_tellix', '_amendo_bp_onske', '_amendo_bp_ref', '_amendo_bp_oppfolging', '_amendo_bp_egen'];

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };

    /**
     * { options: {navn: verdi}, meta: {nøkkel: verdi}, oppdatering: '1.4.22' | null, bryter: null | '' | '1' }
     * Tømmer alle sporene og bryteren (null = finnes ikke), og setter det som
     * er gitt. meta legges på brukeren spor1446@example.com.
     */
    $r('/v1446-spor', function($req) {
        global $wpdb;
        $p = $req->get_json_params() ?: [];
        foreach (AMENDO_TEST_1446_SPOR_OPTIONS as $o) delete_option($o);
        foreach (AMENDO_TEST_1446_SPOR_META as $k) $wpdb->delete($wpdb->usermeta, ['meta_key' => $k]);
        delete_option('amendo_b2b_passordlenker');
        delete_option('amendo_settings_versjon');
        delete_site_transient('update_plugins');
        foreach ((array) ($p['options'] ?? []) as $o => $v) update_option($o, $v);
        if (!empty($p['meta'])) {
            $u = get_user_by('email', 'spor1446@example.com');
            $id = $u ? $u->ID : wp_insert_user(['user_login' => 'spor1446', 'user_email' => 'spor1446@example.com', 'user_pass' => wp_generate_password(), 'role' => 'customer']);
            foreach ((array) $p['meta'] as $k => $v) update_user_meta($id, $k, $v);
        }
        if (!empty($p['oppdatering'])) {
            $t = new stdClass();
            $t->last_checked = time();
            $t->checked = [plugin_basename(AMENDO_SETTINGS_PATH . 'amendo-settings.php') => (string) $p['oppdatering'], 'woocommerce/woocommerce.php' => '11.0.0'];
            $t->response = [];
            set_site_transient('update_plugins', $t);
        }
        if (array_key_exists('bryter', $p) && $p['bryter'] !== null) update_option('amendo_b2b_passordlenker', (string) $p['bryter']);
        wp_cache_flush();
        $lest = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'amendo_b2b_passordlenker'));
        return ['ok' => true, 'bryter_finnes' => $lest !== null];
    });

    /** Det migreringen skrev (null = finnes ikke), og modusen lenkene bruker. */
    $r('/v1446-tilstand', function() {
        return [
            'bryter'  => get_option('amendo_b2b_passordlenker', null),
            'versjon' => get_option('amendo_settings_versjon', null),
            'modus'   => function_exists('amendo_b2b_passord_modus') ? amendo_b2b_passord_modus() : null,
            'utlop'   => (int) apply_filters('password_reset_expiration', DAY_IN_SECONDS),
        ];
    });

    /** Kortet: { post, rolle, nonce } → admin_post_amendo_b2b_passordlenker. */
    $r('/v1446-handling', function($req) {
        $p = $req->get_json_params() ?: [];
        if (($p['rolle'] ?? 'admin') === 'admin') {
            wp_set_current_user(get_user_by('login', 'admin')->ID);
        } else {
            $u = get_user_by('login', 'k1446') ?: get_user_by('id', wp_insert_user(['user_login' => 'k1446', 'user_pass' => 'x', 'role' => 'customer', 'user_email' => 'k1446@example.com']));
            wp_set_current_user($u->ID);
        }
        $_POST = (array) ($p['post'] ?? []) + ['action' => 'amendo_b2b_passordlenker'];
        $_REQUEST = $_POST + ['_wp_http_referer' => '/'];
        if ($p['nonce'] ?? true) $_REQUEST['_wpnonce'] = wp_create_nonce('amendo_b2b_passordlenker');
        $til = null;
        $kast = function($url) use (&$til) { $til = $url; throw new Exception('redirect'); };
        $dø = function() { return function($m) { throw new Exception('die'); }; };
        $filtre = ['wp_die_handler', 'wp_die_json_handler', 'wp_die_ajax_handler'];
        add_filter('wp_redirect', $kast);
        foreach ($filtre as $f) add_filter($f, $dø);
        $stoppet = false;
        $finnes = has_action('admin_post_amendo_b2b_passordlenker');
        try { do_action('admin_post_amendo_b2b_passordlenker'); } catch (Exception $e) { $stoppet = $e->getMessage() === 'die'; }
        remove_filter('wp_redirect', $kast);
        foreach ($filtre as $f) remove_filter($f, $dø);
        wp_set_current_user(0);
        return ['til' => $til, 'stoppet' => $stoppet, 'finnes' => (bool) $finnes, 'bryter' => get_option('amendo_b2b_passordlenker', null)];
    });

    /** Velkomstlenken for { epost } slik e-posten lager den. */
    $r('/v1446-velkomst', function($req) {
        $u = get_user_by('email', (string) (($req->get_json_params() ?: [])['epost'] ?? ''));
        if (!$u || !function_exists('amendo_b2b_velkomst_lenke')) return ['lenke' => null];
        $nokkel = get_password_reset_key($u);
        $lenke = amendo_b2b_velkomst_lenke($u, $nokkel);
        return ['lenke' => $lenke, 'html' => amendo_b2b_velkomst_innhold($u, $lenke)['html'] ?? ''];
    });
});
