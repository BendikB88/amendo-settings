<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Den felles malen (1.4.23), suitene `mal` og `wolt-gavekort`. Ingen ekte kall:
//
// - Wolt-tillegget (abbdw_get_opts) finnes bare når optionen
//   amendo_test_abbdw er satt (JSON med env, api_key og outlets). Wolts API
//   (daas-public-api…) besvares via pre_http_request ut fra amendo_test_wolt
//   { kode, svar }, og kallene lagres i amendo_test_wolt_kall.
// - WooCommerce Gift Cards finnes bare når amendo_test_wcgc er '1'. Stubben
//   under har de samme klassene og funksjonene amendo-settings bruker, og
//   oppfører seg som 2.7.5 i det som betyr noe her: debit trekker bare når
//   saldoen strekker, gavekortlinjene trekkes fra ordretotalen ved lagring
//   (update_order), og cancelled/failed/refunded fører beløpet tilbake
//   (maybe_credit_giftcards). Kortene ligger i amendo_test_gc (JSON).
//
// ⚠ Stille modus (amendo_test_stille = '1', settes først i begge suitene):
// ingen WP-cron og ingen Action Scheduler-forespørsler ved siden av testene.
// Playground har seks arbeidere mot samme SQLite-fil, og en cron-kjøring som
// skrev samtidig, fikk skrivingen i et testkall til å forsvinne (1.4.23: den
// første migreringen og kasse-secreten). v1423-sett og v1423-migrer leser i
// tillegg det de skrev rett fra databasen, og prøver igjen.
if (!defined('ABSPATH')) exit;

if (get_option('amendo_test_stille') === '1') {
    if (!defined('DISABLE_WP_CRON')) define('DISABLE_WP_CRON', true);
    add_filter('action_scheduler_allow_async_request_runner', '__return_false');
}

/** update_option/delete_option som sjekker databasen etterpå, opptil 5 forsøk. */
function amendo_test_skriv_sikkert($opt, $verdi) {
    global $wpdb;
    for ($i = 0; $i < 5; $i++) {
        if ($verdi === null) delete_option($opt); else update_option($opt, $verdi);
        $i_db = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt));
        if ($verdi === null ? $i_db === null : $i_db === (string) $verdi) return true;
        wp_cache_delete($opt, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        usleep(300000);
    }
    return false;
}

add_filter('pre_http_request', function($svar, $args, $url) {
    if (strpos($url, 'daas-public-api') === false) return $svar;
    $kall = get_option('amendo_test_wolt_kall', []);
    $kall[] = ['url' => $url, 'auth' => $args['headers']['Authorization'] ?? null, 'body' => json_decode((string) ($args['body'] ?? ''), true)];
    update_option('amendo_test_wolt_kall', $kall, false);
    $c = json_decode((string) get_option('amendo_test_wolt', '{}'), true) ?: [];
    if (!empty($c['nede'])) return new WP_Error('http_request_failed', 'cURL error 28: timeout');
    return ['response' => ['code' => (int) ($c['kode'] ?? 201), 'message' => ''], 'body' => wp_json_encode($c['svar'] ?? []), 'headers' => [], 'cookies' => []];
}, 10, 3);

// Lavere grense per IP for testen av bremsen.
add_filter('amendo_gavekort_brems_ip', function($v) { return (int) get_option('amendo_test_gk_ip', 0) ?: $v; });

if (get_option('amendo_test_abbdw')) {
    function abbdw_get_opts() {
        $o = json_decode((string) get_option('amendo_test_abbdw', '{}'), true);
        return array_merge(['env' => 'staging', 'api_key' => '', 'outlets' => []], is_array($o) ? $o : []);
    }
}

// ── WooCommerce Gift Cards-stubb ────────────────────────────────────────────

function amendo_test_gc_alle() {
    $a = json_decode((string) get_option('amendo_test_gc', '{}'), true);
    return is_array($a) ? $a : [];
}
function amendo_test_gc_aktivitet($type, $kort_id, $ordre_id, $belop) {
    $a = get_option('amendo_test_gc_aktivitet', []);
    $a[] = ['type' => $type, 'kort' => (int) $kort_id, 'ordre' => (int) $ordre_id, 'belop' => (float) $belop];
    update_option('amendo_test_gc_aktivitet', $a, false);
}

add_action('plugins_loaded', function() {
    if (get_option('amendo_test_wcgc') !== '1' || !class_exists('WC_Order_Item')) return;

    class WC_GC_Gift_Card_Data {
        public $id; public $d;
        public function __construct($id) { $this->read($id); }
        public function read($id) { $this->id = (int) $id; $this->d = amendo_test_gc_alle()[$this->id] ?? null; }
        public function get_id() { return $this->d ? $this->id : 0; }
        public function get_code() { return (string) $this->d['code']; }
        public function get_balance() { return (float) $this->d['remaining']; }
        public function set_balance($v) { $this->d['remaining'] = round((float) $v, 2); }
        public function save() { $a = amendo_test_gc_alle(); $a[$this->id] = $this->d; update_option('amendo_test_gc', wp_json_encode($a)); return true; }
        public function is_active() { return ($this->d['active'] ?? 'on') === 'on'; }
        public function get_expire_date() { return (int) ($this->d['expire'] ?? 0); }
        public function has_expired() { $e = $this->get_expire_date(); return $e !== 0 && $e <= time(); }
        public function is_redeemed() { return !empty($this->d['redeemed_by']); }
        public function lock_transactions() {
            if (get_option('amendo_test_gc_last')) throw new Exception('Gift card code was used in another transaction during this checkout.');
            return '_lock_' . (time() + 60) . '_test';
        }
    }

    function wc_gc_get_gift_card_by_code($code) {
        foreach (amendo_test_gc_alle() as $id => $d) if (strtoupper((string) $d['code']) === strtoupper((string) $code)) return new WC_GC_Gift_Card_Data($id);
        return false;
    }

    class WC_GC_Gift_Card {
        public $data;
        public function __construct($g = null) { $this->data = new WC_GC_Gift_Card_Data(is_numeric($g) ? (int) $g : ($g ? $g->get_id() : 0)); }
        public function get_id() { return $this->data->get_id(); }
        public function get_code() { return $this->data->get_code(); }
        public function get_balance() { return $this->data->get_balance(); }
        public function get_expire_date() { return $this->data->get_expire_date(); }
        public function is_active() { return $this->data->is_active(); }
        public function has_expired() { return $this->data->has_expired(); }
        public function is_redeemed() { return $this->data->is_redeemed(); }
        public function debit($amount, $order) {
            $amount = abs($amount);
            $b = $this->data->get_balance();
            if ($b <= 0 || round($b - $amount, 2) < 0) return false;
            $this->data->set_balance($b - $amount);
            $this->data->save();
            amendo_test_gc_aktivitet('used', $this->get_id(), $order->get_id(), $amount);
            return true;
        }
        public function credit($amount, $order) {
            $this->data->set_balance($this->data->get_balance() + abs($amount));
            $this->data->save();
            amendo_test_gc_aktivitet('refunded', $this->get_id(), $order->get_id(), abs($amount));
            return true;
        }
    }

    class WC_GC_Order_Item_Gift_Card extends WC_Order_Item {
        protected $extra_data = ['giftcard_id' => 0, 'code' => '', 'amount' => 0];
        public function get_type() { return 'gift_card'; }
        public function set_code($v) { $this->set_prop('code', wc_clean($v)); }
        public function set_amount($v) { $this->set_prop('amount', wc_format_decimal((float) $v)); }
        public function set_giftcard_id($v) { $this->set_prop('giftcard_id', absint($v)); }
        public function get_code($c = 'view') { return $this->get_prop('code', $c); }
        public function get_amount($c = 'view') { return (float) $this->get_prop('amount', $c); }
        public function get_giftcard_id($c = 'view') { return (int) $this->get_prop('giftcard_id', $c); }
        /** Som pluginens aktivitetstabell: brukt minus tilbakeført, for kortet på denne ordren. */
        public function get_captured_amount($c = 'view') {
            $sum = 0.0;
            foreach (get_option('amendo_test_gc_aktivitet', []) as $a) {
                if ($a['kort'] === $this->get_giftcard_id() && $a['ordre'] === (int) $this->get_order_id()) $sum += $a['type'] === 'used' ? $a['belop'] : -$a['belop'];
            }
            return min(max(0.0, $sum), $this->get_amount());
        }
        public function get_refunded_amount($c = 'view') { return 0.0; }
    }

    class WC_Order_Item_Gift_Card_Data_Store extends Abstract_WC_Order_Item_Type_Data_Store implements WC_Object_Data_Store_Interface, WC_Order_Item_Type_Data_Store_Interface {
        protected $internal_meta_keys = ['giftcard_id', 'code', 'amount'];
        public function read(&$item) {
            parent::read($item);
            $id = $item->get_id();
            $item->set_props(['giftcard_id' => get_metadata('order_item', $id, 'giftcard_id', true), 'code' => get_metadata('order_item', $id, 'code', true), 'amount' => get_metadata('order_item', $id, 'amount', true)]);
            $item->set_object_read(true);
        }
        public function save_item_data(&$item) {
            foreach (['giftcard_id' => $item->get_giftcard_id('edit'), 'code' => $item->get_code('edit'), 'amount' => $item->get_amount('edit')] as $k => $v) update_metadata('order_item', $item->get_id(), $k, $v);
        }
    }

    class Amendo_Test_GC_Order {
        public function totaler($order) {
            $sum = 0.0;
            foreach ($order->get_items('gift_card') as $i) $sum += $i->get_amount();
            return $sum;
        }
        public function update_order($order_id) {
            remove_action('woocommerce_update_order', [$this, 'update_order']);
            $order = wc_get_order($order_id);
            $gk = $order ? $this->totaler($order) : 0;
            if ($gk > 0) {
                $t = 0.0;
                foreach ($order->get_items() as $i) $t += (float) $i->get_total();
                foreach ($order->get_fees() as $i) $t += (float) $i->get_total();
                $t += (float) $order->get_shipping_total() + (float) $order->get_cart_tax() + (float) $order->get_shipping_tax();
                $order->set_total(max(0, round($t - $gk, 2)));
                $order->save();
            }
            add_action('woocommerce_update_order', [$this, 'update_order']);
        }
        public function maybe_debit_giftcards($order_id, $order, $items = []) {
            $items = $items ?: $order->get_items('gift_card');
            try {
                foreach ($items as $item) {
                    $kort = new WC_GC_Gift_Card($item->get_giftcard_id());
                    if (!$kort->get_id()) throw new Exception('Gift card not found.');
                    if ($item->meta_exists('gift_card_debited')) continue;
                    $belop = $item->get_amount() - $item->get_captured_amount();
                    if ($belop > 0 && !$kort->debit($belop, $order)) throw new Exception('Gift card code ' . $kort->get_code() . ' does not have enough balance.');
                    $item->delete_meta_data('gift_card_credited');
                    $item->add_meta_data('gift_card_debited', 'yes', true);
                    $item->save();
                }
            } finally {
                $order->delete_meta_data('_giftcard_lock_keys');
            }
        }
        public function maybe_credit_giftcards($order_id, $order, $items = []) {
            foreach ($items ?: $order->get_items('gift_card') as $item) {
                $kort = new WC_GC_Gift_Card($item->get_giftcard_id());
                if (!$kort->get_id() || $item->meta_exists('gift_card_credited') || 0 == $item->get_captured_amount()) continue;
                $kort->credit($item->get_captured_amount(), $order);
                $item->delete_meta_data('gift_card_debited');
                $item->add_meta_data('gift_card_credited', 'yes', true);
                $item->save();
            }
        }
    }

    $GLOBALS['amendo_test_gc_order'] = new Amendo_Test_GC_Order();
    function WC_GC() { return (object) ['order' => $GLOBALS['amendo_test_gc_order']]; }

    class WC_GC_Gift_Card_Product {
        public static function is_gift_card($p) {
            if (!$p instanceof WC_Product) return false;
            if ($p->is_type('variation')) $p = wc_get_product($p->get_parent_id());
            return $p && $p->get_meta('_gift_card') === 'yes';
        }
    }

    $o = $GLOBALS['amendo_test_gc_order'];
    add_action('woocommerce_update_order', [$o, 'update_order']);
    // Som pluginen: trekk ved disse statusene (gift_card_debited hindrer dobbelt trekk), tilbake ved de andre.
    foreach (['on-hold', 'pending', 'processing', 'completed'] as $s) add_action('woocommerce_order_status_' . $s, [$o, 'maybe_debit_giftcards'], 10, 2);
    foreach (['cancelled', 'failed', 'refunded'] as $s) add_action('woocommerce_order_status_' . $s, [$o, 'maybe_credit_giftcards'], 10, 2);
    add_filter('woocommerce_get_order_item_classname', function($c, $type) { return $type === 'gift_card' ? 'WC_GC_Order_Item_Gift_Card' : $c; }, 10, 2);
    add_filter('woocommerce_get_items_key', function($k, $item) { return $item instanceof WC_GC_Order_Item_Gift_Card ? 'gift_cards' : $k; }, 10, 2);
    add_filter('woocommerce_order_type_to_group', function($g) { $g['gift_card'] = 'gift_cards'; return $g; });
    add_filter('woocommerce_data_stores', function($s) { $s['order-item-gift_card'] = 'WC_Order_Item_Gift_Card_Data_Store'; return $s; });
}, 5);

// ── Ruter ───────────────────────────────────────────────────────────────────

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };
    $admin = function() { wp_set_current_user(get_user_by('login', 'admin')->ID); };

    /**
     * Migreringen kjøres på nytt i NESTE forespørsel: { moduler, avdelinger }
     * settes (null sletter), og flagget fjernes.
     */
    $r('/v1423-migrer', function($req) {
        $p = $req->get_json_params() ?: [];
        $ok = true;
        foreach (['moduler' => 'amendo_moduler', 'avdelinger' => 'amendo_avdelinger'] as $k => $opt) {
            if (!array_key_exists($k, $p)) continue;
            $ok = amendo_test_skriv_sikkert($opt, $p[$k] === null ? null : wp_json_encode($p[$k])) && $ok;
        }
        $ok = amendo_test_skriv_sikkert('amendo_migrert_1423', null) && $ok;
        return ['ok' => $ok];
    });

    /** Det pluginen har lastet i DENNE forespørselen, og /settings og /leveringsregler. */
    $r('/v1423-inspiser', function() use ($admin) {
        $admin();
        $settings = rest_do_request(new WP_REST_Request('GET', '/amendo-settings/v1/settings'))->get_data();
        $regler   = rest_do_request(new WP_REST_Request('GET', '/amendo-settings/v1/leveringsregler'))->get_data();
        return [
            'lagret'      => json_decode((string) get_option('amendo_moduler', 'null'), true),
            'aktive'      => amendo_moduler_aktive(),
            'flagg'       => get_option('amendo_migrert_1423', null),
            'avdelinger'  => json_decode((string) get_option('amendo_avdelinger', 'null'), true),
            'lastet'      => [
                'helsebygget' => function_exists('amendo_hb_navn_kort'),
                'hb_kort'     => function_exists('amendo_hb_kort'),
                'b2b'         => function_exists('amendo_b2b_soknader'),
                'kundegrupper' => function_exists('amendo_kg_kort'),
                'tellix'      => function_exists('amendo_tellix_rest_telefon'),
                'wolt'        => function_exists('amendo_wolt_tilbud'),
                'gavekort'    => function_exists('amendo_gavekort_sjekk'),
            ],
            'ruter'       => array_values(array_filter(array_keys(rest_get_server()->get_routes()), function($r) { return strpos($r, '/amendo-settings/v1/') === 0; })),
            'settings'    => json_decode(wp_json_encode($settings), true),
            'regler'      => json_decode(wp_json_encode($regler), true),
        ];
    });

    /** Lagring som admin: { post } → optionene etterpå. */
    $r('/v1423-lagre', function($req) use ($admin) {
        $admin();
        $_POST = (($req->get_json_params() ?: [])['post'] ?? []) + ['action' => 'amendo_save_settings'];
        $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('amendo_settings_nonce'), '_wp_http_referer' => '/'];
        $kast = function() { throw new Exception('redirect'); };
        add_filter('wp_redirect', $kast);
        try { do_action('admin_post_amendo_save_settings'); } catch (Exception $e) {}
        remove_filter('wp_redirect', $kast);
        $ut = [];
        foreach (['amendo_butikk_logo_mork', 'amendo_design_tema', 'amendo_levering_produkt_id', 'amendo_levering_pris', 'amendo_levering_min_timer', 'amendo_levering_luke_minutter', 'amendo_levering_krever_gruppe', 'amendo_helsebygget_navn'] as $o) $ut[$o] = get_option($o, null);
        foreach (['amendo_henting_gamle_felter', 'amendo_min_side_lenker', 'amendo_sporing', 'amendo_avdelinger', 'amendo_wolt_utsalg'] as $o) $ut[$o] = json_decode((string) get_option($o, 'null'), true);
        return $ut;
    });

    /**
     * { option: verdi } — lister og objekter som JSON, null sletter.
     * `brems_nullstill: [koder]` nullstiller bremsen for IP-en og kodene.
     */
    $r('/v1423-sett', function($req) {
        $ok = true;
        foreach ($req->get_json_params() ?: [] as $opt => $v) {
            if ($opt === 'brems_nullstill') {
                delete_transient('amendo_gk_ip_' . md5(isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : ''));
                foreach ((array) $v as $kode) {
                    $kode = function_exists('amendo_gavekort_kode') ? amendo_gavekort_kode($kode) : strtoupper(preg_replace('/\s+/', '', (string) $kode));
                    delete_transient('amendo_gk_kode_' . md5($kode));
                }
                continue;
            }
            $ok = amendo_test_skriv_sikkert($opt, $v === null ? null : (is_array($v) ? wp_json_encode($v) : (string) $v)) && $ok;
        }
        return ['ok' => $ok];
    });

    $r('/v1423-tilstand', function() {
        return [
            'wolt_kall' => get_option('amendo_test_wolt_kall', []),
            'gc'        => amendo_test_gc_alle(),
            'aktivitet' => get_option('amendo_test_gc_aktivitet', []),
        ];
    });

    /** Ordre med varer: { varer: [{id, antall}], status, meta } → id og total. */
    $r('/v1423-ordre', function($req) {
        $p = $req->get_json_params() ?: [];
        $o = wc_create_order(['status' => $p['status'] ?? 'pending']);
        foreach ((array) ($p['varer'] ?? []) as $v) $o->add_product(wc_get_product((int) $v['id']), (int) ($v['antall'] ?? 1));
        foreach ((array) ($p['meta'] ?? []) as $k => $v) $o->update_meta_data($k, $v);
        $o->set_billing_email('kunde@example.com');
        $o->calculate_totals();
        $o->save();
        return ['id' => $o->get_id(), 'total' => (float) $o->get_total()];
    });

    /** Ordren slik den er nå: total, status, gavekortlinjer, meta. */
    $r('/v1423-ordre-les', function($req) {
        $o = wc_get_order((int) $req->get_param('id'));
        if (!$o) return ['finnes' => false];
        $gk = [];
        foreach ($o->get_items('gift_card') as $i) $gk[] = ['kort' => $i->get_giftcard_id(), 'belop' => $i->get_amount(), 'trukket' => $i->meta_exists('gift_card_debited')];
        return ['finnes' => true, 'total' => (float) $o->get_total(), 'status' => $o->get_status(), 'gavekort' => $gk, 'levering' => $o->get_meta('_ab_delivery_method'), 'las' => $o->get_meta('_giftcard_lock_keys')];
    });

    /** Statusbytte (cancelled, processing …) eller payment_complete(): { id, status | betal }. */
    $r('/v1423-ordre-status', function($req) {
        $p = $req->get_json_params() ?: [];
        $o = wc_get_order((int) $p['id']);
        if (!empty($p['betal'])) $o->payment_complete('test-' . wp_rand());
        else $o->update_status((string) $p['status']);
        return ['ok' => true];
    });

    /** Et gavekortprodukt (pris 500) og en vanlig vare (pris 100). */
    $r('/v1423-produkter', function() {
        $lag = function($navn, $pris, $gavekort) {
            $finnes = get_posts(['post_type' => 'product', 'title' => $navn, 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any']);
            if ($finnes) return $finnes[0];
            $p = new WC_Product_Simple();
            $p->set_name($navn);
            $p->set_regular_price((string) $pris);
            $p->set_status('publish');
            $p->set_virtual($gavekort);
            if ($gavekort) $p->update_meta_data('_gift_card', 'yes');
            return $p->save();
        };
        return ['gavekort' => $lag('Gavekort 1423', 500, true), 'vare' => $lag('Vare 1423', 100, false)];
    });

    /** Ordre via wc/v3/orders som admin: { line_items } → metaen på linjene. */
    $r('/v1423-rest-ordre', function($req) use ($admin) {
        $admin();
        $r = new WP_REST_Request('POST', '/wc/v3/orders');
        $r->set_body_params(['status' => 'pending', 'line_items' => ($req->get_json_params() ?: [])['line_items'] ?? []]);
        $svar = rest_do_request($r);
        $id = (int) ($svar->get_data()['id'] ?? 0);
        $o = $id ? wc_get_order($id) : null;
        $linjer = [];
        if ($o) foreach ($o->get_items() as $i) {
            $m = [];
            foreach ($i->get_meta_data() as $md) $m[$md->key] = $md->value;
            $linjer[] = ['produkt' => $i->get_product_id(), 'meta' => $m];
        }
        return ['status' => $svar->get_status(), 'id' => $id, 'linjer' => $linjer];
    });

    /** Admin-siden som HTML. */
    $r('/v1423-side', function() use ($admin) {
        $admin();
        ob_start(); amendo_settings_page(); $html = ob_get_clean();
        header('Content-Type: text/html; charset=utf-8');
        echo $html; exit;
    });
});
