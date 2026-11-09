<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Tilvalg fra WCPA på REST-ordrer (1.4.36), suiten `tilvalg`. Bruker den ekte
// WCPA Pro 5.5.0 fra tests/playground/eksterne/ (ikke i repoet, se kjor.mjs).
if (!defined('ABSPATH')) exit;

const AMENDO_TEST_WCPA = 'woo-custom-product-addons-pro/start.php';

// Gruppen kopiene skal få (Playground har ingen olsread) og kallene som ble gjort.
add_filter('amendo_tilvalg_filgruppe', function($g) {
    $t = get_option('amendo_test_v1436_gruppe', '');
    return $t !== '' ? $t : $g;
});
// Playground (WASM) ignorerer chmod/chgrp og viser 777 på alt, så modusen koden ber om sjekkes her.
add_action('amendo_tilvalg_rettigheter', function($sti, $gruppe, $satt, $modus) {
    $up = wp_upload_dir(null, false);
    $kall = get_option('amendo_test_v1436_rettigheter', []);
    $kall[] = ['sti' => str_replace(wp_normalize_path($up['basedir']), '', wp_normalize_path($sti)), 'gruppe' => $gruppe, 'mappe' => is_dir($sti), 'modus' => $modus];
    update_option('amendo_test_v1436_rettigheter', $kall, false);
}, 10, 4);

/** Skjemaet: feltene butikkene bruker (bildevalg, farge, flervalg, antall, tekst, fil, «Velg fyll» per størrelse). */
function amendo_test_v1436_skjema() {
    $felt = function($type, $id, $label, $ekstra = []) {
        return array_merge([
            'type' => $type, 'elementId' => $id, 'name' => $id, 'label' => $label, 'active' => true,
            'required' => false, 'enablePrice' => false, 'enableCl' => false, 'cl_rule' => 'show',
            'relations' => [], 'className' => '', 'description' => '',
        ], $ekstra);
    };
    $valg = function($label, $value, $ekstra = []) {
        return array_merge(['label' => $label, 'value' => $value, 'selected' => false], $ekstra);
    };
    $ikon = function($navn) { return content_url('uploads/ikoner-1436/' . $navn); };
    $storrelse = function($verdi) {
        return [[
            'operator' => false,
            'rules' => [['operator' => false, 'rules' => ['cl_field' => 'attribute', 'cl_field_sub' => 'storrelse', 'cl_relation' => 'is', 'cl_val' => $verdi]]],
        ]];
    };
    $pris = ['enablePrice' => true, 'pricingType' => 'fixed', 'priceOptions' => 'different_for_all'];
    $felter = [
        $felt('image-group', 'kakefyll-1436', 'Velg kakefyll', $pris + ['values' => [
            $valg('Sjokolade', 'sjokolade', ['image' => $ikon('sjokolade.png'), 'thumb' => $ikon('sjokolade.png'), 'image_id' => 0, 'price' => '25']),
            $valg('Vanilje', 'vanilje', ['image' => $ikon('vanilje.png'), 'thumb' => $ikon('vanilje.png'), 'image_id' => 0, 'price' => '0']),
        ], 'disp_size_img' => ['width' => 60, 'height' => 60]]),
        $felt('color-group', 'farge-1436', 'Farge &amp; dekor', ['values' => [
            $valg('Rosa', 'rosa', ['color' => '#ff99cc']),
            $valg('Blå', 'bla', ['color' => '#3366ff']),
        ]]),
        $felt('checkbox-group', 'pynt-1436', 'Pynt', ['multiple' => true, 'values' => [
            $valg('Strøssel', 'strossel'),
            $valg('Lys, 10 stk', 'lys-10'),
        ]]),
        $felt('radio-group', 'lys-1436', 'Ekstra lys', ['enable_quantity' => true, 'values' => [
            $valg('Hvite lys', 'hvite'),
        ]]),
        $felt('text', 'tekst-1436', 'Tekst på kaken'),
        $felt('textarea', 'hilsen-1436', 'Hilsen'),
        $felt('file', 'bilde-1436', 'Last opp ditt bilde!', ['upload_type' => 'ajax', 'multiple_upload' => false]),
        $felt('radio-group', 'fyll-liten-1436', 'Velg fyll', $pris + ['enableCl' => true, 'relations' => $storrelse('Liten'), 'values' => [
            $valg('Bringebær', 'bringebar', ['price' => '50']), $valg('Sitron', 'sitron', ['price' => '50']),
        ]]),
        $felt('radio-group', 'fyll-stor-1436', 'Velg fyll', $pris + ['enableCl' => true, 'relations' => $storrelse('Stor'), 'values' => [
            $valg('Bringebær', 'bringebar', ['price' => '100']), $valg('Sitron', 'sitron', ['price' => '100']),
        ]]),
    ];
    return ['sec_1436' => [
        'extra' => ['key' => 'sec_1436', 'section_id' => 'sec_1436', 'name' => 'Tilvalg', 'enableCl' => false, 'cl_rule' => 'show', 'relations' => [], 'repeater' => false, 'toggle' => true, 'layout_option' => 'default'],
        'fields' => array_map(function($f) { return [$f]; }, $felter),
    ]];
}

/** _WCPA_order_meta_data (eller wcpa_data fra kurven) i en form suiten kan sammenligne. */
function amendo_test_v1436_felter($seksjoner) {
    $ut = [];
    foreach ((array) $seksjoner as $s) {
        foreach ((array) ($s['fields'] ?? []) as $rad) {
            foreach ((array) $rad as $f) {
                if (empty($f['type'])) continue;
                $ut[] = [
                    'elementId' => $f['elementId'], 'type' => $f['type'], 'label' => $f['label'],
                    'value' => $f['value'], 'quantity' => $f['quantity'] ?? null, 'price' => $f['price'] ?? null,
                    'meta_id' => $f['meta_id'] ?? null,
                ];
            }
        }
    }
    return $ut;
}

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };

    /** WCPA på eller av (gjelder fra neste forespørsel). */
    $r('/v1436-wcpa', function($req) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $finnes = file_exists(WP_PLUGIN_DIR . '/' . AMENDO_TEST_WCPA);
        if ($finnes && $req->get_param('pa') === '1' && !is_plugin_active(AMENDO_TEST_WCPA)) activate_plugin(AMENDO_TEST_WCPA);
        if ($finnes && $req->get_param('pa') === '0' && is_plugin_active(AMENDO_TEST_WCPA)) deactivate_plugins(AMENDO_TEST_WCPA);
        return ['finnes' => $finnes, 'lastet' => defined('WCPA_VERSION') ? WCPA_VERSION : null, 'filter' => has_filter('wcpa_add_cart_item_data') !== false];
    });

    /** Produktene og skjemaet (én gang), kasse-secreten. */
    $r('/v1436-produkter', function() {
        amendo_test_skriv_sikkert('amendo_kasse_secret', 'hemmelig-1436');
        $ider = get_option('amendo_test_v1436_ider');
        if (is_array($ider) && wc_get_product($ider['kake'] ?? 0)) return $ider;

        $kake = new WC_Product_Simple();
        $kake->set_name('Bursdagskake'); $kake->set_regular_price('300'); $kake->set_status('publish');
        $ider = ['kake' => $kake->save()];

        $attr = new WC_Product_Attribute();
        $attr->set_name('Størrelse'); $attr->set_options(['Liten', 'Stor']); $attr->set_visible(true); $attr->set_variation(true);
        $fyll = new WC_Product_Variable();
        $fyll->set_name('Fyllkake'); $fyll->set_status('publish'); $fyll->set_attributes([$attr]);
        $ider['fyllkake'] = $fyll->save();
        foreach ([['Liten', '400', 'liten'], ['Stor', '600', 'stor']] as [$verdi, $pris, $nokkel]) {
            $v = new WC_Product_Variation();
            $v->set_parent_id($ider['fyllkake']); $v->set_attributes(['storrelse' => $verdi]); $v->set_regular_price($pris); $v->set_status('publish');
            $ider[$nokkel] = $v->save();
        }
        WC_Product_Variable::sync($ider['fyllkake']);

        $skjema = wp_insert_post(['post_type' => 'wcpa_pt_forms', 'post_status' => 'publish', 'post_title' => 'Kaketilvalg']);
        update_post_meta($skjema, '_wcpa_fb_json_data', wp_slash(wp_json_encode(amendo_test_v1436_skjema())));
        update_post_meta($skjema, '_wcpa_fb_formula_data', '');
        foreach (['kake', 'fyllkake'] as $k) {
            update_post_meta($ider[$k], '_wcpa_product_meta', [$skjema]);
            if (function_exists('Acowebs\WCPA\refreshCaches')) \Acowebs\WCPA\refreshCaches($skjema, $ider[$k]);
            delete_transient('wcpa_fields_' . $ider[$k]);
        }
        $ider['skjema'] = $skjema;
        update_option('amendo_test_v1436_ider', $ider);
        return $ider;
    });

    /**
     * Fasiten: WCPA sin egen «legg i kurv» med det produktsiden sender
     * ({ produkt, variant?, attributter?, post }), og wcpa_data fra kurven.
     */
    $r('/v1436-kurv', function($req) {
        $p = $req->get_json_params() ?: [];
        if (!did_action('woocommerce_load_cart_from_session') && function_exists('wc_load_cart')) wc_load_cart();
        if (!WC()->cart) return ['feil' => 'ingen kurv'];
        WC()->cart->empty_cart();
        $_POST = wp_slash((array) ($p['post'] ?? []));
        $_REQUEST = $_POST + (array) ($p['attributter'] ?? []);
        $nokkel = WC()->cart->add_to_cart((int) $p['produkt'], 1, (int) ($p['variant'] ?? 0), (array) ($p['attributter'] ?? []));
        $linje = $nokkel ? WC()->cart->get_cart_item($nokkel) : null;
        return ['nokkel' => (bool) $nokkel, 'felter' => $linje ? amendo_test_v1436_felter($linje['wcpa_data'] ?? []) : null];
    });

    /** En ordre laget via ekte wc/v3/orders, slik frontenden gjør: { linjer } → { id }. */
    $r('/v1436-ordre', function($req) {
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        $kall = new WP_REST_Request('POST', '/wc/v3/orders');
        $kall->set_header('content-type', 'application/json');
        $kall->set_body(wp_json_encode(['status' => 'pending', 'line_items' => ($req->get_json_params() ?: [])['linjer'] ?? []]));
        $svar = rest_do_request($kall);
        return ['status' => $svar->get_status(), 'id' => $svar->get_data()['id'] ?? null];
    });

    /** Linjene: radene, strukturen, notatene og visningen i e-post og wp-admin. */
    $r('/v1436-linjer', function($req) {
        $ordre = wc_get_order((int) $req->get_param('id'));
        if (!$ordre) return ['feil' => 'ingen ordre'];
        $linjer = [];
        foreach ($ordre->get_items() as $id => $linje) {
            $rader = [];
            foreach ($linje->get_meta_data() as $m) {
                $d = $m->get_data();
                if ($d['key'] === '_WCPA_order_meta_data') continue;
                $rader[] = ['id' => (int) $d['id'], 'key' => $d['key'], 'value' => is_scalar($d['value']) ? (string) $d['value'] : $d['value']];
            }
            ob_start();
            do_action('woocommerce_after_order_itemmeta', $id, $linje, $linje->get_product());
            $admin = ob_get_clean();
            $linjer[] = [
                'id' => $id, 'navn' => $linje->get_name(), 'rader' => $rader,
                'struktur' => $linje->get_meta('_WCPA_order_meta_data') ? amendo_test_v1436_felter($linje->get_meta('_WCPA_order_meta_data')) : null,
                'admin' => $admin,
                'vist' => wc_display_item_meta($linje, ['echo' => false]),
            ];
        }
        $notater = array_values(array_filter(array_map(function($n) { return $n->content; }, wc_get_order_notes(['order_id' => $ordre->get_id()])), function($t) {
            return strpos($t, 'fantes ikke lenger') !== false;
        }));
        $epost = wc_get_email_order_items($ordre, ['show_sku' => false, 'show_image' => false, 'plain_text' => false, 'sent_to_admin' => true]);
        return ['linjer' => $linjer, 'notater' => $notater, 'epost' => $epost];
    });

    /** Ny status (WCPA kjører update_order_item igjen ved processing). */
    $r('/v1436-status', function($req) {
        $ordre = wc_get_order((int) $req->get_param('id'));
        $ordre->update_status((string) $req->get_param('status'));
        return ['status' => $ordre->get_status()];
    });

    /** En fil under uploads: { sti } → finnes, md5, rettigheter (oktalt). Sletter med slett=1. */
    $r('/v1436-fil', function($req) {
        $up = wp_upload_dir(null, false);
        $sti = $up['basedir'] . '/' . ltrim((string) $req->get_param('sti'), '/');
        if (strpos(wp_normalize_path(realpath(dirname($sti)) ?: ''), wp_normalize_path($up['basedir'])) !== 0) return ['feil' => 'utenfor'];
        if ($req->get_param('slett') === '1') @unlink($sti);
        clearstatcache();
        return [
            'finnes' => is_file($sti),
            'md5' => is_file($sti) ? md5_file($sti) : null,
            'rettigheter' => is_file($sti) ? decoct(fileperms($sti) & 0777) : null,
            'mappe' => is_dir(dirname($sti)) ? decoct(fileperms(dirname($sti)) & 0777) : null,
            'filer_i_mappen' => is_dir(dirname($sti)) ? count(glob(dirname($sti) . '/*')) : 0,
        ];
    });

    /** Kallene til amendo_tilvalg_rettigheter siden sist (og tømmer lista). */
    $r('/v1436-rettigheter', function() {
        $kall = get_option('amendo_test_v1436_rettigheter', []);
        delete_option('amendo_test_v1436_rettigheter');
        return ['kall' => $kall, 'basedir_gruppe' => @filegroup(wp_upload_dir(null, false)['basedir'])];
    });

    /** Ekstra filtyper i WCPA sine innstillinger (Settings → «custom extensions»): { typer: ['svg'] }. */
    $r('/v1436-wcpa-filtyper', function($req) {
        $s = get_option('wcpa_settings_key', []);
        $s = is_array($s) ? $s : [];
        $s['wcpa_custom_extensions_choose'] = (array) (($req->get_json_params() ?: [])['typer'] ?? []);
        update_option('wcpa_settings_key', $s);
        if (defined('WCPA_VERSION')) delete_transient('wcpa_settings_' . WCPA_VERSION);
        return ['ok' => true];
    });

    /** En fil lagt rett i wcpa_temp (ikke via WCPA): { navn, innhold } → { url }. */
    $r('/v1436-temp', function($req) {
        $p = $req->get_json_params() ?: [];
        $up = wp_upload_dir(null, false);
        $mappe = $up['basedir'] . '/wcpa_uploads/wcpa_temp/test1436';
        wp_mkdir_p($mappe);
        $navn = sanitize_file_name((string) $p['navn']);
        file_put_contents($mappe . '/' . $navn, (string) $p['innhold']);
        return ['url' => $up['baseurl'] . '/wcpa_uploads/wcpa_temp/test1436/' . $navn];
    });

    $r('/v1436-sett', function($req) {
        foreach ($req->get_json_params() ?: [] as $k => $v) amendo_test_skriv_sikkert($k, $v);
        return ['ok' => true];
    });
});
