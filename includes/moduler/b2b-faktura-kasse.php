<?php
/**
 * Faktura i WooCommerce sin egen kasse (1.4.43). Del av B2B-modulen: lastes
 * bare når B2B er på — se includes/moduler.php.
 *
 * For butikker UTEN headless-frontend (Frontend-URL tom i Butikk-fanen), der
 * kunden handler i temaets kasse. Der bestemmer fakturagatewayen selv
 * (Amendo Gateway, `amendo-invoice`, Gateway_Invoice::is_available()): alle,
 * alle innloggede eller kundegruppen (`_tellixGroupId` →
 * `enabled_customer_group_<id>`). Regelen vår fra 1.4.27 —
 * amendo_faktura_for_bruker() i b2b-tellix.php — gjelder bare i rabatt/kunde,
 * som frontendene leser. Her brukes den i kassen også:
 *
 *   faktura = true      (gruppen har faktura, eller koblet Tellix-bedrift:
 *                        godkjent eller org.nr i Tellix)  → fakturaen vises,
 *                        også når gatewayen selv ville skjult den
 *   grunn = 'sperret'   («Ikke faktura» på kunden)        → fakturaen fjernes,
 *                        også når gatewayen selv ville vist den
 *   ellers                                                 → som gatewayen sier
 *                        (f.eks. «alle innloggede»: vi tar ikke bort noe der)
 *
 * Gjelder `woocommerce_available_payment_gateways`, som WooCommerce bruker både
 * til å VISE betalingsmåtene og til å GODTA den valgte: kassen
 * (WC_Checkout), betal-for-ordre-siden (WC_Form_Handler::pay_action) og Store
 * API-kassen. En kunde som er sperret, kan altså ikke sende `amendo-invoice`
 * likevel. Fakturaen legges bare til når gatewayen er slått på
 * (`enabled = yes`), og i gatewayenes egen rekkefølge.
 *
 * Betal for ordre: regelen gjelder ordrens kunde, og bare når det er den
 * innloggede (WooCommerce krever det selv for ordrer med kunde). Ellers, og
 * for kunder som ikke er logget inn: som gatewayen sier.
 *
 * ⚠ Ingen endring når Frontend-URL er satt (frontendene velger betalingsmåte
 * fra rabatt/kunde), i wp-admin eller for REST utenom Store API. Org.nr fra
 * Tellix leses fra vår kopi av kundelista — ingen kall til Tellix i kassen.
 * ⚠ Gatewayen 1.36.19 har ingen blokk-integrasjon: fakturaen vises bare i den
 * klassiske kassen ([woocommerce_checkout]), ikke i kasseblokken.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_FAKTURA_GATEWAY = 'amendo-invoice';

/** Brukeren regelen gjelder for i denne forespørselen, eller 0 (ingen endring). */
function amendo_faktura_kasse_bruker() {
    $naa = get_current_user_id();
    if ($naa <= 0) return 0;
    $ordre_id = absint(get_query_var('order-pay'));
    if ($ordre_id && function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
        $ordre = wc_get_order($ordre_id);
        return $ordre && (int) $ordre->get_customer_id() === $naa ? $naa : 0;
    }
    return $naa;
}

/** Skal regelen brukes her? Bare uten frontend, ikke i wp-admin. */
function amendo_faktura_kasse_gjelder() {
    if (amendo_frontend_url() !== '') return false;
    if (is_admin() && !wp_doing_ajax()) return false;
    return true;
}

add_filter('woocommerce_available_payment_gateways', function($tilgjengelige) {
    if (!is_array($tilgjengelige) || !amendo_faktura_kasse_gjelder()) return $tilgjengelige;
    $bruker = amendo_faktura_kasse_bruker();
    if ($bruker <= 0) return $tilgjengelige;

    $f = amendo_faktura_for_bruker($bruker, (int) get_user_meta($bruker, '_tellixGroupId', true));
    if (($f['grunn'] ?? '') === 'sperret') {
        unset($tilgjengelige[AMENDO_FAKTURA_GATEWAY]);
        return $tilgjengelige;
    }
    if (empty($f['faktura']) || isset($tilgjengelige[AMENDO_FAKTURA_GATEWAY])) return $tilgjengelige;

    $alle = function_exists('WC') && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
    $gw = $alle[AMENDO_FAKTURA_GATEWAY] ?? null;
    if (!$gw || $gw->enabled !== 'yes') return $tilgjengelige;

    // Gatewayenes rekkefølge fra WooCommerce › Betalinger.
    $ut = [];
    foreach ($alle as $id => $g) {
        if (isset($tilgjengelige[$id])) $ut[$id] = $tilgjengelige[$id];
        elseif ($id === AMENDO_FAKTURA_GATEWAY) $ut[$id] = $gw;
    }
    return $ut + $tilgjengelige;
}, 100);
