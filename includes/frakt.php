<?php
/**
 * POST /amendo-settings/v1/frakt — fraktalternativer beregnet av WooCommerce.
 *
 * Headless-kassene viste tidligere rå soneinnstillinger: frakt uten MVA og
 * gratis frakt som kunne velges uansett beløp. Her bygger vi en midlertidig
 * handlekurv i minnet og lar WooCommerce selv regne ut frakten, slik at MVA,
 * gratis-frakt-grenser, fraktklasser og fraktplugins blir riktige.
 *
 * ⚠⚠ INGENTING SKAL LAGRES. Endepunktet er offentlig, og en kurv som lekker ut
 * av minnet vil tømme eller overskrive ekte kunders kurver. Derfor, i
 * amendo_frakt_beregn():
 *
 * - WC()->session byttes ut med Amendo_Frakt_Minnesesjon (bare minne, ingen
 *   cookie, ingen rad i woocommerce_sessions). En eventuell sesjon, kunde og
 *   kurv som allerede finnes i forespørselen legges til side og settes tilbake
 *   etterpå, urørt.
 * - Alle lyttere på kurvhendelsene i AMENDO_FRAKT_STILLE_KROKER tas av mens
 *   beregningen pågår og settes tilbake etterpå, også ved feil. Ellers sender
 *   sporingsplugins (facebook-for-woocommerce/Conversions API, Mailchimp,
 *   GTM4WP) en falsk AddToCart for hver fraktberegning. Filtre som
 *   woocommerce_add_cart_item_data blir stående: de kan endre pris og vekt,
 *   og dermed frakten.
 * - Beregningen kjøres som gjest (bruker 0), så ingen persistent kurv i
 *   usermeta leses, skrives eller slettes, selv om kallet er autentisert.
 * - Den midlertidige kurven får ingen kroker (filteret
 *   `woocommerce_cart_session_initialize`, WooCommerce 6.9+): ingen cookies,
 *   ingen persistent kurv, ingen lagring ved shutdown. I tillegg er
 *   wc_setcookie og persistent kurv slått av mens beregningen pågår.
 *
 * Rate limit: 30 kall/min per REMOTE_ADDR (filter `amendo_frakt_rate_limit`).
 * Kassene kaller fra Vercel, der alle kunder har samme IP. De sender derfor
 * X-Amendo-Kasse-Secret, som gir unntak — men BARE når `amendo_kasse_secret`
 * er satt. Tom secret gir aldri unntak (ikke «tillat midlertidig» som i de
 * andre rutene). X-Forwarded-For brukes ikke; den kan settes av hvem som helst.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_FRAKT_MAKS_LINJER = 100;
const AMENDO_FRAKT_MAKS_ANTALL  = 9999;

/** Kurvhendelser som ikke skal nå andre plugins mens den midlertidige kurven fylles. */
const AMENDO_FRAKT_STILLE_KROKER = [
    'woocommerce_add_to_cart',
    'woocommerce_cart_updated',
    'woocommerce_cart_item_removed',
    'woocommerce_cart_emptied',
    'woocommerce_after_cart_item_quantity_update',
];

/** Metoder som ikke er hjemlevering, og som derfor aldri skjules. */
const AMENDO_FRAKT_HENTING = ['local_pickup', 'pickup_location'];

add_action('rest_api_init', function() {
    register_rest_route('amendo-settings/v1', '/frakt', [
        'methods'             => 'POST',
        'callback'            => 'amendo_frakt_rest',
        'permission_callback' => '__return_true',
    ]);
});

function amendo_frakt_rest(WP_REST_Request $request) {
    $grense = amendo_frakt_rate_limit($request);
    if ($grense['sperret']) {
        $svar = new WP_REST_Response([
            'code'    => 'amendo_frakt_rate_limit',
            'message' => 'For mange forespørsler. Prøv igjen om litt.',
        ], 429);
        $svar->header('Retry-After', (string) $grense['vent']);
        return $svar;
    }

    if (!function_exists('WC') || !function_exists('wc_load_cart')) {
        return new WP_REST_Response(['code' => 'amendo_frakt_uten_wc', 'message' => 'WooCommerce er ikke aktivert.'], 503);
    }

    $inn = amendo_frakt_valider($request->get_json_params());
    if (is_wp_error($inn)) {
        return new WP_REST_Response(['code' => $inn->get_error_code(), 'message' => $inn->get_error_message()], 400);
    }

    $svar = new WP_REST_Response(amendo_frakt_beregn($inn), 200);
    if ($grense['gjenstaar'] !== null) {
        $svar->header('X-RateLimit-Remaining', (string) $grense['gjenstaar']);
    }
    return $svar;
}

/**
 * @return array{sperret: bool, vent: int, gjenstaar: ?int} `gjenstaar` er null
 *         når kallet er unntatt (gyldig X-Amendo-Kasse-Secret).
 */
function amendo_frakt_rate_limit(WP_REST_Request $request) {
    $secret = (string) get_option('amendo_kasse_secret', '');
    $header = (string) $request->get_header('X-Amendo-Kasse-Secret');
    if ($secret !== '' && $header !== '' && hash_equals($secret, $header)) {
        return ['sperret' => false, 'vent' => 0, 'gjenstaar' => null];
    }

    $maks = max(1, (int) apply_filters('amendo_frakt_rate_limit', 30));
    $ip   = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $nokkel = 'amendo_frakt_rl_' . md5($ip);
    $naa    = time();

    // Fast vindu på 60 sekunder fra første kall.
    $teller = get_transient($nokkel);
    if (!is_array($teller) || ($naa - (int) $teller['start']) >= MINUTE_IN_SECONDS) {
        $teller = ['start' => $naa, 'antall' => 0];
    }
    if ($teller['antall'] >= $maks) {
        return ['sperret' => true, 'vent' => max(1, MINUTE_IN_SECONDS - ($naa - $teller['start'])), 'gjenstaar' => 0];
    }
    $teller['antall']++;
    set_transient($nokkel, $teller, MINUTE_IN_SECONDS);
    return ['sperret' => false, 'vent' => 0, 'gjenstaar' => $maks - $teller['antall']];
}

/**
 * @return array|WP_Error { varer: [{product_id, variation_id, antall}], land, postnummer, sted }
 */
function amendo_frakt_valider($body) {
    if (!is_array($body)) {
        return new WP_Error('amendo_frakt_ugyldig', 'Forventet JSON-body.');
    }

    $varer = $body['varer'] ?? null;
    if (!is_array($varer) || !$varer || array_values($varer) !== $varer) {
        return new WP_Error('amendo_frakt_ugyldig', '«varer» må være en ikke-tom liste.');
    }
    if (count($varer) > AMENDO_FRAKT_MAKS_LINJER) {
        return new WP_Error('amendo_frakt_ugyldig', '«varer» kan ha maks ' . AMENDO_FRAKT_MAKS_LINJER . ' linjer.');
    }

    $rene = [];
    foreach ($varer as $i => $vare) {
        $produkt  = is_array($vare) ? amendo_frakt_heltall($vare['product_id'] ?? null) : null;
        $variant  = is_array($vare) && isset($vare['variation_id']) ? amendo_frakt_heltall($vare['variation_id']) : 0;
        $antall   = is_array($vare) ? amendo_frakt_heltall($vare['antall'] ?? null) : null;
        if (!$produkt || $produkt < 1 || $variant === null || $variant < 0
            || !$antall || $antall < 1 || $antall > AMENDO_FRAKT_MAKS_ANTALL) {
            return new WP_Error('amendo_frakt_ugyldig', "Ugyldig linje varer[$i]: product_id og antall (1–" . AMENDO_FRAKT_MAKS_ANTALL . ') må være positive heltall.');
        }
        $rene[] = ['product_id' => $produkt, 'variation_id' => $variant, 'antall' => $antall];
    }

    $land = strtoupper(is_string($body['land'] ?? null) ? trim($body['land']) : '');
    if (!array_key_exists($land, WC()->countries->get_countries())) {
        return new WP_Error('amendo_frakt_ugyldig', '«land» må være en landkode, f.eks. «NO».');
    }

    $postnummer = $body['postnummer'] ?? null;
    if (!is_string($postnummer) && !is_int($postnummer)) {
        return new WP_Error('amendo_frakt_ugyldig', '«postnummer» mangler.');
    }
    $postnummer = wc_format_postcode(sanitize_text_field((string) $postnummer), $land);
    if (strlen($postnummer) > 20) {
        return new WP_Error('amendo_frakt_ugyldig', '«postnummer» er for langt.');
    }

    $sted = $body['sted'] ?? '';
    $sted = is_string($sted) ? mb_substr(sanitize_text_field($sted), 0, 100) : '';

    return ['varer' => $rene, 'land' => $land, 'postnummer' => $postnummer, 'sted' => $sted];
}

/** Heltall fra JSON (5 eller "5"), ellers null. */
function amendo_frakt_heltall($verdi) {
    if (is_int($verdi)) return $verdi;
    if (is_string($verdi) && preg_match('/^\d{1,9}$/', $verdi)) return (int) $verdi;
    return null;
}

/**
 * Bygger kurven i minnet, lar WooCommerce regne, og rydder opp. Se toppen av
 * filen for hvorfor hvert steg er her.
 */
function amendo_frakt_beregn(array $inn) {
    global $wp_filter;
    amendo_frakt_definer_minnesesjon();

    $wc = WC();
    $lagret = [
        'session'  => $wc->session,
        'customer' => $wc->customer,
        'cart'     => $wc->cart,
        'bruker'   => get_current_user_id(),
    ];
    $sesjonsklasse = function() { return 'Amendo_Frakt_Minnesesjon'; };
    $av = '__return_false';

    $wc->session = $wc->customer = $wc->cart = null;
    wp_set_current_user(0);
    add_filter('woocommerce_session_handler', $sesjonsklasse, PHP_INT_MAX);
    add_filter('woocommerce_set_cookie_enabled', $av, PHP_INT_MAX);
    add_filter('woocommerce_persistent_cart_enabled', $av, PHP_INT_MAX);

    $stille = [];
    foreach (AMENDO_FRAKT_STILLE_KROKER as $krok) {
        if (isset($wp_filter[$krok])) {
            $stille[$krok] = $wp_filter[$krok];
            unset($wp_filter[$krok]);
        }
    }

    try {
        add_filter('woocommerce_cart_session_initialize', $av, PHP_INT_MAX);
        wc_load_cart();
        remove_filter('woocommerce_cart_session_initialize', $av, PHP_INT_MAX);
        remove_filter('woocommerce_session_handler', $sesjonsklasse, PHP_INT_MAX);
        // Kunden lagres ellers ved shutdown — da er den ekte sesjonen satt tilbake.
        remove_action('shutdown', [$wc->customer, 'save'], 10);

        $kunde = $wc->customer;
        $kunde->set_location($inn['land'], '', $inn['postnummer'], $inn['sted']);
        $kunde->set_shipping_location($inn['land'], '', $inn['postnummer'], $inn['sted']);
        $kunde->set_is_vat_exempt(false);
        $kunde->set_calculated_shipping(true);

        $avviste = [];
        foreach ($inn['varer'] as $i => $vare) {
            $grunn = amendo_frakt_legg_til($vare);
            if ($grunn !== null) {
                $avviste[] = ['indeks' => $i, 'product_id' => $vare['product_id'], 'variation_id' => $vare['variation_id'], 'grunn' => $grunn];
            }
        }
        wc_clear_notices();

        $wc->cart->calculate_totals();
        $pakker = $wc->shipping()->get_packages();
        $pakke  = $pakker ? reset($pakker) : null;

        $alternativer = $pakke ? amendo_frakt_alternativer($pakke['rates'] ?? []) : [];
        $gratis = $pakke ? amendo_frakt_gratisgrense($pakke) : ['grense' => null, 'gjenstaar' => null];

        return [
            'valuta'               => get_woocommerce_currency(),
            'alternativer'         => $alternativer,
            'gratis_frakt_grense'  => $gratis['grense'],
            'gjenstaar_til_gratis' => $gratis['gjenstaar'],
            'avviste_varer'        => $avviste,
        ];
    } finally {
        // Nøyaktig de samme lytterne tilbake; noe som ble lagt til underveis
        // hørte til den midlertidige kurven.
        foreach (AMENDO_FRAKT_STILLE_KROKER as $krok) {
            unset($wp_filter[$krok]);
            if (isset($stille[$krok])) $wp_filter[$krok] = $stille[$krok];
        }
        remove_filter('woocommerce_cart_session_initialize', $av, PHP_INT_MAX);
        remove_filter('woocommerce_session_handler', $sesjonsklasse, PHP_INT_MAX);
        remove_filter('woocommerce_set_cookie_enabled', $av, PHP_INT_MAX);
        remove_filter('woocommerce_persistent_cart_enabled', $av, PHP_INT_MAX);
        // Fraktberegningen husker pakkene sine; de hører til kurven vi kaster.
        $wc->shipping()->reset_shipping();
        $wc->session  = $lagret['session'];
        $wc->customer = $lagret['customer'];
        $wc->cart     = $lagret['cart'];
        wp_set_current_user($lagret['bruker']);
    }
}

/** Legger én linje i kurven. Returnerer null, eller en kort grunn. */
function amendo_frakt_legg_til(array $vare) {
    $id = $vare['variation_id'] ?: $vare['product_id'];
    $produkt = wc_get_product($id);
    // Varianter som er slått av har status «private».
    if (!$produkt || $produkt->get_status() !== 'publish') {
        return 'finnes_ikke';
    }
    if ($vare['variation_id'] && (!$produkt->is_type('variation') || $produkt->get_parent_id() !== $vare['product_id'])) {
        return 'finnes_ikke';
    }
    if ($produkt->is_type('variation') && get_post_status($produkt->get_parent_id()) !== 'publish') {
        return 'finnes_ikke';
    }
    if ($produkt->is_type('variable')) {
        return 'mangler_variant';
    }

    $egenskaper = [];
    if ($produkt->is_type('variation')) {
        $egenskaper = amendo_frakt_variantegenskaper($produkt);
    }

    try {
        $nokkel = WC()->cart->add_to_cart(
            $produkt->is_type('variation') ? $produkt->get_parent_id() : $produkt->get_id(),
            $vare['antall'],
            $produkt->is_type('variation') ? $produkt->get_id() : 0,
            $egenskaper
        );
    } catch (Exception $e) {
        $nokkel = false;
    }
    if ($nokkel) return null;
    if (!$produkt->is_purchasable()) return 'kan_ikke_kjoepes';
    if (!$produkt->is_in_stock() || !$produkt->has_enough_stock($vare['antall'])) return 'ikke_paa_lager';
    return 'avvist';
}

/**
 * Egenskapene en variant trenger for å legges i kurven. «Hvilken som helst»-
 * egenskaper (tom verdi) fylles med første gyldige valg — frakten avhenger av
 * varianten (vekt, fraktklasse), ikke av hvilket valg kunden gjorde.
 */
function amendo_frakt_variantegenskaper(WC_Product $variant) {
    $forelder = wc_get_product($variant->get_parent_id());
    $egenskaper = [];
    foreach (wc_get_product_variation_attributes($variant->get_id()) as $navn => $verdi) {
        if ($verdi === '' && $forelder) {
            $taks = substr($navn, strlen('attribute_'));
            $attr = $forelder->get_attributes()[$taks] ?? null;
            if ($attr) {
                $valg = $attr->is_taxonomy() ? $attr->get_slugs() : $attr->get_options();
                $verdi = (string) reset($valg);
            }
        }
        $egenskaper[$navn] = $verdi;
    }
    return $egenskaper;
}

/** @param WC_Shipping_Rate[] $rater */
function amendo_frakt_alternativer(array $rater) {
    $desimaler = wc_get_price_decimals();

    $har_gratis = false;
    foreach ($rater as $rate) {
        if ($rate->get_method_id() === 'free_shipping') $har_gratis = true;
    }
    $skjul_betalt = $har_gratis && get_option('amendo_frakt_skjul_betalt', '1') === '1';

    // Kjerne-metodene legger inn «Varer: Brød × 2» — produktdata frontend
    // allerede har, og som et offentlig endepunkt ikke skal eksponere.
    $varer_etikett = [__('Items', 'woocommerce'), 'Items'];

    $ut = [];
    foreach ($rater as $rate) {
        $metode = $rate->get_method_id();
        if ($skjul_betalt && $metode !== 'free_shipping' && !in_array($metode, AMENDO_FRAKT_HENTING, true)) {
            continue;
        }

        $eks = (float) $rate->get_cost();
        $mva = (float) array_sum(array_map('floatval', $rate->get_taxes()));
        $meta = [];
        foreach ($rate->get_meta_data() as $k => $v) {
            if (in_array($k, $varer_etikett, true) || !is_scalar($v)) continue;
            $meta[(string) $k] = (string) $v;
        }

        $ut[] = [
            // For kjernemetodene er dette method_id:instance_id. Plugins som gir
            // flere rater per instans har egne id-er, og kassen trenger dem
            // uendret for å velge riktig rate på ordren.
            'id'            => $rate->get_id(),
            'metode'        => $metode,
            'navn'          => $rate->get_label(),
            'pris_eks_mva'  => round($eks, $desimaler),
            'mva'           => round($mva, $desimaler),
            'pris_inkl_mva' => round($eks + $mva, $desimaler),
            'meta'          => (object) $meta,
        ];
    }
    return $ut;
}

/**
 * Laveste beløpsgrense for gratis frakt i sonen pakken havner i, og hvor mye
 * som gjenstår. Summen regnes som WC_Shipping_Free_Shipping gjør. Metoder som
 * krever kupong («coupon», «both») kan ikke oppnås med beløp alene og telles ikke.
 */
function amendo_frakt_gratisgrense(array $pakke) {
    $sone = WC_Shipping_Zones::get_zone_matching_package($pakke);
    $grense = null;
    $ignorer_rabatt = 'no';
    foreach ($sone->get_shipping_methods(true) as $metode) {
        if ($metode->id !== 'free_shipping') continue;
        if (!in_array($metode->requires, ['min_amount', 'either'], true)) continue;
        $belop = (float) wc_format_decimal($metode->min_amount);
        if ($grense === null || $belop < $grense) {
            $grense = $belop;
            $ignorer_rabatt = $metode->ignore_discounts;
        }
    }
    if ($grense === null) return ['grense' => null, 'gjenstaar' => null];

    $kurv = WC()->cart;
    $sum = $kurv->get_displayed_subtotal();
    if ($kurv->display_prices_including_tax()) $sum -= $kurv->get_discount_tax();
    if ($ignorer_rabatt === 'no') $sum -= $kurv->get_discount_total();

    $desimaler = wc_get_price_decimals();
    return [
        'grense'    => round($grense, $desimaler),
        'gjenstaar' => round(max(0, $grense - round($sum, $desimaler)), $desimaler),
    ];
}

/**
 * WooCommerce-sesjon som bare lever i minnet: ingen cookie, ingen databaserad,
 * ingen shutdown-lagring. Deklareres først når WooCommerce er lastet.
 */
function amendo_frakt_definer_minnesesjon() {
    if (class_exists('Amendo_Frakt_Minnesesjon')) return;

    class Amendo_Frakt_Minnesesjon extends WC_Session {
        public function init() {
            $this->_customer_id = 't_' . substr(md5(wp_generate_password(32, false)), 0, 30);
            $this->_data = [];
        }
        public function has_session() { return false; }
        public function set_customer_session_cookie($set) {}
        public function get_session_cookie() { return false; }
        public function get_customer_unique_id() { return $this->_customer_id; }
        public function get_session_data() { return $this->_data; }
        public function get_session($customer_id, $default = false) { return $default; }
        public function save_data($old_session_key = 0) {}
        public function forget_session() { $this->_data = []; }
        public function destroy_session() { $this->_data = []; }
        public function update_session_timestamp($customer_id, $timestamp) {}
        public function delete_session($customer_id) {}
        public function cleanup_sessions() {}
    }
}
