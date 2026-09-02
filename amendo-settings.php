<?php
/**
 * Plugin Name: Amendo Innstillinger
 * Description: Innstillinger for butikk, design, kontakt, avdelinger og meny
 * Version: 1.1.0
 * Author: Amendo
 */

if (!defined('ABSPATH')) exit;

define('AMENDO_SETTINGS_VERSION', '1.1.0');
define('AMENDO_SETTINGS_PATH', plugin_dir_path(__FILE__));
define('AMENDO_SETTINGS_URL', plugin_dir_url(__FILE__));

// Admin-meny
add_action('admin_menu', function() {
    add_menu_page(
        'Amendo Innstillinger',
        'Amendo',
        'manage_options',
        'amendo-settings',
        'amendo_settings_page',
        'dashicons-store',
        30
    );
});

// Advarsel i wp-admin når avdelinger mangler adresse/åpningstider
add_action('admin_notices', function() {
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'amendo') === false) return;
    if (!current_user_can('manage_options')) return;

    $avdelinger = json_decode(get_option('amendo_avdelinger', '[]'), true) ?: [];
    $mangler = [];

    foreach ($avdelinger as $avd) {
        $navn = $avd['navn'] ?: 'Ukjent avdeling';
        $feil = [];
        if (empty($avd['adresse'])) $feil[] = 'adresse';
        if (empty($avd['telefon'])) $feil[] = 'telefon';

        // Sjekk om åpningstider er standardverdier (alle 09:00-17:00 man-fre)
        $ant_aapen = 0;
        foreach ($avd['åpningstider'] ?? [] as $dag => $t) {
            if (($t['åpen'] ?? '0') === '1') $ant_aapen++;
        }
        if ($ant_aapen === 0) $feil[] = 'åpningstider (ingen åpne dager)';

        if (!empty($feil)) {
            $mangler[] = '<strong>' . esc_html($navn) . '</strong> mangler: ' . implode(', ', $feil);
        }
    }

    if (!empty($mangler)) {
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>Amendo Innstillinger:</strong> Fyll ut manglende info under <a href="' . admin_url('admin.php?page=amendo-settings#avdelinger') . '">Avdelinger</a>:<br>';
        echo implode('<br>', $mangler);
        echo '</p></div>';
    }
});

// Admin CSS/JS
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook !== 'toplevel_page_amendo-settings') return;
    wp_enqueue_style('amendo-settings', AMENDO_SETTINGS_URL . 'assets/admin.css', [], AMENDO_SETTINGS_VERSION);
    wp_enqueue_script('amendo-settings', AMENDO_SETTINGS_URL . 'assets/admin.js', ['jquery'], AMENDO_SETTINGS_VERSION, true);
    wp_enqueue_media();
});

// Lagre innstillinger
add_action('admin_post_amendo_save_settings', function() {
    if (!current_user_can('manage_options')) wp_die('Ingen tilgang');
    check_admin_referer('amendo_settings_nonce');

    $fields = ['butikk_navn', 'butikk_slagord', 'butikk_logo', 'design_primærfarge', 'design_sekundærfarge', 'kontakt_telefon', 'kontakt_epost', 'sosiale_instagram', 'sosiale_facebook', 'sosiale_tiktok'];
    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            update_option('amendo_' . $field, sanitize_text_field($_POST[$field]));
        }
    }

    // Meny
    if (isset($_POST['meny_elementer'])) {
        $meny = [];
        foreach ($_POST['meny_elementer'] as $item) {
            if (!empty($item['label'])) {
                $meny[] = [
                    'label' => sanitize_text_field($item['label']),
                    'href'  => sanitize_text_field($item['href'] ?? '/'),
                ];
            }
        }
        update_option('amendo_meny', json_encode($meny));
    }

    // Levering & Henting
    $levering_fields = ['henting_aktiv','henting_tidsluke','henting_maks_per_tidsluke',
        'henting_prosesseringstid','henting_cutoff','levering_aktiv','levering_metode',
        'wolt_api_key','levering_tidsluke','levering_prosesseringstid'];
    foreach ($levering_fields as $field) {
        if ($field === 'henting_aktiv' || $field === 'levering_aktiv') {
            update_option('amendo_' . $field, isset($_POST[$field]) ? '1' : '0');
        } elseif ($field === 'wolt_api_key') {
            if (!empty($_POST[$field])) update_option('amendo_wolt_api_key', sanitize_text_field($_POST[$field]));
        } else {
            if (isset($_POST[$field])) update_option('amendo_' . $field, sanitize_text_field($_POST[$field]));
        }
    }
    // Ikke-dager for prosesseringstid
    $ikke_dager = isset($_POST['henting_ikke_dager']) ? array_map('sanitize_text_field', $_POST['henting_ikke_dager']) : [];
    update_option('amendo_henting_ikke_dager', json_encode($ikke_dager));

    // Stengte perioder
    if (isset($_POST['stengte_perioder'])) {
        $perioder = [];
        foreach ($_POST['stengte_perioder'] as $p) {
            if (!empty($p['fra']) && !empty($p['til'])) {
                $perioder[] = [
                    'label' => sanitize_text_field($p['label'] ?? ''),
                    'fra'   => sanitize_text_field($p['fra']),
                    'til'   => sanitize_text_field($p['til']),
                ];
            }
        }
        update_option('amendo_stengte_perioder', json_encode($perioder));
    } else {
        update_option('amendo_stengte_perioder', '[]');
    }

    // Kategori-regler
    if (isset($_POST['kategori_regler'])) {
        $regler = [];
        foreach ($_POST['kategori_regler'] as $r) {
            if (!empty($r['kategori_id'])) {
                $regler[] = [
                    'kategori_id'      => intval($r['kategori_id']),
                    'prosesseringstid' => $r['prosesseringstid'] !== '' ? intval($r['prosesseringstid']) : null,
                    'steng_fra'        => sanitize_text_field($r['steng_fra'] ?? ''),
                    'steng_til'        => sanitize_text_field($r['steng_til'] ?? ''),
                ];
            }
        }
        update_option('amendo_kategori_regler', json_encode($regler));
    } else {
        update_option('amendo_kategori_regler', '[]');
    }

    // Avdelinger
    if (isset($_POST['avdelinger'])) {
        $avdelinger = [];
        $dager = ['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
        foreach ($_POST['avdelinger'] as $avd) {
            $åpningstider = [];
            foreach ($dager as $dag) {
                $åpningstider[$dag] = [
                    'åpen' => isset($avd['åpningstider'][$dag]['åpen']) ? '1' : '0',
                    'fra'  => sanitize_text_field($avd['åpningstider'][$dag]['fra'] ?? '09:00'),
                    'til'  => sanitize_text_field($avd['åpningstider'][$dag]['til'] ?? '17:00'),
                ];
            }
            $avdelinger[] = [
                'navn'             => sanitize_text_field($avd['navn'] ?? ''),
                'adresse'          => sanitize_text_field($avd['adresse'] ?? ''),
                'telefon'          => sanitize_text_field($avd['telefon'] ?? ''),
                'bilde'            => sanitize_url($avd['bilde'] ?? ''),
                'åpningstider'     => $åpningstider,
                'henting_aktiv'    => isset($avd['henting_aktiv']) ? '1' : '0',
                'prosesseringstid' => $avd['prosesseringstid'] !== '' ? intval($avd['prosesseringstid']) : null,
                'cutoff'           => sanitize_text_field($avd['cutoff'] ?? ''),
                'tidsluke'         => !empty($avd['tidsluke']) ? intval($avd['tidsluke']) : null,
                'maks_per_tidsluke'=> !empty($avd['maks_per_tidsluke']) ? intval($avd['maks_per_tidsluke']) : null,
            ];
        }
        update_option('amendo_avdelinger', json_encode($avdelinger));
    }

    wp_redirect(admin_url('admin.php?page=amendo-settings&saved=1'));
    exit;
});

// REST API
add_action('rest_api_init', function() {
    register_rest_route('amendo-settings/v1', '/leveringsregler', [
        'methods'             => 'GET',
        'callback'            => 'amendo_get_leveringsregler',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('amendo-settings/v1', '/settings', [
        'methods'             => 'GET',
        'callback'            => 'amendo_get_settings',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('amendo-settings/v1', '/side/(?P<slug>[a-zA-Z0-9-_]+)', [
        'methods'             => 'GET',
        'callback'            => 'amendo_get_side',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('amendo-settings/v1', '/adyen-session', [
        'methods'             => 'POST',
        'callback'            => 'amendo_create_adyen_session',
        'permission_callback' => function($request) {
            // Valider med KASSE_WEBHOOK_SECRET lagret i wp_options
            $secret = get_option('amendo_kasse_secret', '');
            if (empty($secret)) return true; // Ikke konfigurert — tillat midlertidig
            $header = $request->get_header('X-Amendo-Secret');
            return hash_equals($secret, (string) $header);
        },
    ]);
    register_rest_route('amendo-settings/v1', '/gateway-redirect', [
        'methods'             => 'POST',
        'callback'            => 'amendo_gateway_redirect',
        'permission_callback' => function($request) {
            $secret = get_option('amendo_kasse_secret', '');
            if (empty($secret)) return true;
            $header = $request->get_header('X-Amendo-Secret');
            return hash_equals($secret, (string) $header);
        },
    ]);
    // Helsebygget produkter
    register_rest_route('amendo-settings/v1', '/helsebygget-produkter', [
        'methods'             => 'GET',
        'callback'            => 'amendo_get_helsebygget_produkter',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('amendo-settings/v1', '/helsebygget-produkter', [
        'methods'             => 'POST',
        'callback'            => 'amendo_set_helsebygget_produkter',
        'permission_callback' => function($request) {
            $secret = get_option('amendo_kasse_secret', '');
            if (empty($secret)) return true;
            return hash_equals($secret, (string) $request->get_header('X-Amendo-Secret'));
        },
    ]);
});

function amendo_get_helsebygget_produkter() {
    $aktive_ids = json_decode(get_option('amendo_helsebygget_produkter', '[]'), true) ?: [];

    // Hent alle produkter med pris, bilde og navn
    $alle = wc_get_products(['limit' => 200, 'status' => 'publish', 'return' => 'objects']);

    $produkter = array_map(function($p) use ($aktive_ids) {
        $img = wp_get_attachment_image_url($p->get_image_id(), 'medium') ?: '';
        return [
            'id'     => $p->get_id(),
            'navn'   => $p->get_name(),
            'pris'   => $p->get_price(),
            'bilde'  => $img,
            'aktiv'  => in_array($p->get_id(), $aktive_ids),
        ];
    }, $alle);

    return new WP_REST_Response([
        'produkter'  => $produkter,
        'aktive_ids' => $aktive_ids,
    ], 200);
}

function amendo_set_helsebygget_produkter($request) {
    $body = $request->get_json_params();
    $ids  = array_map('intval', $body['aktive_ids'] ?? []);
    update_option('amendo_helsebygget_produkter', json_encode($ids));
    return new WP_REST_Response(['ok' => true, 'aktive' => count($ids)], 200);
}

function amendo_gateway_redirect($request) {
    $body       = $request->get_json_params();
    $order_id   = intval($body['order_id'] ?? 0);
    $gateway_id = sanitize_text_field($body['gateway_id'] ?? '');
    $return_url = sanitize_url($body['return_url'] ?? '');

    if (!$order_id || !$gateway_id) {
        return new WP_REST_Response(['error' => 'Mangler order_id eller gateway_id'], 400);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return new WP_REST_Response(['error' => 'Fant ikke ordren'], 404);
    }

    $gateways = WC()->payment_gateways()->payment_gateways();
    $gateway  = $gateways[$gateway_id] ?? null;

    if (!$gateway) {
        return new WP_REST_Response(['error' => 'Fant ikke gateway: ' . $gateway_id], 404);
    }

    // Overstyr returnUrl til headless kasse/takk-siden
    if (!empty($return_url)) {
        add_filter('woocommerce_get_checkout_order_received_url', function() use ($return_url, $order_id) {
            return add_query_arg('order_id', $order_id, $return_url);
        });
        add_filter('woocommerce_get_return_url', function() use ($return_url, $order_id) {
            return add_query_arg('order_id', $order_id, $return_url);
        });
    }

    $result = $gateway->process_payment($order_id);

    if (($result['result'] ?? '') === 'success' && !empty($result['redirect'])) {
        return new WP_REST_Response([
            'redirect' => $result['redirect'],
        ], 200);
    }

    return new WP_REST_Response(['error' => 'Gateway returnerte ikke redirect'], 502);
}

function amendo_create_adyen_session($request) {
    // Sjekk at gateway-pluginen er lastet
    if (!class_exists('AOrder\Gateways\API\Adyen')) {
        return new WP_REST_Response([
            'error' => 'Amendo Gateway ikke aktivert'
        ], 503);
    }

    $body           = $request->get_json_params();
    $amount         = floatval($body['amount'] ?? 0);
    $currency       = sanitize_text_field($body['currency'] ?? 'NOK');
    $country        = sanitize_text_field($body['countryCode'] ?? 'NO');
    $ref            = sanitize_text_field($body['reference'] ?? uniqid('order_'));
    $payment_method = sanitize_text_field($body['payment_method'] ?? '');

    if ($amount <= 0) {
        return new WP_REST_Response(['error' => 'Ugyldig beløp'], 400);
    }

    // Klarna bruker AmendoPOS merchant account — kall api_call() direkte
    // slik at vi unngår å endre gateway-pluginen
    $is_klarna = strpos($payment_method, 'klarna') !== false;

    if ($is_klarna && class_exists('AOrder\Gateways\Adyen\Klarna')) {
        $merchant_account = \AOrder\Gateways\Adyen\Klarna::merchant_account();
        $session = \AOrder\Gateways\API\Adyen::api_call('/sessions', [
            'merchantAccount' => $merchant_account,
            'amount'          => [
                'currency' => $currency ?: get_woocommerce_currency(),
                'value'    => $amount * 100,
            ],
            'countryCode'     => $country ?: WC()->countries->get_base_country(),
            'returnUrl'       => site_url('checkout'),
            'channel'         => 'Web',
            'reference'       => get_site_url() . '/' . $ref,
        ]);
    } else {
        $session = \AOrder\Gateways\API\Adyen::create_session($amount, $ref, $country, $currency);
    }

    // create_session returnerer stdClass-objekt
    $session_id   = $session->id ?? ($session['id'] ?? null);
    $session_data = $session->sessionData ?? ($session['sessionData'] ?? null);

    if (empty($session_id)) {
        return new WP_REST_Response(['error' => 'Kunne ikke opprette Adyen-sesjon'], 502);
    }

    // Hent clientKey og environment fra gateway-innstillingene
    $card_gateway = WC()->payment_gateways()->payment_gateways()['amendo_adyen_card'] ?? null;
    $client_key   = $card_gateway ? $card_gateway->get_option('client_key') : '';
    $environment  = \AmendoCore()->is_live() ? 'live' : 'test';

    return new WP_REST_Response([
        'sessionId'   => $session_id,
        'sessionData' => $session_data,
        'clientKey'   => $client_key,
        'environment' => $environment,
    ], 200);
}

function amendo_get_leveringsregler() {
    // Globale innstillinger
    $global_prosesseringstid  = intval(get_option('amendo_henting_prosesseringstid', 2));
    $global_cutoff            = get_option('amendo_henting_cutoff', '10:00');
    $global_tidsluke          = intval(get_option('amendo_henting_tidsluke', 60));
    $global_maks              = intval(get_option('amendo_henting_maks_per_tidsluke', 10));
    $ikke_dager               = json_decode(get_option('amendo_henting_ikke_dager', '["lørdag","søndag"]'), true) ?: ['lørdag','søndag'];
    $stengte                  = json_decode(get_option('amendo_stengte_perioder', '[]'), true) ?: [];
    $kat_regler               = json_decode(get_option('amendo_kategori_regler', '[]'), true) ?: [];
    $avdelinger_raw           = json_decode(get_option('amendo_avdelinger', '[]'), true) ?: [];

    // Løs opp avdelinger med global fallback
    $avdelinger = array_map(function($avd) use ($global_prosesseringstid, $global_cutoff, $global_tidsluke, $global_maks) {
        return array_merge($avd, [
            'prosesseringstid_resolved' => $avd['prosesseringstid'] ?? $global_prosesseringstid,
            'cutoff_resolved'           => !empty($avd['cutoff']) ? $avd['cutoff'] : $global_cutoff,
            'tidsluke_resolved'         => $avd['tidsluke'] ?? $global_tidsluke,
            'maks_per_tidsluke_resolved'=> $avd['maks_per_tidsluke'] ?? $global_maks,
        ]);
    }, $avdelinger_raw);

    return new WP_REST_Response([
        'global' => [
            'henting_aktiv'      => get_option('amendo_henting_aktiv', '1') === '1',
            'prosesseringstid'   => $global_prosesseringstid,
            'cutoff'             => $global_cutoff,
            'tidsluke_minutter'  => $global_tidsluke,
            'maks_per_tidsluke'  => $global_maks,
            'ikke_dager'         => $ikke_dager,
            'stengte_perioder'   => $stengte,
        ],
        'levering' => [
            'aktiv'              => get_option('amendo_levering_aktiv', '0') === '1',
            'metode'             => get_option('amendo_levering_metode', 'wolt'),
            'tidsluke_minutter'  => intval(get_option('amendo_levering_tidsluke', 60)),
            'prosesseringstid'   => intval(get_option('amendo_levering_prosesseringstid', 2)),
            'har_wolt'           => !empty(get_option('amendo_wolt_api_key', '')),
        ],
        'kategori_regler' => $kat_regler,
        'avdelinger'      => $avdelinger,
    ], 200);
}

function amendo_get_settings() {
    $avdelinger = json_decode(get_option('amendo_avdelinger', '[]'), true) ?: [];
    $meny       = json_decode(get_option('amendo_meny', '[]'), true) ?: [];

    // Default meny hvis ingen er satt
    if (empty($meny)) {
        $meny = [
            ['label' => 'Produkter', 'href' => '/produkter'],
            ['label' => 'Om oss',    'href' => '/om-oss'],
            ['label' => 'Bedrift',   'href' => '/bedrift'],
            ['label' => 'Kontakt',   'href' => '/kontakt'],
        ];
    }

    return [
        'butikk' => [
            'navn'    => get_option('amendo_butikk_navn', get_bloginfo('name')),
            'slagord' => get_option('amendo_butikk_slagord', get_bloginfo('description')),
            'logo'    => get_option('amendo_butikk_logo', ''),
        ],
        'design' => [
            'primærfarge'   => get_option('amendo_design_primærfarge', '#b45309'),
            'sekundærfarge' => get_option('amendo_design_sekundærfarge', '#1c1917'),
        ],
        'kontakt' => [
            'telefon' => get_option('amendo_kontakt_telefon', ''),
            'epost'   => get_option('amendo_kontakt_epost', ''),
        ],
        'sosiale' => [
            'instagram' => get_option('amendo_sosiale_instagram', ''),
            'facebook'  => get_option('amendo_sosiale_facebook', ''),
            'tiktok'    => get_option('amendo_sosiale_tiktok', ''),
        ],
        'meny'       => $meny,
        'avdelinger' => $avdelinger,
    ];
}

function amendo_get_side($request) {
    $slug = sanitize_text_field($request['slug']);
    $pages = get_posts(['name' => $slug, 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1]);

    if (empty($pages) && $slug === 'forside') {
        $front_id = get_option('page_on_front');
        if ($front_id) $pages = [get_post($front_id)];
    }

    if (empty($pages) || !$pages[0]) return new WP_REST_Response(['fields' => []], 200);

    $page   = $pages[0];
    $fields = function_exists('get_fields') ? (get_fields($page->ID) ?: []) : [];
    return new WP_REST_Response(['id' => $page->ID, 'slug' => $slug, 'tittel' => $page->post_title, 'fields' => $fields], 200);
}

// Opprett sider ved aktivering
register_activation_hook(__FILE__, 'amendo_opprett_sider');
function amendo_opprett_sider() {
    $sider = [['post_title'=>'Forside','post_name'=>'forside'],['post_title'=>'Om oss','post_name'=>'om-oss'],['post_title'=>'Kontakt','post_name'=>'kontakt'],['post_title'=>'Bedrift','post_name'=>'bedrift']];
    foreach ($sider as $side) {
        if (!get_page_by_path($side['post_name'])) {
            wp_insert_post(['post_title'=>$side['post_title'],'post_name'=>$side['post_name'],'post_status'=>'publish','post_type'=>'page']);
        }
    }
    $forside = get_page_by_path('forside');
    if ($forside) { update_option('show_on_front','page'); update_option('page_on_front',$forside->ID); }
}

// Inkluder ACF-feltgrupper
add_action('plugins_loaded', function() {
    if (function_exists('acf_add_local_field_group')) {
        require_once AMENDO_SETTINGS_PATH . 'acf-fields.php';
    }
});

// Admin-side HTML
function amendo_settings_page() {
    $saved          = isset($_GET['saved']);
    $butikk_navn    = get_option('amendo_butikk_navn', get_bloginfo('name'));
    $butikk_slagord = get_option('amendo_butikk_slagord', '');
    $butikk_logo    = get_option('amendo_butikk_logo', '');
    $primærfarge    = get_option('amendo_design_primærfarge', '#b45309');
    $sekundærfarge  = get_option('amendo_design_sekundærfarge', '#1c1917');
    $telefon        = get_option('amendo_kontakt_telefon', '');
    $epost          = get_option('amendo_kontakt_epost', '');
    $instagram      = get_option('amendo_sosiale_instagram', '');
    $facebook       = get_option('amendo_sosiale_facebook', '');
    $tiktok         = get_option('amendo_sosiale_tiktok', '');
    $avdelinger     = json_decode(get_option('amendo_avdelinger', '[]'), true) ?: [];
    $meny           = json_decode(get_option('amendo_meny', '[]'), true) ?: [
        ['label'=>'Produkter','href'=>'/produkter'],
        ['label'=>'Om oss','href'=>'/om-oss'],
        ['label'=>'Bedrift','href'=>'/bedrift'],
        ['label'=>'Kontakt','href'=>'/kontakt'],
    ];
    $dager = ['mandag','tirsdag','onsdag','torsdag','fredag','lørdag','søndag'];
    ?>
    <div class="amendo-wrap">
        <div class="amendo-header">
            <div class="amendo-logo">⚙️ Amendo Innstillinger</div>
            <?php if ($saved): ?><div class="amendo-notice">✓ Innstillinger lagret</div><?php endif; ?>
        </div>

        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('amendo_settings_nonce'); ?>
            <input type="hidden" name="action" value="amendo_save_settings">

            <div class="amendo-tabs">
                <button type="button" class="amendo-tab active" data-tab="butikk">🏪 Butikk</button>
                <button type="button" class="amendo-tab" data-tab="design">🎨 Design</button>
                <button type="button" class="amendo-tab" data-tab="kontakt">📞 Kontakt</button>
                <button type="button" class="amendo-tab" data-tab="sosiale">📱 Sosiale medier</button>
                <button type="button" class="amendo-tab" data-tab="meny">🔗 Meny</button>
                <button type="button" class="amendo-tab" data-tab="avdelinger">📍 Avdelinger</button>
                <button type="button" class="amendo-tab" data-tab="levering">🚚 Levering & Henting</button>
            </div>

            <!-- BUTIKK -->
            <div class="amendo-panel active" id="tab-butikk">
                <div class="amendo-card">
                    <h2>Butikkinformasjon</h2>
                    <div class="amendo-field">
                        <label>Firmanavn</label>
                        <input type="text" name="butikk_navn" value="<?php echo esc_attr($butikk_navn); ?>" placeholder="Hansen Bakeri AS">
                    </div>
                    <div class="amendo-field">
                        <label>Slagord</label>
                        <input type="text" name="butikk_slagord" value="<?php echo esc_attr($butikk_slagord); ?>" placeholder="Nybakt hver morgen">
                    </div>
                    <div class="amendo-field">
                        <label>Logo</label>
                        <div class="amendo-logo-upload">
                            <?php if ($butikk_logo): ?>
                                <img id="logo-preview" src="<?php echo esc_url($butikk_logo); ?>" alt="Logo" style="max-height:60px;max-width:200px;border:1px solid #e5e7eb;border-radius:6px;padding:4px;">
                            <?php else: ?>
                                <div id="logo-preview" class="logo-placeholder">Ingen logo valgt</div>
                            <?php endif; ?>
                            <input type="hidden" name="butikk_logo" id="butikk_logo" value="<?php echo esc_attr($butikk_logo); ?>">
                            <button type="button" class="amendo-btn-secondary" id="upload-logo">Last opp logo</button>
                            <?php if ($butikk_logo): ?><button type="button" class="amendo-btn-danger" id="remove-logo">Fjern</button><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- DESIGN -->
            <div class="amendo-panel" id="tab-design">
                <div class="amendo-card">
                    <h2>Farger</h2>
                    <div class="amendo-field amendo-color-field">
                        <label>Primærfarge</label>
                        <div class="color-input-wrap">
                            <input type="color" name="design_primærfarge" value="<?php echo esc_attr($primærfarge); ?>">
                            <span class="color-value"><?php echo esc_html($primærfarge); ?></span>
                        </div>
                        <p class="field-help">Brukes til knapper, lenker og fremhevede elementer</p>
                    </div>
                    <div class="amendo-field amendo-color-field">
                        <label>Sekundærfarge</label>
                        <div class="color-input-wrap">
                            <input type="color" name="design_sekundærfarge" value="<?php echo esc_attr($sekundærfarge); ?>">
                            <span class="color-value"><?php echo esc_html($sekundærfarge); ?></span>
                        </div>
                        <p class="field-help">Brukes til bakgrunner og sekundære elementer</p>
                    </div>
                </div>
            </div>

            <!-- KONTAKT -->
            <div class="amendo-panel" id="tab-kontakt">
                <div class="amendo-card">
                    <h2>Kontaktinformasjon</h2>
                    <div class="amendo-field">
                        <label>Telefon</label>
                        <input type="tel" name="kontakt_telefon" value="<?php echo esc_attr($telefon); ?>" placeholder="+47 123 45 678">
                    </div>
                    <div class="amendo-field">
                        <label>E-post</label>
                        <input type="email" name="kontakt_epost" value="<?php echo esc_attr($epost); ?>" placeholder="post@bakeri.no">
                    </div>
                </div>
            </div>

            <!-- SOSIALE MEDIER -->
            <div class="amendo-panel" id="tab-sosiale">
                <div class="amendo-card">
                    <h2>Sosiale medier</h2>
                    <div class="amendo-field">
                        <label>Instagram (uten @)</label>
                        <input type="text" name="sosiale_instagram" value="<?php echo esc_attr($instagram); ?>" placeholder="dittbakeri">
                    </div>
                    <div class="amendo-field">
                        <label>Facebook URL</label>
                        <input type="url" name="sosiale_facebook" value="<?php echo esc_attr($facebook); ?>" placeholder="https://facebook.com/dittbakeri">
                    </div>
                    <div class="amendo-field">
                        <label>TikTok (uten @)</label>
                        <input type="text" name="sosiale_tiktok" value="<?php echo esc_attr($tiktok); ?>" placeholder="dittbakeri">
                    </div>
                </div>
            </div>

            <!-- MENY -->
            <div class="amendo-panel" id="tab-meny">
                <div class="amendo-card">
                    <h2>Navigasjonsmeny</h2>
                    <p class="amendo-desc">Bestem hvilke sider som vises i menyen og i hvilken rekkefølge.</p>
                    <div id="meny-liste">
                        <?php foreach ($meny as $i => $item): ?>
                        <div class="meny-rad" data-index="<?php echo $i; ?>">
                            <div class="meny-drag">⠿</div>
                            <input type="text" name="meny_elementer[<?php echo $i; ?>][label]" value="<?php echo esc_attr($item['label']); ?>" placeholder="Menylenke">
                            <input type="text" name="meny_elementer[<?php echo $i; ?>][href]" value="<?php echo esc_attr($item['href']); ?>" placeholder="/side">
                            <button type="button" class="slett-meny amendo-btn-danger">Slett</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="amendo-btn-secondary" id="legg-til-meny" style="margin-top:12px">+ Legg til menylenke</button>
                </div>
            </div>

            <!-- AVDELINGER -->
            <div class="amendo-panel" id="tab-avdelinger">
                <div class="amendo-card">
                    <h2>Avdelinger</h2>
                    <p class="amendo-desc">Legg til alle butikkens lokasjoner med åpningstider og bilde.</p>
                    <div id="avdelinger-liste">
                        <?php foreach ($avdelinger as $i => $avd): ?>
                        <div class="avdeling-kort" data-index="<?php echo $i; ?>">
                            <div class="avdeling-header">
                                <span class="avdeling-navn-tittel"><?php echo esc_html($avd['navn'] ?: 'Avdeling ' . ($i + 1)); ?></span>
                                <button type="button" class="slett-avdeling amendo-btn-danger">Slett</button>
                            </div>
                            <div class="avdeling-body">
                                <div class="amendo-grid-2">
                                    <div class="amendo-field">
                                        <label>Navn på avdeling</label>
                                        <input type="text" name="avdelinger[<?php echo $i; ?>][navn]" value="<?php echo esc_attr($avd['navn']); ?>" placeholder="Sentrum" class="avdeling-navn-input">
                                    </div>
                                    <div class="amendo-field">
                                        <label>Telefon</label>
                                        <input type="tel" name="avdelinger[<?php echo $i; ?>][telefon]" value="<?php echo esc_attr($avd['telefon']); ?>" placeholder="+47 123 45 678">
                                    </div>
                                </div>
                                <div class="amendo-field">
                                    <label>Adresse</label>
                                    <input type="text" name="avdelinger[<?php echo $i; ?>][adresse]" value="<?php echo esc_attr($avd['adresse']); ?>" placeholder="Storgata 1, 0123 Oslo">
                                </div>
                                <div class="amendo-field">
                                    <label>Bilde</label>
                                    <div class="amendo-logo-upload">
                                        <?php if (!empty($avd['bilde'])): ?>
                                            <img class="avd-bilde-preview" src="<?php echo esc_url($avd['bilde']); ?>" alt="" style="max-height:80px;max-width:160px;border-radius:6px;object-fit:cover;">
                                        <?php else: ?>
                                            <div class="avd-bilde-preview logo-placeholder">Ingen bilde</div>
                                        <?php endif; ?>
                                        <input type="hidden" name="avdelinger[<?php echo $i; ?>][bilde]" class="avd-bilde-input" value="<?php echo esc_attr($avd['bilde'] ?? ''); ?>">
                                        <button type="button" class="amendo-btn-secondary upload-avd-bilde">Last opp bilde</button>
                                        <?php if (!empty($avd['bilde'])): ?><button type="button" class="amendo-btn-danger fjern-avd-bilde">Fjern</button><?php endif; ?>
                                    </div>
                                </div>
                                <div class="amendo-field">
                                    <label>Åpningstider</label>
                                    <div class="åpningstider-grid">
                                        <div class="åpningstider-header"><span>Dag</span><span>Åpen</span><span>Fra</span><span>Til</span></div>
                                        <?php foreach ($dager as $dag):
                                            $t = $avd['åpningstider'][$dag] ?? ['åpen'=>'1','fra'=>'09:00','til'=>'17:00'];
                                        ?>
                                        <div class="åpningstider-rad">
                                            <span class="dag-navn"><?php echo ucfirst($dag); ?></span>
                                            <label class="toggle">
                                                <input type="checkbox" name="avdelinger[<?php echo $i; ?>][åpningstider][<?php echo $dag; ?>][åpen]" <?php checked($t['åpen'],'1'); ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                            <input type="time" name="avdelinger[<?php echo $i; ?>][åpningstider][<?php echo $dag; ?>][fra]" value="<?php echo esc_attr($t['fra']); ?>">
                                            <input type="time" name="avdelinger[<?php echo $i; ?>][åpningstider][<?php echo $dag; ?>][til]" value="<?php echo esc_attr($t['til']); ?>">
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="amendo-field" style="margin-top:20px;padding-top:20px;border-top:1px solid #e5e7eb;">
                                    <label style="font-size:13px;color:#374151;margin-bottom:12px;display:block;">⚙️ Henting-innstillinger for denne avdelingen</label>
                                    <p class="amendo-desc" style="margin-bottom:16px;">Overstyrer globale innstillinger. La felter stå tomme for å arve globale verdier.</p>
                                    <div class="amendo-grid-2">
                                        <div class="amendo-field">
                                            <label>Prosesseringstid (virkedager)</label>
                                            <input type="number" name="avdelinger[<?php echo $i; ?>][prosesseringstid]" value="<?php echo esc_attr($avd['prosesseringstid'] ?? ''); ?>" min="0" max="30" placeholder="Arv global">
                                            <p class="field-help">Tom = arv global. 0 = samme dag.</p>
                                        </div>
                                        <div class="amendo-field">
                                            <label>Cut-off tid (samme dag)</label>
                                            <input type="time" name="avdelinger[<?php echo $i; ?>][cutoff]" value="<?php echo esc_attr($avd['cutoff'] ?? '10:00'); ?>">
                                            <p class="field-help">Bestillinger etter dette tidspunktet teller fra neste dag</p>
                                        </div>
                                    </div>
                                    <div class="amendo-grid-2">
                                        <div class="amendo-field">
                                            <label>Tidsluke-lengde (minutter)</label>
                                            <select name="avdelinger[<?php echo $i; ?>][tidsluke]" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                                                <?php foreach([15,30,60,120] as $min): ?>
                                                <option value="<?php echo $min; ?>" <?php selected($avd['tidsluke']??60,$min); ?>><?php echo $min; ?> min</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="amendo-field">
                                            <label>Maks per tidsluke</label>
                                            <input type="number" name="avdelinger[<?php echo $i; ?>][maks_per_tidsluke]" value="<?php echo esc_attr($avd['maks_per_tidsluke'] ?? 10); ?>" min="1" max="999">
                                        </div>
                                    </div>
                                    <div class="amendo-field">
                                        <label>Tillat henting her</label>
                                        <label class="toggle">
                                            <input type="checkbox" name="avdelinger[<?php echo $i; ?>][henting_aktiv]" <?php checked($avd['henting_aktiv']??'1','1'); ?>>
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="amendo-btn-secondary" id="legg-til-avdeling">+ Legg til avdeling</button>
                </div>
            </div>


            <!-- LEVERING & HENTING -->
            <div class="amendo-panel" id="tab-levering">
                <div class="amendo-card">
                    <h2>Henting (Pickup)</h2>
                    <p class="amendo-desc">Kunder henter selv i butikken.</p>
                    <div class="amendo-field">
                        <label>Tillat henting</label>
                        <label class="toggle">
                            <input type="checkbox" name="henting_aktiv" <?php checked(get_option('amendo_henting_aktiv','1'),'1'); ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="amendo-grid-2">
                        <div class="amendo-field">
                            <label>Tidsluke-lengde (minutter)</label>
                            <select name="henting_tidsluke" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                                <?php foreach([15,30,60,120] as $min): ?>
                                <option value="<?php echo $min; ?>" <?php selected(get_option('amendo_henting_tidsluke','60'),$min); ?>><?php echo $min; ?> min</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="amendo-field">
                            <label>Maks bestillinger per tidsluke</label>
                            <input type="number" name="henting_maks_per_tidsluke" value="<?php echo esc_attr(get_option('amendo_henting_maks_per_tidsluke','10')); ?>" min="1" max="999">
                        </div>
                    </div>
                    <div class="amendo-grid-2">
                        <div class="amendo-field">
                            <label>Prosesseringstid (virkedager)</label>
                            <input type="number" name="henting_prosesseringstid" value="<?php echo esc_attr(get_option('amendo_henting_prosesseringstid','2')); ?>" min="0" max="30">
                            <p class="field-help">0 = samme dag mulig</p>
                        </div>
                        <div class="amendo-field">
                            <label>Cut-off tid for samme dag</label>
                            <input type="time" name="henting_cutoff" value="<?php echo esc_attr(get_option('amendo_henting_cutoff','10:00')); ?>">
                            <p class="field-help">Bestillinger etter dette tidspunktet teller fra neste dag</p>
                        </div>
                    </div>
                    <div class="amendo-field">
                        <label>Dager som ikke teller i prosesseringstid</label>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">
                            <?php
                            $ikke_dager = json_decode(get_option('amendo_henting_ikke_dager','["lørdag","søndag"]'), true) ?: [];
                            $alle_dager = ['mandag','tirsdag','onsdag','torsdag','fredag','lørdag','søndag'];
                            foreach ($alle_dager as $dag):
                            ?>
                            <label style="display:flex;align-items:center;gap:4px;font-size:13px;font-weight:normal;text-transform:none;letter-spacing:0;">
                                <input type="checkbox" name="henting_ikke_dager[]" value="<?php echo $dag; ?>" <?php checked(in_array($dag, $ikke_dager)); ?>>
                                <?php echo ucfirst($dag); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="field-help">Typisk lørdag og søndag</p>
                    </div>
                    <div class="amendo-field">
                        <label>Stengte perioder</label>
                        <div id="stengte-perioder-liste">
                            <?php
                            $perioder = json_decode(get_option('amendo_stengte_perioder','[]'), true) ?: [];
                            foreach ($perioder as $i => $p):
                            ?>
                            <div class="steng-rad">
                                <input type="text" name="stengte_perioder[<?php echo $i; ?>][label]" value="<?php echo esc_attr($p['label']??''); ?>" placeholder="Beskrivelse (f.eks. Sommerferie)">
                                <input type="date" name="stengte_perioder[<?php echo $i; ?>][fra]" value="<?php echo esc_attr($p['fra']??''); ?>">
                                <span>→</span>
                                <input type="date" name="stengte_perioder[<?php echo $i; ?>][til]" value="<?php echo esc_attr($p['til']??''); ?>">
                                <button type="button" class="slett-periode amendo-btn-danger">Slett</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="amendo-btn-secondary" id="legg-til-periode" style="margin-top:8px">+ Legg til periode</button>
                    </div>
                </div>

                <div class="amendo-card">
                    <h2>Levering (Delivery)</h2>
                    <p class="amendo-desc">Vi leverer til kunden.</p>
                    <div class="amendo-field">
                        <label>Tillat levering</label>
                        <label class="toggle">
                            <input type="checkbox" name="levering_aktiv" <?php checked(get_option('amendo_levering_aktiv','0'),'1'); ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="amendo-field">
                        <label>Leveringsmetode</label>
                        <select name="levering_metode" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                            <option value="wolt" <?php selected(get_option('amendo_levering_metode','wolt'),'wolt'); ?>>Wolt Drive</option>
                            <option value="egen" <?php selected(get_option('amendo_levering_metode'),'egen'); ?>>Egen levering</option>
                            <option value="begge" <?php selected(get_option('amendo_levering_metode'),'begge'); ?>>Begge</option>
                        </select>
                    </div>
                    <div class="amendo-field">
                        <label>Wolt Drive API-nøkkel</label>
                        <input type="password" name="wolt_api_key" value="<?php echo esc_attr(get_option('amendo_wolt_api_key','')); ?>" placeholder="wolt_...">
                    </div>
                    <div class="amendo-grid-2">
                        <div class="amendo-field">
                            <label>Tidsluke-lengde levering (minutter)</label>
                            <select name="levering_tidsluke" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                                <?php foreach([30,60,120] as $min): ?>
                                <option value="<?php echo $min; ?>" <?php selected(get_option('amendo_levering_tidsluke','60'),$min); ?>><?php echo $min; ?> min</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="amendo-field">
                            <label>Prosesseringstid levering (virkedager)</label>
                            <input type="number" name="levering_prosesseringstid" value="<?php echo esc_attr(get_option('amendo_levering_prosesseringstid','2')); ?>" min="0" max="30">
                        </div>
                    </div>
                </div>

                <div class="amendo-card">
                    <h2>Kategori-overstyringer</h2>
                    <p class="amendo-desc">Overstyr prosesseringstid eller steng bestilling for spesifikke kategorier i perioder.</p>
                    <div id="kategori-regler-liste">
                        <?php
                        $regler = json_decode(get_option('amendo_kategori_regler','[]'), true) ?: [];
                        $kategorier = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'number'=>100]);
                        foreach ($regler as $i => $r):
                        ?>
                        <div class="kategori-regel-rad" style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-bottom:12px;">
                            <div class="amendo-grid-2">
                                <div class="amendo-field">
                                    <label>Kategori</label>
                                    <select name="kategori_regler[<?php echo $i; ?>][kategori_id]" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                                        <?php foreach($kategorier as $kat): ?>
                                        <option value="<?php echo $kat->term_id; ?>" <?php selected($r['kategori_id']??'',$kat->term_id); ?>><?php echo esc_html($kat->name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="amendo-field">
                                    <label>Prosesseringstid (virkedager, tom = standard)</label>
                                    <input type="number" name="kategori_regler[<?php echo $i; ?>][prosesseringstid]" value="<?php echo esc_attr($r['prosesseringstid']??''); ?>" min="0" max="30" placeholder="Standard">
                                </div>
                            </div>
                            <div class="amendo-grid-2">
                                <div class="amendo-field">
                                    <label>Steng fra dato</label>
                                    <input type="date" name="kategori_regler[<?php echo $i; ?>][steng_fra]" value="<?php echo esc_attr($r['steng_fra']??''); ?>">
                                </div>
                                <div class="amendo-field">
                                    <label>Steng til dato</label>
                                    <input type="date" name="kategori_regler[<?php echo $i; ?>][steng_til]" value="<?php echo esc_attr($r['steng_til']??''); ?>">
                                </div>
                            </div>
                            <button type="button" class="slett-kategori-regel amendo-btn-danger">Slett regel</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="amendo-btn-secondary" id="legg-til-kategori-regel">+ Legg til regel</button>
                </div>
            </div>

            <div class="amendo-footer">
                <button type="submit" class="amendo-btn-primary">Lagre innstillinger</button>
            </div>
        </form>
    </div>
    <?php
}
