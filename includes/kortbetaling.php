<?php
/**
 * Kortbetaling uten sesjon — Adyen «Advanced flow» (1.4.11).
 *
 *   POST /adyen-metoder   betalingsmåtene (bare kort) for land og valuta — kassen
 *                         viser kortfeltene med én gang, uten ordre og sesjon
 *   POST /adyen-betal     ordre-ID + krypterte kortdata → Adyen /payments
 *   POST /adyen-detaljer  fullfører 3D Secure → Adyen /payments/details
 *
 * ⚠⚠ BELØPET KOMMER FRA ORDREN, ALDRI FRA NETTLESEREN. /adyen-betal tar bare
 * ordre-ID-en; beløpet er `$order->get_total()` i øre, regnet her.
 *
 * ⚠⚠ ØRE, IKKE KRONER — og ikke via `API_Adyen::create_payment()`. Den gjør
 * `$amount * 100` på et flyttall (235.3 * 100 = 23529.999…), og Adyen vil ha et
 * heltall. Her kalles `/payments` direkte med `(int) round(total * 100)`.
 * (Sesjonsflyten sender fortsatt KRONER til create_session() — se
 * /api/adyen-session i frontenden. Ikke bland de to.)
 *
 * ⚠ SAMME `reference` SOM SESJONSFLYTEN: `API_Adyen::reference($ordre_id)` =
 * «<host>/<ordre-id>». Gatewayens webhook (aorder/v1/amendo-adyen,
 * AUTHORISATION) finner ordren med `order_from_adyen_ref()` på siste ledd, så
 * den oppdaterer også ordrer betalt her (verifisert mot 1.30.2). I tillegg
 * markeres ordren betalt fra Adyen sitt EGET svar på /payments og
 * /payments/details (server til server) — kassen er da ikke avhengig av at
 * webhooken er satt opp. payment_complete() to ganger er trygt (lageret
 * trekkes én gang).
 *
 * ⚠⚠ FAIL-CLOSED: uten `amendo_kasse_secret` svarer alle tre 503, med feil
 * secret 403. (De eldre betalingsrutene slipper gjennom uten secret.)
 *
 * Svarer ALLTID JSON, også ved unntak i gatewayen.
 */

if (!defined('ABSPATH')) exit;

/** Feltene Adyen sine kortkomponenter sender — alt annet kastes. */
const AMENDO_KORT_FELTER = ['type', 'encryptedCardNumber', 'encryptedExpiryMonth', 'encryptedExpiryYear', 'encryptedSecurityCode', 'holderName', 'brand', 'checkoutAttemptId'];
const AMENDO_NETTLESER_FELTER = ['acceptHeader', 'colorDepth', 'language', 'javaEnabled', 'javaScriptEnabled', 'screenHeight', 'screenWidth', 'userAgent', 'timeZoneOffset'];
const AMENDO_3DS_FELTER = ['redirectResult', 'threeDSResult', 'threeds2.challengeResult', 'threeds2.fingerprint', 'payload', 'MD', 'PaRes'];

add_action('rest_api_init', function() {
    foreach (['adyen-metoder' => 'amendo_adyen_metoder', 'adyen-betal' => 'amendo_adyen_betal', 'adyen-detaljer' => 'amendo_adyen_detaljer'] as $rute => $funksjon) {
        register_rest_route('amendo-settings/v1', '/' . $rute, [
            'methods'             => 'POST',
            'callback'            => function($request) use ($funksjon, $rute) {
                try {
                    return $funksjon($request);
                } catch (Throwable $e) {
                    return amendo_betaling_feil('Betalingen kunne ikke gjennomføres', 500,
                        "$rute: " . get_class($e) . ': ' . $e->getMessage() . ' i ' . $e->getFile() . ':' . $e->getLine());
                }
            },
            'permission_callback' => 'amendo_kortbetaling_tilgang',
        ]);
    }
});

/** Fail-closed: ingen secret satt → 503, feil secret → 403. */
function amendo_kortbetaling_tilgang($request) {
    $secret = (string) get_option('amendo_kasse_secret', '');
    if ($secret === '') {
        return new WP_Error('amendo_kasse_secret_mangler', 'Kassens secret er ikke satt i Amendo-admin.', ['status' => 503]);
    }
    if (!hash_equals($secret, (string) $request->get_header('X-Amendo-Secret'))) {
        return new WP_Error('amendo_ingen_tilgang', 'Ugyldig secret.', ['status' => 403]);
    }
    return true;
}

/** Gatewayen er lastet, og nøkkelen og miljøet stemmer. Gir [client_key, live] eller et feilsvar. */
function amendo_kortbetaling_klar() {
    if (!class_exists('AOrder\Gateways\API\Adyen') || !class_exists('AOrder\Gateways\Adyen\Card') || !function_exists('AmendoCore')) {
        return amendo_betaling_feil('Amendo Gateway ikke aktivert', 503);
    }
    $client_key = amendo_adyen_client_key();
    $live       = (bool) \AmendoCore()->is_live();
    if ($client_key === '') {
        return amendo_betaling_feil('Mangler Adyen client key i Amendo Gateway', 500, 'kortbetaling: fant ingen client_key');
    }
    if (strpos($client_key, 'live_') === 0 && !$live) {
        return amendo_betaling_feil('Amendo Gateway står i testmodus, men nøklene er live', 500, 'kortbetaling: testmodus med live-nøkler');
    }
    if (strpos($client_key, 'test_') === 0 && $live) {
        return amendo_betaling_feil('Amendo Gateway står i live-modus, men nøklene er test', 500, 'kortbetaling: live-modus med test-nøkler');
    }
    return [$client_key, $live];
}

/** stdClass/array/WP_Error fra gatewayen → array, eller null ved feil. */
function amendo_kortbetaling_svar($svar) {
    if (is_wp_error($svar) || $svar === null || $svar === false) return null;
    $arr = json_decode(wp_json_encode($svar), true);
    return is_array($arr) ? $arr : null;
}

function amendo_kortbetaling_kallfeil($rute, $svar) {
    $hva = is_wp_error($svar) ? ($svar->get_error_code() . ' ' . $svar->get_error_message()) : wp_json_encode($svar);
    return amendo_betaling_feil('Betalingen kunne ikke gjennomføres', 502, "$rute: Adyen svarte ikke som ventet: $hva");
}

// ── /adyen-metoder ─────────────────────────────────────────────────────────

function amendo_adyen_metoder($request) {
    $klar = amendo_kortbetaling_klar();
    if ($klar instanceof WP_REST_Response) return $klar;
    [$client_key, $live] = $klar;

    $body     = $request->get_json_params();
    $body     = is_array($body) ? $body : [];
    $land     = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($body['countryCode'] ?? 'NO'))) ?: 'NO';
    $valuta   = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($body['currency'] ?? get_woocommerce_currency()))) ?: 'NOK';
    $belop    = isset($body['amount']) && is_numeric($body['amount']) && $body['amount'] > 0 ? (int) round($body['amount'] * 100) : null;
    if (strlen($land) !== 2 || strlen($valuta) !== 3) {
        return amendo_betaling_feil('Ugyldig land eller valuta', 400);
    }

    // Kort cache: listen er lik for alle kunder, og kassen spør når den åpnes.
    $nokkel = 'amendo_adyen_metoder_' . md5(wp_json_encode([$land, $valuta, $belop, $client_key]));
    $metoder = get_transient($nokkel);
    if (!is_array($metoder)) {
        $param = [
            'merchantAccount' => \AOrder\Gateways\Adyen\Card::merchant_account(),
            'countryCode'     => $land,
            'channel'         => 'Web',
            'shopperLocale'   => 'nb-NO',
        ];
        if ($belop !== null) $param['amount'] = ['currency' => $valuta, 'value' => $belop];
        $svar = amendo_kortbetaling_svar(\AOrder\Gateways\API\Adyen::api_call('/paymentMethods', $param));
        if (!$svar || !isset($svar['paymentMethods']) || !is_array($svar['paymentMethods'])) {
            return amendo_kortbetaling_kallfeil('adyen-metoder', $svar);
        }
        // ⚠ BARE KORT. Vipps og Klarna har egne knapper i kassen (redirect via
        // gatewayen); å vise dem her ville gitt to valg for samme metode.
        $metoder = ['paymentMethods' => array_values(array_filter($svar['paymentMethods'], function($m) {
            return is_array($m) && ($m['type'] ?? '') === 'scheme';
        }))];
        if (!$metoder['paymentMethods']) {
            return amendo_betaling_feil('Kortbetaling er ikke aktivert hos Adyen', 502, 'adyen-metoder: ingen «scheme» i svaret');
        }
        set_transient($nokkel, $metoder, 10 * MINUTE_IN_SECONDS);
    }

    return new WP_REST_Response([
        'paymentMethods' => $metoder,
        'clientKey'      => $client_key,
        'environment'    => $live ? 'live' : 'test',
    ], 200);
}

// ── /adyen-betal ───────────────────────────────────────────────────────────

function amendo_adyen_betal($request) {
    $klar = amendo_kortbetaling_klar();
    if ($klar instanceof WP_REST_Response) return $klar;

    $body = $request->get_json_params();
    $body = is_array($body) ? $body : [];

    $ordre = amendo_kortbetaling_ordre($body['ordre_id'] ?? null);
    if ($ordre instanceof WP_REST_Response) return $ordre;

    $kort = amendo_kortbetaling_plukk($body['paymentMethod'] ?? null, AMENDO_KORT_FELTER);
    if (!$kort || ($kort['type'] ?? '') !== 'scheme' || empty($kort['encryptedCardNumber'])) {
        return amendo_betaling_feil('Ugyldige kortdata', 400);
    }
    $nettleser = amendo_kortbetaling_plukk($body['browserInfo'] ?? null, AMENDO_NETTLESER_FELTER, true) ?: null;
    $origin    = amendo_kortbetaling_url($body['origin'] ?? '');
    $retur     = amendo_kortbetaling_url($body['returnUrl'] ?? '');
    if ($origin === '' || $retur === '') {
        return amendo_betaling_feil('Mangler origin eller returnUrl', 400);
    }
    $ip = filter_var((string) ($body['shopperIP'] ?? ''), FILTER_VALIDATE_IP) ?: null;

    $ordre_id = $ordre->get_id();
    $ore      = (int) round(((float) $ordre->get_total()) * 100);
    $valuta   = $ordre->get_currency() ?: get_woocommerce_currency();
    $ref      = \AOrder\Gateways\API\Adyen::reference($ordre_id);

    // Ordren skal stå med kort som betalingsmåte (kvittering, wp-admin, refusjon).
    $gateways = WC()->payment_gateways()->payment_gateways();
    $kortgw   = $gateways['amendo-adyen-card'] ?? null;
    $ordre->set_payment_method('amendo-adyen-card');
    $ordre->set_payment_method_title($kortgw ? $kortgw->get_title() : 'Kort');
    $ordre->update_meta_data('_adyen_reference', (string) $ordre_id);
    $ordre->save();

    $param = array_filter([
        'merchantAccount'    => \AOrder\Gateways\Adyen\Card::merchant_account(),
        'reference'          => $ref,
        'amount'             => ['currency' => $valuta, 'value' => $ore],
        'countryCode'        => $ordre->get_billing_country() ?: WC()->countries->get_base_country(),
        'paymentMethod'      => $kort,
        'browserInfo'        => $nettleser,
        'channel'            => 'Web',
        'origin'             => $origin,
        'returnUrl'          => $retur,
        'shopperIP'          => $ip,
        'shopperEmail'       => $ordre->get_billing_email() ?: null,
        'shopperLocale'      => 'nb-NO',
        'shopperStatement'   => amendo_kortbetaling_kontoutskrift($kortgw),
        'authenticationData' => ['threeDSRequestData' => ['nativeThreeDS' => 'preferred']],
        'splits'             => amendo_kortbetaling_splits($ore, $ref),
        'applicationInfo'    => [
            'externalPlatform'    => ['name' => 'Amendo WooCommerce Integration', 'integrator' => 'Amendo Group AS'],
            'merchantApplication' => ['name' => 'Amendo headless-kasse', 'version' => AMENDO_SETTINGS_VERSION],
        ],
    ], function($v) { return $v !== null && $v !== ''; });

    $svar = amendo_kortbetaling_svar(\AOrder\Gateways\API\Adyen::api_call('/payments', $param));
    if (!$svar || empty($svar['resultCode'])) {
        return amendo_kortbetaling_kallfeil('adyen-betal', $svar);
    }
    return amendo_kortbetaling_resultat($ordre, $svar, $ore);
}

// ── /adyen-detaljer ────────────────────────────────────────────────────────

function amendo_adyen_detaljer($request) {
    $klar = amendo_kortbetaling_klar();
    if ($klar instanceof WP_REST_Response) return $klar;

    $body  = $request->get_json_params();
    $body  = is_array($body) ? $body : [];
    $ordre = amendo_kortbetaling_ordre($body['ordre_id'] ?? null, true);
    if ($ordre instanceof WP_REST_Response) return $ordre;

    $detaljer = amendo_kortbetaling_plukk($body['details'] ?? null, AMENDO_3DS_FELTER);
    if (!$detaljer) {
        return amendo_betaling_feil('Mangler 3D Secure-detaljer', 400);
    }
    $param = ['details' => $detaljer];
    if (is_string($body['paymentData'] ?? null) && $body['paymentData'] !== '' && strlen($body['paymentData']) < 20000) {
        $param['paymentData'] = $body['paymentData'];
    }

    $svar = amendo_kortbetaling_svar(\AOrder\Gateways\API\Adyen::send_payment_details($param));
    if (!$svar || empty($svar['resultCode'])) {
        return amendo_kortbetaling_kallfeil('adyen-detaljer', $svar);
    }

    /*
     * ⚠⚠ DETALJENE MÅ HØRE TIL DENNE ORDREN. 3DS-svaret kommer fra
     * nettleseren; uten sjekken kunne detaljene fra en betaling for ordre A
     * blitt brukt til å markere ordre B som betalt. Adyen sitt svar bærer
     * `merchantReference` — den skal være referansen vi laget for ordren.
     */
    $ref = \AOrder\Gateways\API\Adyen::reference($ordre->get_id());
    if (($svar['merchantReference'] ?? null) !== $ref) {
        amendo_betaling_logg('adyen-detaljer: merchantReference ' . wp_json_encode($svar['merchantReference'] ?? null) . " stemmer ikke med $ref (ordre " . $ordre->get_id() . ')');
        return new WP_REST_Response(['resultCode' => (string) $svar['resultCode'], 'bekreftet' => false], 200);
    }
    return amendo_kortbetaling_resultat($ordre, $svar, (int) round(((float) $ordre->get_total()) * 100));
}

// ── Felles ─────────────────────────────────────────────────────────────────

/**
 * Ordren som skal betales: finnes, er laget av headless-kassen, og trenger
 * betaling. `$tillat_betalt` for /adyen-detaljer — webhooken kan ha rukket å
 * markere den betalt før nettleseren kommer tilbake fra 3D Secure.
 */
function amendo_kortbetaling_ordre($id, $tillat_betalt = false) {
    $id = is_numeric($id) ? (int) $id : 0;
    $ordre = $id > 0 ? wc_get_order($id) : null;
    // Samme svar på «finnes ikke» og «ikke fra kassen».
    if (!$ordre || $ordre->get_meta('_nextjs_kasse') !== '1') {
        return amendo_betaling_feil('Fant ikke ordren', 404);
    }
    if (!$tillat_betalt && (!$ordre->needs_payment() || $ordre->get_date_paid())) {
        return amendo_betaling_feil('Ordren kan ikke betales', 409);
    }
    if (((float) $ordre->get_total()) <= 0) {
        return amendo_betaling_feil('Ordren har ingen sum å betale', 409);
    }
    return $ordre;
}

/** Bare kjente nøkler, bare skalarer, ingen lange verdier. */
function amendo_kortbetaling_plukk($inn, array $tillatt, $tall_ok = false) {
    if (!is_array($inn)) return null;
    $ut = [];
    foreach ($tillatt as $k) {
        if (!array_key_exists($k, $inn)) continue;
        $v = $inn[$k];
        if (is_string($v) && strlen($v) <= 20000) $ut[$k] = $v;
        elseif ($tall_ok && (is_int($v) || is_bool($v))) $ut[$k] = $v;
    }
    return $ut;
}

/** https-adresser (og http://localhost for utvikling), ellers tom streng. */
function amendo_kortbetaling_url($url) {
    $url = esc_url_raw((string) $url, ['https', 'http']);
    if ($url === '') return '';
    $skjema = wp_parse_url($url, PHP_URL_SCHEME);
    $vert   = wp_parse_url($url, PHP_URL_HOST);
    return ($skjema === 'https' || $vert === 'localhost') ? $url : '';
}

function amendo_kortbetaling_kontoutskrift($kortgw) {
    if (!$kortgw || !method_exists($kortgw, 'get_option')) return null;
    $tekst = trim((string) $kortgw->get_option('cc_caption'));
    return $tekst !== '' ? mb_substr($tekst, 0, 22) : null;
}

/**
 * Splittene som gatewayens egen /payments (Card::get_splits) sender:
 * Amendo sin provisjon og resten til butikkens konto. Samme regel, i øre.
 * Filteret `amendo_kortbetaling_splits` kan overstyre (null = ingen).
 */
function amendo_kortbetaling_splits(int $ore, string $ref) {
    $splits = null;
    if (class_exists('AOrder\Gateways\API\Amendo') && method_exists('AOrder\Gateways\API\Amendo', 'get_split_rate')) {
        $sats = \AOrder\Gateways\API\Amendo::get_split_rate();
        $sats = is_object($sats) ? ($sats->rate ?? null) : $sats;
        if (is_numeric($sats) && $sats > 0) {
            $provisjon = (int) round($ore * ((float) $sats / 100));
            $butikk = ['amount' => ['value' => $ore - $provisjon], 'reference' => "/ECOM-mp-split/$ref"];
            $konto = \AOrder\Gateways\Adyen\Card::balance_account();
            if (!empty($konto)) {
                $butikk['type'] = 'BalanceAccount';
                $butikk['account'] = $konto;
            } else {
                $butikk['type'] = 'MarketPlace';
                $butikk['account'] = \AOrder\Gateways\Adyen\Card::sub_merchant_account();
            }
            $splits = [$butikk, ['amount' => ['value' => $provisjon], 'type' => 'Commission', 'reference' => "/ECOM-comm-split/$ref"]];
        }
    }
    return apply_filters('amendo_kortbetaling_splits', $splits, $ore, $ref);
}

/**
 * Tolker resultCode fra /payments og /payments/details, oppdaterer ordren og
 * svarer kassen: { resultCode, action?, pspReference?, refusalReason? }.
 */
function amendo_kortbetaling_resultat(WC_Order $ordre, array $svar, int $ore) {
    $kode = (string) $svar['resultCode'];
    $psp  = isset($svar['pspReference']) && is_string($svar['pspReference']) ? $svar['pspReference'] : '';
    $ut   = ['resultCode' => $kode];
    if ($psp !== '') $ut['pspReference'] = $psp;

    switch ($kode) {
        case 'Authorised':
            // Beløpet Adyen autoriserte, når svaret har det, skal være ordrens.
            $autorisert = $svar['amount']['value'] ?? null;
            if ($autorisert !== null && (int) $autorisert !== $ore) {
                amendo_betaling_logg('kortbetaling: Adyen autoriserte ' . $autorisert . " øre, ordre {$ordre->get_id()} er $ore — IKKE markert betalt");
                $ordre->add_order_note("Adyen: autorisert beløp ($autorisert øre) avviker fra ordren ($ore øre). Kontroller i Adyen før ordren behandles.");
                $ordre->save();
                break;
            }
            if ($ordre->needs_payment()) {
                $ordre->update_meta_data('_adyen_pspReference', $psp);
                $ordre->add_order_note('Adyen (kort, headless-kasse): autorisert ' . $psp);
                $ordre->payment_complete($psp);
            }
            break;

        case 'RedirectShopper':
        case 'ChallengeShopper':
        case 'IdentifyShopper':
            if (isset($svar['action']) && is_array($svar['action'])) $ut['action'] = $svar['action'];
            break;

        case 'Refused':
        case 'Error':
        case 'Cancelled':
            if (!empty($svar['refusalReason']) && is_string($svar['refusalReason'])) $ut['refusalReason'] = $svar['refusalReason'];
            $ordre->add_order_note('Adyen (kort, headless-kasse): ' . $kode . ($psp ? " $psp" : '') . (!empty($ut['refusalReason']) ? ' — ' . $ut['refusalReason'] : ''));
            $ordre->save();
            break;

        // Pending/Received: banken er ikke ferdig. Webhooken avgjør.
        default:
            $ordre->add_order_note('Adyen (kort, headless-kasse): ' . $kode . ($psp ? " $psp" : '') . ' — venter på Adyen.');
            $ordre->save();
    }
    return new WP_REST_Response($ut, 200);
}
