<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Hjelperuter for suitene `tellix` (1.4.14 punkt 1–3) og `henting` (punkt 4–6).
// ⚠ Moduler lastes når pluginen lastes: sett tilstanden i ett kall og sjekk i
// det neste.
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    $r = function($path, $cb) {
        register_rest_route('amendo-test/v1', $path, ['methods' => 'GET,POST', 'callback' => $cb, 'permission_callback' => '__return_true']);
    };

    /** Nøkler i kroppen → optioner; null sletter. Lister/objekter lagres som JSON. */
    $r('/v1414-klargjor', function($req) {
        $p = $req->get_json_params() ?: [];
        $map = [
            'moduler' => 'amendo_moduler', 'tellix' => 'amendo_test_tellix', 'grupper' => 'amendo_test_tellix_grupper',
            'kunder' => 'amendo_test_tellix_kunder', 'sov' => 'amendo_test_tellix_sov', 'kast' => 'amendo_test_tellix_kast',
            'helsebygget' => 'amendo_helsebygget_grupper', 'store_groups' => 'amendo-store-groups',
            'avdelinger' => 'amendo_avdelinger', 'stengte' => 'amendo_stengte_perioder',
            'kundegrupper' => 'amendo_kundegruppe_config',
        ];
        foreach ($map as $k => $opt) {
            if (!array_key_exists($k, $p)) continue;
            $v = $p[$k];
            if ($v === null) delete_option($opt);
            elseif (is_array($v)) update_option($opt, wp_json_encode(in_array($k, ['helsebygget', 'kundegrupper'], true) ? (object) $v : $v));
            else update_option($opt, (string) $v);
        }
        // HPOS, som på garcon.no: filterkroken i ordrelisten finnes bare der,
        // og wc_get_orders støtter meta_query bare der.
        if (!empty($p['hpos']) && class_exists('Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer')) {
            $ds = wc_get_container()->get('Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer');
            if (!$ds->check_orders_table_exists()) $ds->create_database_tables();
            update_option('woocommerce_custom_orders_table_data_sync_enabled', 'no');
            update_option('woocommerce_custom_orders_table_enabled', 'yes');
        }
        if (!empty($p['nullstill'])) {
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%amendo_tellix_%' OR option_name LIKE '%amendo_rabatt_tlf_%'");
            delete_option('amendo_test_tellix_kall');
            delete_option('amendo_test_tellix_args');
            delete_option('amendo_test_tellix_navnkall');
            wp_cache_flush();
        }
        return ['ok' => true];
    });

    $r('/v1414-tilstand', function() {
        return [
            'kall' => (int) get_option('amendo_test_tellix_kall', 0),
            'args' => get_option('amendo_test_tellix_args', []),
            'treg' => (bool) get_transient('amendo_tellix_treg'),
            'navnkall' => (int) get_option('amendo_test_tellix_navnkall', 0),
        ];
    });

    /** Innholdet i WooCommerce-loggfilene for kilden amendo-tellix. */
    $r('/v1414-logg', function() {
        $dir = defined('WC_LOG_DIR') ? WC_LOG_DIR : WP_CONTENT_DIR . '/uploads/wc-logs/';
        $tekst = '';
        foreach (glob(trailingslashit($dir) . 'amendo-tellix*.log') ?: [] as $f) $tekst .= file_get_contents($f);
        return ['tekst' => $tekst];
    });

    $r('/v1414-brukermeta', function($req) {
        $u = get_user_by('email', (string) $req->get_param('epost'));
        if (!$u) return ['finnes' => false];
        $m = [];
        foreach (['_tellixGroupId', '_tellixCustomerId', '_tellixCustomerUuId'] as $k) $m[$k] = get_user_meta($u->ID, $k, true);
        return ['finnes' => true, 'id' => $u->ID, 'meta' => $m];
    });

    /** Admin-siden som HTML (B2B-fanen), som admin eller shop_manager. */
    $r('/v1414-side', function($req) {
        $rolle = (string) $req->get_param('rolle');
        if ($rolle === 'shop_manager') {
            $id = wp_insert_user(['user_login' => 'sm' . wp_rand(), 'user_pass' => 'x', 'role' => 'shop_manager']);
            wp_set_current_user($id);
        } else {
            wp_set_current_user(get_user_by('login', 'admin')->ID);
        }
        ob_start(); amendo_settings_page(); $html = ob_get_clean();
        header('Content-Type: text/html; charset=utf-8');
        echo $html; exit;
    });

    /** Lagring via admin_post_amendo_save_settings: { post, rolle }. */
    $r('/v1414-lagre', function($req) {
        $p = $req->get_json_params() ?: [];
        if (($p['rolle'] ?? 'admin') === 'shop_manager') {
            wp_set_current_user(wp_insert_user(['user_login' => 'sm' . wp_rand(), 'user_pass' => 'x', 'role' => 'shop_manager']));
        } else {
            wp_set_current_user(get_user_by('login', 'admin')->ID);
        }
        $_POST = ($p['post'] ?? []) + ['action' => 'amendo_save_settings'];
        $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('amendo_settings_nonce'), '_wp_http_referer' => '/'];
        $kast = function() { throw new Exception('redirect'); };
        add_filter('wp_redirect', $kast);
        try { do_action('admin_post_amendo_save_settings'); } catch (Exception $e) {}
        remove_filter('wp_redirect', $kast);
        return [
            'helsebygget' => get_option('amendo_helsebygget_grupper', null),
            'stengte'     => json_decode((string) get_option('amendo_stengte_perioder', 'null'), true),
            'kundegrupper' => json_decode((string) get_option('amendo_kundegruppe_config', 'null'), true),
        ];
    });

    // ── henting ──

    /** { meta: {...} } → ny ordre med metaen. */
    $r('/v1414-ordre', function($req) {
        $p = $req->get_json_params() ?: [];
        $ids = get_option('amendo_test_ids') ?: amendo_test_setup();
        $o = wc_create_order();
        $o->add_product(wc_get_product($ids['brod']), 1);
        $o->set_billing_email('henting@example.com');
        foreach ((array) ($p['meta'] ?? []) as $k => $v) $o->update_meta_data($k, $v);
        $o->calculate_totals();
        $o->save();
        return ['id' => $o->get_id()];
    });

    /** Alt hentelinjen vises i, for én ordre. */
    $r('/v1414-vis', function($req) {
        $o = wc_get_order((int) $req->get_param('id'));
        $fang = function($cb) { ob_start(); $cb(); return ob_get_clean(); };
        $mailer = function_exists('WC') ? WC()->mailer() : null;
        $epost = $mailer ? ($mailer->get_emails()['WC_Email_New_Order'] ?? null) : null;
        return [
            'linje'      => function_exists('amendo_hentelinje') ? amendo_hentelinje($o) : 'MODUL_AV',
            'ordreside'  => $fang(function() use ($o) { do_action('woocommerce_admin_order_data_after_order_details', $o); }),
            'kolonner'   => apply_filters('manage_woocommerce_page_wc-orders_columns', ['cb' => '', 'order_number' => 'Ordre', 'order_status' => 'Status', 'order_total' => 'Sum']),
            'kolonne'    => $fang(function() use ($o) { do_action('manage_woocommerce_page_wc-orders_custom_column', 'amendo_henting', $o); }),
            'epost_html' => $fang(function() use ($o, $epost) { do_action('woocommerce_email_order_meta', $o, true, false, $epost); }),
            'epost_kunde'=> $fang(function() use ($o, $epost) { do_action('woocommerce_email_order_meta', $o, false, false, $epost); }),
            'epost_tekst'=> $fang(function() use ($o, $epost) { do_action('woocommerce_email_order_meta', $o, false, true, $epost); }),
            'wpo'        => $fang(function() use ($o) { do_action('wpo_wcol_custom_row', $o, 'order'); }),
            'wpo_id'     => $fang(function() use ($o) { do_action('wpo_wcol_custom_row', 'kolonne', $o->get_id()); }),
            'tellix_kroker' => (bool) has_filter('amendo_tellix_delivery_fields'),
        ];
    });

    /** { id, felt } → amendo_tellix_delivery_fields. */
    $r('/v1414-tellix-felt', function($req) {
        $p = $req->get_json_params() ?: [];
        return ['felt' => apply_filters('amendo_tellix_delivery_fields', $p['felt'] ?? [], wc_get_order((int) ($p['id'] ?? 0)))];
    });

    /**
     * { id } → det Amendo Gateway 1.36.13 ville sendt Tellix som deliveryDate.
     * Kopi av Tellix::get_delivery_datetime() (uten _aoTidspunkt og sluttfilteret),
     * så testen viser hva gatewayen faktisk leser når filteret vårt har kjørt.
     */
    $r('/v1414-gateway-dato', function($req) {
        $order = wc_get_order((int) (($req->get_json_params() ?: [])['id'] ?? 0));
        $config = apply_filters('amendo_tellix_delivery_fields', [
            'pairs' => [
                ['date' => 'leveringsdato',        'time' => 'leveringstidspunkt'],
                ['date' => 'hentetid_kaker',       'time' => 'hentetidspunkt_kaker'],
                ['date' => 'hentetid_helsebygget', 'time' => 'tidspunkt_helsebygget'],
                ['date' => 'hentedato_stasjonen',  'time' => 'tidspunkt_stasjonen'],
            ],
            'method'        => 'hentested',
            'method_value'  => 'Levering',
            'delivery_time' => 'leveringstidspunkt',
        ], $order);
        $parse_date = function(string $raw) {
            $raw = trim($raw);
            if ($raw === '') return null;
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $raw, $m)) { [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]]; }
            elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})/', $raw, $m)) { [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]]; }
            else return null;
            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
        };
        $parse_time = function(string $raw) {
            if (preg_match('/(\d{1,2})[:.](\d{2})/', $raw, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
            return null;
        };
        $disabled = array_filter(array_map('trim', explode(',', (string) $order->get_meta('_thwcfe_disabled_fields'))));
        $get = function($key) use ($order, $disabled) { return ($key && !in_array($key, $disabled, true)) ? (string) $order->get_meta($key) : ''; };
        $is_delivery = !empty($config['method']) && isset($config['method_value'])
            && strcasecmp(trim((string) $order->get_meta($config['method'])), (string) $config['method_value']) === 0;
        $pairs = (array) ($config['pairs'] ?? []);
        $all_times = array_values(array_filter(array_column($pairs, 'time')));
        $result = null;
        foreach ($pairs as $pair) {
            $date = $parse_date($get($pair['date'] ?? ''));
            if (!$date) continue;
            $time_keys = [$pair['time'] ?? ''];
            if ($is_delivery && !empty($config['delivery_time'])) array_unshift($time_keys, $config['delivery_time']);
            $time_keys = array_unique(array_merge($time_keys, $all_times));
            $time = null;
            foreach ($time_keys as $key) { if ($time = $parse_time($get($key))) break; }
            $result = $time ? "$date $time:00" : $date;
            break;
        }
        return ['resultat' => $result, 'er_levering' => $is_delivery];
    });

    /** { dato, sted, ider } → hvilke av ordrene filteret finner, og filterfeltene. */
    $r('/v1414-filter', function($req) {
        $p = $req->get_json_params() ?: [];
        $_GET['amendo_hentedato'] = (string) ($p['dato'] ?? '');
        $_GET['amendo_hentested'] = (string) ($p['sted'] ?? '');
        $args = apply_filters('woocommerce_order_list_table_prepare_items_query_args', ['type' => 'shop_order', 'limit' => -1, 'return' => 'ids', 'status' => array_keys(wc_get_order_statuses())]);
        $funnet = array_map('intval', wc_get_orders($args));
        ob_start(); do_action('woocommerce_order_list_table_restrict_manage_orders', 'shop_order', 'top'); $html = ob_get_clean();
        $ider = array_map('intval', (array) ($p['ider'] ?? []));
        $hpos = class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        return ['funnet' => array_values(array_intersect($ider, $funnet)), 'html' => $html, 'hpos' => $hpos];
    });
});
