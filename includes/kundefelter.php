<?php
/**
 * Kundefelter med `_` som frontendene skriver og leser via wc/v3 (1.4.28).
 *
 * WordPress regner nøkler som begynner med `_` som beskyttet
 * (is_protected_meta()), og `wc/v3/customers` hopper stille over dem — både når
 * de skrives (POST svarer 201, PUT 200, men ingenting lagres) og når de leses.
 * Målt i Playground med WooCommerce 11.2.1 (02.11.2026); i WooCommerce sin kode
 * er det `! is_protected_meta( $meta['key'], 'user' )` i customers-kontrolleren.
 * Eldre WooCommerce lagret dem, og frontendene er skrevet for det.
 *
 * Feltene under slippes derfor gjennom, for brukere ('user') og bare dem:
 *
 *     _vipps_syntetisk_epost   '1' på Vipps-kunder uten ekte e-post (kontaktes
 *                              på telefon). Settes av garcon-frontend, bakeri-mal
 *                              og emmk ved første Vipps-innlogging.
 *
 * Tellix-modulfilene (b2b-tellix.php) legger til `_tellixGroupId` med filteret
 * `amendo_rest_kundefelter`, så det følger B2B/Helsebygget og ikke alle butikker.
 *
 * ⚠⚠ Søknadsnøklene (`_orgnr_soknad`, `_b2b_status`, `_b2b_sokt`) skal IKKE
 * hit. Da kunne hvem som helst med en wc/v3-nøkkel sette `_b2b_status =
 * godkjent`. Søknaden sendes til POST /b2b-soknad (includes/moduler/b2b.php),
 * som bare kan sette 'venter'.
 *
 * wc/v3 krever uansett en nøkkel med skrivetilgang (edit_users); kundene selv
 * kommer ikke til feltene via Store API eller Min konto.
 */

if (!defined('ABSPATH')) exit;

/** Nøklene som slippes gjennom, uten duplikater. */
function amendo_rest_kundefelter() {
    $felt = apply_filters('amendo_rest_kundefelter', ['_vipps_syntetisk_epost']);
    return array_values(array_unique(array_filter((array) $felt, 'is_string')));
}

add_filter('is_protected_meta', function($beskyttet, $nokkel, $type = '') {
    if ($beskyttet && $type === 'user' && in_array($nokkel, amendo_rest_kundefelter(), true)) return false;
    return $beskyttet;
}, 10, 3);
