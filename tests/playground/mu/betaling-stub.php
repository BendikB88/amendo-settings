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
            public static function reference($ref = null, $prefix = '') { return "shop.test$prefix/$ref"; }
            public static function send_payment_details($data) {
                \update_option('stub_adyen_kall', ['details', '/payments/details', $data]);
                $ordre = (int) \get_option('stub_kort_ordre', 0);
                switch (\get_option('stub_detaljer_svar', 'authorised')) {
                    case 'feil_ref': return (object) ['resultCode' => 'Authorised', 'pspReference' => 'PSP_DET', 'merchantReference' => 'shop.test/999999'];
                    case 'refused':  return (object) ['resultCode' => 'Refused', 'pspReference' => 'PSP_DET', 'merchantReference' => "shop.test/$ordre", 'refusalReason' => 'Not enough balance'];
                    case 'wp_error': return new \WP_Error(422, 'Unprocessable Entity');
                    default:         return (object) ['resultCode' => 'Authorised', 'pspReference' => 'PSP_DET', 'merchantReference' => "shop.test/$ordre"];
                }
            }
            /** 1.4.11: /paymentMethods og /payments, styrt av stub_kort_svar. */
            private static function kort($sti, $data) {
                if ($sti === '/paymentMethods') {
                    \update_option('stub_metoder_kall', (int) \get_option('stub_metoder_kall', 0) + 1);
                    if (\get_option('stub_kort_svar') === 'wp_error') return new \WP_Error(401, 'Unauthorized');
                    return (object) ['paymentMethods' => [
                        (object) ['type' => 'scheme', 'name' => 'Kort', 'brands' => ['visa', 'mc']],
                        (object) ['type' => 'vipps', 'name' => 'Vipps'],
                    ]];
                }
                $belop = $data['amount']['value'] ?? 0;
                switch (\get_option('stub_kort_svar', 'authorised')) {
                    case 'refused':    return (object) ['resultCode' => 'Refused', 'pspReference' => 'PSP_NEI', 'refusalReason' => 'Refused'];
                    case 'challenge':  return (object) ['resultCode' => 'ChallengeShopper', 'action' => (object) ['type' => 'threeDS2', 'paymentMethodType' => 'scheme', 'token' => 'tok']];
                    case 'feil_belop': return (object) ['resultCode' => 'Authorised', 'pspReference' => 'PSP_FEIL', 'amount' => (object) ['value' => $belop + 100, 'currency' => 'NOK']];
                    case 'wp_error':   return new \WP_Error(422, 'Unprocessable Entity');
                    case 'kaster':     throw new \RuntimeException('Adyen svarte 500: stub');
                    default:           return (object) ['resultCode' => 'Authorised', 'pspReference' => 'PSP_OK', 'amount' => (object) ['value' => $belop, 'currency' => 'NOK']];
                }
            }
            public static function api_call($sti, $data) {
                \update_option('stub_adyen_kall', ['api_call', $sti, $data]);
                if ($sti === '/paymentMethods' || $sti === '/payments') return self::kort($sti, $data);
                $s = self::svar('ok');
                if ($s !== 'ok') return $s;
                return ['id' => 'CS_KLARNA_1', 'sessionData' => 'Ab02b4c0!klarna'];
            }
        }
    }
}

namespace AOrder\Gateways\API {
    if ($GLOBALS['stub_betaling_paa']) {
        class Amendo {
            public static function get_split_rate() { return (float) \get_option('stub_provisjon', 0); }
        }
    }
}

namespace AOrder\Gateways\Adyen {
    if ($GLOBALS['stub_betaling_paa']) {
        class Klarna {
            public static function merchant_account() { return 'AmendoPOS_STUB'; }
        }
        class Card {
            public static function merchant_account() { return 'AmendoECOM_STUB'; }
            public static function balance_account() { return \get_option('stub_balance_account', ''); }
            public static function sub_merchant_account() { return 'SUB_STUB'; }
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
        // 1.4.11: en ordre fra headless-kassen (`_nextjs_kasse`), eller uten merket.
        register_rest_route('amendo-test/v1', '/kortordre', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function($req) {
            $ids = get_option('amendo_test_ids') ?: amendo_test_setup();
            $o = wc_create_order();
            $o->add_product(wc_get_product($ids['brod']), 1);
            $o->set_billing_email('kort@example.com'); $o->set_billing_country('NO');
            $o->calculate_totals();
            $o->set_total(235.3);
            if ($req->get_param('headless') !== false) $o->update_meta_data('_nextjs_kasse', '1');
            if ($req->get_param('status')) $o->set_status($req->get_param('status'));
            $o->save();
            update_option('stub_kort_ordre', $o->get_id());
            return ['id' => $o->get_id()];
        }]);
        register_rest_route('amendo-test/v1', '/kortordre-status', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function($req) {
            $o = wc_get_order((int) $req->get_param('id'));
            return [
                'status'      => $o->get_status(),
                'transaksjon' => $o->get_transaction_id(),
                'metode'      => $o->get_payment_method(),
                'psp'         => $o->get_meta('_adyen_pspReference'),
                'notater'     => array_map(function($n) { return $n->content; }, wc_get_order_notes(['order_id' => $o->get_id()])),
            ];
        }]);
        register_rest_route('amendo-test/v1', '/betaling-tilstand', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function() {
            return ['logg' => get_option('stub_betaling_logg', []), 'vipps' => get_option('stub_vipps_saa', null), 'adyen_kall' => get_option('stub_adyen_kall', null)];
        }]);
    });
}
