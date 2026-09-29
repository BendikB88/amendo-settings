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
 *   bare én slik oppfølging; den sender alle taggene som har ventet
 *   (option amendo_revalider_ventende).
 *
 * Utløses av lagring i Amendo-admin (alle tagger) og av WP-sider som
 * publiseres, oppdateres, avpubliseres eller slettes (bare «side»).
 */

if (!defined('ABSPATH')) exit;

const AMENDO_REVALIDER_ETTER = 30;
const AMENDO_REVALIDER_ALLE  = ['innstillinger', 'forside', 'side'];

add_action('amendo_revalider_frontend', 'amendo_revalider_oppfolging');

/**
 * Kalles etter lagring i Amendo-admin. Returnerer true når frontenden er satt
 * opp og varslet, så admin kan vise «Nettsiden oppdateres i løpet av et minutt».
 */
function amendo_revalider_etter_lagring() {
    return amendo_revalider(AMENDO_REVALIDER_ALLE);
}

/** Tøm LiteSpeed REST, varsle frontenden nå, og planlegg én oppfølging. */
function amendo_revalider(array $tags) {
    if (defined('LSCWP_V')) {
        do_action('litespeed_purge', 'REST');
    }

    if (!amendo_revalider_frontend($tags)) return false;

    $ventende = get_option('amendo_revalider_ventende', []);
    $ventende = array_values(array_unique(array_merge(is_array($ventende) ? $ventende : [], $tags)));
    update_option('amendo_revalider_ventende', $ventende, false);
    if (!wp_next_scheduled('amendo_revalider_frontend')) {
        wp_schedule_single_event(time() + AMENDO_REVALIDER_ETTER, 'amendo_revalider_frontend');
    }
    return true;
}

/** Oppfølgingen fra WP-Cron: alle taggene som har ventet siden sist. */
function amendo_revalider_oppfolging() {
    $ventende = get_option('amendo_revalider_ventende', []);
    delete_option('amendo_revalider_ventende');
    amendo_revalider_frontend(is_array($ventende) && $ventende ? $ventende : AMENDO_REVALIDER_ALLE);
}

/** Sender revalideringen. False når frontend-URL eller secret mangler. */
function amendo_revalider_frontend(array $tags = AMENDO_REVALIDER_ALLE) {
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
            'tags' => array_values(apply_filters('amendo_revalider_tags', $tags)),
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

// ── WP-sider ────────────────────────────────────────────────────────────────

/**
 * En side som er, eller har vært, publisert: publisering, oppdatering,
 * avpublisering (til utkast/privat) og papirkurv. Utkast som aldri har vært
 * publisert (draft → draft), autosave og revisjoner utløser ingenting.
 */
add_action('transition_post_status', function($ny, $gammel, $post) {
    if (!$post instanceof WP_Post || $post->post_type !== 'page') return;
    if (wp_is_post_autosave($post) || wp_is_post_revision($post)) return;
    if ($ny !== 'publish' && $gammel !== 'publish') return;
    amendo_revalider_side();
}, 10, 3);

// Permanent sletting av en publisert side (uten papirkurv) går ikke via
// transition_post_status. En side i papirkurven ble varslet da den ble flyttet dit.
add_action('before_delete_post', function($post_id, $post = null) {
    $post = $post instanceof WP_Post ? $post : get_post($post_id);
    if ($post && $post->post_type === 'page' && $post->post_status === 'publish') {
        amendo_revalider_side();
    }
}, 10, 2);

/** Én gang per forespørsel — én lagring kan gi flere statusoverganger. */
function amendo_revalider_side() {
    static $sendt = false;
    if ($sendt) return;
    $sendt = true;
    amendo_revalider(['side']);
}
