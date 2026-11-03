<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Flytting fra B2B Wholesale Suite (1.4.28), suiten `flytting`. Tellix-lista
// er stubben i tellix-stub.php, web-API-et og Enhetsregisteret v1422-test.php.
if (!defined('ABSPATH')) exit;

// includes/nocache.php: viser i svaret at LiteSpeed-kroken ble kjørt (suiten `tellix`).
add_action('litespeed_control_set_nocache', function() {
    if (!headers_sent()) header('X-Amendo-Test-LS-Nocache: 1');
});

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };
    $som = function($rolle) {
        if ($rolle === 'admin') { wp_set_current_user(get_user_by('login', 'admin')->ID); return; }
        $login = 'f1428' . $rolle;
        $u = get_user_by('login', $login) ?: get_user_by('id', wp_insert_user(['user_login' => $login, 'user_pass' => 'x', 'role' => $rolle, 'user_email' => $login . '@example.com']));
        wp_set_current_user($u->ID);
    };

    /** { brukere: [{epost, meta}], slett_plan } — lager alle i ett kall (Playground er treg). */
    $r('/v1428-klargjor', function($req) {
        $p = $req->get_json_params() ?: [];
        if (!empty($p['slett_plan'])) { delete_option('amendo_b2b_flytting'); delete_option('amendo_b2b_flytting_las'); }
        $ider = [];
        foreach ((array) ($p['brukere'] ?? []) as $b) {
            $epost = (string) $b['epost'];
            if ($gammel = get_user_by('email', $epost)) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user($gammel->ID);
            }
            $id = wp_insert_user(['user_login' => 'f' . substr(md5($epost), 0, 12), 'user_email' => $epost, 'user_pass' => wp_generate_password(), 'role' => (string) ($b['rolle'] ?? 'customer')]);
            if (is_wp_error($id)) return ['feil' => $id->get_error_message()];
            foreach ((array) ($b['meta'] ?? []) as $k => $v) update_user_meta($id, $k, $v);
            $ider[$epost] = $id;
        }
        return ['ider' => $ider];
    });

    /** Endre metafelt på én bruker: { epost, meta } (null = slett). */
    $r('/v1428-sett', function($req) {
        $p = $req->get_json_params() ?: [];
        $u = get_user_by('email', (string) $p['epost']);
        foreach ((array) ($p['meta'] ?? []) as $k => $v) {
            if ($v === null) delete_user_meta($u->ID, $k); else update_user_meta($u->ID, $k, $v);
        }
        return ['ok' => true];
    });

    /** Metafeltene flyttingen leser og skriver, for e-postene. */
    $r('/v1428-meta', function($req) {
        $p = $req->get_json_params() ?: [];
        $ut = [];
        foreach ((array) ($p['eposter'] ?? []) as $e) {
            $u = get_user_by('email', $e);
            if (!$u) { $ut[$e] = null; continue; }
            $m = ['id' => $u->ID];
            foreach (['_orgnr_soknad', '_b2b_sokt', '_vipps_syntetisk_epost', '_b2b_status', 'billing_org_number', '_b2b_kilde', '_b2b_behandlet', '_tellixCustomerId', '_tellixCustomerUuId', '_tellixGroupId', '_b2b_tellix_status', 'b2bwhs_b2buser', 'b2bwhs_customergroup', 'b2bwhs_account_approved'] as $k) $m[$k] = get_user_meta($u->ID, $k, true);
            $t = get_user_meta($u->ID, '_b2b_tellix', true);
            $m['tellix_melding'] = is_array($t) ? (string) ($t['melding'] ?? '') : '';
            $ut[$e] = $m;
        }
        return $ut;
    });

    /**
     * wc/v3 som administrator, suiten `soknad`: { metode, sti, body } →
     * { status, data }. Samme kontroller som frontendene treffer med nøkkel.
     */
    $r('/v1428-wc', function($req) use ($som) {
        $p = $req->get_json_params() ?: [];
        $som('admin');
        $kall = new WP_REST_Request((string) ($p['metode'] ?? 'GET'), (string) $p['sti']);
        if (!empty($p['body'])) {
            $kall->set_header('content-type', 'application/json');
            $kall->set_body(wp_json_encode($p['body']));
        }
        $svar = rest_do_request($kall);
        wp_set_current_user(0);
        return ['status' => $svar->get_status(), 'data' => $svar->get_data()];
    });

    /**
     * Org.nr. på ordren (suiten `faktura`): { orgnr } → fakturaadressen,
     * leveringsadressen, e-postmalene og feltene i wp-admin for en ny ordre.
     * PDF-en fra WP Overnight bruker get_formatted_billing_address() = `faktura`.
     */
    $r('/v1428-ordreadresse', function($req) {
        $p = $req->get_json_params() ?: [];
        $o = wc_create_order();
        $adr = ['first_name' => 'Kari', 'last_name' => 'Nordmann', 'company' => 'Firma & Sønn AS', 'address_1' => 'Storgata 1', 'postcode' => '0155', 'city' => 'Oslo', 'country' => 'NO'];
        $o->set_address($adr, 'billing');
        $o->set_address($adr, 'shipping');
        if (array_key_exists('orgnr', $p) && $p['orgnr'] !== null) $o->update_meta_data('_billing_org_number', (string) $p['orgnr']);
        $o->save();
        $o = wc_get_order($o->get_id());
        $ut = [
            'faktura'     => $o->get_formatted_billing_address(),
            'levering'    => $o->get_formatted_shipping_address(),
            'epost'       => wc_get_template_html('emails/email-addresses.php', ['order' => $o, 'sent_to_admin' => true, 'plain_text' => false, 'email' => null]),
            'epost_tekst' => wc_get_template_html('emails/plain/email-addresses.php', ['order' => $o, 'sent_to_admin' => true, 'plain_text' => true, 'email' => null]),
            'admin_felt'  => array_keys((array) apply_filters('woocommerce_admin_billing_fields', ['first_name' => [], 'last_name' => [], 'company' => [], 'address_1' => []], $o, 'edit')),
            'kunde_adr'   => WC()->countries->get_formatted_address(['company' => 'Firma AS', 'city' => 'Oslo', 'country' => 'NO']),
        ];
        $o->delete(true);
        return $ut;
    });

    /** is_protected_meta() for { par: [[nøkkel, type]] }. */
    $r('/v1428-beskyttet', function($req) {
        $p = $req->get_json_params() ?: [];
        $ut = [];
        foreach ((array) ($p['par'] ?? []) as $par) $ut[] = is_protected_meta((string) $par[0], (string) $par[1]);
        return $ut;
    });

    $r('/v1428-plan', function() {
        return ['plan' => get_option('amendo_b2b_flytting', null), 'las' => get_option('amendo_b2b_flytting_las', null)];
    });

    /** { alder } sekunder gammel tørrkjøring, { las } = sett låsen. */
    $r('/v1428-juster', function($req) {
        $p = $req->get_json_params() ?: [];
        if (isset($p['alder'])) {
            $plan = get_option('amendo_b2b_flytting');
            $plan['tid'] = time() - (int) $p['alder'];
            update_option('amendo_b2b_flytting', $plan, false);
        }
        if (array_key_exists('las', $p)) {
            if ($p['las']) update_option('amendo_b2b_flytting_las', (string) time(), false); else delete_option('amendo_b2b_flytting_las');
        }
        return ['ok' => true];
    });

    /** Kortet: { post, rolle, nonce } → admin_post_amendo_b2b_flytting. */
    $r('/v1428-handling', function($req) use ($som) {
        $p = $req->get_json_params() ?: [];
        $som((string) ($p['rolle'] ?? 'admin'));
        $_POST = (array) ($p['post'] ?? []) + ['action' => 'amendo_b2b_flytting'];
        $_REQUEST = $_POST + ['_wp_http_referer' => '/'];
        if ($p['nonce'] ?? true) $_REQUEST['_wpnonce'] = wp_create_nonce('amendo_b2b_flytting');
        $til = null;
        $kast = function($url) use (&$til) { $til = $url; throw new Exception('redirect'); };
        $dø = function() { return function($m) { throw new Exception('die'); }; };
        $filtre = ['wp_die_handler', 'wp_die_json_handler', 'wp_die_ajax_handler'];
        add_filter('wp_redirect', $kast);
        foreach ($filtre as $f) add_filter($f, $dø);
        $stoppet = false;
        $finnes = has_action('admin_post_amendo_b2b_flytting');
        try { do_action('admin_post_amendo_b2b_flytting'); } catch (Exception $e) { $stoppet = $e->getMessage() === 'die'; }
        remove_filter('wp_redirect', $kast);
        foreach ($filtre as $f) remove_filter($f, $dø);
        return ['til' => $til, 'stoppet' => $stoppet, 'finnes' => (bool) $finnes];
    });
});
