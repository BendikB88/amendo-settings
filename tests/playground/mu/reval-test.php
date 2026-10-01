<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
if (!defined('ABSPATH')) exit;
// Tester for revalidering ved lagring. Kun for Playground.
// LiteSpeed etterlignes med LSCWP_V når stub_lscwp = 1 (settes i forrige forespørsel).
if (get_option('stub_lscwp') === '1' && !defined('LSCWP_V')) define('LSCWP_V', 'stub');

add_action('rest_api_init', function() {
    register_rest_route('amendo-test/v1', '/reval', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'amendo_test_reval']);
});

function amendo_test_reval() {
    $admin = get_user_by('login', 'admin')->ID;
    wp_set_current_user($admin);

    $http = [];
    add_filter('pre_http_request', function($pre, $args, $url) use (&$http) {
        $http[] = ['url' => $url, 'args' => $args];
        return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }, 10, 3);
    $purge = [];
    add_action('litespeed_purge', function($tag) use (&$purge) { $purge[] = 'purge:' . $tag; });
    add_action('litespeed_purge_all', function() use (&$purge) { $purge[] = 'purge_all'; });

    $lagre = function($post) {
        $_POST = $post + ['action' => 'amendo_save_settings'];
        $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('amendo_settings_nonce'), '_wp_http_referer' => '/'];
        $til = null;
        $kast = function($loc) use (&$til) { $til = $loc; throw new Exception('redirect'); };
        add_filter('wp_redirect', $kast);
        $start = microtime(true);
        try { do_action('admin_post_amendo_save_settings'); } catch (Exception $e) {}
        remove_filter('wp_redirect', $kast);
        return ['til' => $til, 'ms' => (microtime(true) - $start) * 1000];
    };
    $side = function($get = []) { $_GET = $get; ob_start(); amendo_settings_page(); $h = ob_get_clean(); $_GET = []; return $h; };
    $ut = ['litespeed_aktiv' => defined('LSCWP_V') ? 'ja' : 'nei'];

    wp_clear_scheduled_hook('amendo_revalider_frontend');
    delete_option('amendo_frontend_url');
    delete_option('amendo_revalidate_secret');
    update_option('amendo_kasse_secret', 'kasse-uendret');

    // Ikke satt opp.
    $http = []; $purge = [];
    $r = $lagre(['butikk_navn' => 'A']);
    $ut['ikke_satt_opp_ingen_http'] = $http === [];
    $ut['ikke_satt_opp_ingen_melding'] = strpos((string) $r['til'], 'oppdateres=1') === false && strpos((string) $r['til'], 'saved=1') !== false;
    $ut['ikke_satt_opp_ingen_cron'] = wp_next_scheduled('amendo_revalider_frontend') === false;
    $ut['litespeed_purge_REST_ogsaa_uten_frontend'] = defined('LSCWP_V') ? $purge === ['purge:REST'] : $purge === [];

    // URL-validering.
    $url = function($inn) use ($lagre) { $lagre(['frontend_url' => $inn]); return get_option('amendo_frontend_url'); };
    $ut['url_https_skraastrek_fjernes'] = $url('https://emmk-frontend.vercel.app/') === 'https://emmk-frontend.vercel.app';
    $ut['url_mellomrom_og_sti'] = $url('  https://x.example/sti/  ') === 'https://x.example/sti';
    $ut['url_http_avvises'] = $url('http://emmk.example') === '';
    $ut['url_javascript_avvises'] = $url('javascript:alert(1)') === '';
    $ut['url_localhost_http_tillatt'] = $url('http://localhost:3000') === 'http://localhost:3000';
    $ut['url_tom'] = $url('') === '';
    $url('https://emmk-frontend.vercel.app');
    $lagre(['butikk_navn' => 'B']);
    $ut['url_annen_fane_uten_felt_urort'] = get_option('amendo_frontend_url') === 'https://emmk-frontend.vercel.app';

    // URL uten secret: ingen HTTP.
    $http = [];
    $r = $lagre(['butikk_navn' => 'C']);
    $ut['url_uten_secret_ingen_http'] = $http === [] && strpos($r['til'], 'oppdateres=1') === false;

    // Revaliderings-secret: generer.
    $lagre(['amendo_ny_revalidering_secret' => '1']);
    $sec = get_option('amendo_revalidate_secret');
    $ut['revalidering_secret_64_hex'] = (bool) preg_match('/^[0-9a-f]{64}$/', (string) $sec);
    $ut['kasse_urort_av_revalidering'] = get_option('amendo_kasse_secret') === 'kasse-uendret';
    $h1 = $side(); $h2 = $side();
    $ut['revalidering_vises_en_gang'] = substr_count($h1, $sec) === 1 && strpos($h2, $sec) === false;
    $lagre(['butikk_navn' => 'D', 'amendo_ny_revalidering_secret' => '0']);
    $ut['revalidering_secret_urort_ved_lagring'] = get_option('amendo_revalidate_secret') === $sec;
    $lagre(['amendo_ny_kasse_secret' => '1']);
    $ut['kasse_generering_rorer_ikke_revalidering'] = get_option('amendo_revalidate_secret') === $sec && get_option('amendo_kasse_secret') !== 'kasse-uendret';

    // Satt opp: én POST med riktig innhold, melding, cron.
    wp_clear_scheduled_hook('amendo_revalider_frontend');
    $http = []; $purge = [];
    $r = $lagre(['butikk_navn' => 'E']);
    $kall = $http[0] ?? null;
    $ut['en_post'] = count($http) === 1;
    $ut['post_url'] = $kall && $kall['url'] === 'https://emmk-frontend.vercel.app/api/revalidate';
    $ut['post_metode'] = $kall && $kall['args']['method'] === 'POST';
    $ut['post_header_secret'] = $kall && ($kall['args']['headers']['X-Revalidate-Secret'] ?? null) === $sec;
    $ut['post_content_type'] = $kall && ($kall['args']['headers']['Content-Type'] ?? null) === 'application/json';
    $ut['post_body'] = $kall && $kall['args']['body'] === '{"tags":["innstillinger","forside","side"]}';
    $ut['post_timeout_5'] = $kall && (int) $kall['args']['timeout'] === 5;
    $ut['post_ikke_blokkerende'] = $kall && $kall['args']['blocking'] === false;
    $ut['melding_i_redirect'] = strpos($r['til'], 'saved=1&oppdateres=1') !== false;
    $neste = wp_next_scheduled('amendo_revalider_frontend');
    $ut['oppfolging_om_30s'] = $neste !== false && $neste - time() >= 25 && $neste - time() <= 31;
    $ut['litespeed_purge_REST_ikke_alt'] = defined('LSCWP_V') ? $purge === ['purge:REST'] : $purge === [];

    $lagre(['butikk_navn' => 'F']);
    $crons = 0;
    foreach (_get_cron_array() as $tid => $hooks) if (isset($hooks['amendo_revalider_frontend'])) $crons += count($hooks['amendo_revalider_frontend']);
    $ut['to_lagringer_en_oppfolging'] = $crons === 1;

    // Oppfølgingen via cron sender samme POST.
    $http = [];
    do_action('amendo_revalider_frontend');
    $ut['cron_sender_samme_post'] = count($http) === 1 && $http[0]['url'] === 'https://emmk-frontend.vercel.app/api/revalidate' && $http[0]['args']['headers']['X-Revalidate-Secret'] === $sec;

    // Meldingen og feltene på siden.
    $html = $side(['saved' => '1', 'oppdateres' => '1']);
    $ut['melding_vises'] = strpos($html, 'Nettsiden oppdateres i løpet av et minutt') !== false;
    $ut['melding_ikke_uten_flagg'] = strpos($side(['saved' => '1']), 'Nettsiden oppdateres i løpet av et minutt') === false;
    $ut['felt_frontend_url'] = (bool) preg_match('/name="frontend_url"[^>]*value="https:\/\/emmk-frontend\.vercel\.app"/', $html);
    $ut['kort_revalidering_og_kasse'] = strpos($html, 'data-hemmelighet="revalidering"') !== false && strpos($html, 'data-hemmelighet="kasse"') !== false;
    $ut['secret_ikke_i_siden'] = strpos($html, $sec) === false;

    // Lagring som tar tid? Ikke-blokkerende og ingen venting: under 2 s selv i Playground.
    $ut['lagring_ms'] = round($r['ms']);

    wp_clear_scheduled_hook('amendo_revalider_frontend');
    update_option('amendo_kasse_secret', 'hemmelig-test-123');
    return $ut;
}
