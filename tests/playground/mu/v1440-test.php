<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Egne regler for ukedager (1.4.40), suiten `henting-ukedagsregler`. Lagring
// via /v1414-lagre, admin-siden via /v1414-side, optionene via /opt.
if (!defined('ABSPATH')) exit;

// «Nå» for fristen: optionen amendo_test_naa (Unix-tid), ellers den ekte tiden.
add_filter('amendo_henting_naa', function($naa) {
    $t = get_option('amendo_test_naa', '');
    return is_numeric($t) ? (int) $t : $naa;
});

add_action('rest_api_init', function() {
    $r = function($path, $cb) {
        register_rest_route('amendo-test/v1', $path, ['methods' => 'GET,POST', 'callback' => $cb, 'permission_callback' => '__return_true']);
    };

    /**
     * Kategoriene Kaker, Kaker › Bløtkaker, Kaker › Bløtkaker › Marsipan og
     * Brød, og produktene kake, blotkake, marsipan, brod, begge (Kaker + Brød),
     * variabel (Kaker, med én variant) og ukategorisert. Én gang.
     */
    $r('/v1440-oppsett', function() {
        if (($ids = get_option('amendo_test_v1440')) && wc_get_product($ids['kake'] ?? 0)) return $ids;
        $kat = function($navn, $forelder = 0) {
            $t = term_exists($navn, 'product_cat', $forelder) ?: wp_insert_term($navn, 'product_cat', ['parent' => $forelder]);
            return (int) $t['term_id'];
        };
        $ids = ['kaker' => $kat('Kaker v1440')];
        $ids['blotkaker'] = $kat('Bløtkaker v1440', $ids['kaker']);
        $ids['marsipankaker'] = $kat('Marsipan v1440', $ids['blotkaker']);
        $ids['brodkat'] = $kat('Brød v1440');
        $ids['bakst'] = $kat('Bakst v1440');
        $prod = function($navn, $kat) {
            $p = new WC_Product_Simple();
            $p->set_name($navn); $p->set_regular_price('100'); $p->set_status('publish');
            if ($kat) $p->set_category_ids($kat);
            return $p->save();
        };
        $ids['kake'] = $prod('Sjokoladekake', [$ids['kaker']]);
        $ids['blotkake'] = $prod('Bløtkake', [$ids['blotkaker']]);
        $ids['marsipan'] = $prod('Marsipankake', [$ids['marsipankaker']]);
        $ids['brod'] = $prod('Grovbrød', [$ids['brodkat']]);
        $ids['bolle'] = $prod('Skolebolle', [$ids['bakst']]);
        $ids['begge'] = $prod('Kake og brød', [$ids['brodkat'], $ids['kaker']]);
        $ids['ukategorisert'] = $prod('Uten kategori', []);
        $v = new WC_Product_Variable();
        $v->set_name('Variabel kake'); $v->set_status('publish'); $v->set_category_ids([$ids['kaker']]);
        $attr = new WC_Product_Attribute();
        $attr->set_name('Størrelse'); $attr->set_options(['Liten', 'Stor']); $attr->set_variation(true); $attr->set_visible(true);
        $v->set_attributes([$attr]);
        $ids['variabel'] = $v->save();
        $var = new WC_Product_Variation();
        $var->set_parent_id($ids['variabel']); $var->set_attributes([sanitize_title('Størrelse') => 'Liten']); $var->set_regular_price('200'); $var->set_status('publish');
        $ids['variant'] = $var->save();
        update_option('amendo_test_v1440', $ids, false);
        return $ids;
    });

    /**
     * En ordre via ekte wc/v3/orders, som kassen: { meta: {nøkkel: verdi},
     * varer: [{product_id, variation_id?}], id? }. Med id: PUT.
     * → { status, kode, melding, data, id, nye }
     */
    $r('/v1440-ordre', function($req) {
        $p = $req->get_json_params() ?: [];
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        $meta = [];
        foreach ((array) ($p['meta'] ?? []) as $k => $v) $meta[] = ['key' => $k, 'value' => $v];
        $linjer = [];
        foreach ((array) ($p['varer'] ?? []) as $v) $linjer[] = array_filter(['product_id' => (int) ($v['product_id'] ?? 0), 'variation_id' => (int) ($v['variation_id'] ?? 0), 'quantity' => 1, 'name' => $v['name'] ?? null]);
        $foer = wc_get_orders(['limit' => -1, 'return' => 'ids']);
        $id = (int) ($p['id'] ?? 0);
        $kall = new WP_REST_Request($id ? 'PUT' : 'POST', '/wc/v3/orders' . ($id ? '/' . $id : ''));
        $kall->set_header('content-type', 'application/json');
        $kropp = ['status' => 'pending', 'meta_data' => $meta];
        if ($linjer) $kropp['line_items'] = $linjer;
        $kall->set_body(wp_json_encode($kropp));
        $svar = rest_do_request($kall);
        $data = $svar->get_data();
        $etter = wc_get_orders(['limit' => -1, 'return' => 'ids']);
        wp_set_current_user(0);
        return [
            'status'  => $svar->get_status(),
            'kode'    => $data['code'] ?? null,
            'melding' => $data['message'] ?? null,
            'data'    => $data['data'] ?? null,
            'id'      => $svar->get_status() < 300 ? (int) ($data['id'] ?? 0) : null,
            'nye'     => count(array_diff($etter, $foer)),
        ];
    });

    /** Optionen som den står i databasen. */
    $r('/v1440-tilstand', function() {
        return ['regler' => get_option('amendo_henting_ukedagsregler', null), 'tz' => get_option('timezone_string', ''), 'offset' => get_option('gmt_offset', 0)];
    });

    /** amendo_ukedagsregel_brudd() direkte: { dato, produkter, naa } → grunn/frist. */
    $r('/v1440-brudd', function($req) {
        $p = $req->get_json_params() ?: [];
        $b = amendo_ukedagsregel_brudd((string) ($p['dato'] ?? ''), (array) ($p['produkter'] ?? []), isset($p['naa']) ? (int) $p['naa'] : null);
        // Alltid et objekt: null fra en REST-rute gir tomt svar.
        return ['brudd' => $b ? ['grunn' => $b['grunn'], 'frist' => isset($b['frist']) ? $b['frist']->format('c') : null, 'utenfor' => $b['utenfor'] ?? null] : null];
    });
});
