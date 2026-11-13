<?php
/**
 * Beløpssikring (1.4.41): er det Adyen meldte BETALT ikke lik ordretotalen
 * når betalingen fullføres, settes ordren til «On hold» med et notat — ikke
 * «Behandler».
 *
 * Hvorfor: headless-kassen gjenbruker den ventende ordren (én ordre per
 * kjøp) og kan endre linjene på den. En betaling som ble startet før
 * endringen, og som fullføres etterpå, ville ellers gjort ordren til
 * «Behandler» med en annen sum enn den kunden betalte — og butikken ville
 * plukket og sendt varer ingen har betalt for.
 *
 * To veier inn:
 *  1. Gatewayens Adyen-webhook (`POST /wp-json/aorder/v1/amendo-adyen`,
 *     woocommerce-amendo-gateway): vi kan ikke endre gatewayen, så beløpet
 *     leses av webhookens kropp FØR gatewayen kjører
 *     (`rest_request_before_callbacks`), og statusen settes når gatewayen
 *     kaller payment_complete() (`woocommerce_payment_complete_order_status`).
 *     Gjelder Vipps, Klarna og kort i sesjonsflyten.
 *  2. Kortbetalingen uten sesjon (includes/kortbetaling.php, Authorised):
 *     sjekker beløpet selv og bruker amendo_belopssikring_on_hold().
 *
 * ⚠ Ordre-ID-en leses av `merchantReference` (`<vert>/<id>`, se
 * Adyen::reference() og betaling.php) — siste del etter siste skråstrek.
 * Kassen sender ordrens NUMMER som referanse; nummeret er ID-en så lenge
 * butikken ikke har en plugin for løpenummer.
 *
 * ⚠ Bare `AUTHORISATION` med `success: true` teller. Uten et beløp fra
 * webhooken (andre gatewayer, wp-admin) gjør filteret ingenting.
 */

if (!defined('ABSPATH')) exit;

/** Øre Adyen meldte betalt, per ordre-ID, i denne forespørselen. */
function amendo_belopssikring_betalt($ordre_id, $ore = null) {
    static $kart = [];
    if ($ore !== null) $kart[(int) $ordre_id] = (int) $ore;
    return $kart[(int) $ordre_id] ?? null;
}

/** Setter ordren «On hold» med notat. Brukt av webhook-veien og kortbetalingen. */
function amendo_belopssikring_on_hold(WC_Order $ordre, int $betalt_ore, int $ordre_ore, string $kilde) {
    $tekst = sprintf(
        'Beløpssikring (%s): Adyen meldte %s betalt, men ordretotalen er %s. Ordren er satt til «On hold», ikke «Behandler» — kontroller betalingen i Adyen før ordren behandles.',
        $kilde,
        number_format($betalt_ore / 100, 2, ',', ' ') . ' ' . $ordre->get_currency(),
        number_format($ordre_ore / 100, 2, ',', ' ') . ' ' . $ordre->get_currency()
    );
    if (function_exists('amendo_betaling_logg')) {
        amendo_betaling_logg("beløpssikring: ordre {$ordre->get_id()} betalt $betalt_ore øre, total $ordre_ore øre ($kilde) — on-hold");
    }
    return $tekst;
}

// 1a) Webhooken: beløpet per ordre, før gatewayen behandler den.
add_filter('rest_request_before_callbacks', function ($svar, $handler, $request) {
    if (!($request instanceof WP_REST_Request) || strpos($request->get_route(), '/aorder/v1/amendo-adyen') !== 0) {
        return $svar;
    }
    $kropp = json_decode((string) $request->get_body(), true);
    foreach ((is_array($kropp) ? ($kropp['notificationItems'] ?? []) : []) as $element) {
        $n = is_array($element) ? ($element['NotificationRequestItem'] ?? null) : null;
        if (!is_array($n) || ($n['eventCode'] ?? '') !== 'AUTHORISATION') continue;
        if (!in_array($n['success'] ?? '', ['true', true], true)) continue;
        $id    = (int) preg_replace('~^.*/~', '', (string) ($n['merchantReference'] ?? ''));
        $verdi = $n['amount']['value'] ?? null;
        if ($id > 0 && is_numeric($verdi)) amendo_belopssikring_betalt($id, (int) $verdi);
    }
    return $svar;
}, 10, 3);

// 1b) payment_complete(): avviker beløpet, blir statusen «On hold».
add_filter('woocommerce_payment_complete_order_status', function ($status, $ordre_id, $ordre = null) {
    $betalt = amendo_belopssikring_betalt($ordre_id);
    if ($betalt === null) return $status;
    $ordre = $ordre instanceof WC_Order ? $ordre : wc_get_order($ordre_id);
    if (!$ordre) return $status;
    $forventet = (int) round(((float) $ordre->get_total()) * 100);
    if ($betalt === $forventet) return $status;
    $ordre->add_order_note(amendo_belopssikring_on_hold($ordre, $betalt, $forventet, 'Adyen-webhook'));
    return 'on-hold';
}, 99, 3);
