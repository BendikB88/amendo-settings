<?php
/**
 * Modulen Gavekort (1.4.23). Lastes BARE når modulen er på — se
 * includes/moduler.php. Bygget mot WooCommerce Gift Cards 2.7.5
 * (woocommerce-gift-cards), som Aanerud bruker.
 *
 * ⚠⚠ SALDOEN FØRES AV PLUGINEN, IKKE HER. Vi kaller bare pluginens egne
 * funksjoner, slik dens egen kasse gjør. Da blir aktivitetsloggen, «pending
 * balance», tilbakeføringen ved kansellering og refusjon og e-postene
 * pluginens egne.
 *
 * ── POST gavekort/sjekk { kode } (X-Amendo-Secret) ────────────────────────
 *   → { gyldig:true,  saldo, valuta, utloper: 'YYYY-MM-DD' | null }
 *   → { gyldig:false, saldo:0, valuta, utloper:null }
 * ⚠ SAMME svar for ukjent, brukt opp, utløpt, deaktivert og koblet til en
 * konto: ellers kunne svaret brukes til å finne koder som finnes. Gyldig er
 * det pluginens kasse godtar (apply_giftcard_to_session): aktivt, ikke utløpt,
 * ikke koblet til en kundekonto og saldo > 0.
 * Bremset per IP og per kode (429): se amendo_gavekort_brems().
 *
 * ── POST gavekort/los-inn { order_id, kode, belop } (X-Amendo-Secret) ─────
 * Etter at frontenden har laget ordren (status pending). Legger til en
 * gavekortlinje (WC_GC_Order_Item_Gift_Card) med beløpet, lagrer ordren
 * (pluginen trekker gavekortene fra totalen) og trekker beløpet fra kortet
 * med pluginens maybe_debit_giftcards(), som i kassen. Kortet er låst
 * (lock_transactions) mens det skjer, som i kassen.
 *   → { ok:true, trukket, saldo, ordre_total }
 *   → 400 ugyldig · 404 ordre · 409 { feil: ikke_gyldig | for_mye | allerede | ikke_ubetalt | opptatt }
 * Beløpene er kroner med desimaler, som i WooCommerce.
 * Tilbake på kortet: pluginen gjør det selv når ordren blir cancelled, failed
 * eller refunded (også delvis refusjon via ordresiden). Ubetalte
 * _nextjs_kasse-ordrer kanselleres av includes/ubetalte.php — da kommer
 * beløpet tilbake.
 *
 * ── KJØP av gavekort ──────────────────────────────────────────────────────
 * Pluginen lager kortet når ordren betales (woocommerce_payment_complete)
 * eller fullføres, ut fra meta på varelinjen. I sin egen kasse skriver den
 * metaen i woocommerce_checkout_create_order_line_item, som IKKE kjøres for
 * ordrer laget via REST (wc/v3/orders). Frontenden sender dem derfor som
 * line_items[].meta_data, med disse nøklene (get_form_fields() i 2.7.5):
 *     wc_gc_giftcard_to          mottakerens e-post (flere: kommaseparert,
 *                                eller wc_gc_giftcard_to_multiple)
 *     wc_gc_giftcard_cc          kopi til (e-post), valgfritt
 *     wc_gc_giftcard_from        avsenderens navn
 *     wc_gc_giftcard_message     hilsen
 *     wc_gc_giftcard_delivery    leveringsdato som Unix-tid i UTC, valgfritt
 *                                (tom = sendes når ordren er betalt)
 *     wc_gc_giftcard_amount      beløpet kortet får. ⚠ Settes ALLTID her fra
 *                                varens pris (som pluginen gjør), uansett hva
 *                                som ble sendt — ellers kunne en kunde kjøpt
 *                                et kort på 5 000 kr for prisen av ett på 100.
 * wc_gc_giftcards og wc_gc_giftcard_code er pluginens egne og fjernes hvis
 * de sendes.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_GAVEKORT_BREMS_IP   = 30; // forsøk per vindu og IP (Vercel deler IP mellom kunder)
const AMENDO_GAVEKORT_BREMS_KODE = 5;  // forsøk per vindu og kode
const AMENDO_GAVEKORT_VINDU      = 10 * MINUTE_IN_SECONDS;

/** Er WooCommerce Gift Cards aktivt med det vi bruker? */
function amendo_gavekort_klar() {
    return function_exists('WC_GC') && function_exists('wc_gc_get_gift_card_by_code')
        && class_exists('WC_GC_Gift_Card') && class_exists('WC_GC_Order_Item_Gift_Card');
}

add_action('rest_api_init', function() {
    foreach (['gavekort/sjekk' => 'amendo_gavekort_sjekk', 'gavekort/los-inn' => 'amendo_gavekort_los_inn'] as $rute => $cb) {
        register_rest_route('amendo-settings/v1', '/' . $rute, [
            'methods'             => 'POST',
            'callback'            => $cb,
            'permission_callback' => 'amendo_gavekort_tilgang',
        ]);
    }
});

/** Fail-closed: ingen secret satt → 503, feil eller ingen header → 401. */
function amendo_gavekort_tilgang(WP_REST_Request $request) {
    $secret = (string) get_option('amendo_kasse_secret', '');
    if ($secret === '') return new WP_Error('amendo_kasse_secret_mangler', 'Kassens secret er ikke satt i Amendo-admin.', ['status' => 503]);
    if (!hash_equals($secret, (string) $request->get_header('X-Amendo-Secret'))) {
        return new WP_Error('amendo_ingen_tilgang', 'Ugyldig secret.', ['status' => 401]);
    }
    return true;
}

/**
 * Koden slik pluginen lagrer den: XXXX-XXXX-XXXX-XXXX med store bokstaver
 * (wc_gc_is_gift_card_code(), og oppslaget krever den formen). Kunden kan
 * skrive små bokstaver, mellomrom i stedet for bindestrek eller ingenting
 * mellom gruppene. Er det ikke 16 tegn, brukes koden som den er (store
 * bokstaver, uten mellomrom), og den finnes ikke.
 */
function amendo_gavekort_kode($kode) {
    if (!is_scalar($kode)) return '';
    $kode = strtoupper(preg_replace('/\s+/', '', (string) $kode));
    $tegn = preg_replace('/[^A-Z0-9]/', '', $kode);
    if (strlen($tegn) === 16 && preg_match('/^[A-Z0-9]{4}(-?[A-Z0-9]{4}){3}$/', $kode)) {
        return implode('-', str_split($tegn, 4));
    }
    return $kode;
}

/**
 * Fast vindu på 10 minutter per IP og per kode, som amendo_bedrift_sok_rate_limit().
 * Teller hvert forsøk, også de gyldige. ⚠ Ikke X-Forwarded-For: den velger
 * angriperen selv.
 *
 * @return int sekunder å vente, 0 = slipp gjennom
 */
function amendo_gavekort_brems($kode) {
    $naa = time();
    $ip  = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $grenser = [
        'amendo_gk_ip_' . md5($ip)     => (int) apply_filters('amendo_gavekort_brems_ip', AMENDO_GAVEKORT_BREMS_IP),
        'amendo_gk_kode_' . md5($kode) => (int) apply_filters('amendo_gavekort_brems_kode', AMENDO_GAVEKORT_BREMS_KODE),
    ];
    $tellere = [];
    foreach ($grenser as $nokkel => $maks) {
        $t = get_transient($nokkel);
        if (!is_array($t) || ($naa - (int) $t['start']) >= AMENDO_GAVEKORT_VINDU) $t = ['start' => $naa, 'antall' => 0];
        if ($t['antall'] >= max(1, $maks)) return max(1, AMENDO_GAVEKORT_VINDU - ($naa - $t['start']));
        $tellere[$nokkel] = $t;
    }
    foreach ($tellere as $nokkel => $t) {
        $t['antall']++;
        set_transient($nokkel, $t, AMENDO_GAVEKORT_VINDU);
    }
    return 0;
}

function amendo_gavekort_bremset($vent) {
    $svar = new WP_REST_Response(['code' => 'amendo_gavekort_brems', 'message' => 'For mange forsøk. Prøv igjen om litt.'], 429);
    $svar->header('Retry-After', (string) $vent);
    return $svar;
}

/** Kortet når pluginens kasse ville godtatt det, ellers null. */
function amendo_gavekort_gyldig($kode) {
    if ($kode === '') return null;
    $data = wc_gc_get_gift_card_by_code($kode);
    if (!$data) return null;
    $kort = new WC_GC_Gift_Card($data);
    if (!$kort->get_id() || !$kort->is_active() || $kort->has_expired() || $kort->is_redeemed() || (float) $kort->get_balance() <= 0) return null;
    if (apply_filters('woocommerce_gc_pre_apply_gift_card_to_session', null, $kort) !== null) return null;
    return $kort;
}

function amendo_gavekort_sjekk(WP_REST_Request $request) {
    if (!amendo_gavekort_klar()) return new WP_REST_Response(['code' => 'amendo_gavekort_mangler', 'message' => 'WooCommerce Gift Cards er ikke aktivt.'], 503);
    $kode = amendo_gavekort_kode(($request->get_json_params() ?: [])['kode'] ?? '');
    if ($kode === '') return new WP_REST_Response(['code' => 'amendo_gavekort_ugyldig', 'message' => 'kode mangler.'], 400);
    $vent = amendo_gavekort_brems($kode);
    if ($vent) return amendo_gavekort_bremset($vent);

    $valuta = get_woocommerce_currency();
    $kort = amendo_gavekort_gyldig($kode);
    if (!$kort) return ['gyldig' => false, 'saldo' => 0, 'valuta' => $valuta, 'utloper' => null];
    $utloper = (int) $kort->get_expire_date();
    return [
        'gyldig'  => true,
        'saldo'   => round((float) $kort->get_balance(), wc_get_price_decimals()),
        'valuta'  => $valuta,
        'utloper' => $utloper ? wp_date('Y-m-d', $utloper) : null,
    ];
}

function amendo_gavekort_feil($status, $feil, $melding) {
    return new WP_REST_Response(['ok' => false, 'feil' => $feil, 'melding' => $melding], $status);
}

function amendo_gavekort_los_inn(WP_REST_Request $request) {
    if (!amendo_gavekort_klar()) return new WP_REST_Response(['code' => 'amendo_gavekort_mangler', 'message' => 'WooCommerce Gift Cards er ikke aktivt.'], 503);
    $p = $request->get_json_params();
    $p = is_array($p) ? $p : [];
    $kode  = amendo_gavekort_kode($p['kode'] ?? '');
    $belop = is_numeric($p['belop'] ?? null) ? round((float) $p['belop'], wc_get_price_decimals()) : 0.0;
    $ordre = is_numeric($p['order_id'] ?? null) ? wc_get_order((int) $p['order_id']) : null;
    if ($kode === '' || $belop <= 0) return amendo_gavekort_feil(400, 'ugyldig', 'kode og belop (> 0) må være med.');
    $vent = amendo_gavekort_brems($kode);
    if ($vent) return amendo_gavekort_bremset($vent);
    if (!$ordre instanceof WC_Order || $ordre instanceof WC_Order_Refund) return amendo_gavekort_feil(404, 'ordre', 'Fant ikke ordren.');
    if (!$ordre->has_status('pending')) return amendo_gavekort_feil(409, 'ikke_ubetalt', 'Ordren er ikke ubetalt.');

    $kort = amendo_gavekort_gyldig($kode);
    if (!$kort) return amendo_gavekort_feil(409, 'ikke_gyldig', 'Gavekortet kan ikke brukes.');
    foreach ($ordre->get_items('gift_card') as $linje) {
        if ((int) $linje->get_giftcard_id() === (int) $kort->get_id()) return amendo_gavekort_feil(409, 'allerede', 'Gavekortet er allerede brukt på ordren.');
    }
    if ($belop > (float) $kort->get_balance()) return amendo_gavekort_feil(409, 'for_mye', 'Beløpet er større enn saldoen.');
    if ($belop > (float) $ordre->get_total()) return amendo_gavekort_feil(409, 'for_mye', 'Beløpet er større enn det som gjenstår på ordren.');

    // Låst mens vi trekker, som i pluginens kasse (checkout_create_order). maybe_debit_giftcards låser opp.
    try {
        $las = $kort->data->lock_transactions();
    } catch (Exception $e) {
        return amendo_gavekort_feil(409, 'opptatt', 'Gavekortet brukes i en annen bestilling akkurat nå. Prøv igjen.');
    }
    if ($las) {
        $laste = $ordre->get_meta('_giftcard_lock_keys');
        $laste = is_array($laste) ? $laste : [];
        $laste[$kort->get_id()] = $las;
        $ordre->update_meta_data('_giftcard_lock_keys', $laste);
    }

    $total_foer = $ordre->get_total();
    $linje = new WC_GC_Order_Item_Gift_Card();
    $linje->set_props(['giftcard_id' => $kort->get_id(), 'code' => $kort->get_code(), 'amount' => $belop]);
    $ordre->add_item($linje);
    $ordre->save(); // pluginen trekker gavekortene fra totalen (update_order)
    $ordre = wc_get_order($ordre->get_id());

    try {
        WC_GC()->order->maybe_debit_giftcards($ordre->get_id(), $ordre);
    } catch (Exception $e) {
        // Saldoen strakk ikke likevel (brukt et annet sted samtidig): linjen ut igjen.
        foreach ($ordre->get_items('gift_card') as $id => $l) {
            if ((int) $l->get_giftcard_id() === (int) $kort->get_id() && !$l->meta_exists('gift_card_debited')) $ordre->remove_item($id);
        }
        // Pluginen regner bare totalen på nytt når det finnes gavekort igjen.
        $ordre->set_total($total_foer);
        $ordre->save();
        return amendo_gavekort_feil(409, 'for_mye', 'Gavekortet har ikke nok saldo.');
    }
    $ordre->save();

    $etter = new WC_GC_Gift_Card($kort->get_id());
    return [
        'ok'          => true,
        'trukket'     => $belop,
        'saldo'       => round((float) $etter->get_balance(), wc_get_price_decimals()),
        'ordre_total' => round((float) wc_get_order($ordre->get_id())->get_total(), wc_get_price_decimals()),
    ];
}

// ── Kjøp: beløpet på kortet fra varens pris ─────────────────────────────────

add_action('woocommerce_rest_insert_shop_order_object', function($ordre, $request, $ny) {
    if (!$ny || !$ordre instanceof WC_Order || !class_exists('WC_GC_Gift_Card_Product')) return;
    $endret = false;
    foreach ($ordre->get_items() as $linje) {
        $produkt = $linje instanceof WC_Order_Item_Product ? $linje->get_product() : null;
        if (!$produkt || !WC_GC_Gift_Card_Product::is_gift_card($produkt)) continue;
        // Som pluginens create_order_line_item(): beløpet er varens ordinære pris.
        $belop = apply_filters('woocommerce_gc_gift_card_amount', $produkt->get_regular_price(), $produkt, $ordre);
        $linje->update_meta_data('wc_gc_giftcard_amount', $belop);
        $linje->delete_meta_data('wc_gc_giftcards');
        $linje->delete_meta_data('wc_gc_giftcard_code');
        $linje->save();
        $endret = true;
    }
    if ($endret) $ordre->save();
}, 10, 3);
