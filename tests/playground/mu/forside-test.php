<?php
// Kun for tests/playground (se CLAUDE.md). Lastes som mu-plugin i Playground,
// aldri i en ekte butikk.
if (!defined('ABSPATH')) exit;
// Tester for 1.4.13: forsideseksjonene (kategorifliser, historie, lokasjoner,
// galleri, tall-merke, nyhetsoverskrift, nyhetsbrev), `seksjoner`, og
// ACF-reserven felt for felt. Suiten: suiter/forside.mjs.

/**
 * ACF-stubb: Playground har ikke ACF. Defineres bare når optionen
 * `amendo_test_acf_stub` finnes (satt av suiten forside), så de andre suitene
 * ser WordPress uten ACF som før. Gjelder fra forespørselen ETTER at den er satt.
 */
if (!function_exists('get_fields') && get_option('amendo_test_acf_stub', null) !== null) {
    function get_fields($id = false) {
        $d = json_decode((string) get_option('amendo_test_acf_stub', ''), true);
        return is_array($d) && $d ? $d : false;
    }
}

add_action('rest_api_init', function() {
    register_rest_route('amendo-test/v1', '/forside', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'amendo_test_forside']);
});

/** ACF-verdiene på «forsiden» — Garçon-lignende, med felter Amendo ikke eier. */
function amendo_test_forside_acf() {
    return [
        'hero_tittel'        => 'ACF hero',
        'hero_tittel2'       => 'ACF linje 2',
        'hero_undertekst'    => 'ACF under',
        'lok_etikett'        => 'Finn oss',
        'lok_tittel'         => 'ACF lok',
        'lok_vis_alle'       => false,
        'galleri_tittel'     => 'ACF galleri',
        'galleri_bilder'     => [['bilde' => 'https://cdn.example/g1.jpg'], ['bilde' => 'https://cdn.example/g2.jpg'], ['bilde' => 'https://cdn.example/g3.jpg']],
        'historie_tittel'    => 'ACF historie',
        'historie_tekst'     => 'ACF tekst',
        'kategorier_tittel'  => 'ACF kategorier',
        'produkter_tittel'   => 'ACF produkter',
        'omoss_stat_tall'    => '3',
        'omoss_stat_etikett' => 'kaféer',
        'b2b_tittel'         => 'ACF b2b',
    ];
}

function amendo_test_forside_svar() {
    return rest_do_request(new WP_REST_Request('GET', '/amendo-settings/v1/side/forside'))->get_data();
}

/** En product_cat (idempotent på slug). */
function amendo_test_kategori($navn, $slug, $forelder = 0) {
    $t = get_term_by('slug', $slug, 'product_cat');
    if ($t) return (int) $t->term_id;
    $r = wp_insert_term($navn, 'product_cat', ['slug' => $slug, 'parent' => $forelder]);
    return is_wp_error($r) ? 0 : (int) $r['term_id'];
}

/** Ett scenario per forespørsel. ?sak=… */
function amendo_test_forside(WP_REST_Request $req) {
    wp_set_current_user(get_user_by('login', 'admin')->ID);
    $sak = $req->get_param('sak');
    $ut = [];
    $ider = get_option('amendo_test_forside_ider', []);

    switch ($sak) {
        case 'klargjor':
            if (!amendo_finn_side('forside')) {
                wp_insert_post(['post_type' => 'page', 'post_name' => 'forside', 'post_title' => 'Forside', 'post_status' => 'publish']);
            }
            $klaer = amendo_test_kategori('Klær', 'klaer');
            $ider = [
                'klaer'   => $klaer,
                'kjoler'  => amendo_test_kategori('Kjoler', 'kjoler', $klaer),
                'bukser'  => amendo_test_kategori('Bukser', 'bukser', $klaer),
                'sko'     => amendo_test_kategori('Sko & støvler', 'sko'),
                'salg'    => amendo_test_kategori('Salg', 'salg'),
                'vesker'  => amendo_test_kategori('Vesker', 'vesker'),
                'smykker' => amendo_test_kategori('Smykker', 'smykker'),
                'slettes' => amendo_test_kategori('Slettes', 'slettes'),
            ];
            update_option('amendo_test_forside_ider', $ider);
            update_option('amendo_test_acf_stub', wp_json_encode(amendo_test_forside_acf()));
            delete_option('amendo_forside');
            return ['ok' => $klaer > 0 && !in_array(0, $ider, true), 'ider' => $ider];

        case 'stubb':
            // Forutsetningen for resten: stubben er lastet.
            $ut['acf_stubb_aktiv'] = function_exists('get_fields') && (get_fields(0)['lok_tittel'] ?? '') === 'ACF lok';
            return $ut;

        case 'uten_amendo':
            // Ingenting lagret i Amendo: svaret er ACF, nøyaktig som før.
            delete_option('amendo_forside');
            $svar = amendo_test_forside_svar();
            $side = amendo_finn_side('forside');
            $ut['svar_er_ren_acf'] = $svar === ['id' => $side->ID, 'slug' => 'forside', 'tittel' => $side->post_title, 'fields' => amendo_test_forside_acf()];
            $ut['ingen_seksjoner'] = !array_key_exists('seksjoner', $svar['fields'] ?? []);
            return $ut;

        case 'lagring_uten_endring':
            // En butikk på ACF (Garçon) oppdaterer og lagrer en annen fane:
            // hele skjemaet sendes, forhåndsutfylt fra ACF. Frontenden skal se
            // de samme verdiene etterpå.
            delete_option('amendo_forside');
            $for = amendo_test_forside_svar()['fields'];
            list($f) = amendo_forside_for_skjema();
            $u = amendo_forside_utvidet_for_skjema();
            $ut['skjema_forhandsutfylt_fra_acf'] = $u['lok_tittel'] === 'ACF lok' && count($u['galleri_bilder']) === 3 && $u['historie_tittel'] === 'ACF historie';
            $post = array_merge($f, $u);
            $post['seksjoner'] = array_map(function($r) { return ['id' => $r['id'], 'skjult' => $r['skjult'] ? '1' : '']; }, $u['seksjoner']);
            amendo_test_forside_lagre($post);
            $etter = amendo_test_forside_svar()['fields'];
            $avvik = [];
            foreach ($for as $k => $v) {
                if ($k === 'galleri_bilder') {
                    $a = array_column($v, 'bilde'); $b = array_column($etter[$k] ?? [], 'bilde');
                    if ($a !== $b) $avvik[$k] = [$a, $b];
                } elseif (($etter[$k] ?? null) !== $v) {
                    $avvik[$k] = [$v, $etter[$k] ?? null];
                }
            }
            $ut['samme_verdier_etter_lagring'] = $avvik ?: true;
            $ut['lok_vis_alle_false_beholdt'] = array_key_exists('lok_vis_alle', $etter) && $etter['lok_vis_alle'] === false;
            $ut['standard_seksjoner_sendes_ikke'] = !array_key_exists('seksjoner', $etter);
            $ut['lok_vis_ikke_satt'] = !array_key_exists('lok_vis', $etter);
            return $ut;

        case 'felt_for_felt':
            amendo_test_forside_lagre([
                'hero_tittel'     => 'Amendo hero',
                'historie_tittel' => 'Amendo historie',
                'lok_tittel'      => '',
                'galleri_bilder'  => [],
                'omoss_stat_tall' => '5',
            ]);
            $f = amendo_test_forside_svar()['fields'];
            $ut['gammelt_felt_fra_amendo'] = ($f['hero_tittel'] ?? '') === 'Amendo hero';
            $ut['gammelt_felt_eies_av_amendo'] = ($f['hero_undertekst'] ?? null) === '';
            $ut['nytt_felt_med_innhold_vinner'] = ($f['historie_tittel'] ?? '') === 'Amendo historie';
            $ut['nytt_felt_tomt_gir_acf'] = ($f['lok_tittel'] ?? '') === 'ACF lok' && ($f['historie_tekst'] ?? '') === 'ACF tekst';
            $ut['tom_liste_gir_acf_raa'] = ($f['galleri_bilder'] ?? null) === amendo_test_forside_acf()['galleri_bilder'];
            $ut['tall_merke_amendo_etikett_acf'] = ($f['omoss_stat_tall'] ?? '') === '5' && ($f['omoss_stat_etikett'] ?? '') === 'kaféer';
            $ut['acf_felt_amendo_ikke_kjenner'] = ($f['b2b_tittel'] ?? '') === 'ACF b2b' && ($f['lok_vis_alle'] ?? null) === false;
            return $ut;

        case 'bare_nye_felt':
            // Bare et felt fra 1.4.13 utfylt: heroen kommer fortsatt fra ACF.
            amendo_test_forside_lagre(['nyhetsbrev_tittel' => 'Få 10 %', 'nyhetsbrev_tekst' => "Nye plagg <script>x</script>først."]);
            $lagret = json_decode(get_option('amendo_forside'), true);
            $ut['teller_ikke_som_innhold'] = !amendo_forside_har_innhold($lagret);
            $f = amendo_test_forside_svar()['fields'];
            $ut['hero_fra_acf'] = ($f['hero_tittel'] ?? '') === 'ACF hero' && ($f['hero_undertekst'] ?? '') === 'ACF under';
            $ut['nyhetsbrev_tittel'] = ($f['nyhetsbrev_tittel'] ?? '') === 'Få 10 %';
            $ut['nyhetsbrev_tekst_renset'] = strpos($f['nyhetsbrev_tekst'] ?? '<script', '<script') === false && strpos($f['nyhetsbrev_tekst'], 'først') !== false;
            return $ut;

        case 'saniter':
            $fliser = [];
            foreach (['kjoler', 'bukser', 'sko', 'salg', 'vesker', 'smykker', 'klaer'] as $s) $fliser[] = ['term_id' => (string) $ider[$s], 'tittel' => 'T ' . $s];
            array_splice($fliser, 1, 0, [['term_id' => '999999', 'tittel' => 'Finnes ikke'], ['term_id' => '', 'tittel' => 'Uten kategori']]);
            $galleri = [['alt' => 'uten bilde']];
            for ($i = 1; $i <= 10; $i++) $galleri[] = ['bilde' => "https://cdn.example/b$i.jpg", 'alt' => "Bilde $i", 'lenke' => $i === 1 ? 'javascript:alert(1)' : 'produkt/x'];
            $lagret = amendo_test_forside_lagre([
                'hero_tittel'          => 'Hei',
                'historie_tittel'      => '<b>Fet</b> <script>alert(1)</script>tittel',
                'historie_knapp_lenke' => 'javascript:alert(1)',
                'lok_vis'              => 'on',
                'kategorifliser'       => $fliser,
                'galleri_bilder'       => $galleri,
            ]);
            $ut['tekst_renset'] = ($lagret['historie_tittel'] ?? '') === 'Fet tittel';
            $ut['javascript_lenke_tom'] = ($lagret['historie_knapp_lenke'] ?? null) === '';
            $ut['bryter_paa'] = ($lagret['lok_vis'] ?? '') === '1';
            $k = $lagret['kategorifliser'] ?? [];
            $ut['fliser_maks_6'] = count($k) === 6;
            $ut['fliser_ugyldig_kategori_fjernet'] = !in_array('Finnes ikke', array_column($k, 'tittel'), true) && !in_array('Uten kategori', array_column($k, 'tittel'), true);
            $ut['fliser_term_id_er_tall'] = ($k[0]['term_id'] ?? null) === $ider['kjoler'];
            $ut['fliser_rekkefolge'] = array_column($k, 'tittel') === ['T kjoler', 'T bukser', 'T sko', 'T salg', 'T vesker', 'T smykker'];
            $g = $lagret['galleri_bilder'] ?? [];
            $ut['galleri_maks_8'] = count($g) === 8;
            $ut['galleri_uten_bilde_fjernet'] = ($g[0]['alt'] ?? '') === 'Bilde 1';
            $ut['galleri_javascript_lenke_tom'] = ($g[0]['lenke'] ?? null) === '';
            $ut['galleri_relativ_lenke'] = ($g[1]['lenke'] ?? '') === '/produkt/x';
            $ut['galleri_bilde_id_0'] = ($g[0]['bilde_id'] ?? null) === 0;
            $f = amendo_test_forside_svar()['fields'];
            $ut['rest_lok_vis'] = ($f['lok_vis'] ?? '') === '1';
            $ut['rest_fliser'] = count($f['kategorifliser'] ?? []) === 6 && ($f['kategorifliser'][2]['term_id'] ?? null) === $ider['sko'];

            // Bryteren av: '' i lagringen, ikke med i svaret (ACF har den ikke).
            $lagret = amendo_test_forside_lagre(['hero_tittel' => 'Hei']);
            $ut['bryter_av'] = ($lagret['lok_vis'] ?? null) === '';
            $ut['rest_bryter_av_utelatt'] = !array_key_exists('lok_vis', amendo_test_forside_svar()['fields']);
            return $ut;

        case 'seksjoner':
            $lagret = amendo_test_forside_lagre(['hero_tittel' => 'Hei', 'seksjoner' => [
                ['id' => 'galleri', 'skjult' => '1'], ['id' => 'hero'], ['id' => 'ukjent'], ['id' => 'galleri'], 'nyheter', ['id' => 'tillit', 'skjult' => ''],
            ]]);
            $forventet = [
                ['id' => 'galleri', 'skjult' => true], ['id' => 'nyheter', 'skjult' => false], ['id' => 'tillit', 'skjult' => false],
                ['id' => 'kategorifliser', 'skjult' => false], ['id' => 'features', 'skjult' => false], ['id' => 'historie', 'skjult' => false],
                ['id' => 'omoss', 'skjult' => false], ['id' => 'lokasjoner', 'skjult' => false], ['id' => 'instagram', 'skjult' => false],
            ];
            $ut['renset_og_komplett'] = ($lagret['seksjoner'] ?? null) === $forventet ?: ($lagret['seksjoner'] ?? null);
            $f = amendo_test_forside_svar()['fields'];
            $ut['i_svaret'] = ($f['seksjoner'] ?? null) === $forventet;
            $ut['ingen_hero'] = !in_array('hero', array_column($f['seksjoner'] ?? [], 'id'), true);

            // Bare skjult, standard rekkefølge: sendes.
            $std = array_map(function($id) { return ['id' => $id]; }, amendo_forside_seksjon_ider());
            $std[3]['skjult'] = '1';
            amendo_test_forside_lagre(['hero_tittel' => 'Hei', 'seksjoner' => $std]);
            $f = amendo_test_forside_svar()['fields'];
            $ut['bare_skjult_sendes'] = ($f['seksjoner'][3] ?? null) === ['id' => 'features', 'skjult' => true];

            // Standard: sendes ikke.
            amendo_test_forside_lagre(['hero_tittel' => 'Hei', 'seksjoner' => array_map(function($id) { return ['id' => $id]; }, amendo_forside_seksjon_ider())]);
            $ut['standard_sendes_ikke'] = !array_key_exists('seksjoner', amendo_test_forside_svar()['fields']);

            // Bare seksjoner endret, ingen andre felter: svaret er ACF + seksjoner.
            amendo_test_forside_lagre(['seksjoner' => [['id' => 'historie']]]);
            $svar = amendo_test_forside_svar();
            $ut['bare_seksjoner_acf_beholdt'] = ($svar['fields']['hero_tittel'] ?? '') === 'ACF hero' && ($svar['fields']['seksjoner'][0]['id'] ?? '') === 'historie';
            $ut['bare_seksjoner_samme_form'] = array_keys($svar) === ['id', 'slug', 'tittel', 'fields'];
            return $ut;

        case 'bildemaal':
            [$h_id, $h_url] = amendo_test_vedlegg('hist-' . wp_rand() . '.jpg', 900, 1200);
            [$k_id, $k_url] = amendo_test_vedlegg('flis-' . wp_rand() . '.jpg', 800, 1000);
            [$g_id, $g_url] = amendo_test_vedlegg('gal-' . wp_rand() . '.jpg', 1080, 1080);
            $lagret = amendo_test_forside_lagre([
                'hero_tittel'    => 'Hei',
                'historie_bilde' => $h_url, 'historie_bilde_id' => (string) $h_id,
                'kategorifliser' => [['term_id' => (string) $ider['kjoler'], 'bilde' => $k_url, 'bilde_id' => '']],
                'galleri_bilder' => [['bilde' => $g_url, 'bilde_id' => (string) $g_id], ['bilde' => 'https://cdn.example/x.jpg']],
            ]);
            $ut['historie_id_lagret'] = ($lagret['historie_bilde_id'] ?? null) === $h_id;
            $ut['flis_url_slaas_opp'] = ($lagret['kategorifliser'][0]['bilde_id'] ?? null) === $k_id;
            $f = amendo_test_forside_svar()['fields'];
            $ut['historie_mal'] = ($f['historie_bilde_bredde'] ?? null) === 900 && ($f['historie_bilde_hoyde'] ?? null) === 1200;
            $n = array_keys($f); $i = array_search('historie_bilde', $n, true);
            $ut['historie_rekkefolge'] = array_slice($n, $i, 4) === ['historie_bilde', 'historie_bilde_id', 'historie_bilde_bredde', 'historie_bilde_hoyde'];
            $ut['flis_mal'] = ($f['kategorifliser'][0]['bilde_bredde'] ?? null) === 800 && ($f['kategorifliser'][0]['bilde_hoyde'] ?? null) === 1000;
            $ut['galleri_mal'] = ($f['galleri_bilder'][0]['bilde_bredde'] ?? null) === 1080;
            $ut['galleri_ekstern_uten_mal'] = !array_key_exists('bilde_bredde', $f['galleri_bilder'][1] ?? ['bilde_bredde' => 1]);
            $ut['tomt_bildefelt_ingen_id'] = !array_key_exists('historie_bilde_id', (function() {
                amendo_test_forside_lagre(['hero_tittel' => 'Hei', 'historie_tittel' => 'X']);
                return amendo_test_forside_svar()['fields'];
            })());
            return $ut;

        case 'slettet_kategori':
            amendo_test_forside_lagre(['hero_tittel' => 'Hei', 'kategorifliser' => [
                ['term_id' => (string) $ider['slettes'], 'tittel' => 'Borte'], ['term_id' => (string) $ider['kjoler'], 'tittel' => 'Kjoler'],
            ]]);
            wp_delete_term($ider['slettes'], 'product_cat');
            $f = amendo_test_forside_svar()['fields'];
            $ut['slettet_kategori_hoppes_over'] = array_column($f['kategorifliser'] ?? [], 'tittel') === ['Kjoler'];
            return $ut;

        case 'migrering':
            // Forside lagret før 1.4.5 (uten *_id) OG felter fra 1.4.13: den
            // engangs-oppslaget som skriver optionen på nytt, må ikke tape dem.
            [, $h_url] = amendo_test_vedlegg('mig-' . wp_rand() . '.jpg', 1200, 800);
            $gammel = amendo_forside_saniter([]);
            foreach (array_keys($gammel) as $k) if (substr($k, -3) === '_id') unset($gammel[$k]);
            $gammel['hero_tittel'] = 'Gammel';
            $gammel['hero_bilde'] = $h_url;
            $gammel['historie_tittel'] = 'Ny historie';
            $gammel['seksjoner'] = [['id' => 'historie', 'skjult' => false]];
            update_option('amendo_forside', wp_json_encode($gammel), false);
            amendo_test_forside_svar();
            $lagret = json_decode(get_option('amendo_forside'), true);
            $ut['migrert'] = array_key_exists('hero_bilde_id', $lagret);
            $ut['nye_felt_beholdt'] = ($lagret['historie_tittel'] ?? '') === 'Ny historie' && ($lagret['seksjoner'][0]['id'] ?? '') === 'historie';
            return $ut;

        case 'garcon_identisk':
            // Amendo har gamle felter, ingen nye: svaret er nøyaktig 1.4.12-formen.
            amendo_test_forside_lagre(['hero_tittel' => 'Amendo hero', 'features_kort' => [['tittel' => 'K']]]);
            $f = amendo_test_forside_svar()['fields'];
            $acf = amendo_test_forside_acf();
            $ut['som_1_4_12'] = $f === array_merge(array_diff_key($acf, amendo_forside_skjema()), amendo_forside_med_dimensjoner(amendo_forside_hent_ren()));
            return $ut;

        case 'admin':
            amendo_test_forside_lagre([
                'hero_tittel'    => 'Hei',
                'kategorifliser' => [['term_id' => (string) $ider['bukser'], 'tittel' => 'Bukser!']],
                'seksjoner'      => [['id' => 'galleri', 'skjult' => '1']],
                'lok_vis'        => '1',
            ]);
            ob_start(); amendo_settings_page(); $html = ob_get_clean();
            $ut['kursiv_etikett'] = strpos($html, 'Tittel, linje 2 (kursiv)') !== false;
            $ut['acf_forhandsutfylt'] = (bool) preg_match('/name="forside\[lok_tittel\]" value="ACF lok"/', $html);
            $ut['flis_valgt'] = (bool) preg_match('/<option value="' . $ider['bukser'] . '"\s+selected=\'selected\'>Klær › Bukser<\/option>/u', $html);
            $ut['kategorinavn_dekodet'] = strpos($html, 'Sko &amp; støvler') !== false && strpos($html, '&amp;amp;') === false;
            $ut['flis_mal'] = strpos($html, 'name="forside[kategorifliser][__i__][term_id]"') !== false;
            $ut['galleri_mal'] = strpos($html, 'name="forside[galleri_bilder][__i__][bilde]"') !== false && strpos($html, 'name="forside[galleri_bilder][__i__][bilde_id]"') !== false;
            preg_match_all('/name="forside\[seksjoner\]\[(\d+)\]\[id\]" value="([a-z]+)"/', $html, $m);
            $ut['seksjoner_i_rekkefolge'] = $m[2] === ['galleri', 'tillit', 'nyheter', 'kategorifliser', 'features', 'historie', 'omoss', 'lokasjoner', 'instagram'] ?: $m[2];
            $ut['seksjoner_nummerert'] = $m[1] === array_map('strval', range(0, 8));
            $ut['skjul_avkrysset'] = (bool) preg_match('/name="forside\[seksjoner\]\[0\]\[skjult\]" value="1"\s+checked/', $html);
            $ut['lok_vis_avkrysset'] = (bool) preg_match('/name="forside\[lok_vis\]" value="1"\s+checked/', $html);
            $ut['nyhetsbrev_felt'] = strpos($html, 'name="forside[nyhetsbrev_tittel]"') !== false && strpos($html, 'name="forside[nyhetsbrev_tekst]"') !== false;
            $ut['hero_ikke_i_lista'] = strpos($html, 'value="hero"') === false;
            return $ut;
    }
    return ['feil' => 'ukjent sak'];
}
