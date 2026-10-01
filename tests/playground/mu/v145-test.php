<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
if (!defined('ABSPATH')) exit;
// Tester for 1.4.5: bilde-ID/-mål på forsiden, og sider som varsler frontenden.

add_action('rest_api_init', function() {
    $r = function($path, $cb) {
        register_rest_route('amendo-test/v1', $path, ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => $cb]);
    };
    $r('/bilder', 'amendo_test_bilder');
    $r('/bilder-legacy', 'amendo_test_bilder_legacy');
    $r('/side-reval', 'amendo_test_side_reval');
});

/** Vedlegg uten fil: _wp_attached_file + metadata er det attachment_url_to_postid og målene trenger. */
function amendo_test_vedlegg($fil, $b, $h) {
    $id = wp_insert_attachment(['post_title' => $fil, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], false, 0);
    update_post_meta($id, '_wp_attached_file', '2026/09/' . $fil);
    wp_update_attachment_metadata($id, ['width' => $b, 'height' => $h, 'file' => '2026/09/' . $fil, 'sizes' => []]);
    return [$id, wp_get_attachment_url($id)];
}

function amendo_test_forside_lagre($forside) {
    wp_set_current_user(get_user_by('login', 'admin')->ID);
    $_POST = ['action' => 'amendo_save_settings', 'forside' => $forside];
    $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('amendo_settings_nonce'), '_wp_http_referer' => '/'];
    $kast = function() { throw new Exception('redirect'); };
    add_filter('wp_redirect', $kast);
    try { do_action('admin_post_amendo_save_settings'); } catch (Exception $e) {}
    remove_filter('wp_redirect', $kast);
    return json_decode(get_option('amendo_forside'), true);
}

function amendo_test_rest_forside() {
    $res = rest_do_request(new WP_REST_Request('GET', '/amendo-settings/v1/side/forside'));
    return $res->get_data()['fields'] ?? [];
}

function amendo_test_bilder() {
    [$h_id, $h_url] = amendo_test_vedlegg('hero-' . wp_rand() . '.jpg', 1600, 900);
    [$o_id, $o_url] = amendo_test_vedlegg('omoss-' . wp_rand() . '.jpg', 800, 1000);
    [$k_id, $k_url] = amendo_test_vedlegg('kort-' . wp_rand() . '.jpg', 600, 400);
    $ut = [];

    // A: valgt fra biblioteket (ID sendt med).
    $lagret = amendo_test_forside_lagre([
        'hero_tittel' => 'Hei', 'hero_bilde' => $h_url, 'hero_bilde_id' => (string) $h_id,
        'hero_video' => 'https://cdn.example/v.mp4',
        'omoss_bilde' => $o_url, 'omoss_bilde_id' => '',
        'features_kort' => [['tittel' => 'K', 'bilde' => $k_url, 'bilde_id' => (string) $k_id]],
    ]);
    $ut['A_hero_id_lagret'] = ($lagret['hero_bilde_id'] ?? null) === $h_id;
    $ut['B_manuell_url_slaas_opp'] = ($lagret['omoss_bilde_id'] ?? null) === $o_id;
    $ut['A_kort_id_lagret'] = ($lagret['features_kort'][0]['bilde_id'] ?? null) === $k_id;
    $ut['G_video_uten_id'] = !array_key_exists('hero_video_id', $lagret);

    $f = amendo_test_rest_forside();
    $ut['REST_hero_mal'] = ($f['hero_bilde_bredde'] ?? null) === 1600 && ($f['hero_bilde_hoyde'] ?? null) === 900;
    $noekler = array_keys($f);
    $i = array_search('hero_bilde', $noekler, true);
    $ut['REST_rekkefolge'] = array_slice($noekler, $i, 4) === ['hero_bilde', 'hero_bilde_id', 'hero_bilde_bredde', 'hero_bilde_hoyde'];
    $ut['REST_omoss_mal'] = ($f['omoss_bilde_bredde'] ?? null) === 800 && ($f['omoss_bilde_hoyde'] ?? null) === 1000;
    $ut['REST_kort_mal'] = ($f['features_kort'][0]['bilde_bredde'] ?? null) === 600 && ($f['features_kort'][0]['bilde_hoyde'] ?? null) === 400;
    $ut['REST_url_uendret'] = ($f['hero_bilde'] ?? null) === $h_url;
    $ut['REST_video_uten_mal'] = !array_key_exists('hero_video_bredde', $f);

    // C: gammel ID, men URL-en er byttet for hånd → den nye URL-ens ID.
    $lagret = amendo_test_forside_lagre(['hero_tittel' => 'Hei', 'hero_bilde' => $o_url, 'hero_bilde_id' => (string) $h_id]);
    $ut['C_foreldet_id_erstattes'] = ($lagret['hero_bilde_id'] ?? null) === $o_id;

    // D: ekstern URL → 0, og ingen mål i REST.
    $lagret = amendo_test_forside_lagre(['hero_tittel' => 'Hei', 'hero_bilde' => 'https://cdn.example/bilde.jpg', 'hero_bilde_id' => '']);
    $ut['D_ekstern_id_0'] = ($lagret['hero_bilde_id'] ?? null) === 0;
    $f = amendo_test_rest_forside();
    $ut['D_ekstern_uten_mal'] = !array_key_exists('hero_bilde_bredde', $f) && !array_key_exists('hero_bilde_hoyde', $f);

    // D2: ID som ikke finnes, med URL som ikke er i biblioteket → 0.
    $lagret = amendo_test_forside_lagre(['hero_tittel' => 'Hei', 'hero_bilde' => 'https://cdn.example/b.jpg', 'hero_bilde_id' => '999999']);
    $ut['D2_ukjent_id_0'] = ($lagret['hero_bilde_id'] ?? null) === 0;

    // J: vedlegget slettet etter lagring → ingen mål, ingen feil.
    [$s_id, $s_url] = amendo_test_vedlegg('slett-' . wp_rand() . '.jpg', 300, 300);
    amendo_test_forside_lagre(['hero_tittel' => 'Hei', 'hero_bilde' => $s_url, 'hero_bilde_id' => (string) $s_id]);
    wp_delete_attachment($s_id, true);
    $f = amendo_test_rest_forside();
    $ut['J_slettet_vedlegg_uten_mal'] = ($f['hero_tittel'] ?? '') === 'Hei' && !array_key_exists('hero_bilde_bredde', $f);

    // H: tomt skjema teller fortsatt som tomt.
    $lagret = amendo_test_forside_lagre([]);
    $ut['H_tomt_er_tomt'] = !amendo_forside_har_innhold($lagret) && ($lagret['hero_bilde_id'] ?? null) === 0;

    // I: skjulte ID-felt i admin.
    amendo_test_forside_lagre(['hero_tittel' => 'Hei', 'hero_bilde' => $h_url, 'hero_bilde_id' => (string) $h_id,
        'features_kort' => [['tittel' => 'K', 'bilde' => $k_url, 'bilde_id' => (string) $k_id]]]);
    ob_start(); amendo_settings_page(); $html = ob_get_clean();
    $ut['I_skjult_hero_id'] = (bool) preg_match('/type="hidden" class="amendo-media-id" name="forside\[hero_bilde_id\]" value="' . $h_id . '"/', $html);
    $ut['I_skjult_kort_id'] = (bool) preg_match('/name="forside\[features_kort\]\[0\]\[bilde_id\]" value="' . $k_id . '"/', $html);
    $ut['I_mal_har_id_felt'] = strpos($html, 'name="forside[features_kort][__i__][bilde_id]"') !== false;
    $ut['I_video_uten_id_felt'] = strpos($html, 'forside[hero_video_id]') === false;
    return $ut;
}

/** F: verdier lagret før 1.4.5 (uten *_id) — slås opp ved første lesing og lagres. */
function amendo_test_bilder_legacy() {
    [$h_id, $h_url] = amendo_test_vedlegg('lhero-' . wp_rand() . '.jpg', 1200, 800);
    [$k_id, $k_url] = amendo_test_vedlegg('lkort-' . wp_rand() . '.jpg', 500, 500);
    $gammel = amendo_forside_saniter([]);
    foreach (array_keys($gammel) as $k) if (substr($k, -3) === '_id') unset($gammel[$k]);
    $gammel['hero_tittel'] = 'Gammel';
    $gammel['hero_bilde'] = $h_url;
    $gammel['omoss_bilde'] = 'https://cdn.example/ekstern.jpg';
    $gammel['features_kort'] = [['tittel' => 'K', 'tekst' => '', 'bilde' => $k_url, 'ikon' => '', 'lenke_tekst' => '', 'lenke' => '']];
    update_option('amendo_forside', wp_json_encode($gammel), false);

    $oppslag = 0;
    add_filter('attachment_url_to_postid', function($id) use (&$oppslag) { $oppslag++; return $id; });

    $ut = ['F_forutsetning_mangler_id' => amendo_forside_mangler_bilde_id($gammel)];
    $f = amendo_test_rest_forside();
    $ut['F_forste_lesing_slaar_opp'] = $oppslag === 3;
    $ut['F_mal_forste_lesing'] = ($f['hero_bilde_bredde'] ?? null) === 1200 && ($f['features_kort'][0]['bilde_hoyde'] ?? null) === 500;
    $lagret = json_decode(get_option('amendo_forside'), true);
    $ut['F_ider_lagret'] = ($lagret['hero_bilde_id'] ?? null) === $h_id && ($lagret['features_kort'][0]['bilde_id'] ?? null) === $k_id && ($lagret['omoss_bilde_id'] ?? null) === 0;
    $ut['F_innhold_uendret'] = ($lagret['hero_tittel'] ?? '') === 'Gammel' && ($lagret['hero_bilde'] ?? '') === $h_url;

    $oppslag = 0;
    $f2 = amendo_test_rest_forside();
    ob_start(); amendo_settings_page(); ob_end_clean();
    $ut['F_ingen_nye_oppslag'] = $oppslag === 0;
    $ut['F_samme_svar'] = $f2 === $f;
    return $ut;
}

/**
 * Ett scenario per forespørsel (varselet sendes maks én gang per forespørsel).
 * ?sak=… ; svarer med fangede HTTP-kall, LiteSpeed-tømminger og cron-status.
 */
function amendo_test_side_reval(WP_REST_Request $req) {
    $sak = $req->get_param('sak');
    wp_set_current_user(get_user_by('login', 'admin')->ID);
    $http = []; $purge = [];
    add_filter('pre_http_request', function($pre, $args, $url) use (&$http) {
        $http[] = ['url' => $url, 'tags' => json_decode($args['body'], true)['tags'] ?? null];
        return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }, 10, 3);
    add_action('litespeed_purge', function($t) use (&$purge) { $purge[] = $t; });

    $side = function($status = 'publish', $type = 'page') {
        return wp_insert_post(['post_type' => $type, 'post_title' => 'T' . wp_rand(), 'post_status' => $status]);
    };
    $for_test = function() use (&$http, &$purge) { $http = []; $purge = []; };

    switch ($sak) {
        case 'klargjor':
            update_option('amendo_frontend_url', 'https://emmk-frontend.vercel.app');
            update_option('amendo_revalidate_secret', str_repeat('b', 64));
            wp_clear_scheduled_hook('amendo_revalider_frontend');
            delete_option('amendo_revalider_ventende');
            return ['ok' => true];
        case 'lag_publisert':   // brukes av de neste sakene
            $id = $side('publish');
            wp_clear_scheduled_hook('amendo_revalider_frontend');
            delete_option('amendo_revalider_ventende');
            update_option('test_side_id', $id);
            update_option('test_utkast_id', $side('draft'));
            return ['ok' => true];
        case 'lag_ekstra':      // egen forespørsel, så varselet fra opprettelsen ikke skjuler det neste
            update_option('test_ekstra_id', $side('publish'));
            wp_clear_scheduled_hook('amendo_revalider_frontend');
            delete_option('amendo_revalider_ventende');
            return ['ok' => true];
        case 'publiser_ny':
            $side('publish'); break;
        case 'oppdater':
            wp_update_post(['ID' => get_option('test_side_id'), 'post_title' => 'Ny tittel']); break;
        case 'to_oppdateringer':
            wp_update_post(['ID' => get_option('test_side_id'), 'post_title' => 'A']);
            wp_update_post(['ID' => get_option('test_side_id'), 'post_title' => 'B']); break;
        case 'oppdater_ekstra':
            wp_update_post(['ID' => get_option('test_ekstra_id'), 'post_title' => 'Ekstra ' . wp_rand()]); break;
        case 'avpubliser':
            wp_update_post(['ID' => get_option('test_side_id'), 'post_status' => 'draft']); break;
        case 'publiser_igjen':
            wp_update_post(['ID' => get_option('test_side_id'), 'post_status' => 'publish']); break;
        case 'papirkurv':
            wp_trash_post(get_option('test_side_id')); break;
        case 'slett_trashed':
            wp_delete_post(get_option('test_side_id'), true); break;
        case 'slett_publisert_direkte':
            wp_delete_post(get_option('test_ekstra_id'), true); break;
        case 'utkast_aldri_publisert':
            wp_update_post(['ID' => get_option('test_utkast_id'), 'post_title' => 'Utkast endret']);
            $side('draft'); break;
        case 'revisjon':
            require_once ABSPATH . 'wp-admin/includes/post.php'; // wp_create_post_autosave
            $id = (int) get_option('test_ekstra_id');
            _wp_put_post_revision(get_post($id));
            wp_create_post_autosave(['post_ID' => $id, 'post_title' => 'Autosave', 'post_content' => 'x', 'post_type' => 'page']); break;
        case 'innlegg':
            $side('publish', 'post'); break;
        case 'ikke_satt_opp':
            delete_option('amendo_frontend_url');
            $side('publish'); break;
        case 'cron':
            do_action('amendo_revalider_frontend'); break;
        case 'innstillinger':
            $_POST = ['action' => 'amendo_save_settings', 'butikk_navn' => 'X'];
            $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('amendo_settings_nonce'), '_wp_http_referer' => '/'];
            $kast = function() { throw new Exception('redirect'); };
            add_filter('wp_redirect', $kast);
            try { do_action('admin_post_amendo_save_settings'); } catch (Exception $e) {}
            remove_filter('wp_redirect', $kast);
            break;
        default:
            return ['feil' => 'ukjent sak'];
    }
    $crons = 0;
    foreach (_get_cron_array() ?: [] as $hooks) if (isset($hooks['amendo_revalider_frontend'])) $crons += count($hooks['amendo_revalider_frontend']);
    return [
        'http' => $http,
        'purge' => $purge,
        'cron' => $crons,
        'ventende' => get_option('amendo_revalider_ventende', null),
        'litespeed' => defined('LSCWP_V'),
    ];
}
