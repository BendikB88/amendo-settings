<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
if (!defined('ABSPATH')) exit;
add_filter('amendo_frakt_rate_limit', function($n) { return (int) get_option('amendo_test_rl', $n); });
add_action('rest_api_init', function() {
    $r = function($path, $cb) {
        register_rest_route('amendo-test/v1', $path, ['methods' => 'GET,POST', 'callback' => $cb, 'permission_callback' => '__return_true']);
    };
    $r('/setup', 'amendo_test_setup');
    $r('/tilstand', 'amendo_test_tilstand');
    $r('/opt', function($req) {
        foreach ($req->get_json_params() ?: [] as $k => $v) {
            if ($v === null) delete_option($k); else update_option($k, $v);
        }
        return ['ok' => true];
    });
    $r('/nullstill-rl', function() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%amendo_frakt_rl_%'");
        wp_cache_flush();
        return ['ok' => true];
    });
    $r('/intern', 'amendo_test_intern');
    $r('/kilde', function() {
        $m = new ReflectionMethod('WC_Cart_Session', 'init');
        $l = file($m->getFileName());
        return implode('', array_slice($l, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    });
    $r('/admin', 'amendo_test_admin');
});

function amendo_test_setup() {
    if ($ids = get_option('amendo_test_ids')) return $ids;
    update_option('woocommerce_default_country', 'NO');
    update_option('woocommerce_currency', 'NOK');
    update_option('woocommerce_price_num_decimals', '2');
    update_option('woocommerce_calc_taxes', 'yes');
    update_option('woocommerce_prices_include_tax', 'yes');
    update_option('woocommerce_tax_display_cart', 'incl');
    update_option('woocommerce_tax_display_shop', 'incl');
    update_option('woocommerce_tax_based_on', 'shipping');
    update_option('woocommerce_ship_to_countries', '');
    update_option('woocommerce_shipping_cost_requires_address', 'no');
    WC_Tax::_insert_tax_rate(['tax_rate_country' => 'NO', 'tax_rate' => '25.0000', 'tax_rate_name' => 'MVA', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '']);
    WC_Tax::_insert_tax_rate(['tax_rate_country' => 'SE', 'tax_rate' => '25.0000', 'tax_rate_name' => 'Moms', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '']);

    $no = new WC_Shipping_Zone();
    $no->set_zone_name('Norge'); $no->add_location('NO', 'country'); $no->save();
    $fr = $no->add_shipping_method('flat_rate');
    update_option("woocommerce_flat_rate_{$fr}_settings", ['title' => 'Hjemlevering', 'tax_status' => 'taxable', 'cost' => '99']);
    $fs = $no->add_shipping_method('free_shipping');
    update_option("woocommerce_free_shipping_{$fs}_settings", ['title' => 'Gratis frakt', 'requires' => 'min_amount', 'min_amount' => '1000', 'ignore_discounts' => 'no']);
    $lp = $no->add_shipping_method('local_pickup');
    update_option("woocommerce_local_pickup_{$lp}_settings", ['title' => 'Hent i butikk', 'tax_status' => 'none', 'cost' => '']);

    $se = new WC_Shipping_Zone();
    $se->set_zone_name('Sverige'); $se->add_location('SE', 'country'); $se->save();
    $sefr = $se->add_shipping_method('flat_rate');
    update_option("woocommerce_flat_rate_{$sefr}_settings", ['title' => 'Frakt Sverige', 'tax_status' => 'taxable', 'cost' => '199']);

    $lag = function($navn, $pris, $status = 'publish', $lager = 'instock') {
        $p = new WC_Product_Simple();
        $p->set_name($navn); $p->set_regular_price($pris); $p->set_status($status); $p->set_stock_status($lager);
        return $p->save();
    };
    $brod    = $lag('Brød', '250');
    $utsolgt = $lag('Utsolgt', '100', 'publish', 'outofstock');
    $kladd   = $lag('Kladd', '100', 'draft');

    $kake = new WC_Product_Variable();
    $kake->set_name('Kake'); $kake->set_status('publish');
    $a = new WC_Product_Attribute();
    $a->set_name('Størrelse'); $a->set_options(['Liten', 'Stor']); $a->set_visible(true); $a->set_variation(true);
    $kake->set_attributes([$a]);
    $kake_id = $kake->save();
    $v1 = new WC_Product_Variation();
    $v1->set_parent_id($kake_id); $v1->set_regular_price('500'); $v1->set_attributes(['størrelse' => '']); $v1->set_status('publish');
    $v1_id = $v1->save();
    $v2 = new WC_Product_Variation();
    $v2->set_parent_id($kake_id); $v2->set_regular_price('600'); $v2->set_attributes(['størrelse' => 'Stor']); $v2->set_status('private');
    $v2_id = $v2->save();
    WC_Product_Variable::sync($kake_id);

    $ids = compact('brod', 'utsolgt', 'kladd', 'kake_id', 'v1_id', 'v2_id', 'fr', 'fs', 'lp', 'sefr');
    update_option('amendo_test_ids', $ids);
    return $ids;
}

function amendo_test_tilstand() {
    global $wpdb;
    return [
        'sesjoner'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions"),
        'persistent'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE '_woocommerce_persistent_cart%'"),
        'rl_transient' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_amendo_frakt_rl_%'"),
    ];
}

/** Kjører beregningen midt i en forespørsel som allerede har en ekte innlogget kunde med kurv. */
function amendo_test_intern() {
    $ids = get_option('amendo_test_ids');
    $admin = get_user_by('login', 'admin')->ID;
    wp_set_current_user($admin);
    update_user_meta($admin, '_woocommerce_persistent_cart_' . get_current_blog_id(), ['cart' => ['xyz' => ['product_id' => $ids['brod'], 'quantity' => 7]]]);

    wc_load_cart();
    WC()->cart->get_cart();
    $foer_meta = get_user_meta($admin, '_woocommerce_persistent_cart_' . get_current_blog_id(), true);
    $kroker_foer = amendo_test_shutdown_kroker();
    $ekte_sesjon = WC()->session; $ekte_kunde = WC()->customer; $ekte_kurv = WC()->cart;
    $ekte_kunde->set_shipping_postcode('9999');
    $kurv_foer = array_map(fn($i) => [$i['product_id'], $i['quantity']], $ekte_kurv->get_cart());

    $res = amendo_frakt_beregn(['varer' => [['product_id' => $ids['brod'], 'variation_id' => 0, 'antall' => 1]], 'land' => 'NO', 'postnummer' => '0150', 'sted' => 'Oslo']);

    $kurv_etter = array_map(fn($i) => [$i['product_id'], $i['quantity']], WC()->cart->get_cart());
    $ut = [
        'res_ok'              => count($res['alternativer']) > 0,
        'samme_sesjon'        => WC()->session === $ekte_sesjon,
        'samme_kunde'         => WC()->customer === $ekte_kunde,
        'samme_kurv'          => WC()->cart === $ekte_kurv,
        'kunde_postnr_urort'  => WC()->customer->get_shipping_postcode() === '9999',
        'kurv_urort'          => $kurv_foer === $kurv_etter,
        'kurv_innhold'        => $kurv_etter,
        'bruker_tilbake'      => get_current_user_id() === $admin,
        'persistent_urort'    => get_user_meta($admin, '_woocommerce_persistent_cart_' . get_current_blog_id(), true) === $foer_meta,
        'sesjon_uten_amendo'  => !(WC()->session instanceof Amendo_Frakt_Minnesesjon),
        'shutdown_kroker'     => amendo_test_shutdown_kroker(),
        'shutdown_kroker_foer'=> $kroker_foer,
    ];
    // Ikke la testens egen, ekte sesjon skrive noe ved shutdown.
    remove_all_actions('shutdown');
    return $ut;
}

function amendo_test_shutdown_kroker() {
    global $wp_filter;
    $navn = [];
    foreach (($wp_filter['shutdown']->callbacks ?? []) as $prio => $cbs) {
        foreach ($cbs as $cb) {
            $f = $cb['function'];
            if (is_array($f) && is_object($f[0])) $navn[] = get_class($f[0]) . '::' . $f[1];
        }
    }
    return $navn;
}

/** Innstillingen i admin: standard på, lagres av/på, og feltet vises. */
function amendo_test_admin() {
    $admin = get_user_by('login', 'admin')->ID;
    wp_set_current_user($admin);
    $ut = [];
    delete_option('amendo_frakt_skjul_betalt');
    ob_start(); amendo_settings_page(); $html = ob_get_clean();
    $ut['felt_vises_avkrysset'] = (bool) preg_match('/name="frakt_skjul_betalt"\s+checked/', $html);

    $lagre = function($post) {
        $_POST = $post + ['action' => 'amendo_save_settings'];
        $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('amendo_settings_nonce')];
        $_REQUEST['_wp_http_referer'] = '/';
        $kast = function() { throw new Exception('redirect'); };
        add_filter('wp_redirect', $kast);
        try { do_action('admin_post_amendo_save_settings'); } catch (Exception $e) {}
        remove_filter('wp_redirect', $kast);
        return get_option('amendo_frakt_skjul_betalt');
    };
    $ut['lagret_av'] = $lagre(['butikk_navn' => 'Test']);
    $ut['lagret_paa'] = $lagre(['butikk_navn' => 'Test', 'frakt_skjul_betalt' => 'on']);

    // Uten tilgang: en kunde skal ikke kunne lagre.
    $kunde = wp_insert_user(['user_login' => 'kunde' . wp_rand(), 'user_pass' => 'x', 'role' => 'customer']);
    wp_set_current_user($kunde);
    $ut['kunde_har_tilgang'] = current_user_can(amendo_settings_kapabilitet());
    $sm = wp_insert_user(['user_login' => 'sm' . wp_rand(), 'user_pass' => 'x', 'role' => 'shop_manager']);
    wp_set_current_user($sm);
    $ut['shop_manager_har_tilgang'] = current_user_can(amendo_settings_kapabilitet());
    return $ut;
}

add_action('rest_api_init', function() {
    register_rest_route('amendo-test/v1', '/diag', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function() {
        global $wp_filter;
        $laget = [];
        add_filter('woocommerce_cart_session_initialize', function($ja, $s) use (&$laget) { $laget[] = spl_object_id($s); return $ja; }, 10, 2);
        $ids = get_option('amendo_test_ids');
        amendo_frakt_beregn(['varer' => [['product_id' => $ids['brod'], 'variation_id' => 0, 'antall' => 1]], 'land' => 'NO', 'postnummer' => '0150', 'sted' => '']);
        $kroker = [];
        foreach (['shutdown', 'wp', 'woocommerce_add_to_cart', 'woocommerce_cart_emptied', 'wp_loaded', 'template_redirect', 'woocommerce_after_calculate_totals'] as $h) {
            foreach (($wp_filter[$h]->callbacks ?? []) as $prio => $cbs) foreach ($cbs as $cb) {
                $f = $cb['function'];
                if (is_array($f) && is_object($f[0]) && in_array(spl_object_id($f[0]), $laget, true)) $kroker[] = "$h@$prio " . $f[1];
            }
        }
        remove_all_actions('shutdown');
        return ['laget' => $laget, 'gjenvaerende_kroker_fra_midlertidig_kurv' => $kroker];
    }]);
});

add_action('rest_api_init', function() {
    $r = function($path, $cb) {
        register_rest_route('amendo-test/v1', $path, ['methods' => 'GET,POST', 'callback' => $cb, 'permission_callback' => '__return_true']);
    };

    // Sporingslyttere skal være stille under beregningen og virke igjen etterpå.
    $r('/stille', function() {
        global $wp_filter;
        $ids = get_option('amendo_test_ids');
        $kroker = ['woocommerce_add_to_cart', 'woocommerce_cart_updated', 'woocommerce_cart_item_removed', 'woocommerce_cart_emptied', 'woocommerce_after_cart_item_quantity_update'];
        $kall = array_fill_keys($kroker, 0);
        foreach ($kroker as $k) {
            add_action($k, function() use (&$kall, $k) { $kall[$k]++; }, 10, 0);
        }
        $filterkall = 0;
        add_filter('woocommerce_add_cart_item_data', function($d) use (&$filterkall) { $filterkall++; return $d; });
        $antall_lyttere_foer = array_map(fn($k) => count($wp_filter[$k]->callbacks, COUNT_RECURSIVE), $kroker);

        $inn = ['varer' => [['product_id' => $ids['brod'], 'variation_id' => 0, 'antall' => 2], ['product_id' => $ids['brod'], 'variation_id' => 0, 'antall' => 1]], 'land' => 'NO', 'postnummer' => '0150', 'sted' => ''];
        $res = amendo_frakt_beregn($inn);
        $ut = [
            'res_ok'             => !empty($res['alternativer']),
            'kall_under'         => $kall,
            'filter_kalt_under'  => $filterkall,
            'lyttere_tilbake'    => array_map(fn($k) => count($wp_filter[$k]->callbacks, COUNT_RECURSIVE), $kroker) === $antall_lyttere_foer,
        ];
        foreach ($kroker as $k) do_action($k);
        $ut['kall_etter'] = $kall;

        // Feil midt i beregningen: lytterne skal likevel tilbake.
        $kast = function($d) { throw new Error('test-feil'); };
        add_filter('woocommerce_add_cart_item_data', $kast);
        $kall = array_fill_keys($kroker, 0);
        try { amendo_frakt_beregn($inn); $ut['feil_kastet'] = false; } catch (Throwable $e) { $ut['feil_kastet'] = $e->getMessage() === 'test-feil'; }
        remove_filter('woocommerce_add_cart_item_data', $kast);
        $ut['lyttere_tilbake_etter_feil'] = array_map(fn($k) => count($wp_filter[$k]->callbacks, COUNT_RECURSIVE), $kroker) === $antall_lyttere_foer;
        foreach ($kroker as $k) do_action($k);
        $ut['kall_etter_feil'] = $kall;
        $ut['sesjon_tilbake_etter_feil'] = WC()->session === null && WC()->cart === null;
        return $ut;
    });

    // Kasse-secret: endres bare med «Generer ny», vises én gang.
    $r('/secret', function() {
        $admin = get_user_by('login', 'admin')->ID;
        wp_set_current_user($admin);
        $lagre = function($post) {
            $_POST = $post + ['action' => 'amendo_save_settings'];
            $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('amendo_settings_nonce'), '_wp_http_referer' => '/'];
            $kast = function() { throw new Exception('redirect'); };
            add_filter('wp_redirect', $kast);
            try { do_action('admin_post_amendo_save_settings'); } catch (Exception $e) {}
            remove_filter('wp_redirect', $kast);
            return get_option('amendo_kasse_secret', '');
        };
        $side = function() { ob_start(); amendo_settings_page(); return ob_get_clean(); };
        $ut = [];

        delete_option('amendo_kasse_secret');
        $html = $side();
        $ut['ikke_satt_vises'] = strpos($html, '<strong>Ikke satt</strong>') !== false;
        $ut['skjult_felt_0'] = (bool) preg_match('/name="amendo_ny_kasse_secret"[^>]*value="0"/', $html);
        $ut['knapp_ikke_submit'] = (bool) preg_match('/<button type="button"[^>]*amendo-generer-secret/', $html);
        $ut['advarsel'] = strpos($html, 'KASSE_WEBHOOK_SECRET') !== false;

        $ut['annen_fane_tom_forblir_tom'] = $lagre(['butikk_navn' => 'X']) === '';
        $en = $lagre(['butikk_navn' => 'X', 'amendo_ny_kasse_secret' => '1']);
        $ut['generert_64_hex'] = (bool) preg_match('/^[0-9a-f]{64}$/', $en);
        $ut['andre_felt_lagret_samtidig'] = get_option('amendo_butikk_navn') === 'X';

        $html1 = $side();
        $ut['vises_foerste_gang'] = substr_count($html1, $en) === 1 && strpos($html1, 'id="amendo_ny_kasse_secret_verdi"') !== false;
        $ut['status_satt_64'] = (bool) preg_match('/<strong>Satt<\/strong>, 64 tegn/', $html1);
        $html2 = $side();
        $ut['vises_ikke_andre_gang'] = strpos($html2, $en) === false && strpos($html2, 'id="amendo_ny_kasse_secret_verdi"') === false;

        $ut['annen_fane_urort'] = $lagre(['butikk_navn' => 'Y']) === $en;
        $ut['skjult_0_urort'] = $lagre(['butikk_navn' => 'Y', 'amendo_ny_kasse_secret' => '0']) === $en;
        $ut['tom_verdi_urort'] = $lagre(['amendo_kasse_secret' => '', 'kasse_secret' => '']) === $en;
        $to = $lagre(['amendo_ny_kasse_secret' => '1']);
        $ut['ny_generering_bytter'] = $to !== $en && strlen($to) === 64;

        // Uten tilgang: ingen ny secret.
        $kunde = wp_insert_user(['user_login' => 'k' . wp_rand(), 'user_pass' => 'x', 'role' => 'customer']);
        wp_set_current_user($kunde);
        $_POST = ['amendo_ny_kasse_secret' => '1', 'action' => 'amendo_save_settings'];
        $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('amendo_settings_nonce'), '_wp_http_referer' => '/'];
        $stopp = function() { return function() { throw new Exception('die'); }; };
        add_filter('wp_die_handler', $stopp);
        add_filter('wp_die_json_handler', $stopp);
        try { do_action('admin_post_amendo_save_settings'); } catch (Exception $e) {}
        remove_filter('wp_die_handler', $stopp);
        remove_filter('wp_die_json_handler', $stopp);
        $ut['kunde_kan_ikke_generere'] = get_option('amendo_kasse_secret') === $to;

        update_option('amendo_kasse_secret', 'hemmelig-test-123');
        return $ut;
    });
});

add_action('rest_api_init', function() {
    register_rest_route('amendo-test/v1', '/side', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function() {
        $admin = get_user_by('login', 'admin')->ID;
        wp_set_current_user($admin);
        update_option('amendo_kasse_secret', str_repeat('a', 64));
        set_transient('amendo_ny_kasse_secret_' . $admin, str_repeat('a', 64), 600);
        ob_start(); amendo_settings_page(); $html = ob_get_clean();
        update_option('amendo_kasse_secret', 'hemmelig-test-123');
        header('Content-Type: text/html; charset=utf-8');
        echo $html; exit;
    }]);
});
