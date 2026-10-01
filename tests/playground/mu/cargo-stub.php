<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
if (!defined('ABSPATH')) exit;
// Stub av cargonizer-connect 2.0.6 (signaturene fra oppgaven). Kun for Playground.
// Styres med options: stub_lcfwc_hook (registrer metoden på kroken),
// stub_lcfwc_metode_skriver (metoden skriver data), stub_lcfwc_funksjon (fallback finnes).

// stub_lcfwc_modus: 'tidlig' (ved lasting), 'sen' (plugins_loaded prioritet 20,
// ETTER amendo-settings — slik cargonizer-connect gjør på emmk), 'av' (ingenting).
$GLOBALS['stub_modus'] = get_option('stub_lcfwc_modus', 'tidlig');

function stub_lcfwc_definer() {
    class LCFWC_Checkout {
        public function initialize_checkout_hooks() {
            if (get_option('stub_lcfwc_hook', '1') === '1') {
                add_action('woocommerce_checkout_update_order_meta', [$this, 'save_pickup_point_data_to_order']);
                add_action('kco_wc_process_payment', [$this, 'save_pickup_point_data_to_order']);
            }
        }
        public function save_pickup_point_data_to_order($order_id) {
            update_option('stub_lcfwc_metode_kall', (int) get_option('stub_lcfwc_metode_kall', 0) + 1);
            if (get_option('stub_lcfwc_metode_kaster') === '1') throw new RuntimeException('stub-krasj');
            if (get_option('stub_lcfwc_metode_skriver', '1') !== '1') return;
            $order = wc_get_order($order_id);
            foreach ($order->get_items('shipping') as $l) {
                $s = get_option('woocommerce_' . $l->get_method_id() . '_' . $l->get_instance_id() . '_settings');
                if (empty($s['lcfwc_service'])) continue;
                [$avtale, $tjeneste] = explode(':', $s['lcfwc_service']);
                $order->update_meta_data('_lcfwc_carrier_name', 'Bring');
                $order->update_meta_data('_lcfwc_carrier_id', 'bring2');
                $order->update_meta_data('_lcfwc_service', $s['lcfwc_service']);
                $order->update_meta_data('_lcfwc_transport_agreement_id', $avtale);
                $order->update_meta_data('_lcfwc_service_name', 'Pakke til hentested');
                $order->update_meta_data('_lcfwc_order_total_weight', '9.9');
                $p = json_decode((string) $l->get_meta('krokedil_selected_pickup_point'), true);
                if ($p) {
                    $order->update_meta_data('_lcfwc_pickup_point_id', $p['id']);
                    $order->update_meta_data('_lcfwc_pickup_point_data', ['number' => $p['id'], 'name' => $p['name']]);
                }
            }
            $order->save();
        }
    }

    if (get_option('stub_lcfwc_funksjon', '1') === '1') {
        function lcfwc_save_cargonizer_shipment_data_to_order($order, $service, $pickup_point_id, $pickup_point_data) {
            update_option('stub_lcfwc_fallback_args', ['order' => $order instanceof WC_Order ? $order->get_id() : null, 'service' => $service, 'pickup_point_id' => $pickup_point_id, 'pickup_point_data' => $pickup_point_data]);
            $order->update_meta_data('_lcfwc_service', $service);
            $order->update_meta_data('_lcfwc_service_name', 'Fallback-tjeneste');
            if ($pickup_point_id) {
                $p = $pickup_point_data['pickup_points_' . $service][0];
                $order->update_meta_data('_lcfwc_pickup_point_id', $pickup_point_id);
                $order->update_meta_data('_lcfwc_pickup_point_data', $p);
            }
            $order->save();
        }
    }
}

if ($GLOBALS['stub_modus'] === 'tidlig') stub_lcfwc_definer();
if ($GLOBALS['stub_modus'] === 'sen') add_action('plugins_loaded', 'stub_lcfwc_definer', 20);
// Bevis på at testen er realistisk: klassen finnes ikke på plugins_loaded 10.
add_action('plugins_loaded', function() { $GLOBALS['stub_klasse_paa_pl10'] = class_exists('LCFWC_Checkout', false); }, 10);
add_action('init', function() { if (class_exists('LCFWC_Checkout', false)) (new LCFWC_Checkout())->initialize_checkout_hooks(); });

// En annen plugin på samme kasse-krok: skal ALDRI kjøres av oss.
add_action('woocommerce_checkout_update_order_meta', function() {
    update_option('stub_annen_plugin_kall', (int) get_option('stub_annen_plugin_kall', 0) + 1);
});

add_action('rest_api_init', function() {
    register_rest_route('amendo-test/v1', '/cargo-modus', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function() {
        return [
            'modus' => $GLOBALS['stub_modus'],
            'klasse_paa_plugins_loaded_10' => $GLOBALS['stub_klasse_paa_pl10'] ?? null,
            'klasse_naa' => class_exists('LCFWC_Checkout', false),
            'funksjon_naa' => function_exists('lcfwc_save_cargonizer_shipment_data_to_order'),
            'krok_registrert' => has_action('woocommerce_rest_insert_shop_order_object', 'amendo_cargonizer_rest_ordre') === 20,
        ];
    }]);
    // Uten Cargonizer i det hele tatt (modus 'av').
    register_rest_route('amendo-test/v1', '/cargo-av', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function() {
        $ids = get_option('amendo_test_ids');
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        $logg = [];
        add_filter('woocommerce_logger_log_message', function($m, $level, $ctx, $handler = null) use (&$logg) {
            static $forste = null;
            $h = is_object($handler) ? get_class($handler) : (string) $handler;
            if ($forste === null) $forste = $h;
            if ($h === $forste && ($ctx['source'] ?? '') === 'amendo-cargonizer') $logg[] = $m;
            return $m;
        }, 10, 4);
        $lag = function($frakt) use ($ids) {
            $req = new WP_REST_Request('POST', '/wc/v3/orders');
            $req->set_body_params(['line_items' => [['product_id' => $ids['brod'], 'quantity' => 1]], 'shipping_lines' => [$frakt]]);
            return wc_get_order(rest_do_request($req)->get_data()['id'] ?? 0);
        };
        $o1 = $lag(['method_id' => 'local_pickup', 'instance_id' => (string) $ids['lp'], 'method_title' => 'Hent', 'total' => '0']);
        $ut = ['K_uten_tjeneste_ingen_logg' => $o1 && $logg === []];
        $o2 = $lag(['method_id' => 'flat_rate', 'instance_id' => (string) $ids['fr'], 'method_title' => 'Hjemlevering', 'total' => '99']);
        $ut['K_med_tjeneste_en_logglinje'] = count($logg) === 1 && strpos($logg[0], 'Fant verken') !== false;
        $ut['K_ordre_urort'] = $o2 && $o2->get_meta('_lcfwc_service') === '';
        $ut['K_logg'] = $logg;
        return $ut;
    }]);
    register_rest_route('amendo-test/v1', '/cargo', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'amendo_test_cargo']);
    // Kjøres med stub_lcfwc_hook=0 og stub_lcfwc_funksjon=0 satt i FORRIGE forespørsel.
    register_rest_route('amendo-test/v1', '/cargo-ingen', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function() {
        $ids = get_option('amendo_test_ids');
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        $logg = [];
        add_filter('woocommerce_logger_log_message', function($m, $level, $ctx, $handler = null) use (&$logg) {
            // Kjøres én gang per logg-handler; tell bare første handler.
            static $forste = null;
            $h = is_object($handler) ? get_class($handler) : (string) $handler;
            if ($forste === null) $forste = $h;
            if ($h === $forste && ($ctx['source'] ?? '') === 'amendo-cargonizer') $logg[] = $m;
            return $m;
        }, 10, 4);
        $req = new WP_REST_Request('POST', '/wc/v3/orders');
        $req->set_body_params(['line_items' => [['product_id' => $ids['brod'], 'quantity' => 1]], 'shipping_lines' => [['method_id' => 'flat_rate', 'instance_id' => (string) $ids['fr'], 'method_title' => 'Hjemlevering', 'total' => '99']]]);
        $o = wc_get_order(rest_do_request($req)->get_data()['id'] ?? 0);
        $notater = array_map(fn($n) => $n->content, wc_get_order_notes(['order_id' => $o->get_id()]));
        return [
            'I_forutsetning_funksjon_mangler' => !function_exists('lcfwc_save_cargonizer_shipment_data_to_order'),
            'I_ordre_opprettet' => (bool) $o,
            'I_ingen_lcfwc_meta' => $o && !array_filter($o->get_meta_data(), fn($m) => strpos($m->key, '_lcfwc_') === 0),
            'I_ingen_notat' => !array_filter($notater, fn($n) => strpos($n, 'Cargonizer') !== false),
            'I_en_logglinje' => count($logg) === 1 && strpos($logg[0], 'Fant verken') !== false,
            'I_logg' => $logg,
        ];
    }]);
});

function amendo_test_cargo() {
    $ids = get_option('amendo_test_ids') ?: amendo_test_setup();
    wp_set_current_user(get_user_by('login', 'admin')->ID);
    $logg = [];
    add_filter('woocommerce_logger_log_message', function($m, $level, $ctx, $handler = null) use (&$logg) {
            // Kjøres én gang per logg-handler; tell bare første handler.
            static $forste = null;
            $h = is_object($handler) ? get_class($handler) : (string) $handler;
            if ($forste === null) $forste = $h;
            if ($h === $forste && ($ctx['source'] ?? '') === 'amendo-cargonizer') $logg[] = $m;
            return $m;
        }, 10, 4);

    // Instans med Cargonizer-tjeneste (fr) og uten (lp).
    $s = get_option("woocommerce_flat_rate_{$ids['fr']}_settings");
    $s['lcfwc_service'] = '34807:bring2_parcel_pickup_point';
    update_option("woocommerce_flat_rate_{$ids['fr']}_settings", $s);
    update_option('woocommerce_weight_unit', 'g');
    $brod = wc_get_product($ids['brod']); $brod->set_weight('500'); $brod->save();

    $punkt = wp_json_encode(['id' => '123456', 'name' => 'Coop Mega Storo', 'address' => ['street' => 'Vitaminveien 7', 'postcode' => '0485', 'city' => 'Oslo', 'country' => 'NO']]);
    $lag = function($frakt, $ekstra = []) use ($ids) {
        $req = new WP_REST_Request('POST', '/wc/v3/orders');
        $req->set_body_params(array_merge([
            'status' => 'processing',
            'line_items' => [['product_id' => $ids['brod'], 'quantity' => 3]],
            'shipping_lines' => [$frakt],
        ], $ekstra));
        $res = rest_do_request($req);
        return wc_get_order($res->get_data()['id'] ?? 0);
    };
    $nullstill = function($opts) use (&$logg) {
        foreach (['stub_lcfwc_metode_kall', 'stub_annen_plugin_kall', 'stub_lcfwc_fallback_args'] as $k) delete_option($k);
        foreach ($opts as $k => $v) update_option($k, $v);
        $logg = [];
    };
    $notater = fn($o) => array_map(fn($n) => $n->content, wc_get_order_notes(['order_id' => $o->get_id()]));
    $med_punkt = ['method_id' => 'flat_rate', 'instance_id' => (string) $ids['fr'], 'method_title' => 'Hjemlevering', 'total' => '99', 'meta_data' => [['key' => 'krokedil_selected_pickup_point', 'value' => $punkt]]];
    $ut = [];

    // Hooken er registrert på init; stubben leser optionen der. Denne
    // forespørselens init er allerede kjørt med standardverdiene (hook på).
    $nullstill(['stub_lcfwc_metode_skriver' => '1']);
    $o = $lag($med_punkt);
    $ut['A_plugin_metode_kalt_en_gang'] = (int) get_option('stub_lcfwc_metode_kall') === 1;
    $ut['A_annen_plugin_ikke_kalt'] = (int) get_option('stub_annen_plugin_kall', 0) === 0;
    $ut['A_service'] = $o->get_meta('_lcfwc_service') === '34807:bring2_parcel_pickup_point';
    $ut['A_hentested'] = $o->get_meta('_lcfwc_pickup_point_id') === '123456';
    $ut['A_vekt_fra_plugin_urort'] = $o->get_meta('_lcfwc_order_total_weight') === '9.9';
    $ut['A_fallback_ikke_brukt'] = get_option('stub_lcfwc_fallback_args') === false;
    $ut['A_notat'] = in_array('Cargonizer-data satt fra REST: Pakke til hentested, hentested Coop Mega Storo', $notater($o), true);
    $ut['A_ingen_logg'] = $logg === [];

    // Metoden finnes men skriver ingenting → fallback med krokedil-data og vekt.
    $nullstill(['stub_lcfwc_metode_skriver' => '0']);
    $o = $lag($med_punkt);
    $a = get_option('stub_lcfwc_fallback_args');
    $ut['B_metode_forsokt'] = (int) get_option('stub_lcfwc_metode_kall') === 1;
    $ut['B_fallback_args'] = is_array($a) && $a['order'] === $o->get_id() && $a['service'] === '34807:bring2_parcel_pickup_point' && $a['pickup_point_id'] === '123456'
        && $a['pickup_point_data'] === ['pickup_points_34807:bring2_parcel_pickup_point' => [['number' => '123456', 'name' => 'Coop Mega Storo', 'address1' => 'Vitaminveien 7', 'postcode' => '0485', 'city' => 'Oslo', 'country' => 'NO']]];
    $ut['B_fallback_args_raa'] = $a;
    $ut['B_vekt_3x500g_1_5kg'] = (string) $o->get_meta('_lcfwc_order_total_weight') === '1.5';
    $ut['B_vekt_raa'] = $o->get_meta('_lcfwc_order_total_weight');

    // Uavrundet: 3 × 333,3 g = 0,9999 kg.
    $nullstill(['stub_lcfwc_metode_skriver' => '0']);
    $brod = wc_get_product($ids['brod']); $brod->set_weight('333.3'); $brod->save();
    $o = $lag($med_punkt);
    $ut['B2_uavrundet_0_9999'] = abs((float) $o->get_meta('_lcfwc_order_total_weight') - 0.9999) < 1e-9;
    $ut['B2_vekt_raa'] = $o->get_meta('_lcfwc_order_total_weight');
    $brod->set_weight('500'); $brod->save();

    // Produkt uten vekt → "0".
    $nullstill(['stub_lcfwc_metode_skriver' => '0']);
    $req = new WP_REST_Request('POST', '/wc/v3/orders');
    $req->set_body_params(['line_items' => [['product_id' => $ids['utsolgt'], 'quantity' => 2]], 'shipping_lines' => [$med_punkt]]);
    $o = wc_get_order(rest_do_request($req)->get_data()['id'] ?? 0);
    $ut['J_uten_vekt_0'] = $o && (string) $o->get_meta('_lcfwc_order_total_weight') === '0';
    $ut['J_vekt_raa'] = $o ? $o->get_meta('_lcfwc_order_total_weight') : null;
    $ut['B_notat'] = in_array('Cargonizer-data satt fra REST: Fallback-tjeneste, hentested Coop Mega Storo', $notater($o), true);

    // Uten hentested.
    $nullstill(['stub_lcfwc_metode_skriver' => '0']);
    $uten_punkt = $med_punkt; $uten_punkt['meta_data'] = [];
    $o = $lag($uten_punkt);
    $a = get_option('stub_lcfwc_fallback_args');
    $ut['C_uten_hentested_args'] = is_array($a) && $a['pickup_point_id'] === '' && $a['pickup_point_data'] === [];
    $ut['C_notat_uten_hentested'] = in_array('Cargonizer-data satt fra REST: Fallback-tjeneste', $notater($o), true);

    // Instans uten lcfwc_service → ingenting.
    $nullstill(['stub_lcfwc_metode_skriver' => '1']);
    $o = $lag(['method_id' => 'local_pickup', 'instance_id' => (string) $ids['lp'], 'method_title' => 'Hent', 'total' => '0']);
    $ut['D_uten_tjeneste_urort'] = get_option('stub_lcfwc_metode_kall') === false && $o->get_meta('_lcfwc_service') === '' && $logg === [];

    // Uten instance_id → ingenting.
    $nullstill(['stub_lcfwc_metode_skriver' => '1']);
    $o = $lag(['method_id' => 'flat_rate', 'method_title' => 'Hjemlevering', 'total' => '99']);
    $ut['E_uten_instance_id_urort'] = get_option('stub_lcfwc_metode_kall') === false && $o->get_meta('_lcfwc_service') === '';

    // _lcfwc_service satt fra før → ikke rørt.
    $nullstill(['stub_lcfwc_metode_skriver' => '1']);
    $o = $lag($med_punkt, ['meta_data' => [['key' => '_lcfwc_service', 'value' => 'fra-frontend']]]);
    $ut['F_eksisterende_service_urort'] = get_option('stub_lcfwc_metode_kall') === false && $o->get_meta('_lcfwc_service') === 'fra-frontend';

    // Oppdatering (ikke opprettelse) → ikke rørt.
    $nullstill(['stub_lcfwc_metode_skriver' => '0']);
    $o = $lag(['method_id' => 'local_pickup', 'instance_id' => (string) $ids['lp'], 'method_title' => 'Hent', 'total' => '0']);
    update_option('stub_lcfwc_metode_skriver', '1');
    delete_option('stub_lcfwc_metode_kall');
    $req = new WP_REST_Request('PUT', '/wc/v3/orders/' . $o->get_id());
    $req->set_body_params(['shipping_lines' => [$med_punkt]]);
    rest_do_request($req);
    $ut['G_oppdatering_urort'] = get_option('stub_lcfwc_metode_kall') === false && wc_get_order($o->get_id())->get_meta('_lcfwc_service') === '';

    // Metoden kaster → fallback brukes, og feilen logges.
    $nullstill(['stub_lcfwc_metode_skriver' => '1', 'stub_lcfwc_metode_kaster' => '1']);
    $o = $lag($med_punkt);
    delete_option('stub_lcfwc_metode_kaster');
    $ut['H_kaster_fallback_brukt'] = $o->get_meta('_lcfwc_service') === '34807:bring2_parcel_pickup_point' && $o->get_meta('_lcfwc_service_name') === 'Fallback-tjeneste';
    $ut['H_kaster_logget_en_linje'] = count($logg) === 1 && strpos($logg[0], 'stub-krasj') !== false;
    $ut['H_logg'] = $logg;

    return $ut + ['logg' => $logg];
}
