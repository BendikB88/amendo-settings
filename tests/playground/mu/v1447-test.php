<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// 1.4.47, suiten `frakt-holio`: en stubb av Holio Shipping 1.4.2, bare når
// optionen `amendo_test_holio` er satt (ellers finnes ingen Holio-klasser):
// - Holio_Database::get_shop_id()
// - Holio_Shipping_Option_Repository::find_enabled_by_shop_id() — svarer med ALLE
//   valgene i `amendo_test_holio_valg`, også deaktiverte (som om Holios backend
//   ikke filtrerte), og teller kallene. `amendo_test_holio_modus`: 'kast' = unntak,
//   'tom' = [] (det Holio svarer når kallet feiler).
// - fraktmetoden `holio_shipping`: én rate per aktivt valg, med `holio_option_id`
//   i metadataene og 0 kr på eller over grensen, regnet som Holios
//   get_package_total() (line_total, + line_tax ved visning med mva). Deaktivert
//   valg = ingen rate, som i Holio; `rate: false` = aktivt valg uten rate (gjelder
//   ikke lageret); `tving_rate: true` = rate selv om valget er deaktivert.
if (!defined('ABSPATH')) exit;

function amendo_test_holio_valg() {
    $v = json_decode((string) get_option('amendo_test_holio_valg', '[]'), true);
    return is_array($v) ? $v : [];
}

if (get_option('amendo_test_holio')) {
    class Holio_Database {
        public static function get_shop_id(bool $force_regenerate = false): ?string { return 'test-butikk-1447'; }
    }

    class Holio_Shipping_Option_Repository {
        public function find_enabled_by_shop_id(string $shop_id): array {
            global $wpdb;
            $wpdb->query("UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = 'amendo_test_holio_kall'");
            $modus = get_option('amendo_test_holio_modus', '');
            if ($modus === 'kast') throw new Exception('Holio nede (test)');
            if ($modus === 'tom') return [];
            return array_map(function($v) { unset($v['rate'], $v['tving_rate'], $v['pris']); return $v; }, amendo_test_holio_valg());
        }
    }

    add_action('woocommerce_shipping_init', function() {
        if (class_exists('Amendo_Test_Holio_Metode', false)) return;
        class Amendo_Test_Holio_Metode extends WC_Shipping_Method {
            public function __construct($instance_id = 0) {
                $this->id = 'holio_shipping';
                $this->instance_id = absint($instance_id);
                $this->method_title = 'Holio Shipping (test)';
                $this->supports = ['shipping-zones', 'instance-settings'];
                $this->enabled = 'yes';
                $this->title = 'Holio';
                $this->tax_status = 'taxable';
            }
            public function calculate_shipping($package = []) {
                $med_mva = get_option('woocommerce_tax_display_cart') === 'incl';
                $sum = 0.0;
                foreach ($package['contents'] ?? [] as $l) {
                    $sum += (float) ($l['line_total'] ?? 0);
                    if ($med_mva) $sum += (float) ($l['line_tax'] ?? 0);
                }
                foreach (amendo_test_holio_valg() as $v) {
                    $aktiv = filter_var($v['is_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
                    if ((!$aktiv && empty($v['tving_rate'])) || (isset($v['rate']) && !$v['rate'])) continue;
                    $pris = (float) ($v['pris'] ?? 149);
                    if (!empty($v['is_free_shipping']) && !empty($v['free_shipping_threshold']) && $sum >= (float) $v['free_shipping_threshold']) $pris = 0;
                    $meta = ['holio_carrier' => 'bring'];
                    if (!array_key_exists('uten_meta', $v)) $meta['holio_option_id'] = $v['id'];
                    $this->add_rate(['id' => $this->get_rate_id($v['id'] . '_P' . $v['id']), 'label' => $v['product_name'] ?? 'Holio', 'cost' => $pris, 'meta_data' => $meta]);
                }
            }
        }
    });
    add_filter('woocommerce_shipping_methods', function($m) { $m['holio_shipping'] = 'Amendo_Test_Holio_Metode'; return $m; });
}

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };

    /**
     * Sonene (idempotent, krever at stubben er på): DK = bare Holio;
     * FI = Holio + WooCommerce free_shipping (3000) + flat_rate. 25 % mva i begge,
     * som Norge, så 250 kr inkl. mva = 200 + 50 også der.
     * Og kupongen holio10 (10 %).
     */
    $r('/v1447-soner', function() {
        if ($ids = get_option('amendo_test_1447_ids')) return $ids;
        if (!get_option('amendo_test_holio')) return new WP_Error('av', 'stubben er av', ['status' => 409]);
        $a = new WC_Shipping_Zone();
        $a->set_zone_name('Holio-test'); $a->add_location('DK', 'country'); $a->save();
        $ha = $a->add_shipping_method('holio_shipping');
        $b = new WC_Shipping_Zone();
        $b->set_zone_name('Holio og WC'); $b->add_location('FI', 'country'); $b->save();
        $hb = $b->add_shipping_method('holio_shipping');
        $fs = $b->add_shipping_method('free_shipping');
        update_option("woocommerce_free_shipping_{$fs}_settings", ['title' => 'Gratis frakt', 'requires' => 'min_amount', 'min_amount' => '3000', 'ignore_discounts' => 'no']);
        $fr = $b->add_shipping_method('flat_rate');
        update_option("woocommerce_flat_rate_{$fr}_settings", ['title' => 'Hjemlevering', 'tax_status' => 'taxable', 'cost' => '79']);
        foreach (['DK', 'FI'] as $land) {
            WC_Tax::_insert_tax_rate(['tax_rate_country' => $land, 'tax_rate' => '25.0000', 'tax_rate_name' => 'MVA', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '']);
        }
        if (!wc_get_coupon_id_by_code('holio10')) {
            $k = new WC_Coupon();
            $k->set_code('holio10'); $k->set_discount_type('percent'); $k->set_amount(10); $k->save();
        }
        $ids = ['sone_holio' => $a->get_id(), 'sone_begge' => $b->get_id(), 'ha' => $ha, 'hb' => $hb, 'fs' => $fs, 'fr' => $fr];
        update_option('amendo_test_1447_ids', $ids);
        return $ids;
    });

    /** { valg: [...], modus: '' | 'kast' | 'tom', tom_mellomlager: true } */
    $r('/v1447-holio', function($req) {
        global $wpdb;
        $p = $req->get_json_params() ?: [];
        if (array_key_exists('valg', $p)) amendo_test_skriv_sikkert('amendo_test_holio_valg', wp_json_encode($p['valg']));
        if (array_key_exists('modus', $p)) amendo_test_skriv_sikkert('amendo_test_holio_modus', (string) $p['modus']);
        if (!empty($p['nullstill_teller'])) amendo_test_skriv_sikkert('amendo_test_holio_kall', '0');
        if (!empty($p['tom_mellomlager'])) {
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_amendo\\_holio\\_gratis\\_%' OR option_name LIKE '\\_transient\\_timeout\\_amendo\\_holio\\_gratis\\_%'");
        }
        wp_cache_flush();
        return ['ok' => true];
    });

    /** Antall kall mot Holios repository og mellomlageret (bare antall, utløp og om det finnes). */
    $r('/v1447-tilstand', function() {
        global $wpdb;
        $navn = '_transient_amendo_holio_gratis_' . md5('test-butikk-1447');
        $verdi = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $navn));
        $utlop = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_timeout_amendo_holio_gratis_' . md5('test-butikk-1447')));
        $data = $verdi === null ? null : maybe_unserialize($verdi);
        return [
            'kall'         => (int) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'amendo_test_holio_kall'"),
            'mellomlager'  => $verdi !== null,
            'grenser'      => is_array($data) ? ($data['grenser'] ?? null) : null,
            'sekunder_igjen' => $utlop === null ? null : ((int) $utlop - time()),
        ];
    });
});
