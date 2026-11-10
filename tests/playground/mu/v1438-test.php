<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Helsebygget-PIN uten wp_salt (1.4.38), i suiten `helsebygget`: en hash i
// gammelt format (HMAC med wp_salt('auth')) og nye salter uten å røre wp-config.
if (!defined('ABSPATH')) exit;

// Nye salter: optionen amendo_test_salt gjelder alle saltene i hver forespørsel.
add_filter('salt', function($salt) {
    $test = get_option('amendo_test_salt', '');
    return $test !== '' ? hash('sha256', $test . '|' . $salt) : $salt;
});

add_action('rest_api_init', function() {
    $r = function($path, $cb) {
        register_rest_route('amendo-test/v1', $path, ['methods' => 'GET,POST', 'callback' => $cb, 'permission_callback' => '__return_true']);
    };

    /** { id, pin } → den ansattes PIN lagres som før 1.4.38 (HMAC med dagens salt). */
    $r('/v1438-gammel-pin', function($req) {
        $p = $req->get_json_params() ?: [];
        $ansatte = amendo_hb_ansatte();
        $id = (string) ($p['id'] ?? '');
        if (!isset($ansatte[$id])) return ['ok' => false];
        $ansatte[$id]['pin'] = hash_hmac('sha256', (string) $p['pin'], wp_salt('auth') . '|amendo-helsebygget-pin');
        amendo_hb_ansatte_lagre($ansatte);
        return ['ok' => true, 'pin' => $ansatte[$id]['pin']];
    });

    /** { salt } → nye salter fra neste forespørsel; tom = wp-config som før. */
    $r('/v1438-salt', function($req) {
        $salt = (string) (($req->get_json_params() ?: [])['salt'] ?? '');
        if ($salt === '') delete_option('amendo_test_salt'); else update_option('amendo_test_salt', $salt);
        return ['ok' => true];
    });

    /** Den lagrede hashen og v per ansatt-ID. */
    $r('/v1438-hasher', function() {
        $ut = [];
        foreach (amendo_hb_ansatte() as $a) $ut[$a['id']] = ['pin' => $a['pin'], 'v' => $a['v']];
        return $ut;
    });
});
