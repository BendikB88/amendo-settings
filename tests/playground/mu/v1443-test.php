<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// 1.4.43, butikker uten frontend: suitene `faktura-kasse`, `velkomst-woo` og
// `orgnr-kasse`.
//
// Fakturagatewayen stubbes (bare når `amendo_test_faktura_stub` = '1', så de
// andre suitene ikke får en ny betalingsmåte) med SAMME is_available() som
// Gateway_Invoice i woocommerce-amendo-gateway 1.36.19: alle / alle innloggede
// / kundegruppen (`_tellixGroupId` → enabled_customer_group_<id>). Den sjekker
// ikke `enabled`, akkurat som den ekte.
if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', function() {
    if (get_option('amendo_test_faktura_stub') !== '1' || class_exists('AOrder\Gateways\Adyen\Gateway_Invoice') || !class_exists('WC_Payment_Gateway')) return;
    if (!class_exists('Amendo_Test_Faktura_Gateway')) {
        class Amendo_Test_Faktura_Gateway extends WC_Payment_Gateway {
            public function __construct() {
                $this->id = 'amendo-invoice';
                $this->method_title = 'Faktura (stubb)';
                $this->title = 'Faktura';
                $this->has_fields = false;
                $this->init_settings();
                $this->enabled = $this->get_option('enabled', 'no');
            }
            public function is_available() {
                if (!is_user_logged_in()) return $this->get_option('enabled_customer_all') == 'yes';
                if ($this->get_option('enabled_customer_logged_in') == 'yes') return true;
                $group_id = (int) get_user_meta(get_current_user_id(), '_tellixGroupId', true);
                return $this->get_option("enabled_customer_group_$group_id") == 'yes';
            }
            public function process_payment($order_id) {
                $o = wc_get_order($order_id);
                $o->update_status('on-hold', 'Faktura (stubb)');
                return ['result' => 'success', 'redirect' => 'https://takk.example/' . $order_id];
            }
        }
    }
    add_filter('woocommerce_payment_gateways', function($gw) { $gw[] = 'Amendo_Test_Faktura_Gateway'; return $gw; });
}, 5);

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };
    $som = function($epost) {
        $u = $epost ? get_user_by('email', (string) $epost) : null;
        wp_set_current_user($u ? $u->ID : 0);
        return $u ? $u->ID : 0;
    };
    // Betal-for-ordre-siden: is_checkout_pay_page() = is_checkout() + query var order-pay.
    $betalside = function($ordre_id) {
        global $wp;
        $wp->query_vars['order-pay'] = (int) $ordre_id;
        set_query_var('order-pay', (int) $ordre_id);
        add_filter('woocommerce_is_checkout', '__return_true');
    };
    $produkt = function() {
        $id = (int) get_option('amendo_test_v1443_produkt', 0);
        if ($id && wc_get_product($id)) return $id;
        $p = new WC_Product_Simple();
        $p->set_name('Testbolle v1443');
        $p->set_regular_price('50');
        $p->set_status('publish');
        $id = $p->save();
        update_option('amendo_test_v1443_produkt', $id);
        return $id;
    };

    /** { epost, betal (ordre-ID), } → ID-ene get_available_payment_gateways() gir, og rekkefølgen til alle. */
    $r('/v1443-gatewayer', function($req) use ($som, $betalside) {
        $p = $req->get_json_params() ?: [];
        $som($p['epost'] ?? null);
        if (!empty($p['betal'])) $betalside($p['betal']);
        if (function_exists('wc_load_cart')) wc_load_cart();
        $tilgj = WC()->payment_gateways()->get_available_payment_gateways();
        return ['tilgjengelige' => array_keys($tilgj), 'alle' => array_keys(WC()->payment_gateways()->payment_gateways())];
    });

    /** Ventende ordre for { epost } (null = gjest). → { id, key } */
    $r('/v1443-ordre', function($req) use ($produkt) {
        $p = $req->get_json_params() ?: [];
        $u = !empty($p['epost']) ? get_user_by('email', (string) $p['epost']) : null;
        $o = wc_create_order(['customer_id' => $u ? $u->ID : 0]);
        $o->add_product(wc_get_product($produkt()), 2);
        $o->set_billing_email($u ? $u->user_email : 'gjest@example.com');
        $o->set_billing_country('NO');
        $o->calculate_totals();
        $o->set_status('pending');
        $o->save();
        return ['id' => $o->get_id(), 'key' => $o->get_order_key()];
    });

    /**
     * Den ekte WC_Form_Handler::pay_action() på betal-for-ordre-siden:
     * { epost, ordre, key, metode } → { redirect, feil, metode, status }.
     */
    $r('/v1443-betal', function($req) use ($som, $betalside) {
        $p = $req->get_json_params() ?: [];
        $som($p['epost'] ?? null);
        $betalside($p['ordre']);
        wc_load_cart();
        wc_clear_notices();
        $_GET['key'] = (string) $p['key'];
        $_POST = ['woocommerce_pay' => '1', 'payment_method' => (string) $p['metode'], 'woocommerce-pay-nonce' => wp_create_nonce('woocommerce-pay')];
        $_REQUEST = $_POST + $_GET;
        $til = null;
        // pay_action() har wp_redirect() + exit inne i sin egen try/catch(Exception):
        // unntaket som stopper exit blir en feilmelding med denne teksten, som vi
        // tar ut igjen (WooCommerce sine egne feil står).
        $merke = '__amendo_test_omdirigert__';
        $kast = function($url) use (&$til, $merke) { $til = $url; throw new Exception($merke); };
        add_filter('wp_redirect', $kast);
        try { WC_Form_Handler::pay_action(); } catch (Exception $e) {}
        remove_filter('wp_redirect', $kast);
        while (ob_get_level() > 1) ob_end_clean();
        $feil = array_map(function($n) { return wp_strip_all_tags(is_array($n) ? $n['notice'] : $n); }, wc_get_notices('error'));
        $feil = array_values(array_filter($feil, function($t) use ($merke) { return $t !== $merke; }));
        wc_clear_notices();
        $o = wc_get_order((int) $p['ordre']);
        return ['redirect' => $til, 'feil' => $feil, 'metode' => $o->get_payment_method(), 'status' => $o->get_status()];
    });

    /**
     * Den ekte klassiske kassen: WC_Checkout::create_order() med en kurv, som
     * { epost } (null = gjest). { forhand } = org.nr som en annen plugin har
     * skrevet på ordren før oss. → { id, orgnr, kunde }
     */
    $r('/v1443-kasse', function($req) use ($som, $produkt) {
        $p = $req->get_json_params() ?: [];
        $id = $som($p['epost'] ?? null);
        wc_load_cart();
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart($produkt(), 1);
        WC()->cart->calculate_totals();
        $forhand = (string) ($p['forhand'] ?? '');
        $f = function($o) use ($forhand) { if ($forhand !== '') $o->update_meta_data('_billing_org_number', $forhand); };
        add_action('woocommerce_checkout_create_order', $f, 10);
        $epost = $id ? get_userdata($id)->user_email : 'gjest@example.com';
        $ordre_id = WC()->checkout()->create_order(['payment_method' => 'bacs', 'billing_first_name' => 'Kari', 'billing_last_name' => 'Nordmann', 'billing_email' => $epost, 'billing_country' => 'NO', 'billing_company' => 'Testfirma AS']);
        remove_action('woocommerce_checkout_create_order', $f, 10);
        WC()->cart->empty_cart();
        if (is_wp_error($ordre_id)) return ['feil' => $ordre_id->get_error_message()];
        $o = wc_get_order($ordre_id);
        return ['id' => $ordre_id, 'orgnr' => $o->get_meta('_billing_org_number'), 'kunde' => $o->get_customer_id(), 'adresse' => $o->get_formatted_billing_address()];
    });

    /** Store API-kassen: kroken WooCommerce 11.3 kjører før ordren lagres. { epost, forhand } */
    $r('/v1443-storeapi', function($req) use ($som, $produkt) {
        $p = $req->get_json_params() ?: [];
        $id = $som($p['epost'] ?? null);
        $o = wc_create_order(['customer_id' => $id]);
        $o->add_product(wc_get_product($produkt()), 1);
        if (!empty($p['forhand'])) $o->update_meta_data('_billing_org_number', (string) $p['forhand']);
        do_action('woocommerce_store_api_checkout_update_order_from_request', $o, new WP_REST_Request('POST', '/wc/store/v1/checkout'));
        $o->save();
        $o = wc_get_order($o->get_id());
        return ['id' => $o->get_id(), 'orgnr' => $o->get_meta('_billing_org_number')];
    });

    /** Ordre fra wc/v3 og wp-admin-veien (wc_create_order uten kasse). { epost } */
    $r('/v1443-uten-kasse', function($req) use ($produkt) {
        $p = $req->get_json_params() ?: [];
        $u = get_user_by('email', (string) ($p['epost'] ?? ''));
        $o = wc_create_order(['customer_id' => $u ? $u->ID : 0]);
        $o->add_product(wc_get_product($produkt()), 1);
        $o->save();
        return ['orgnr' => wc_get_order($o->get_id())->get_meta('_billing_org_number')];
    });

    /** Min konto-siden av/på: { av: true } fjerner den (og husker ID-en), { av: false } setter den tilbake. */
    $r('/v1443-min-konto', function($req) {
        $p = $req->get_json_params() ?: [];
        if (!empty($p['av'])) {
            if (!get_option('amendo_test_v1443_min_konto')) update_option('amendo_test_v1443_min_konto', (int) get_option('woocommerce_myaccount_page_id'));
            update_option('woocommerce_myaccount_page_id', 0);
        } elseif (get_option('amendo_test_v1443_min_konto')) {
            update_option('woocommerce_myaccount_page_id', (int) get_option('amendo_test_v1443_min_konto'));
            delete_option('amendo_test_v1443_min_konto');
        }
        return ['side' => wc_get_page_id('myaccount'), 'url' => wc_get_page_id('myaccount') > 0 ? wc_get_page_permalink('myaccount') : ''];
    });
});
