<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Bedriftspakker (1.4.31), suiten `bedriftspakker`. Innstillinger settes med
// v1423-sett, lagring via v1423-lagre/v1414-lagre, admin-siden via v1414-side,
// brukerne via v1428-klargjor.
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };

    /**
     * Produktene (én gang): Bolle 25 kr og Kaffe 50 kr (enkle), Sjokoladekake
     * med variantene «8 personer» (400) og «12 personer» (550), en kladd og en
     * uten pris. tellix_id på Bolle, 8 personer, kladden og den uten pris.
     * Kaffe ligger i «Kaffe og te», en underkategori av «Drikke» (reserven arves).
     */
    $r('/v1431-produkter', function() {
        $ider = get_option('amendo_test_v1431_ider');
        if (is_array($ider) && isset($ider['drikke']) && wc_get_product($ider['bolle'] ?? 0)) return $ider;
        $enkel = function($navn, $pris, $tellix, $status = 'publish') {
            $p = new WC_Product_Simple();
            $p->set_name($navn); $p->set_regular_price($pris); $p->set_status($status);
            $id = $p->save();
            if ($tellix !== '') update_post_meta($id, 'tellix_id', $tellix);
            return $id;
        };
        $drikke = wp_insert_term('Drikke', 'product_cat');
        $drikke = is_wp_error($drikke) ? (int) $drikke->get_error_data('term_exists') : (int) $drikke['term_id'];
        $kaffete = wp_insert_term('Kaffe og te', 'product_cat', ['parent' => $drikke]);
        $kaffete = is_wp_error($kaffete) ? (int) $kaffete->get_error_data('term_exists') : (int) $kaffete['term_id'];
        $ider = [
            'drikke'  => $drikke,
            'kaffete' => $kaffete,
            'bolle'   => $enkel('Bolle', '25', 'T-BOLLE'),
            'kaffe'   => $enkel('Kaffe', '50', ''),
            'kladd'   => $enkel('Kladdvare', '10', 'T-KLADD', 'draft'),
            'prislos' => $enkel('Prisløs', '', 'T-PRISLOS'),
        ];
        $attr = new WC_Product_Attribute();
        $attr->set_name('Størrelse'); $attr->set_options(['8 personer', '12 personer']); $attr->set_visible(true); $attr->set_variation(true);
        $kake = new WC_Product_Variable();
        $kake->set_name('Sjokoladekake'); $kake->set_status('publish'); $kake->set_attributes([$attr]);
        $kid = $kake->save();
        foreach ([['8 personer', '400', 'T-K8', 'k8'], ['12 personer', '550', '', 'k12']] as [$verdi, $pris, $tellix, $nokkel]) {
            $v = new WC_Product_Variation();
            $v->set_parent_id($kid); $v->set_attributes([sanitize_title('Størrelse') => $verdi]); $v->set_regular_price($pris); $v->set_status('publish');
            $ider[$nokkel] = $v->save();
            if ($tellix !== '') update_post_meta($ider[$nokkel], 'tellix_id', $tellix);
        }
        WC_Product_Variable::sync($kid);
        wp_set_object_terms($ider['kaffe'], [$kaffete], 'product_cat');
        $ider['kake'] = $kid;
        update_option('amendo_test_v1431_ider', $ider);
        return $ider;
    });

    /**
     * Gatewayens reserveprodukt for tellix_id: { drikke, standard } — en verdi
     * setter, null fjerner. `drikke` er termmetaen på overkategorien «Drikke».
     */
    $r('/v1431-reserve', function($req) {
        $p = $req->get_json_params() ?: [];
        $ider = get_option('amendo_test_v1431_ider');
        if (array_key_exists('drikke', $p)) {
            if ($p['drikke'] === null) delete_term_meta($ider['drikke'], '_amendo_tellix_fallback_id');
            else update_term_meta($ider['drikke'], '_amendo_tellix_fallback_id', (string) $p['drikke']);
        }
        if (array_key_exists('standard', $p)) {
            if ($p['standard'] === null) delete_option('amendo_tellix_fallback_product');
            else update_option('amendo_tellix_fallback_product', (string) $p['standard']);
        }
        return ['ok' => true];
    });

    /** { id, pris, tilbud } — tilbud '' fjerner tilbudsprisen. */
    $r('/v1431-pris', function($req) {
        $p = wc_get_product((int) $req->get_param('id'));
        $p->set_regular_price((string) $req->get_param('pris'));
        $p->set_sale_price((string) $req->get_param('tilbud'));
        $p->save();
        return ['pris' => $p->get_price()];
    });

    /** Pakkene slik de er lagret, og metafeltene på brukerne { ider }. */
    $r('/v1431-tilstand', function($req) {
        $brukere = [];
        foreach ((array) $req->get_param('ider') as $id) {
            $brukere[$id] = [
                'onske'  => get_user_meta((int) $id, '_amendo_bp_onske', true),
                'ref'    => get_user_meta((int) $id, '_amendo_bp_ref', true),
                'tid'    => get_user_meta((int) $id, '_amendo_bp_tid', true),
                'status' => get_user_meta((int) $id, '_b2b_status', true),
            ];
        }
        return [
            'pakker'  => json_decode((string) get_option('amendo_bedriftspakker', 'null'), true),
            'brukere' => $brukere,
        ];
    });

    /** Brukerprofilen (edit_user_profile) for { id }, sett av { rolle } (admin eller customer). */
    $r('/v1431-profil', function($req) {
        if ($req->get_param('rolle') === 'customer') {
            $u = get_user_by('login', 'f1431kunde') ?: get_user_by('id', wp_insert_user(['user_login' => 'f1431kunde', 'user_pass' => 'x', 'role' => 'customer', 'user_email' => 'f1431kunde@example.com']));
            wp_set_current_user($u->ID);
        } else {
            wp_set_current_user(get_user_by('login', 'admin')->ID);
        }
        ob_start(); do_action('edit_user_profile', get_userdata((int) $req->get_param('id'))); $html = ob_get_clean();
        wp_set_current_user(0);
        return ['html' => $html];
    });
});
