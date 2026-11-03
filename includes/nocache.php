<?php
/**
 * Ingen mellomlagring av amendo-settings/v1 (1.4.28).
 *
 * Flere av rutene er kundespesifikke — rabatt/kunde svarer med én kundes gruppe,
 * rabatt og faktura — og LiteSpeed kan cache REST-GET-er. Et mellomlagret svar
 * kunne gitt neste kunde feil pris eller faktura. Derfor får ALLE svar under
 * navnerommet, også feil (401/403/404), uten unntak:
 *
 *   - nocache_headers() (Cache-Control: no-cache, must-revalidate, max-age=0,
 *     no-store, private og Expires i fortiden), både som header() og på
 *     REST-svaret, så de står der selv om noe annet har satt dem;
 *   - X-LiteSpeed-Cache-Control: no-cache, som LiteSpeed-serveren følger også
 *     uten LiteSpeed-pluginen;
 *   - do_action('litespeed_control_set_nocache'), for LiteSpeed-pluginen.
 *
 * Frontendene mellomlagrer selv (Next.js/Vercel) der det er trygt.
 *
 * rest_post_dispatch kjøres for HTTP-forespørsler, ikke for rest_do_request()
 * internt, og internt er det ingen cache å stoppe.
 */

if (!defined('ABSPATH')) exit;

add_filter('rest_post_dispatch', function($svar, $server, $req) {
    if (!($svar instanceof WP_HTTP_Response) || !($req instanceof WP_REST_Request)) return $svar;
    $rute = (string) $req->get_route();
    if ($rute !== '/amendo-settings/v1' && strpos($rute, '/amendo-settings/v1/') !== 0) return $svar;

    nocache_headers();
    foreach (wp_get_nocache_headers() as $navn => $verdi) {
        if ($verdi !== false && $verdi !== '') $svar->header($navn, $verdi);
    }
    $svar->header('X-LiteSpeed-Cache-Control', 'no-cache');
    do_action('litespeed_control_set_nocache', 'amendo-settings: REST-svar skal ikke mellomlagres');
    return $svar;
}, 10, 3);
