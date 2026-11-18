<?php
/**
 * Org.nr. på ordren (1.4.28): `_billing_org_number` under firmanavnet i
 * fakturaadressen. Del av B2B-modulen: lastes bare når B2B er på.
 *
 * Nettbutikken (garcon-b2b, lib/ordrefirma.ts) skriver firmanavnet i
 * billing_company og org.nr. i ordremetaen `_billing_org_number` på
 * bedriftsbestillinger (samme navn som brukerfeltet `billing_org_number`, med
 * WooCommerce sin understrek for ordrer). WooCommerce har ikke noe org.nr.-felt,
 * så uten dette står nummeret bare i databasen.
 *
 * Fakturaadressen er det ÉNE stedet wp-admin (ordresiden), e-postene
 * (emails/email-addresses.php) og PDF-fakturaen fra WP Overnight alle henter
 * fra — $order->get_formatted_billing_address(). Derfor legges nummeret inn
 * der, rett under firmanavnet, og ikke i tre maler.
 *
 * Bare nøyaktig ni siffer vises, som «Org.nr. 923 207 074». Leveringsadressen
 * får det aldri, og adresser uten nummer ser ut som før (WooCommerce fjerner
 * tomme linjer). Feltet kan redigeres i wp-admin (Fakturering → Org.nr.).
 *
 * Utkast fra Garçon-økta; endret: WooCommerce kjører allerede esc_html() på
 * erstatningene, så verdien escapes ikke en gang til her.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_ORGNR_ORDREMETA = '_billing_org_number';

/** Ni siffer, eller tom streng. */
function amendo_orgnr_for_ordre($ordre) {
    if (!$ordre instanceof WC_Order) return '';
    $nr = preg_replace('/\D/', '', (string) $ordre->get_meta(AMENDO_ORGNR_ORDREMETA));
    return strlen($nr) === 9 ? $nr : '';
}

function amendo_orgnr_formater($nr) {
    return 'Org.nr. ' . substr($nr, 0, 3) . ' ' . substr($nr, 3, 3) . ' ' . substr($nr, 6);
}

// Bare FAKTURAadressen får nummeret.
add_filter('woocommerce_order_formatted_billing_address', function($adresse, $ordre) {
    $nr = amendo_orgnr_for_ordre($ordre);
    if ($nr !== '' && is_array($adresse)) $adresse['amendo_orgnr'] = amendo_orgnr_formater($nr);
    return $adresse;
}, 10, 2);

add_filter('woocommerce_formatted_address_replacements', function($erstatt, $args) {
    $erstatt['{amendo_orgnr}'] = isset($args['amendo_orgnr']) ? (string) $args['amendo_orgnr'] : '';
    return $erstatt;
}, 10, 2);

// Plassholderen rett etter {company} i alle adresseformatene.
add_filter('woocommerce_localisation_address_formats', function($formater) {
    foreach ($formater as $land => $f) {
        if (strpos($f, '{amendo_orgnr}') !== false) continue;
        $formater[$land] = strpos($f, '{company}') !== false
            ? str_replace('{company}', "{company}\n{amendo_orgnr}", $f)
            : $f . "\n{amendo_orgnr}";
    }
    return $formater;
}, 20);

// Redigerbart i wp-admin. Nøkkelen `org_number` lagres som `_billing_org_number`.
add_filter('woocommerce_admin_billing_fields', function($felt) {
    $ny = [];
    foreach ((array) $felt as $k => $v) {
        $ny[$k] = $v;
        if ($k === 'company') $ny['org_number'] = ['label' => 'Org.nr.', 'show' => false];
    }
    if (!isset($ny['org_number'])) $ny['org_number'] = ['label' => 'Org.nr.', 'show' => false];
    return $ny;
});

// ── Org.nr. fra kassen i et vanlig tema (1.4.43) ────────────────────────────
//
// Uten headless-frontend (Frontend-URL tom) er det ingen som skriver
// `_billing_org_number` på ordren. Når en GODKJENT bedrift handler — kunden har
// `billing_org_number` med ni siffer, som Godkjenn i Søknader og automatisk
// godkjenning skriver — kopieres nummeret fra kunden til ordren når ordren
// lages i kassen: den klassiske (woocommerce_checkout_create_order) og Store
// API-kassen (woocommerce_store_api_checkout_update_order_from_request). Da
// vises det som over: i wp-admin, e-postene og PDF-fakturaen.
//
// Et nummer som alt står på ordren, overskrives aldri. Gjester, kunder uten
// godkjent org.nr, ordrer fra wp-admin og REST (wc/v3) røres ikke. Med
// Frontend-URL satt: ingenting (frontenden skriver nummeret selv).

/** Kundens godkjente org.nr. (ni siffer), eller ''. */
function amendo_orgnr_for_kunde($kunde_id) {
    $kunde_id = (int) $kunde_id;
    if ($kunde_id <= 0) return '';
    $nr = amendo_b2b_orgnr(get_user_meta($kunde_id, 'billing_org_number', true));
    return strlen($nr) === 9 ? $nr : '';
}

function amendo_orgnr_fra_kunden($ordre) {
    if (!$ordre instanceof WC_Order || amendo_frontend_url() !== '') return;
    if (preg_replace('/\D/', '', (string) $ordre->get_meta(AMENDO_ORGNR_ORDREMETA)) !== '') return;
    $nr = amendo_orgnr_for_kunde($ordre->get_customer_id());
    if ($nr !== '') $ordre->update_meta_data(AMENDO_ORGNR_ORDREMETA, $nr);
}
add_action('woocommerce_checkout_create_order', function($ordre) { amendo_orgnr_fra_kunden($ordre); }, 20);
add_action('woocommerce_store_api_checkout_update_order_from_request', function($ordre) { amendo_orgnr_fra_kunden($ordre); }, 20);
