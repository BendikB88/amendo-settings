<?php
/**
 * Wolt-status (1.4.30), del av modulen Wolt-levering. Lastes fra wolt.php.
 *
 * Bakgrunn: Wolt kansellerte en bestilt levering selv («No couriers
 * available»), men ordren sto fortsatt som bekreftet, og ingen fikk vite det.
 * Wolt-tillegget bestiller leveransen, men hører aldri fra Wolt etterpå.
 *
 * ── Webhooken ─────────────────────────────────────────────────────────────
 * POST amendo-settings/v1/wolt/webhook. Wolt Drive sender hver hendelse som
 * { "token": "<JWT>" }, signert med HS256 og den client_secret som ble oppgitt
 * da webhooken ble registrert hos Wolt (POST /v1/merchants/{merchant_id}/
 * webhooks). Den secreten er vår «Wolt-webhook-secret» (fanen Wolt, «Generer
 * ny»). Fail-closed: ingen secret = 503, feil signatur = 401.
 *
 * Ordren finnes på (i rekkefølge) wolt_order_reference_id eller details.id mot
 * _ab_wolt_order_ref (mu-pluginen som lagrer Wolts referanse) og
 * _ab_wolt_delivery_id (tillegget), og til slutt merchant_order_reference_id
 * («woo-<ordre-ID>», som tillegget sender) — bare når ordren har
 * _ab_delivery_method = 'wolt' og ikke har en annen _ab_wolt_order_ref eller
 * _ab_wolt_delivery_id enn meldingen. Ukjent ordre = 200 { funnet: false },
 * ingenting endres, og Wolt prøver ikke igjen i det uendelige.
 *
 * ⚠ Oppslaget på metaen går rett mot databasen (HPOS-tabellen eller
 * postmeta), og treffet sjekkes mot ordren etterpå. wc_get_orders() med
 * meta_query ble ikke filtrert i Playground-testen (den ga den nyeste ordren
 * uansett referanse), og da traff en melding feil ordre.
 *
 * Hver statushendelse: _ab_wolt_status oppdateres og et ordrenotat med status
 * og årsak. En sluttstatus (rejected, delivered, not_delivered) overskrives
 * aldri av en eldre eller ikke-endelig hendelse. Samme hendelse to ganger
 * (Wolt prøver igjen) gjør ingenting. ETA-hendelser lagrer bare tiden.
 *
 * ── Varselet ──────────────────────────────────────────────────────────────
 * Wolt har ingen egen «kansellert»-hendelse: kansellering, også fra Wolt selv,
 * kommer som order.rejected. Ved order.rejected og order.not_delivered får
 * butikken en e-post (ordrenummer, kunde, telefon, leveringstid, utsalg,
 * årsak, lenke). Mottaker: avdelingens varseladresse (fanen Wolt, avdelingen
 * med ordrens utsalg), ellers den felles, ellers admin-e-posten. Ikke når
 * butikken selv kansellerte med knappen under. Ordrestatusen endres ALDRI
 * automatisk. Ordrelisten får kolonnen Wolt med et rødt merke.
 *
 * ── Kanseller ─────────────────────────────────────────────────────────────
 * Knappen på ordresiden (erstatter snippet «Kanseller ordre»): PATCH
 * /order/{_ab_wolt_order_ref}/status/cancel med årsaken. Wolt godtar det bare
 * før budet har tatt oppdraget.
 *
 * ── Ingen reservejobb ─────────────────────────────────────────────────────
 * Wolt Drive har ikke noe oppslag på status for en levering (bare webhooks),
 * så det finnes ingenting en jobb kunne spurt om.
 *
 * ⚠ Ingen kundedata i loggen — bare type og antall.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_WOLT_SLUTT = ['rejected', 'delivered', 'not_delivered'];
const AMENDO_WOLT_VARSLE = ['rejected', 'not_delivered'];
const AMENDO_WOLT_STATUSER = [
    'confirmed'         => 'Bestilt hos Wolt',
    'received'          => 'Mottatt av Wolt',
    'pickup_started'    => 'Budet er på vei for å hente',
    'pickup_arrival'    => 'Budet er ved hentestedet',
    'picked_up'         => 'Hentet av budet',
    'dropoff_started'   => 'På vei til mottakeren',
    'dropoff_arrival'   => 'Budet er fremme hos mottakeren',
    'dropoff_completed' => 'Levert til mottakeren',
    'delivered'         => 'Levert',
    'not_delivered'     => 'Ikke levert',
    'rejected'          => 'Avvist eller kansellert av Wolt',
];

// ── Secreten ────────────────────────────────────────────────────────────────

add_filter('amendo_hemmeligheter', function($h) {
    $h['wolt_webhook'] = [
        'option'      => 'amendo_wolt_webhook_secret',
        'tittel'      => 'Wolt-webhook-secret',
        'beskrivelse' => 'Wolt signerer hver statusmelding med denne. Den er client_secret når webhooken registreres hos Wolt.',
        'advarsel'    => '<strong>⚠ Samme verdi må inn som <code>client_secret</code> i webhooken hos Wolt</strong>. Til det er gjort, avvises alle statusmeldinger fra Wolt.',
        'bekreft_ny'  => 'Generere Wolt-webhook-secret? Den må registreres hos Wolt som client_secret.',
        'bekreft_bytt'=> 'Erstatte Wolt-webhook-secreten? Statusmeldinger fra Wolt avvises til webhooken hos Wolt har fått den nye.',
    ];
    return $h;
});

function amendo_wolt_logg($melding) {
    if (function_exists('wc_get_logger')) wc_get_logger()->info($melding, ['source' => 'amendo-wolt']);
}

// ── Webhooken ───────────────────────────────────────────────────────────────

add_action('rest_api_init', function() {
    register_rest_route('amendo-settings/v1', '/wolt/webhook', [
        'methods'             => 'POST',
        'callback'            => 'amendo_wolt_webhook',
        'permission_callback' => '__return_true', // signaturen sjekkes i amendo_wolt_webhook
    ]);
});

function amendo_wolt_b64($s) {
    $s = strtr((string) $s, '-_', '+/');
    return base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

/** Innholdet i en HS256-JWT signert med $secret, eller null. */
function amendo_wolt_jwt($token, $secret) {
    $deler = explode('.', (string) $token);
    if (count($deler) !== 3) return null;
    $hode = json_decode((string) amendo_wolt_b64($deler[0]), true);
    if (!is_array($hode) || ($hode['alg'] ?? '') !== 'HS256') return null;
    $sign = amendo_wolt_b64($deler[2]);
    if ($sign === false || !hash_equals(hash_hmac('sha256', $deler[0] . '.' . $deler[1], $secret, true), $sign)) return null;
    $innhold = json_decode((string) amendo_wolt_b64($deler[1]), true);
    return is_array($innhold) ? $innhold : null;
}

function amendo_wolt_webhook(WP_REST_Request $req) {
    $secret = (string) get_option('amendo_wolt_webhook_secret', '');
    if ($secret === '') return new WP_REST_Response(['ok' => false, 'feil' => 'ikke_satt_opp'], 503);

    $p = $req->get_json_params();
    $token = is_array($p) && is_string($p['token'] ?? null) ? $p['token'] : trim((string) $req->get_body());
    if ($token === '' || substr_count($token, '.') !== 2) return new WP_REST_Response(['ok' => false, 'feil' => 'ugyldig'], 400);

    $h = amendo_wolt_jwt($token, $secret);
    if ($h === null) {
        amendo_wolt_logg('Wolt-webhook: avvist, feil signatur');
        return new WP_REST_Response(['ok' => false, 'feil' => 'signatur'], 401);
    }

    $type = (string) ($h['type'] ?? '');
    $d = is_array($h['details'] ?? null) ? $h['details'] : [];
    if (!preg_match('/^order\.[a-z_]+$/', $type)) return new WP_REST_Response(['ok' => false, 'feil' => 'ugyldig'], 400);
    if (in_array($type, ['order.location_updated', 'order.handshake_delivery'], true)) return ['ok' => true, 'ignorert' => true];

    $ordre = amendo_wolt_finn_ordre($d);
    if (!$ordre) {
        amendo_wolt_logg('Wolt-webhook: fant ikke ordren for ' . $type);
        return ['ok' => true, 'funnet' => false];
    }
    return amendo_wolt_behandle($ordre, $type, $d, (string) ($h['dispatched_at'] ?? ''));
}

/** Nyeste ordre med meta $nokkel = $verdi, eller null. Se toppen. */
function amendo_wolt_ordre_med($nokkel, $verdi) {
    global $wpdb;
    if ($verdi === '') return null;
    $util = 'Automattic\WooCommerce\Utilities\OrderUtil';
    $hpos = class_exists($util) && $util::custom_orders_table_usage_is_enabled();
    $ider = $hpos
        ? $wpdb->get_col($wpdb->prepare("SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s AND meta_value = %s ORDER BY order_id DESC LIMIT 5", $nokkel, $verdi))
        : $wpdb->get_col($wpdb->prepare("SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'shop_order' AND pm.meta_key = %s AND pm.meta_value = %s ORDER BY pm.post_id DESC LIMIT 5", $nokkel, $verdi));
    foreach ((array) $ider as $id) {
        $o = wc_get_order((int) $id);
        if ($o instanceof WC_Order && (string) $o->get_meta($nokkel) === $verdi) return $o;
    }
    return null;
}

function amendo_wolt_er_wolt_ordre(WC_Order $o) {
    return $o->get_meta('_ab_wolt_delivery_id') !== '' || $o->get_meta('_ab_wolt_order_ref') !== ''
        || in_array($o->get_meta('_ab_delivery_method'), ['wolt', 'wolt_venter'], true);
}

function amendo_wolt_finn_ordre(array $d) {
    $ref = is_scalar($d['wolt_order_reference_id'] ?? null) ? trim((string) $d['wolt_order_reference_id']) : '';
    $id  = is_scalar($d['id'] ?? null) ? trim((string) $d['id']) : '';
    foreach (array_unique(array_filter([$ref, $id])) as $verdi) {
        foreach (['_ab_wolt_order_ref', '_ab_wolt_delivery_id'] as $nokkel) {
            if ($o = amendo_wolt_ordre_med($nokkel, $verdi)) return $o;
        }
    }
    $var = is_scalar($d['merchant_order_reference_id'] ?? null) ? trim((string) $d['merchant_order_reference_id']) : '';
    if (!preg_match('/^(?:woo-)?(\d+)$/', $var, $m)) return null;
    $o = wc_get_order((int) $m[1]);
    // Bare en Wolt-ordre, og bare når den ikke har en ANNEN levering enn meldingen.
    if (!$o instanceof WC_Order || $o->get_meta('_ab_delivery_method') !== 'wolt') return null;
    $har_ref = (string) $o->get_meta('_ab_wolt_order_ref');
    $har_id  = (string) $o->get_meta('_ab_wolt_delivery_id');
    if ($har_ref !== '' && $har_ref !== $ref) return null;
    if ($har_id !== '' && $har_id !== $id) return null;
    return $o;
}

/** Årsaken Wolt oppgir, eller ''. */
function amendo_wolt_arsak($status, array $d) {
    if ($status === 'rejected') return is_scalar($d['purchase_rejected_reason'] ?? null) ? (string) $d['purchase_rejected_reason'] : '';
    if ($status === 'not_delivered') {
        $nd = is_array($d['not_delivered_details'] ?? null) ? $d['not_delivered_details'] : [];
        return implode(' – ', array_filter([(string) ($nd['outcome'] ?? ''), (string) ($nd['reason'] ?? '')]));
    }
    return '';
}

function amendo_wolt_behandle(WC_Order $o, $type, array $d, $tid) {
    $status = substr($type, 6);
    if ($status === 'customer_no_show') $status = 'not_delivered'; // utgått hos Wolt

    $ref = is_scalar($d['wolt_order_reference_id'] ?? null) ? trim((string) $d['wolt_order_reference_id']) : '';
    if ($ref !== '' && $o->get_meta('_ab_wolt_order_ref') === '') $o->update_meta_data('_ab_wolt_order_ref', $ref);

    // Wolt prøver igjen når svaret uteblir: samme hendelse skal bare telle én gang.
    $nokkel = md5($type . '|' . $tid . '|' . wp_json_encode($d));
    $sett = (array) $o->get_meta('_amendo_wolt_hendelser');
    if (in_array($nokkel, $sett, true)) return ['ok' => true, 'duplikat' => true];
    $o->update_meta_data('_amendo_wolt_hendelser', array_slice(array_merge($sett, [$nokkel]), -40));

    if (in_array($status, ['pickup_eta_updated', 'dropoff_eta_updated'], true)) {
        $eta = $status === 'pickup_eta_updated' ? ($d['pickup']['eta'] ?? '') : ($d['dropoff']['eta'] ?? '');
        if (is_array($eta)) $eta = (string) ($eta['max'] ?? $eta['min'] ?? '');
        // Lagret som «Y-m-d H:i:s» i UTC, samme form uansett database.
        $t = is_scalar($eta) && (string) $eta !== '' ? strtotime((string) $eta) : false;
        $o->update_meta_data($status === 'pickup_eta_updated' ? '_amendo_wolt_eta_henting' : '_amendo_wolt_eta_levering', $t ? gmdate('Y-m-d H:i:s', $t) : '');
        $o->save();
        return ['ok' => true, 'status' => (string) $o->get_meta('_ab_wolt_status')];
    }

    $forrige = (string) $o->get_meta('_ab_wolt_status');
    $forrige_tid = (string) $o->get_meta('_amendo_wolt_status_tid');
    $eldre = $tid !== '' && $forrige_tid !== '' && strtotime($tid) !== false && strtotime($tid) < strtotime($forrige_tid);
    $endre = !$eldre && !(in_array($forrige, AMENDO_WOLT_SLUTT, true) && !in_array($status, AMENDO_WOLT_SLUTT, true));
    $arsak = amendo_wolt_arsak($status, $d);

    $notat = 'Wolt: ' . (AMENDO_WOLT_STATUSER[$status] ?? $status) . ' (' . $status . ')';
    if ($arsak !== '') $notat .= '. Årsak: ' . $arsak;
    if (!$endre) $notat .= '. Statusen er ikke endret (eldre melding eller allerede avsluttet).';
    if ($endre) {
        $o->update_meta_data('_ab_wolt_status', $status);
        if ($tid !== '') $o->update_meta_data('_amendo_wolt_status_tid', $tid);
        if ($arsak !== '') $o->update_meta_data('_amendo_wolt_arsak', $arsak);
    }

    $varsle = $endre && in_array($status, AMENDO_WOLT_VARSLE, true)
        && !($status === 'rejected' && $o->get_meta('_amendo_wolt_kansellert_av') !== '');
    if ($varsle) {
        $sendt = amendo_wolt_varsle($o, $status, $arsak);
        $notat .= $sendt ? '. Varsel sendt til ' . implode(', ', $sendt) . '.' : '. ⚠ Varselet kunne ikke sendes.';
        $o->update_meta_data('_amendo_wolt_varslet', time());
        amendo_wolt_logg('Wolt-webhook: ' . $status . ', ' . ($sendt ? 'varsel sendt' : 'varselet feilet'));
    }
    $o->add_order_note($notat);
    $o->save();
    return ['ok' => true, 'status' => $endre ? $status : $forrige, 'varslet' => $varsle];
}

// ── Varselet ────────────────────────────────────────────────────────────────

/** Gyldige adresser i en tekst med komma, semikolon eller mellomrom. */
function amendo_wolt_adresser($tekst) {
    $ut = [];
    foreach (preg_split('/[\s,;]+/', (string) $tekst, -1, PREG_SPLIT_NO_EMPTY) as $a) {
        if (is_email($a)) $ut[] = $a;
    }
    return array_values(array_unique($ut));
}

/** Avdelingen med ordrens utsalg, så den felles adressen, så admin-e-posten. */
function amendo_wolt_mottakere(WC_Order $o) {
    $slug = (string) $o->get_meta('_ab_pickup_store');
    if ($slug !== '') {
        foreach (amendo_wolt_regler() as $r) {
            if ($r['utsalg'] === $slug && ($a = amendo_wolt_adresser($r['varsel'] ?? ''))) return $a;
        }
    }
    return amendo_wolt_adresser(get_option('amendo_wolt_varsel_epost', '')) ?: amendo_wolt_adresser(get_option('admin_email', ''));
}

/** Leveringstiden slik ordren har den: dato + luke, ellers Wolts tidspunkt i butikkens tidssone. */
function amendo_wolt_leveringstid(WC_Order $o) {
    $dato = (string) $o->get_meta('_ab_delivery_date');
    $luke = (string) $o->get_meta('_ab_delivery_window');
    if ($dato !== '') return trim($dato . ' ' . $luke);
    $iso = (string) $o->get_meta('_ab_wolt_scheduled_iso');
    $t = $iso !== '' ? strtotime($iso) : false;
    return $t ? wp_date('Y-m-d H:i', $t) : 'ukjent';
}

/** Sender varselet. Returnerer adressene, eller [] når det feilet. */
function amendo_wolt_varsle(WC_Order $o, $status, $arsak) {
    $til = amendo_wolt_mottakere($o);
    if (!$til) return [];
    $nr = $o->get_order_number();
    $kunde = trim($o->get_formatted_billing_full_name() . ($o->get_billing_company() !== '' ? ' (' . $o->get_billing_company() . ')' : ''));
    $slug = (string) $o->get_meta('_ab_pickup_store');
    $utsalg = function_exists('amendo_wolt_utsalg_alle') ? (string) (amendo_wolt_utsalg_alle()[$slug]['label'] ?? $slug) : $slug;
    $mottaker = trim((string) $o->get_meta('_ab_employee_name') . ' ' . (string) $o->get_meta('_ab_employee_phone'));

    $emne = $status === 'rejected'
        ? sprintf('⚠ Wolt har avvist leveringen – ordre #%s', $nr)
        : sprintf('⚠ Wolt leverte ikke – ordre #%s', $nr);
    $linjer = [
        $status === 'rejected'
            ? 'Wolt har avvist eller kansellert leveringen. Ordren er ikke endret: kontakt kunden og finn en annen løsning.'
            : 'Wolt meldte at leveringen ikke ble gjennomført. Ordren er ikke endret: kontakt kunden.',
        '',
        'Ordre: #' . $nr,
        'Kunde: ' . ($kunde !== '' ? $kunde : '–'),
        'Telefon: ' . ($o->get_billing_phone() !== '' ? $o->get_billing_phone() : '–'),
    ];
    if ($mottaker !== '') $linjer[] = 'Mottaker: ' . $mottaker;
    $linjer[] = 'Levering: ' . amendo_wolt_leveringstid($o);
    if ($utsalg !== '') $linjer[] = 'Utsalg: ' . $utsalg;
    $linjer[] = 'Årsak: ' . ($arsak !== '' ? $arsak : 'ikke oppgitt av Wolt');
    $linjer[] = '';
    $linjer[] = 'Åpne ordren: ' . $o->get_edit_order_url();

    return wp_mail($til, $emne, implode("\n", $linjer)) ? $til : [];
}

// ── Ordrelisten og ordresiden ──────────────────────────────────────────────

function amendo_wolt_merke(WC_Order $o) {
    if (!amendo_wolt_er_wolt_ordre($o)) return '–';
    $s = (string) $o->get_meta('_ab_wolt_status');
    if ($s === '') return '<span class="amendo-wolt-status">Ikke bestilt</span>';
    if (in_array($s, AMENDO_WOLT_VARSLE, true)) {
        $tekst = $s === 'rejected' ? '⚠ Avvist av Wolt' : '⚠ Ikke levert';
        return '<mark class="order-status status-failed amendo-wolt-feil" title="' . esc_attr((string) $o->get_meta('_amendo_wolt_arsak')) . '"><span>' . esc_html($tekst) . '</span></mark>';
    }
    return '<span class="amendo-wolt-status">' . esc_html(AMENDO_WOLT_STATUSER[$s] ?? $s) . '</span>';
}

function amendo_wolt_kolonner($kolonner) {
    $ut = [];
    foreach ($kolonner as $k => $v) {
        $ut[$k] = $v;
        if ($k === 'order_status') $ut['amendo_wolt'] = 'Wolt';
    }
    if (!isset($ut['amendo_wolt'])) $ut['amendo_wolt'] = 'Wolt';
    return $ut;
}
add_filter('manage_woocommerce_page_wc-orders_columns', 'amendo_wolt_kolonner');
add_filter('manage_edit-shop_order_columns', 'amendo_wolt_kolonner');

function amendo_wolt_kolonne($kolonne, $ordre) {
    if ($kolonne !== 'amendo_wolt') return;
    $o = $ordre instanceof WC_Order ? $ordre : wc_get_order($ordre);
    echo $o ? amendo_wolt_merke($o) : '–';
}
add_action('manage_woocommerce_page_wc-orders_custom_column', 'amendo_wolt_kolonne', 10, 2);
add_action('manage_shop_order_posts_custom_column', 'amendo_wolt_kolonne', 10, 2);

const AMENDO_WOLT_KANSELLER_SVAR = [
    'ok'          => 'Leveringen er kansellert hos Wolt. Ordrestatusen er ikke endret.',
    'mangler_ref' => 'Ordren har ingen Wolt-referanse, så den kan ikke kanselleres herfra.',
    'avsluttet'   => 'Leveringen er allerede avsluttet hos Wolt.',
    'wolt'        => 'Wolt avviste kanselleringen. Se ordrenotatet.',
    'kontakt'     => 'Fikk ikke kontakt med Wolt. Prøv igjen.',
];

/** Boksen på ordresiden: status, årsak og «Kanseller hos Wolt». */
function amendo_wolt_ordreboks($ordre) {
    if (!$ordre instanceof WC_Order || !amendo_wolt_er_wolt_ordre($ordre)) return;
    $s = (string) $ordre->get_meta('_ab_wolt_status');
    $ref = (string) $ordre->get_meta('_ab_wolt_order_ref');
    $arsak = (string) $ordre->get_meta('_amendo_wolt_arsak');
    $svar = sanitize_key((string) ($_GET['amendo_wolt'] ?? ''));
    echo '<div class="form-field form-field-wide amendo-wolt-boks"><h3>Wolt</h3><p>' . amendo_wolt_merke($ordre) . '</p>';
    if ($arsak !== '') echo '<p>Årsak: ' . esc_html($arsak) . '</p>';
    if (isset(AMENDO_WOLT_KANSELLER_SVAR[$svar])) echo '<p class="amendo-wolt-svar"><strong>' . esc_html(AMENDO_WOLT_KANSELLER_SVAR[$svar]) . '</strong></p>';
    if ($ref !== '' && !in_array($s, AMENDO_WOLT_SLUTT, true)) {
        $url = wp_nonce_url(admin_url('admin-post.php?action=amendo_wolt_kanseller&ordre=' . $ordre->get_id()), 'amendo_wolt_kanseller_' . $ordre->get_id());
        echo '<p><a href="' . esc_url($url) . '" class="button amendo-wolt-kanseller" onclick="var a=window.prompt(\'Årsak til kanselleringen (sendes til Wolt):\',\'Kansellert av butikken\');if(a===null||a===\'\')return false;this.href+=\'&arsak=\'+encodeURIComponent(a);">Kanseller hos Wolt</a></p>';
        echo '<p class="description">Bare leveringen hos Wolt kanselleres, ikke ordren. Wolt godtar det bare før budet har tatt oppdraget.</p>';
    }
    echo '</div>';
}
add_action('woocommerce_admin_order_data_after_shipping_address', 'amendo_wolt_ordreboks');

/**
 * PATCH /order/{ref}/status/cancel med utsalgets nøkkel (samme rekkefølge som
 * amendo_wolt_post). Returnerer svarkoden over (AMENDO_WOLT_KANSELLER_SVAR).
 */
function amendo_wolt_kanseller(WC_Order $o, $arsak) {
    $ref = (string) $o->get_meta('_ab_wolt_order_ref');
    if ($ref === '') return 'mangler_ref';
    if (in_array((string) $o->get_meta('_ab_wolt_status'), AMENDO_WOLT_SLUTT, true)) return 'avsluttet';
    $oppsett = amendo_wolt_oppsett();
    if ($oppsett === null) return 'kontakt';
    $utsalg = amendo_wolt_utsalg_alle()[(string) $o->get_meta('_ab_pickup_store')] ?? [];
    $arsak = trim($arsak) !== '' ? trim($arsak) : 'Kansellert av butikken';
    $svar = amendo_wolt_kall($oppsett, $utsalg, 'PATCH', '/order/' . rawurlencode($ref) . '/status/cancel', ['reason' => $arsak]);
    $hvem = wp_get_current_user()->display_name ?: 'ukjent';
    if (is_wp_error($svar)) {
        $o->add_order_note('Wolt: kanselleringen feilet (' . $svar->get_error_message() . '). Forsøkt av ' . $hvem . '.');
        return 'kontakt';
    }
    if ($svar['kode'] < 200 || $svar['kode'] >= 300) {
        $feil = (string) ($svar['json']['details'] ?? $svar['json']['reason'] ?? $svar['json']['error_code'] ?? ('HTTP ' . $svar['kode']));
        $o->add_order_note('Wolt avviste kanselleringen: ' . substr($feil, 0, 300) . '. Forsøkt av ' . $hvem . '.');
        return 'wolt';
    }
    $o->update_meta_data('_amendo_wolt_kansellert_av', get_current_user_id());
    $o->update_meta_data('_ab_wolt_status', 'rejected');
    $o->update_meta_data('_amendo_wolt_arsak', 'Kansellert av butikken: ' . $arsak);
    $o->add_order_note('Wolt: leveringen er kansellert av ' . $hvem . '. Årsak: ' . $arsak . '. Ordrestatusen er ikke endret.');
    $o->save();
    return 'ok';
}

add_action('admin_post_amendo_wolt_kanseller', function() {
    $id = absint($_GET['ordre'] ?? 0);
    if (!$id || !(current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce'))) wp_die('Ingen tilgang.', 403);
    check_admin_referer('amendo_wolt_kanseller_' . $id);
    $o = wc_get_order($id);
    if (!$o) wp_die('Fant ikke ordren.', 404);
    $svar = amendo_wolt_kanseller($o, sanitize_text_field(wp_unslash((string) ($_GET['arsak'] ?? ''))));
    wp_safe_redirect(add_query_arg('amendo_wolt', $svar, $o->get_edit_order_url()));
    exit;
});
