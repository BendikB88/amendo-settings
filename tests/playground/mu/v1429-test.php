<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Bedriftskunder fra Tellix (1.4.29), suiten `bedriftskunder`. Tellix-lista er
// stubben i tellix-stub.php (satt med /v1414-klargjor).
if (!defined('ABSPATH')) exit;

// E-post fanges i stedet for å sendes, når amendo_test_epost_fang = '1'.
add_filter('pre_wp_mail', function($svar, $a) {
    if (get_option('amendo_test_epost_fang') !== '1') return $svar;
    if (get_option('amendo_test_epost_feil') === '1') return false;
    $alle = get_option('amendo_test_epost', []);
    $alle[] = ['til' => (array) $a['to'], 'emne' => (string) $a['subject'], 'html' => (string) $a['message'], 'headers' => $a['headers']];
    update_option('amendo_test_epost', $alle, false);
    return true;
}, 10, 2);

// Jobben skal bare kjøre når suiten kaller den (/v1429-jobb), aldri fra WP-cron
// midt i testene: planlagt, men om 30 dager.
add_filter('schedule_event', function($e) {
    if (is_object($e) && ($e->hook ?? '') === 'amendo_b2b_tellix_jobb') $e->timestamp = time() + 30 * DAY_IN_SECONDS;
    return $e;
});

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };
    $som = function($rolle) {
        if ($rolle === 'admin') { wp_set_current_user(get_user_by('login', 'admin')->ID); return; }
        $login = 'f1429' . $rolle;
        $u = get_user_by('login', $login) ?: get_user_by('id', wp_insert_user(['user_login' => $login, 'user_pass' => 'x', 'role' => $rolle, 'user_email' => $login . '@example.com']));
        wp_set_current_user($u->ID);
    };

    /**
     * { brukere: [{epost, meta, rolle, passord}], slett_epost: [...], nullstill } —
     * nullstill tømmer brytere, grunnlinje, manuell-lista, planen og fangede e-poster.
     */
    $r('/v1429-klargjor', function($req) {
        $p = $req->get_json_params() ?: [];
        require_once ABSPATH . 'wp-admin/includes/user.php';
        if (!empty($p['nullstill'])) {
            foreach (['amendo_b2b_auto', 'amendo_b2b_jobb', 'amendo_b2b_epost', 'amendo_b2b_velkomst', 'amendo_b2b_tellix_kjente', 'amendo_b2b_tellix_manuell',
                      'amendo_b2b_invitasjon', 'amendo_b2b_invitasjon_las', 'amendo_b2b_tellix_jobb_las', 'amendo_b2b_tellix_jobb_sist', 'amendo_test_epost', 'amendo_test_epost_feil'] as $o) delete_option($o);
            wp_clear_scheduled_hook('amendo_b2b_tellix_jobb');
        }
        foreach ((array) ($p['slett_epost'] ?? []) as $e) if ($u = get_user_by('email', $e)) wp_delete_user($u->ID);
        $ider = [];
        foreach ((array) ($p['brukere'] ?? []) as $b) {
            $epost = (string) $b['epost'];
            if ($gammel = get_user_by('email', $epost)) wp_delete_user($gammel->ID);
            $id = wp_insert_user(['user_login' => 'g' . substr(md5($epost), 0, 12), 'user_email' => $epost, 'user_pass' => (string) ($b['passord'] ?? wp_generate_password()), 'role' => (string) ($b['rolle'] ?? 'customer')]);
            if (is_wp_error($id)) return ['feil' => $id->get_error_message()];
            foreach ((array) ($b['meta'] ?? []) as $k => $v) update_user_meta($id, $k, $v);
            $ider[$epost] = $id;
        }
        return ['ider' => $ider];
    });

    /** Metafeltene for e-postene (null = finnes ikke). */
    $r('/v1429-meta', function($req) {
        $p = $req->get_json_params() ?: [];
        $ut = [];
        foreach ((array) ($p['eposter'] ?? []) as $e) {
            $u = get_user_by('email', $e);
            if (!$u) { $ut[$e] = null; continue; }
            $m = ['id' => $u->ID, 'login' => $u->user_login, 'roller' => $u->roles];
            foreach (['_b2b_status', 'billing_org_number', '_orgnr_soknad', '_b2b_kilde', '_b2b_behandlet_av', '_tellixCustomerId', '_tellixCustomerUuId', 'billing_company', 'billing_email', 'billing_phone', '_amendo_invitasjon', '_amendo_fra_tellix', '_amendo_passord_satt', '_b2b_tellix_status'] as $k) $m[$k] = get_user_meta($u->ID, $k, true);
            $ut[$e] = $m;
        }
        return $ut;
    });

    /** { epost, passord, via: 'authenticate'|'wp_login' } — innlogging slik WordPress og jwt-auth gjør den. */
    $r('/v1429-logg-inn', function($req) {
        $p = $req->get_json_params() ?: [];
        $u = get_user_by('email', (string) $p['epost']);
        if (($p['via'] ?? '') === 'wp_login') {
            do_action('wp_login', $u->user_login, $u);
            return ['ok' => true];
        }
        $res = wp_authenticate($u->user_login, (string) $p['passord']);
        return ['ok' => $res instanceof WP_User];
    });

    /**
     * Det frontendens /sett-passord gjør (garcon-b2b 825eecb): WordPress sin
     * egen tilbakestilling. { login, key, passord } → { ok, feil }.
     */
    $r('/v1429-tilbakestill', function($req) {
        $p = $req->get_json_params() ?: [];
        $u = check_password_reset_key((string) ($p['key'] ?? ''), (string) ($p['login'] ?? ''));
        if (!$u instanceof WP_User) return ['ok' => false, 'feil' => is_wp_error($u) ? $u->get_error_code() : 'ukjent'];
        if (isset($p['passord'])) reset_password($u, (string) $p['passord']);
        return ['ok' => true];
    });

    /** Gjør nøkkelen til brukeren { epost } { dager } gammel (user_activation_key = «tid:hash»). */
    $r('/v1429-nokkel-alder', function($req) {
        global $wpdb;
        $p = $req->get_json_params() ?: [];
        $u = get_user_by('email', (string) $p['epost']);
        [, $hash] = array_pad(explode(':', (string) $u->user_activation_key, 2), 2, '');
        $wpdb->update($wpdb->users, ['user_activation_key' => (time() - (int) $p['dager'] * DAY_IN_SECONDS) . ':' . $hash], ['ID' => $u->ID]);
        clean_user_cache($u->ID);
        return ['ok' => $hash !== ''];
    });

    /** «Glemt passord» slik wp-login.php sender det: { epost } → retrieve_password(). */
    $r('/v1429-glemt', function($req) {
        $u = get_user_by('email', (string) ($req->get_json_params()['epost'] ?? ''));
        $r = retrieve_password($u->user_login);
        return ['ok' => $r === true];
    });

    $r('/v1429-jobb', function() {
        return ['svar' => amendo_b2b_tellix_jobb_kjor(), 'planlagt' => (bool) wp_next_scheduled('amendo_b2b_tellix_jobb')];
    });

    $r('/v1429-tilstand', function() {
        $kjente = get_option('amendo_b2b_tellix_kjente', null);
        return [
            'kjente'   => is_array($kjente) ? array_keys($kjente) : null,
            'manuell'  => get_option('amendo_b2b_tellix_manuell', []),
            'plan'     => get_option('amendo_b2b_invitasjon', null),
            'epost'    => get_option('amendo_test_epost', []),
            'sist'     => get_option('amendo_b2b_tellix_jobb_sist', null),
            'planlagt' => (bool) wp_next_scheduled('amendo_b2b_tellix_jobb'),
            'brytere'  => ['auto' => get_option('amendo_b2b_auto', ''), 'jobb' => get_option('amendo_b2b_jobb', ''), 'epost' => get_option('amendo_b2b_epost', '')],
            'finnes'   => function_exists('amendo_b2b_auto'),
        ];
    });

    /** Kortet: { post, rolle, nonce } → admin_post_amendo_b2b_auto. */
    $r('/v1429-handling', function($req) use ($som) {
        $p = $req->get_json_params() ?: [];
        $som((string) ($p['rolle'] ?? 'admin'));
        $_POST = (array) ($p['post'] ?? []) + ['action' => 'amendo_b2b_auto'];
        $_REQUEST = $_POST + ['_wp_http_referer' => '/'];
        if ($p['nonce'] ?? true) $_REQUEST['_wpnonce'] = wp_create_nonce('amendo_b2b_auto');
        $til = null;
        $kast = function($url) use (&$til) { $til = $url; throw new Exception('redirect'); };
        $dø = function() { return function($m) { throw new Exception('die'); }; };
        $filtre = ['wp_die_handler', 'wp_die_json_handler', 'wp_die_ajax_handler'];
        add_filter('wp_redirect', $kast);
        foreach ($filtre as $f) add_filter($f, $dø);
        $stoppet = false;
        $finnes = has_action('admin_post_amendo_b2b_auto');
        try { do_action('admin_post_amendo_b2b_auto'); } catch (Exception $e) { $stoppet = $e->getMessage() === 'die'; }
        remove_filter('wp_redirect', $kast);
        foreach ($filtre as $f) remove_filter($f, $dø);
        wp_set_current_user(0);
        return ['til' => $til, 'stoppet' => $stoppet, 'finnes' => (bool) $finnes];
    });
});
