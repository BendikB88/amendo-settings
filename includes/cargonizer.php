<?php
/**
 * Cargonizer-data (_lcfwc_*) på ordrer som opprettes via REST.
 *
 * cargonizer-connect lagrer tjeneste, transportavtale, vekt og hentested i
 * LCFWC_Checkout::save_pickup_point_data_to_order(), koblet på
 * woocommerce_checkout_update_order_meta og kco_wc_process_payment. Ingen av
 * dem kjøres når headless-kassen lager ordren med POST /wc/v3/orders, så
 * Cargonizer visste verken tjeneste eller hentested for disse ordrene.
 *
 * Om Cargonizer finnes, sjekkes først når ordren lages (se kroken under).
 *
 * Når en ordre OPPRETTES via REST, med en fraktlinje hvis metode-instans har
 * `lcfwc_service`, og ordren ikke allerede har `_lcfwc_service`:
 *
 * 1. Kall pluginens egen metode direkte, på instansen den selv har registrert
 *    på woocommerce_checkout_update_order_meta. IKKE do_action på kroken —
 *    da ville også andre plugins' kasse-lyttere kjørt en gang til.
 * 2. Mangler _lcfwc_service fortsatt: lcfwc_save_cargonizer_shipment_data_to_order()
 *    med hentestedet fra fraktlinjens krokedil_selected_pickup_point, og
 *    _lcfwc_order_total_weight regnet ut her (funksjonen setter ikke vekt).
 * 3. Finnes ingen av delene (Cargonizer fjernet eller endret): ingenting endres,
 *    én linje logges (WooCommerce › Status › Logger, kilde amendo-cargonizer).
 *
 * Frontend må sende `instance_id` på fraktlinjen (andre del av `id` fra
 * /amendo-settings/v1/frakt, f.eks. "flat_rate:3" → method_id "flat_rate",
 * instance_id "3"). Uten den finnes ikke `lcfwc_service`, og ingenting skjer.
 */

if (!defined('ABSPATH')) exit;

// ⚠ Kroken registreres UBETINGET. cargonizer-connect laster klassene sine selv
// på plugins_loaded, etter vår kode — en sjekk ved lasting eller på
// plugins_loaded (1.4.2) så dem aldri, og kroken ble aldri koblet på.
// class_exists/function_exists sjekkes når ordren lages. Butikker uten
// Cargonizer stopper før det: ingen fraktinstans har lcfwc_service, så de
// får verken arbeid eller logglinjer.
add_action('woocommerce_rest_insert_shop_order_object', 'amendo_cargonizer_rest_ordre', 20, 3);

function amendo_cargonizer_rest_ordre($order, $request, $creating) {
    if (!$creating || !$order instanceof WC_Order) return;
    if ($order->get_meta('_lcfwc_service') !== '') return;

    $frakt = amendo_cargonizer_fraktlinje($order);
    if (!$frakt) return;

    $ordre_id = $order->get_id();
    $logg = function($melding) use ($ordre_id) {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->warning("Ordre $ordre_id: $melding", ['source' => 'amendo-cargonizer']);
        }
    };

    $metode = amendo_cargonizer_plugin_metode();
    if ($metode) {
        try {
            $metode($ordre_id);
        } catch (Throwable $e) {
            $logg('save_pickup_point_data_to_order feilet: ' . $e->getMessage());
        }
    }

    $ordre = wc_get_order($ordre_id);
    if (!$ordre) return;

    if ($ordre->get_meta('_lcfwc_service') === '') {
        if (!function_exists('lcfwc_save_cargonizer_shipment_data_to_order')) {
            $logg($metode
                ? 'Cargonizer satte ikke _lcfwc_service, og lcfwc_save_cargonizer_shipment_data_to_order finnes ikke. Ingen Cargonizer-data lagret.'
                : 'Fant verken LCFWC_Checkout::save_pickup_point_data_to_order på woocommerce_checkout_update_order_meta eller lcfwc_save_cargonizer_shipment_data_to_order. Ingen Cargonizer-data lagret.');
            return;
        }

        $punkt = $frakt['hentested'];
        $punktdata = [];
        if ($punkt) {
            $punktdata['pickup_points_' . $frakt['tjeneste']] = [[
                'number'   => $punkt['id'],
                'name'     => $punkt['name'],
                'address1' => $punkt['street'],
                'postcode' => $punkt['postcode'],
                'city'     => $punkt['city'],
                'country'  => $punkt['country'],
            ]];
        }
        try {
            lcfwc_save_cargonizer_shipment_data_to_order($ordre, $frakt['tjeneste'], $punkt ? $punkt['id'] : '', $punktdata);
        } catch (Throwable $e) {
            $logg('lcfwc_save_cargonizer_shipment_data_to_order feilet: ' . $e->getMessage());
            return;
        }

        $ordre = wc_get_order($ordre_id);
        if (!$ordre || $ordre->get_meta('_lcfwc_service') === '') {
            $logg('lcfwc_save_cargonizer_shipment_data_to_order satte ikke _lcfwc_service.');
            return;
        }
        if ($ordre->get_meta('_lcfwc_order_total_weight') === '') {
            $ordre->update_meta_data('_lcfwc_order_total_weight', amendo_cargonizer_vekt_kg($ordre));
            $ordre->save();
        }
    }

    $navn = (string) $ordre->get_meta('_lcfwc_service_name');
    if ($navn === '') $navn = (string) $ordre->get_meta('_lcfwc_service');
    $notat = 'Cargonizer-data satt fra REST: ' . $navn;
    $punkt_navn = amendo_cargonizer_hentestednavn($ordre, $frakt['hentested']);
    if ($punkt_navn !== '') $notat .= ', hentested ' . $punkt_navn;
    $ordre->add_order_note($notat);
}

/**
 * Første fraktlinje hvis metode-instans har `lcfwc_service`.
 *
 * @return array{tjeneste: string, hentested: ?array}|null
 */
function amendo_cargonizer_fraktlinje(WC_Order $order) {
    foreach ($order->get_items('shipping') as $linje) {
        $metode   = (string) $linje->get_method_id();
        $instans  = (int) $linje->get_instance_id();
        if ($metode === '' || !$instans) continue;

        $innstillinger = get_option("woocommerce_{$metode}_{$instans}_settings", []);
        $tjeneste = is_array($innstillinger) ? trim((string) ($innstillinger['lcfwc_service'] ?? '')) : '';
        if ($tjeneste === '') continue;

        return ['tjeneste' => $tjeneste, 'hentested' => amendo_cargonizer_hentested($linje)];
    }
    return null;
}

/** krokedil_selected_pickup_point fra fraktlinjen, eller null. */
function amendo_cargonizer_hentested(WC_Order_Item_Shipping $linje) {
    $raa = $linje->get_meta('krokedil_selected_pickup_point');
    $data = is_string($raa) ? json_decode($raa, true) : (is_object($raa) ? json_decode(wp_json_encode($raa), true) : $raa);
    if (!is_array($data) || empty($data['id'])) return null;

    $adresse = is_array($data['address'] ?? null) ? $data['address'] : [];
    return [
        'id'       => sanitize_text_field((string) $data['id']),
        'name'     => sanitize_text_field((string) ($data['name'] ?? '')),
        'street'   => sanitize_text_field((string) ($adresse['street'] ?? '')),
        'postcode' => sanitize_text_field((string) ($adresse['postcode'] ?? '')),
        'city'     => sanitize_text_field((string) ($adresse['city'] ?? '')),
        'country'  => sanitize_text_field((string) ($adresse['country'] ?? '')),
    ];
}

/**
 * LCFWC_Checkout::save_pickup_point_data_to_order på instansen pluginen selv
 * har registrert på woocommerce_checkout_update_order_meta, eller null.
 */
function amendo_cargonizer_plugin_metode() {
    global $wp_filter;
    if (!class_exists('LCFWC_Checkout') || empty($wp_filter['woocommerce_checkout_update_order_meta'])) return null;

    foreach ($wp_filter['woocommerce_checkout_update_order_meta']->callbacks as $lyttere) {
        foreach ($lyttere as $lytter) {
            $f = $lytter['function'];
            if (is_array($f) && $f[0] instanceof LCFWC_Checkout && $f[1] === 'save_pickup_point_data_to_order') {
                return [$f[0], 'save_pickup_point_data_to_order'];
            }
        }
    }
    return null;
}

/**
 * Totalvekt i kg, regnet som cargonizer-connect selv gjør det: produkter med
 * has_weight(), wc_get_weight(..., 'kg') × antall, uten avrunding. En ordre
 * uten vekt får 0.
 */
function amendo_cargonizer_vekt_kg(WC_Order $order) {
    $sum = 0;
    foreach ($order->get_items() as $linje) {
        $produkt = $linje->get_product();
        if ($produkt && $produkt->has_weight()) {
            $sum += wc_get_weight(floatval($produkt->get_weight()), 'kg') * $linje->get_quantity();
        }
    }
    return $sum;
}

/** Hentestedets navn: fra Cargonizer-dataen, ellers fra fraktlinjen. */
function amendo_cargonizer_hentestednavn(WC_Order $order, $hentested) {
    $data = $order->get_meta('_lcfwc_pickup_point_data');
    if (is_string($data)) $data = json_decode($data, true) ?: maybe_unserialize($data);
    if (is_array($data) && !empty($data['name'])) return (string) $data['name'];
    return $hentested ? (string) $hentested['name'] : '';
}
