<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
//
// Hjelperuter for skjulte varianter på Helsebygget (1.4.21), suiten
// `helsebygget`. Se includes/helsebygget.php.
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function() {
    $r = function($sti, $cb) {
        register_rest_route('amendo-test/v1', $sti, ['methods' => 'GET,POST', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };

    /** En variasjon som lages og slettes for godt: ID-en finnes ikke lenger. */
    $r('/v1421-slettet-variasjon', function() {
        $ids = get_option('amendo_test_ids');
        $v = new WC_Product_Variation();
        $v->set_parent_id((int) $ids['kake_id']); $v->set_regular_price('700'); $v->set_status('publish');
        $id = $v->save();
        wp_delete_post($id, true);
        return ['id' => $id, 'finnes' => (bool) get_post($id)];
    });

    /** Optionen slik den er lagret, autoload for begge, og om variasjonen fortsatt er kjøpbar. */
    $r('/v1421-tilstand', function() {
        global $wpdb;
        $ids = get_option('amendo_test_ids');
        $autoload = function($navn) use ($wpdb) {
            return $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $navn));
        };
        $v1 = wc_get_product((int) $ids['v1_id']);
        return [
            'skjulte_raa'        => get_option('amendo_helsebygget_skjulte_varianter', null),
            'autoload_skjulte'   => $autoload('amendo_helsebygget_skjulte_varianter'),
            'autoload_produkter' => $autoload('amendo_helsebygget_produkter'),
            'v1_kjopbar'         => $v1 ? $v1->is_purchasable() : null,
            'v1_synlig'          => $v1 ? $v1->variation_is_visible() : null,
        ];
    });
});
