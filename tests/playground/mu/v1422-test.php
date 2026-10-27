<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Tellix-kunde ved B2B-godkjenning (1.4.22), suiten `tellix-kunder`. Se
// includes/moduler/b2b-tellix-kunder.php. Ingen ekte kall: Tellix sitt
// web-API (alle verter med /web/v…/) og Enhetsregisteret besvares her via
// pre_http_request. Kundelista (amendo-core) er stubben i tellix-stub.php, og
// en kunde som «opprettes» legges til i den, så neste henting finner den.
//
// amendo_test_tellixweb (JSON): {
//   token: 'ok'|'feil', nede: bool (WP_Error på alt),
//   save_kode: 200, save_uten_id: bool, uuid_i_svar: bool,
//   gruppe_lagres: bool (false = Tellix ignorerer customerGroupId → 0),
//   update_kode: 200, neste_id: 9001 }
// amendo_test_brreg (JSON): Enhetsregisteret-svaret, eller tomt = 404.
if (!defined('ABSPATH')) exit;

add_filter('pre_http_request', function($svar, $args, $url) {
    if (strpos($url, 'data.brreg.no/') !== false) {
        $kall = get_option('amendo_test_tellixweb_kall', []);
        $kall[] = ['url' => $url, 'metode' => 'GET'];
        update_option('amendo_test_tellixweb_kall', $kall, false);
        $b = get_option('amendo_test_brreg', '');
        return $b ? ['response' => ['code' => 200, 'message' => 'OK'], 'body' => $b, 'headers' => [], 'cookies' => []]
                  : ['response' => ['code' => 404, 'message' => 'Not Found'], 'body' => '{}', 'headers' => [], 'cookies' => []];
    }
    if (!preg_match('#/web/v\d+/(.+)$#', $url, $m)) return $svar;
    $c = json_decode((string) get_option('amendo_test_tellixweb', '{}'), true) ?: [];
    $sti = $m[1];
    $body = is_string($args['body'] ?? null) ? json_decode($args['body'], true) : ($args['body'] ?? null);
    $kall = get_option('amendo_test_tellixweb_kall', []);
    // Passordet og nøkkelen lagres bare som «satt», så testen ser at de ble sendt.
    if ($sti === 'getaccesstoken' && is_array($body)) $body = ['username' => $body['username'] ?? null, 'password_satt' => !empty($body['password']), 'apikey_satt' => !empty($body['apikey'])];
    $kall[] = ['url' => $url, 'sti' => $sti, 'metode' => $args['method'] ?? 'GET', 'auth' => $args['headers']['Authorization'] ?? null, 'body' => $body];
    update_option('amendo_test_tellixweb_kall', $kall, false);
    $json = function($kode, $data) { return ['response' => ['code' => $kode, 'message' => ''], 'body' => wp_json_encode($data), 'headers' => [], 'cookies' => []]; };

    if (!empty($c['nede'])) return new WP_Error('http_request_failed', 'cURL error 28: timeout');
    if ($sti === 'getaccesstoken') return ($c['token'] ?? 'ok') === 'ok' ? $json(200, ['token' => 'TOKEN-1422']) : $json(401, ['message' => 'Unauthorized']);
    if (($args['headers']['Authorization'] ?? '') !== 'TOKEN-1422') return $json(401, ['message' => 'Unauthorized']);

    if ($sti === 'customer/save') {
        $kode = (int) ($c['save_kode'] ?? 200);
        if ($kode !== 200) return $json($kode, ['message' => 'Feil']);
        $id = (int) get_option('amendo_test_tellixweb_neste', (int) ($c['neste_id'] ?? 9001));
        update_option('amendo_test_tellixweb_neste', $id + 1, false);
        $rec = $body['data'][0] ?? [];
        $kunder = json_decode((string) get_option('amendo_test_tellix_kunder', '[]'), true) ?: [];
        $kunder[] = [
            'id' => (string) $id, 'uuid' => 'UUID-' . $id, 'name' => $rec['name'] ?? '', 'email' => $rec['email'] ?? '',
            'phone' => $rec['phoneNumber'] ?? '', 'orgnr' => $rec['orgNumber'] ?? '',
            'groupId' => ($c['gruppe_lagres'] ?? true) ? ($rec['customerGroupId'] ?? 0) : 0,
        ];
        update_option('amendo_test_tellix_kunder', wp_json_encode($kunder));
        if (!empty($c['save_uten_id'])) return $json(200, ['data' => [['customerData' => []]]]);
        $cd = ['customerId' => $id];
        if (!empty($c['uuid_i_svar'])) $cd['customerUuid'] = 'UUID-' . $id;
        return $json(200, ['data' => [['customerData' => $cd]]]);
    }
    if (strpos($sti, 'customer/update/') === 0) {
        return $json((int) ($c['update_kode'] ?? 200), ['ok' => true]);
    }
    return $json(404, []);
}, 10, 3);

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };
    $som = function($rolle) {
        if ($rolle === 'admin') { wp_set_current_user(get_user_by('login', 'admin')->ID); return; }
        wp_set_current_user(wp_insert_user(['user_login' => 'r' . wp_rand(), 'user_pass' => 'x', 'role' => $rolle]));
    };
    /** Kjør en admin-post-handling og fang omdirigeringen. */
    $post = function($handling, array $post, $nonce) {
        $_POST = $post + ['action' => $handling];
        $_REQUEST = $_POST + ['_wp_http_referer' => '/'];
        if ($nonce) $_REQUEST['_wpnonce'] = wp_create_nonce($nonce);
        $til = null;
        $kast = function($url) use (&$til) { $til = $url; throw new Exception('redirect'); };
        $dø = function() { return function($m) { throw new Exception('die'); }; };
        add_filter('wp_redirect', $kast);
        add_filter('wp_die_handler', $dø);
        add_filter('wp_die_json_handler', $dø);
        $stoppet = false;
        try { do_action('admin_post_' . $handling); } catch (Exception $e) { $stoppet = $e->getMessage() === 'die'; }
        remove_filter('wp_redirect', $kast);
        remove_filter('wp_die_handler', $dø);
        remove_filter('wp_die_json_handler', $dø);
        return ['til' => $til, 'stoppet' => $stoppet];
    };

    /** { web, brreg, sync, egen, kall_slett } */
    $r('/v1422-klargjor', function($req) {
        $p = $req->get_json_params() ?: [];
        foreach (['web' => 'amendo_test_tellixweb', 'brreg' => 'amendo_test_brreg'] as $k => $opt) {
            if (!array_key_exists($k, $p)) continue;
            if ($p[$k] === null) delete_option($opt); else update_option($opt, wp_json_encode($p[$k]));
        }
        foreach (['sync' => 'garcon_tellix_b2b_sync_settings', 'egen' => 'amendo_tellix_web'] as $k => $opt) {
            if (!array_key_exists($k, $p)) continue;
            if ($p[$k] === null) delete_option($opt); else update_option($opt, (array) $p[$k], false);
        }
        if (!empty($p['kall_slett'])) { delete_option('amendo_test_tellixweb_kall'); }
        return ['ok' => true];
    });

    $r('/v1422-tilstand', function() {
        return [
            'kall'   => get_option('amendo_test_tellixweb_kall', []),
            'kunder' => json_decode((string) get_option('amendo_test_tellix_kunder', '[]'), true),
            'egen'   => get_option('amendo_tellix_web', null),
        ];
    });

    $r('/v1422-meta', function($req) {
        $u = get_user_by('email', (string) $req->get_param('epost'));
        if (!$u) return ['finnes' => false];
        $m = [];
        foreach (['_tellixGroupId', '_tellixCustomerId', '_tellixCustomerUuId', '_b2b_status', 'billing_org_number', '_b2b_tellix_status', '_b2b_tellix'] as $k) $m[$k] = get_user_meta($u->ID, $k, true);
        return ['finnes' => true, 'id' => $u->ID, 'meta' => $m];
    });

    /** Kortet: { post, rolle, nonce } → admin_post_amendo_b2b_tellix. */
    $r('/v1422-handling', function($req) use ($som, $post) {
        $p = $req->get_json_params() ?: [];
        $som($p['rolle'] ?? 'admin');
        return $post('amendo_b2b_tellix', (array) ($p['post'] ?? []), ($p['nonce'] ?? true) ? 'amendo_b2b_tellix' : null);
    });

    /** Tellix-tilgangen: { post, rolle } → admin_post_amendo_tellix_web. */
    $r('/v1422-web', function($req) use ($som, $post) {
        $p = $req->get_json_params() ?: [];
        $som($p['rolle'] ?? 'admin');
        return $post('amendo_tellix_web', (array) ($p['post'] ?? []), 'amendo_tellix_web');
    });

    /** Massehandlingen i Brukere: { ider, gruppe, rolle } → omdirigering og beskjeden. */
    $r('/v1422-masse', function($req) use ($som) {
        global $pagenow;
        $p = $req->get_json_params() ?: [];
        $som($p['rolle'] ?? 'admin');
        $_REQUEST['amendo_tellix_gruppe'] = (string) ($p['gruppe'] ?? '');
        $dø = function() { return function($m) { throw new Exception('die'); }; };
        add_filter('wp_die_handler', $dø);
        add_filter('wp_die_json_handler', $dø);
        $handlinger =apply_filters('bulk_actions-users', []);
        ob_start();
        try {
            $til = apply_filters('handle_bulk_actions-users', admin_url('users.php'), 'amendo_tellix_koble', array_map('intval', (array) ($p['ider'] ?? [])));
        } catch (Exception $e) {
            ob_end_clean();
            return ['stoppet' => true, 'handlinger' => $handlinger];
        }
        // Massehandlingen skal bare gi en adresse å omdirigere til, aldri skrive ut noe.
        $ekko = ob_get_clean();
        parse_str((string) parse_url($til, PHP_URL_QUERY), $q);
        $pagenow = 'users.php';
        $_GET = $q;
        // Bare vår beskjed: do_action('admin_notices') kjører også krokene som
        // kaller get_current_screen(), som ikke finnes i REST (fatal feil).
        ob_start();
        if (isset($q['amendo_tellix']) && function_exists('amendo_b2b_tellix_masse_beskjed')) amendo_b2b_tellix_masse_beskjed((string) $q['amendo_tellix']);
        $beskjed = ob_get_clean();
        ob_start(); do_action('restrict_manage_users', 'top'); $valg = ob_get_clean();
        return ['til' => $til, 'ekko' => $ekko, 'beskjed' => $beskjed, 'handlinger' => $handlinger, 'valg' => $valg, 'visninger' => apply_filters('views_users', [])];
    });

    /** Filteret «Uten Tellix-kobling»: e-postene det finner blant { eposter }. */
    $r('/v1422-ukoblet', function($req) {
        global $pagenow;
        $p = $req->get_json_params() ?: [];
        require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        $GLOBALS['current_screen'] = WP_Screen::get('users');
        $pagenow = 'users.php';
        $_GET['amendo_tellix_ukoblet'] = '1';
        $q = new WP_User_Query(['search' => '*@tk1422.no', 'search_columns' => ['user_email'], 'fields' => ['user_email'], 'number' => 200]);
        return ['eposter' => array_values(array_map(function($u) { return $u->user_email; }, $q->get_results()))];
    });
});
