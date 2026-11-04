<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Statusmeldinger fra Wolt (1.4.30), suiten `wolt-status`. Wolt-tillegget og
// Wolts API er stubbene i v1423-test.php (amendo_test_abbdw, amendo_test_wolt),
// e-post fanges i v1429-test.php (amendo_test_epost_fang).
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };
    $som = function($rolle) {
        if ($rolle === 'admin') { wp_set_current_user(get_user_by('login', 'admin')->ID); return; }
        $login = 'f1430' . $rolle;
        $u = get_user_by('login', $login) ?: get_user_by('id', wp_insert_user(['user_login' => $login, 'user_pass' => 'x', 'role' => $rolle, 'user_email' => $login . '@example.com']));
        wp_set_current_user($u->ID);
    };

    /** { meta, status, fornavn, etternavn, firma, telefon } → id og nummer. */
    $r('/v1430-ordre', function($req) {
        $p = $req->get_json_params() ?: [];
        $o = wc_create_order(['status' => $p['status'] ?? 'processing']);
        $o->set_billing_first_name((string) ($p['fornavn'] ?? ''));
        $o->set_billing_last_name((string) ($p['etternavn'] ?? ''));
        $o->set_billing_company((string) ($p['firma'] ?? ''));
        $o->set_billing_phone((string) ($p['telefon'] ?? ''));
        $o->set_billing_email('kunde1430@example.com');
        foreach ((array) ($p['meta'] ?? []) as $k => $v) $o->update_meta_data($k, $v);
        $o->save();
        return ['id' => $o->get_id(), 'nr' => $o->get_order_number(), 'url' => $o->get_edit_order_url()];
    });

    /** Ordren: status, Wolt-metaen, notatene, merket i ordrelisten og boksen på ordresiden. */
    $r('/v1430-les', function($req) use ($som) {
        $som('admin');
        $o = wc_get_order((int) $req->get_param('id'));
        if (!$o) return ['finnes' => false];
        $m = [];
        foreach (['_ab_wolt_status', '_ab_wolt_order_ref', '_amendo_wolt_arsak', '_amendo_wolt_eta_henting', '_amendo_wolt_eta_levering', '_amendo_wolt_kansellert_av', '_amendo_wolt_varslet'] as $k) $m[$k] = $o->get_meta($k);
        $notater = array_map(function($n) { return $n->content; }, wc_get_order_notes(['order_id' => $o->get_id(), 'order' => 'ASC']));
        ob_start(); amendo_wolt_kolonne('amendo_wolt', $o); $merke = ob_get_clean();
        ob_start(); amendo_wolt_ordreboks($o); $boks = ob_get_clean();
        $_GET['amendo_wolt'] = (string) $req->get_param('svar');
        ob_start(); amendo_wolt_ordreboks($o); $boks_svar = ob_get_clean();
        return [
            'finnes' => true, 'status' => $o->get_status(), 'meta' => $m, 'notater' => $notater,
            'merke' => $merke, 'boks' => $boks, 'boks_svar' => $boks_svar,
            'kolonner' => array_keys(apply_filters('manage_woocommerce_page_wc-orders_columns', ['cb' => '', 'order_number' => '', 'order_status' => '', 'order_total' => ''])),
        ];
    });

    /** «Kanseller hos Wolt»: { id, arsak, rolle, nonce } → admin_post_amendo_wolt_kanseller. */
    $r('/v1430-kanseller', function($req) use ($som) {
        $p = $req->get_json_params() ?: [];
        $som((string) ($p['rolle'] ?? 'admin'));
        $id = (int) ($p['id'] ?? 0);
        $_GET = ['action' => 'amendo_wolt_kanseller', 'ordre' => (string) $id, 'arsak' => (string) ($p['arsak'] ?? '')];
        $_REQUEST = $_GET + ['_wpnonce' => ($p['nonce'] ?? true) ? wp_create_nonce('amendo_wolt_kanseller_' . $id) : 'feil'];
        $til = null;
        $kast = function($url) use (&$til) { $til = $url; throw new Exception('redirect'); };
        $dø = function() { return function($m) { throw new Exception('die'); }; };
        $filtre = ['wp_die_handler', 'wp_die_json_handler', 'wp_die_ajax_handler'];
        add_filter('wp_redirect', $kast);
        foreach ($filtre as $f) add_filter($f, $dø);
        $stoppet = false;
        try { do_action('admin_post_amendo_wolt_kanseller'); } catch (Exception $e) { $stoppet = $e->getMessage() === 'die'; }
        remove_filter('wp_redirect', $kast);
        foreach ($filtre as $f) remove_filter($f, $dø);
        wp_set_current_user(0);
        return ['til' => $til, 'stoppet' => $stoppet, 'finnes' => (bool) has_action('admin_post_amendo_wolt_kanseller')];
    });

    /** Loggfilene for kilden amendo-wolt. */
    $r('/v1430-logg', function() {
        $dir = defined('WC_LOG_DIR') ? WC_LOG_DIR : WP_CONTENT_DIR . '/uploads/wc-logs/';
        $tekst = '';
        foreach (glob(trailingslashit($dir) . 'amendo-wolt*.log') ?: [] as $f) $tekst .= file_get_contents($f);
        return ['tekst' => $tekst];
    });

    $r('/v1430-tilstand', function() {
        return [
            'secret'  => get_option('amendo_wolt_webhook_secret', ''),
            'felles'  => get_option('amendo_wolt_varsel_epost', null),
            'admin'   => get_option('admin_email'),
            'epost'   => get_option('amendo_test_epost', []),
            'kall'    => get_option('amendo_test_wolt_kall', []),
            'finnes'  => function_exists('amendo_wolt_webhook'),
            'hemmeligheter' => array_keys(amendo_hemmeligheter()),
        ];
    });
});
