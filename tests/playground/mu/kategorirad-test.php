<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
if (!defined('ABSPATH')) exit;
// Tester for 1.4.6: kategoriraden.

add_action('rest_api_init', function() {
    register_rest_route('amendo-test/v1', '/kategorirad', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'amendo_test_kategorirad']);
    register_rest_route('amendo-test/v1', '/kategorirad-oppsett', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'amendo_test_kategorirad_oppsett']);
});

/** Kategorier og produkter; idempotent. */
function amendo_test_kategorirad_oppsett() {
    if ($k = get_option('amendo_test_kat')) return $k;
    $lag = function($navn, $parent = 0) {
        $t = wp_insert_term($navn, 'product_cat', ['parent' => $parent]);
        return is_wp_error($t) ? $t->get_error_data() : $t['term_id'];
    };
    $k = [
        'ringer'   => $lag('Ringer'),
        'oredobber'=> $lag('Øredobber'),
        'smykker'  => $lag('Smykker & klokker'),
        'merker'   => $lag('Merker'),
        'epoker'   => $lag('Epoker'),
        'salg'     => $lag('Salg'),
    ];
    $k['gullringer'] = $lag('Gullringer', $k['ringer']);
    $k['tag'] = wp_insert_term('Bare en tagg', 'post_tag')['term_id'];
    foreach ([[$k['ringer']], [$k['ringer']], [$k['gullringer']]] as $i => $kat) {
        $p = new WC_Product_Simple();
        $p->set_name('Ring ' . $i); $p->set_regular_price('100'); $p->set_status('publish'); $p->set_category_ids($kat);
        $p->save();
    }
    if (function_exists('wc_recount_all_terms')) wc_recount_all_terms();
    update_option('amendo_test_kat', $k);
    return $k;
}

function amendo_test_kategorirad() {
    $k = amendo_test_kategorirad_oppsett();
    wp_set_current_user(get_user_by('login', 'admin')->ID);
    $http = [];
    add_filter('pre_http_request', function($pre, $args, $url) use (&$http) {
        $http[] = json_decode($args['body'], true)['tags'] ?? null;
        return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }, 10, 3);
    update_option('amendo_frontend_url', 'https://emmk-frontend.vercel.app');
    update_option('amendo_revalidate_secret', str_repeat('c', 64));

    $lagre = function($post) {
        $_POST = $post + ['action' => 'amendo_save_settings'];
        $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('amendo_settings_nonce'), '_wp_http_referer' => '/'];
        $kast = function() { throw new Exception('redirect'); };
        add_filter('wp_redirect', $kast);
        try { do_action('admin_post_amendo_save_settings'); } catch (Exception $e) {}
        remove_filter('wp_redirect', $kast);
        return json_decode((string) get_option('amendo_kategorirad'), true);
    };
    $settings = function() { return rest_do_request(new WP_REST_Request('GET', '/amendo-settings/v1/settings'))->get_data(); };
    $ut = [];

    delete_option('amendo_kategorirad');
    $ut['standard_tom_liste'] = $settings()['kategorirad'] === [];

    $lagret = $lagre(['kategorirad' => [
        'finnes' => '1',
        'rad' => [
            ['term_id' => (string) $k['ringer'], 'kortnavn' => 'Ring<b>x</b>', 'uthevet' => '1'],
            ['term_id' => (string) $k['oredobber'], 'kortnavn' => ''],
            ['term_id' => '999999', 'kortnavn' => 'finnes ikke'],
            ['term_id' => (string) $k['tag'], 'kortnavn' => 'post_tag'],
            ['term_id' => (string) $k['smykker'], 'kortnavn' => str_repeat('x', 60)],
        ],
        'panel' => [
            ['term_id' => (string) $k['merker'], 'kortnavn' => 'Merk'],
            ['term_id' => (string) $k['ringer'], 'kortnavn' => 'duplikat'],
            ['term_id' => (string) $k['epoker'], 'kortnavn' => ''],
        ],
    ]]);
    $ut['lagret_bare_ider'] = $lagret === [
        'rad' => [
            ['term_id' => $k['ringer'], 'kortnavn' => 'Ringx', 'uthevet' => true],
            ['term_id' => $k['oredobber'], 'kortnavn' => '', 'uthevet' => false],
            ['term_id' => $k['smykker'], 'kortnavn' => str_repeat('x', 40), 'uthevet' => false],
        ],
        'panel' => [
            ['term_id' => $k['merker'], 'kortnavn' => 'Merk'],
            ['term_id' => $k['epoker'], 'kortnavn' => ''],
        ],
    ];
    $ut['lagret_raa'] = $lagret;
    $ut['revalidering_innstillinger'] = count($http) === 1 && in_array('innstillinger', $http[0] ?? [], true);

    $s = $settings();
    $kr = $s['kategorirad'];
    $ut['meny_fortsatt_liste'] = is_array($s['meny']) && array_values($s['meny']) === $s['meny'];
    $ut['rekkefolge_uthevet_sist_panel_til_slutt'] = array_column($kr, 'term_id') === [$k['oredobber'], $k['smykker'], $k['ringer'], $k['merker'], $k['epoker']];
    $ut['nokler'] = array_keys($kr[0] ?? []) === ['term_id', 'slug', 'navn', 'kortnavn', 'uthevet', 'bare_panel'];
    $ut['navn_og_slug'] = ($kr[1]['navn'] ?? '') === 'Smykker & klokker' && ($kr[1]['slug'] ?? '') === 'smykker-klokker';
    $ut['uthevet_flagg'] = ($kr[2]['uthevet'] ?? null) === true && ($kr[0]['uthevet'] ?? null) === false;
    $ut['bare_panel_flagg'] = ($kr[3]['bare_panel'] ?? null) === true && ($kr[2]['bare_panel'] ?? null) === false && ($kr[3]['uthevet'] ?? null) === false;
    $ut['kortnavn'] = ($kr[2]['kortnavn'] ?? null) === 'Ringx' && ($kr[3]['kortnavn'] ?? null) === 'Merk';
    $ut['typer'] = is_int($kr[0]['term_id']) && is_string($kr[0]['slug']) && is_bool($kr[0]['bare_panel']);
    $ut['settings_kategorirad'] = $kr;

    // Nytt navn og ny slug i WooCommerce: fortsatt med, med nye verdier.
    wp_update_term($k['ringer'], 'product_cat', ['name' => 'Fingerringer', 'slug' => 'fingerringer']);
    $kr = $settings()['kategorirad'];
    $ringer = array_values(array_filter($kr, fn($e) => $e['term_id'] === $k['ringer']))[0] ?? null;
    $ut['nytt_navn_slug_slaas_opp'] = $ringer && $ringer['navn'] === 'Fingerringer' && $ringer['slug'] === 'fingerringer' && $ringer['uthevet'] === true;
    wp_update_term($k['ringer'], 'product_cat', ['name' => 'Ringer', 'slug' => 'ringer']);

    // Admin: toppnivå med antall, ikke underkategorier; rader og mal.
    ob_start(); amendo_settings_page(); $html = ob_get_clean();
    $ut['admin_seksjon'] = strpos($html, 'id="kategorirad"') !== false && strpos($html, 'name="kategorirad[finnes]"') !== false;
    $ut['admin_toppnivaa_med_antall'] = (bool) preg_match('/<option value="' . $k['ringer'] . '"[^>]*>Ringer \((\d+)\)<\/option>/', $html, $m);
    $ut['admin_antall_inkl_underkategori'] = ($m[1] ?? null) === (function_exists('wc_recount_all_terms') ? '3' : '2');
    $ut['admin_antall_funnet'] = $m[1] ?? null;
    $ut['admin_ikke_underkategori'] = strpos($html, '>Gullringer (') === false;
    $ut['admin_rad_med_navn'] = (bool) preg_match('/name="kategorirad\[rad\]\[0\]\[term_id\]" value="' . $k['ringer'] . '"/', $html);
    $ut['admin_uthevet_avkrysset'] = (bool) preg_match('/name="kategorirad\[rad\]\[0\]\[uthevet\]" value="1"\s+checked/', $html);
    $ut['admin_panel_uten_uthev'] = strpos($html, 'kategorirad[panel][0][uthevet]') === false;
    $ut['admin_maler'] = strpos($html, 'name="kategorirad[rad][__i__][term_id]"') !== false && strpos($html, 'name="kategorirad[panel][__i__][term_id]"') !== false;
    $ut['admin_navn_escapet'] = strpos($html, 'Smykker &amp; klokker') !== false && strpos($html, 'Smykker & klokker') === false;

    // Slettet kategori: borte fra svaret og admin, fjernes ved neste lagring.
    wp_delete_term($k['oredobber'], 'product_cat');
    $kr = $settings()['kategorirad'];
    $ut['slettet_borte_fra_svar'] = !in_array($k['oredobber'], array_column($kr, 'term_id'), true) && count($kr) === 4;
    ob_start(); amendo_settings_page(); $html = ob_get_clean();
    $ut['slettet_borte_fra_admin'] = strpos($html, 'value="' . $k['oredobber'] . '"') === false;
    $lagret = $lagre(['kategorirad' => ['finnes' => '1', 'rad' => array_map(fn($r) => ['term_id' => (string) $r['term_id']], amendo_kategorirad_lagret()['rad'])]]);
    $ut['slettet_fjernet_ved_lagring'] = !in_array($k['oredobber'], array_column($lagret['rad'], 'term_id'), true);
    update_option('amendo_test_kat', array_merge($k, ['oredobber' => amendo_test_kategorirad_lag_igjen('Øredobber')]));

    // Andre skjema uten markør: urørt. Med markør og tomme lister: tømt.
    $foer = get_option('amendo_kategorirad');
    $lagre(['butikk_navn' => 'X']);
    $ut['uten_markor_urort'] = get_option('amendo_kategorirad') === $foer;
    $lagret = $lagre(['kategorirad' => ['finnes' => '1']]);
    $ut['markor_tom_tommer'] = $lagret === ['rad' => [], 'panel' => []];

    // Skriptavhengigheter for dra-og-slipp.
    // wp_enqueue_media() kan feile utenfor wp-admin; skriptet vårt er registrert før den.
    // Bare vår egen krok: andre plugins' admin_enqueue_scripts krever
    // get_current_screen(), som ikke finnes i REST.
    global $wp_filter;
    foreach ($wp_filter['admin_enqueue_scripts']->callbacks as $cbs) foreach ($cbs as $cb) {
        if (!$cb['function'] instanceof Closure) continue;
        $fil = (new ReflectionFunction($cb['function']))->getFileName();
        if (substr(str_replace(chr(92), '/', $fil), -strlen('amendo-settings/amendo-settings.php')) !== 'amendo-settings/amendo-settings.php') continue;
        try { $cb['function']('toplevel_page_amendo-settings'); } catch (Throwable $e) { $ut['enqueue_feil'] = $e->getMessage(); }
    }
    $deps = wp_scripts()->registered['amendo-settings']->deps ?? [];
    $ut['dra_avhengigheter'] = in_array('jquery-ui-sortable', $deps, true) && in_array('jquery-touch-punch', $deps, true);

    // La ett oppsett ligge igjen til jsdom-testen.
    $k = get_option('amendo_test_kat');
    $lagre(['kategorirad' => ['finnes' => '1', 'rad' => [['term_id' => (string) $k['ringer']], ['term_id' => (string) $k['smykker']]], 'panel' => [['term_id' => (string) $k['merker']]]]]);
    delete_option('amendo_frontend_url');
    return $ut;
}

function amendo_test_kategorirad_lag_igjen($navn) {
    $t = wp_insert_term($navn, 'product_cat');
    return is_wp_error($t) ? $t->get_error_data() : $t['term_id'];
}

add_action('rest_api_init', function() {
    register_rest_route('amendo-test/v1', '/kategorirad-reval', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'amendo_test_kategorirad_reval']);
});

/** Ett scenario per forespørsel (maks ett varsel per forespørsel). */
function amendo_test_kategorirad_reval(WP_REST_Request $req) {
    $k = amendo_test_kategorirad_oppsett();
    $http = [];
    add_filter('pre_http_request', function($pre, $args, $url) use (&$http) {
        $http[] = json_decode($args['body'], true)['tags'] ?? null;
        return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }, 10, 3);
    $id = function($navn) { $t = get_term_by('name', $navn, 'product_cat'); return $t ? $t->term_id : 0; };

    switch ($req->get_param('sak')) {
        case 'klargjor':
            update_option('amendo_frontend_url', 'https://emmk-frontend.vercel.app');
            update_option('amendo_revalidate_secret', str_repeat('d', 64));
            $midl1 = wp_insert_term('Midlertidig i rad ' . wp_rand(), 'product_cat')['term_id'];
            $midl2 = wp_insert_term('Midlertidig utenfor ' . wp_rand(), 'product_cat')['term_id'];
            update_option('test_midl', [$midl1, $midl2]);
            update_option('amendo_kategorirad', wp_json_encode([
                'rad' => [['term_id' => $k['ringer'], 'kortnavn' => '', 'uthevet' => false], ['term_id' => $midl1, 'kortnavn' => '', 'uthevet' => false]],
                'panel' => [['term_id' => $k['merker'], 'kortnavn' => '']],
            ]));
            wp_clear_scheduled_hook('amendo_revalider_frontend');
            delete_option('amendo_revalider_ventende');
            return ['ok' => true];
        case 'nytt_navn_i_rad':
            wp_update_term($k['ringer'], 'product_cat', ['name' => 'Ringer ' . wp_rand()]); break;
        case 'ny_slug_i_panel':
            wp_update_term($k['merker'], 'product_cat', ['slug' => 'merker-' . wp_rand()]); break;
        case 'bare_beskrivelse':
            wp_update_term($k['ringer'], 'product_cat', ['description' => 'Ny beskrivelse ' . wp_rand()]); break;
        case 'nytt_navn_utenfor':
            wp_update_term($k['salg'], 'product_cat', ['name' => 'Salg ' . wp_rand()]); break;
        case 'to_i_rad':
            wp_update_term($k['ringer'], 'product_cat', ['name' => 'Ringer ' . wp_rand()]);
            wp_update_term($k['merker'], 'product_cat', ['name' => 'Merker ' . wp_rand()]); break;
        case 'slett_i_rad':
            wp_delete_term(get_option('test_midl')[0], 'product_cat'); break;
        case 'slett_utenfor':
            wp_delete_term(get_option('test_midl')[1], 'product_cat'); break;
        case 'rydd':
            wp_update_term($k['ringer'], 'product_cat', ['name' => 'Ringer', 'slug' => 'ringer', 'description' => '']);
            wp_update_term($k['merker'], 'product_cat', ['name' => 'Merker', 'slug' => 'merker']);
            wp_update_term($k['salg'], 'product_cat', ['name' => 'Salg', 'slug' => 'salg']);
            delete_option('amendo_frontend_url');
            // Tilbake til oppsettet jsdom-testen forventer.
            update_option('amendo_kategorirad', wp_json_encode([
                'rad' => [['term_id' => $k['ringer'], 'kortnavn' => '', 'uthevet' => false], ['term_id' => $k['smykker'], 'kortnavn' => '', 'uthevet' => false]],
                'panel' => [['term_id' => $k['merker'], 'kortnavn' => '']],
            ]));
            return ['ok' => true];
        default:
            return ['feil' => 'ukjent sak'];
    }
    return ['http' => $http, 'purge_ventende' => get_option('amendo_revalider_ventende', null)];
}
