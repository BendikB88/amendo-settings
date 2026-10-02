<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Stubber av Amendo Gateway (AOrder\Gateways\*, AmendoCore()) og betalings-
// gatewayer, styrt med options (settes i forrige forespørsel via /opt):
//   stub_betaling_av = '1'          → ingen Amendo Gateway-klasser/AmendoCore
//   stub_adyen_svar  = ok | tomt_objekt | null | false | kaster | tom_array
//   stub_adyen_live  = '1' | '0'
//   stub_kort_id     = bindestrek | understrek | begge   (kortgatewayens ID)

namespace {
    if (!defined('ABSPATH')) exit;
    $GLOBALS['stub_betaling_paa'] = get_option('stub_betaling_av') !== '1';
}

namespace AOrder\Gateways\API {
    if ($GLOBALS['stub_betaling_paa']) {
        class Adyen {
            private static function svar($id) {
                switch (\get_option('stub_adyen_svar', 'ok')) {
                    case 'tomt_objekt': return new \stdClass();
                    case 'null':        return null;
                    case 'false':       return false;
                    case 'tom_array':   return [];
                    case 'kaster':      throw new \RuntimeException('Adyen svarte 422: stub');
                    default:            return $id;
                }
            }
            public static function create_session($amount, $ref, $country, $currency) {
                \update_option('stub_adyen_kall', ['create_session', $amount, $ref, $country, $currency]);
                $s = self::svar('ok');
                if ($s !== 'ok') return $s;
                return (object) ['id' => 'CS_STUB_1', 'sessionData' => 'Ab02b4c0!stub'];
            }
            public static function api_call($sti, $data) {
                \update_option('stub_adyen_kall', ['api_call', $sti, $data]);
                $s = self::svar('ok');
                if ($s !== 'ok') return $s;
                return ['id' => 'CS_KLARNA_1', 'sessionData' => 'Ab02b4c0!klarna'];
            }
        }
    }
}

namespace AOrder\Gateways\Adyen {
    if ($GLOBALS['stub_betaling_paa']) {
        class Klarna {
            public static function merchant_account() { return 'AmendoPOS_STUB'; }
        }
    }
}

namespace {
    if ($GLOBALS['stub_betaling_paa']) {
        function AmendoCore() {
            return new class {
                public function is_live() { return get_option('stub_adyen_live') === '1'; }
            };
        }
    }

    add_action('plugins_loaded', function() {
        if (!class_exists('WC_Payment_Gateway')) return;

        class Stub_Gateway extends WC_Payment_Gateway {
            public function __construct($id) { $this->id = $id; $this->method_title = $id; $this->init_settings(); }
        }

        /** Etterligner Vipps: leser WC()->session og WC()->customer, som er null i REST uten wc_load_cart(). */
        class Stub_Vipps extends Stub_Gateway {
            public function process_payment($order_id) {
                $order = wc_get_order($order_id);
                WC()->session->set('vipps_ordre', $order_id);   // fatal hvis session er null
                $tlf = WC()->customer->get_billing_phone();     // fatal hvis customer er null
                update_option('stub_vipps_saa', [
                    'sesjon'  => is_object(WC()->session) ? get_class(WC()->session) : null,
                    'telefon' => $tlf,
                    'by'      => WC()->customer->get_billing_city(),
                    'lev_by'  => WC()->customer->get_shipping_city(),
                    'retur'   => $this->get_return_url($order),
                ]);
                return ['result' => 'success', 'redirect' => 'https://vipps.example/betal?tlf=' . rawurlencode($tlf)];
            }
        }
        class Stub_Krasj extends Stub_Gateway {
            public function process_payment($order_id) { return strlen([]); } // TypeError
        }
        class Stub_Avvist extends Stub_Gateway {
            public function process_payment($order_id) { return ['result' => 'failure']; }
        }

        add_filter('woocommerce_payment_gateways', function($gw) {
            $kort = get_option('stub_kort_id', 'bindestrek');
            if ($kort !== 'understrek') $gw[] = new Stub_Gateway('amendo-adyen-card');
            if ($kort !== 'bindestrek') $gw[] = new Stub_Gateway('amendo_adyen_card');
            $gw[] = new Stub_Vipps('vipps');
            $gw[] = new Stub_Krasj('krasj');
            $gw[] = new Stub_Avvist('avvist');
            return $gw;
        });
    }, 5);

    // Logglinjer fra amendo-betaling, for testene (bare første logg-handler).
    add_filter('woocommerce_logger_log_message', function($m, $level, $ctx, $handler = null) {
        static $forste = null;
        $h = is_object($handler) ? get_class($handler) : (string) $handler;
        if ($forste === null) $forste = $h;
        if ($h === $forste && ($ctx['source'] ?? '') === 'amendo-betaling') {
            $logg = get_option('stub_betaling_logg', []);
            $logg[] = $level . ': ' . $m;
            update_option('stub_betaling_logg', $logg, false);
        }
        return $m;
    }, 10, 4);

    add_action('rest_api_init', function() {
        register_rest_route('amendo-test/v1', '/betaling-ordre', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function() {
            $ids = get_option('amendo_test_ids') ?: amendo_test_setup();
            $o = wc_create_order();
            $o->add_product(wc_get_product($ids['brod']), 1);
            $o->set_billing_first_name('Kari'); $o->set_billing_phone('+4799999999'); $o->set_billing_city('Oslo');
            $o->set_billing_email('kari@example.com'); $o->set_billing_country('NO');
            $o->set_shipping_first_name('Kari'); $o->set_shipping_address_1('Storgata 1'); $o->set_shipping_city('Bergen'); $o->set_shipping_country('NO');
            $o->calculate_totals(); $o->save();
            return ['id' => $o->get_id()];
        }]);
        register_rest_route('amendo-test/v1', '/betaling-tilstand', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function() {
            return ['logg' => get_option('stub_betaling_logg', []), 'vipps' => get_option('stub_vipps_saa', null), 'adyen_kall' => get_option('stub_adyen_kall', null)];
        }]);
    });
}
