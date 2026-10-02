<?php
/**
 * Betalingsrutene: POST /adyen-session og POST /gateway-redirect.
 *
 * Kalles server-side av headless-kassen. Svarer ALLTID med JSON — også ved
 * fatale feil i gateway-pluginene (try/catch Throwable). En HTML-feilside fra
 * WordPress gir bare «Unexpected token <» i kassen og er umulig å feilsøke.
 * Detaljene logges (WooCommerce › Status › Logger, kilde amendo-betaling);
 * svaret har en kort norsk feilmelding under `error`, som før.
 *
 * Fatale PHP-feil som ikke er Throwable (minne, tidsavbrudd) kan ikke fanges.
 */

if (!defined('ABSPATH')) exit;

/** Gateway-ID-en til Amendo Gateway (kort). Understrek var feil, men beholdes som reserve. */
const AMENDO_ADYEN_KORT_GATEWAYER = ['amendo-adyen-card', 'amendo_adyen_card'];

function amendo_betaling_logg($melding, $niva = 'error') {
    if (function_exists('wc_get_logger')) {
        wc_get_logger()->log($niva, $melding, ['source' => 'amendo-betaling']);
    }
}

function amendo_betaling_feil($melding, $status, $logg = null) {
    if ($logg !== null) amendo_betaling_logg($logg);
    return new WP_REST_Response(['error' => $melding], $status);
}

function amendo_gateway_redirect($request) {
    try {
        return amendo_gateway_redirect_inner($request);
    } catch (Throwable $e) {
        return amendo_betaling_feil('Betalingen kunne ikke startes', 500,
            'gateway-redirect: ' . get_class($e) . ': ' . $e->getMessage() . ' i ' . $e->getFile() . ':' . $e->getLine());
    }
}

function amendo_gateway_redirect_inner($request) {
    $body       = $request->get_json_params();
    $body       = is_array($body) ? $body : [];
    $order_id   = intval($body['order_id'] ?? 0);
    $gateway_id = sanitize_text_field($body['gateway_id'] ?? '');
    $return_url = sanitize_url($body['return_url'] ?? '');

    if (!$order_id || !$gateway_id) {
        return amendo_betaling_feil('Mangler order_id eller gateway_id', 400);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return amendo_betaling_feil('Fant ikke ordren', 404);
    }

    $gateways = WC()->payment_gateways()->payment_gateways();
    $gateway  = $gateways[$gateway_id] ?? null;

    if (!$gateway) {
        return amendo_betaling_feil('Fant ikke gateway: ' . $gateway_id, 404);
    }

    // Gatewayer som Vipps leser WC()->session og WC()->customer, som er null i
    // REST. Last kurv/sesjon/kunde, og fyll kunden fra ordren (telefon og
    // adresse brukes bl.a. til forhåndsutfylling hos Vipps).
    amendo_betaling_klargjor_kunde($order);

    // Overstyr returnUrl til headless kasse/takk-siden
    if (!empty($return_url)) {
        add_filter('woocommerce_get_checkout_order_received_url', function() use ($return_url, $order_id) {
            return add_query_arg('order_id', $order_id, $return_url);
        });
        add_filter('woocommerce_get_return_url', function() use ($return_url, $order_id) {
            return add_query_arg('order_id', $order_id, $return_url);
        });
    }

    $result = $gateway->process_payment($order_id);

    if (is_array($result) && ($result['result'] ?? '') === 'success' && !empty($result['redirect'])) {
        return new WP_REST_Response([
            'redirect' => $result['redirect'],
        ], 200);
    }

    return amendo_betaling_feil('Gateway returnerte ikke redirect', 502,
        'gateway-redirect: ' . $gateway_id . ' ga ikke redirect for ordre ' . $order_id . ': ' . wp_json_encode($result));
}

/** wc_load_cart(), og WC()->customer med ordrens faktura- (og leverings)adresse. */
function amendo_betaling_klargjor_kunde(WC_Order $order) {
    if (function_exists('wc_load_cart') && (!WC()->session || !WC()->customer || !WC()->cart)) {
        wc_load_cart();
    }
    $kunde = WC()->customer;
    if (!$kunde) return;

    foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state', 'email', 'phone'] as $felt) {
        $kunde->{"set_billing_$felt"}($order->{"get_billing_$felt"}());
    }
    if ($order->has_shipping_address()) {
        foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'state'] as $felt) {
            $kunde->{"set_shipping_$felt"}($order->{"get_shipping_$felt"}());
        }
    }
}

function amendo_create_adyen_session($request) {
    try {
        return amendo_create_adyen_session_inner($request);
    } catch (Throwable $e) {
        return amendo_betaling_feil('Kunne ikke opprette Adyen-sesjon', 502,
            'adyen-session: ' . get_class($e) . ': ' . $e->getMessage() . ' i ' . $e->getFile() . ':' . $e->getLine());
    }
}

function amendo_create_adyen_session_inner($request) {
    // Sjekk at gateway-pluginen er lastet
    if (!class_exists('AOrder\Gateways\API\Adyen') || !function_exists('AmendoCore')) {
        return amendo_betaling_feil('Amendo Gateway ikke aktivert', 503);
    }

    $body           = $request->get_json_params();
    $body           = is_array($body) ? $body : [];
    $amount         = floatval($body['amount'] ?? 0);
    $currency       = sanitize_text_field($body['currency'] ?? 'NOK');
    $country        = sanitize_text_field($body['countryCode'] ?? 'NO');
    $ref            = sanitize_text_field($body['reference'] ?? uniqid('order_'));
    $payment_method = sanitize_text_field($body['payment_method'] ?? '');

    if ($amount <= 0) {
        return amendo_betaling_feil('Ugyldig beløp', 400);
    }

    // Client key og miljø FØR sesjonen lages: med live-nøkler mot
    // testmiljøet (eller omvendt) feiler betalingen i nettleseren uansett.
    $client_key = amendo_adyen_client_key();
    $live       = (bool) \AmendoCore()->is_live();
    if ($client_key === '') {
        return amendo_betaling_feil('Mangler Adyen client key i Amendo Gateway', 500,
            'adyen-session: fant ingen client_key på ' . implode(' eller ', AMENDO_ADYEN_KORT_GATEWAYER));
    }
    if (strpos($client_key, 'live_') === 0 && !$live) {
        $melding = 'Amendo Gateway står i testmodus, men nøklene er live';
        return amendo_betaling_feil($melding, 500, 'adyen-session: ' . $melding);
    }
    if (strpos($client_key, 'test_') === 0 && $live) {
        $melding = 'Amendo Gateway står i live-modus, men nøklene er test';
        return amendo_betaling_feil($melding, 500, 'adyen-session: ' . $melding);
    }

    // Klarna bruker AmendoPOS merchant account — kall api_call() direkte
    // slik at vi unngår å endre gateway-pluginen
    $is_klarna = strpos($payment_method, 'klarna') !== false;

    if ($is_klarna && class_exists('AOrder\Gateways\Adyen\Klarna')) {
        $merchant_account = \AOrder\Gateways\Adyen\Klarna::merchant_account();
        $session = \AOrder\Gateways\API\Adyen::api_call('/sessions', [
            'merchantAccount' => $merchant_account,
            'amount'          => [
                'currency' => $currency ?: get_woocommerce_currency(),
                'value'    => $amount * 100,
            ],
            'countryCode'     => $country ?: WC()->countries->get_base_country(),
            'returnUrl'       => site_url('checkout'),
            'channel'         => 'Web',
            'reference'       => get_site_url() . '/' . $ref,
        ]);
    } else {
        $session = \AOrder\Gateways\API\Adyen::create_session($amount, $ref, $country, $currency);
    }

    // stdClass fra create_session, array fra api_call — eller tomt/false/null
    // når Adyen svarer med feil. ⚠ `$objekt['id']` på et stdClass er en fatal
    // Error, så typen må sjekkes før oppslaget.
    $session_id   = is_object($session) ? ($session->id ?? null) : (is_array($session) ? ($session['id'] ?? null) : null);
    $session_data = is_object($session) ? ($session->sessionData ?? null) : (is_array($session) ? ($session['sessionData'] ?? null) : null);

    if (empty($session_id) || !is_scalar($session_id)) {
        return amendo_betaling_feil('Kunne ikke opprette Adyen-sesjon', 502,
            'adyen-session: Adyen ga ingen sesjons-id (' . ($is_klarna ? 'Klarna' : 'kort') . ', ref ' . $ref . '): ' . wp_json_encode($session));
    }

    return new WP_REST_Response([
        'sessionId'   => $session_id,
        'sessionData' => $session_data,
        'clientKey'   => $client_key,
        'environment' => $live ? 'live' : 'test',
    ], 200);
}

/** client_key fra kortgatewayen: bindestrek-ID først, understrek som reserve. */
function amendo_adyen_client_key() {
    $gateways = WC()->payment_gateways()->payment_gateways();
    foreach (AMENDO_ADYEN_KORT_GATEWAYER as $id) {
        if (!empty($gateways[$id])) {
            $nokkel = trim((string) $gateways[$id]->get_option('client_key'));
            if ($nokkel !== '') return $nokkel;
        }
    }
    return '';
}
