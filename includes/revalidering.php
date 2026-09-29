<?php
/**
 * Oppdater LiteSpeed og frontenden når Amendo-innstillingene lagres.
 *
 * 1. LiteSpeed: do_action('litespeed_purge', 'REST') tømmer alle REST-svar
 *    (LiteSpeed merker dem med taggen REST), ikke hele nettstedets hurtigbuffer.
 * 2. Frontenden: POST {frontend}/api/revalidate med X-Revalidate-Secret,
 *    ikke-blokkerende, så lagringen aldri blir treg.
 *
 * ⚠ LiteSpeed-tømmingen sendes som en header på SVARET på lagringen, og
 *   skjer altså først når omdirigeringen er sendt. Kallet til frontenden går
 *   før det, så frontenden kan rekke å hente de gamle REST-svarene. Derfor
 *   sendes revalideringen én gang til via WP-Cron ~30 sekunder senere, når
 *   tømmingen garantert er gjort. Flere lagringer rett etter hverandre gir
 *   bare én slik oppfølging.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_REVALIDER_ETTER = 30;

add_action('amendo_revalider_frontend', 'amendo_revalider_frontend');

/**
 * Kalles etter lagring. Returnerer true når frontenden er satt opp og
 * varslet, så admin kan vise «Nettsiden oppdateres i løpet av et minutt».
 */
function amendo_revalider_etter_lagring() {
    if (defined('LSCWP_V')) {
        do_action('litespeed_purge', 'REST');
    }

    if (!amendo_revalider_frontend()) return false;

    if (!wp_next_scheduled('amendo_revalider_frontend')) {
        wp_schedule_single_event(time() + AMENDO_REVALIDER_ETTER, 'amendo_revalider_frontend');
    }
    return true;
}

/** Sender revalideringen. False når frontend-URL eller secret mangler. */
function amendo_revalider_frontend() {
    $url    = amendo_frontend_url();
    $secret = (string) get_option('amendo_revalidate_secret', '');
    if ($url === '' || $secret === '') return false;

    wp_remote_post($url . '/api/revalidate', [
        'timeout'  => 5,
        'blocking' => false,
        'headers'  => [
            'Content-Type'        => 'application/json',
            'X-Revalidate-Secret' => $secret,
        ],
        'body'     => wp_json_encode([
            'tags' => apply_filters('amendo_revalider_tags', ['innstillinger', 'forside', 'side']),
        ]),
    ]);
    return true;
}

/**
 * Lagret frontend-URL uten avsluttende skråstrek, eller ''. Bare https —
 * secreten går i en header — unntatt localhost for lokal utvikling.
 */
function amendo_frontend_url() {
    return amendo_frontend_url_rens((string) get_option('amendo_frontend_url', ''));
}

function amendo_frontend_url_rens($url) {
    $url = esc_url_raw(trim($url), ['https', 'http']);
    if ($url === '') return '';
    $deler = wp_parse_url($url);
    $vert  = $deler['host'] ?? '';
    if ($vert === '') return '';
    $lokal = in_array($vert, ['localhost', '127.0.0.1'], true);
    if (($deler['scheme'] ?? '') !== 'https' && !$lokal) return '';
    return untrailingslashit($url);
}
