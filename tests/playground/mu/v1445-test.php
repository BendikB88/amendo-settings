<?php
/**
 * 1.4.45 — siste kjente gode kopi av Tellix-rabattlista. Se
 * suiter/kundegrupper.mjs og includes/moduler/b2b-tellix.php.
 *
 * Kun for tests/playground (se CLAUDE.md), aldri i en ekte butikk.
 */
if (!defined('ABSPATH')) exit;

/*
 * Teller HVER gang koden kaller update_option() på kopien — også når
 * WordPress selv ville kortsluttet fordi verdien er den samme. Det er nettopp
 * den kortslutningen vi IKKE skal stole på: vakten skal stå i vår egen kode,
 * så vi ikke bygger en JSON-streng og kaller update_option() per forespørsel.
 * `pre_update_option_*` kjører før likhetssjekken i WordPress.
 */
add_filter('pre_update_option_amendo_tellix_grupper_kopi', function($ny) {
    update_option('amendo_test_kopi_skriv', (int) get_option('amendo_test_kopi_skriv', 0) + 1, false);
    return $ny;
});

add_action('rest_api_init', function() {
    register_rest_route('amendo-test/v1', '/v1445-kopi', [
        'methods'             => 'GET,POST',
        'permission_callback' => '__return_true',
        'callback'            => function($req) {
            if ($req->get_param('slett')) {
                delete_option('amendo_tellix_grupper_kopi');
                delete_option('amendo_tellix_kopi_versjon');
            }
            if ($req->get_param('nullstill')) update_option('amendo_test_kopi_skriv', 0, false);
            // Aktivering/oppgradering: kjør det `init` gjør, uten et oppslag.
            if ($req->get_param('oppstart')) {
                wp_clear_scheduled_hook('amendo_tellix_grupper_jobb');
                delete_option('amendo_tellix_kopi_versjon');
                amendo_tellix_grupper_oppstart();
            }
            // WP-cron som plukker opp jobben.
            if ($req->get_param('kjor_jobb')) do_action('amendo_tellix_grupper_jobb');
            // Grensen per nummer (10 per 15 min) skal ikke stoppe en suite.
            $fri = (string) $req->get_param('fri');
            if ($fri !== '' && function_exists('amendo_tellix_telefon')) {
                delete_transient('amendo_rabatt_tlf_' . md5(amendo_tellix_telefon($fri)));
            }
            $raa = json_decode((string) get_option('amendo_tellix_grupper_kopi', ''), true);
            return [
                'kopi'       => is_array($raa['grupper'] ?? null) ? $raa['grupper'] : null,
                'tid'        => (int) ($raa['tid'] ?? 0),
                'skrivinger' => (int) get_option('amendo_test_kopi_skriv', 0),
                'timesjobb'  => (int) wp_next_scheduled('amendo_tellix_grupper_jobb'),
                'engangs'    => (int) count(array_filter(
                    (array) (_get_cron_array() ?: []),
                    function($t) { return isset($t['amendo_tellix_grupper_jobb']); }
                )),
            ];
        },
    ]);
});
