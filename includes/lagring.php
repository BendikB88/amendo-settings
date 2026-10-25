<?php
/**
 * Lagring av faner som lagres som ÉN samlet option (1.4.16).
 *
 * ⚠⚠ HELE ADMIN-SKJEMAET SENDES VED HVER LAGRING. En fane som er lagret som én
 * option (forsiden, menyen, kategoriraden, avdelingene, de stengte periodene,
 * kategori-reglene, B2B-kundegruppene) ble derfor skrevet på nytt med det
 * nettleseren hadde da siden ble åpnet — også når noen bare lagret en annen
 * fane. Ble optionen endret et annet sted i mellomtiden, gikk den endringen
 * tapt. På garcon.no ble forsiden overskrevet med tomme felt slik.
 *
 * Nå har hver slik del to skjulte felt i skjemaet:
 *     amendo_endret[<del>]   ''  — settes til '1' av admin.js ved første
 *                                  endring i delen (skriving, valg, knapper,
 *                                  dra-og-slipp)
 *     amendo_versjon[<del>]  md5 av optionen slik den var da siden ble lastet
 *
 * - Delen lagres BARE når amendo_endret[<del>] er '1'.
 * - Er delen endret i nettleseren OG optionen endret på serveren etter at
 *   siden ble lastet, avvises HELE lagringen med beskjed om å laste siden på
 *   nytt. Ellers ville den andre endringen blitt overskrevet.
 * - ⚠ Mangler amendo_endret[<del>] helt (et eldre skjema, en annen klient),
 *   lagres delen som før. Bakoverkompatibelt.
 */

if (!defined('ABSPATH')) exit;

/** del => option. Moduler legger til sine via filteret. */
function amendo_lagring_deler() {
    return apply_filters('amendo_lagring_deler', [
        'forside'        => 'amendo_forside',
        'meny'           => 'amendo_meny',
        'kategorirad'    => 'amendo_kategorirad',
        'avdelinger'     => 'amendo_avdelinger',
        'stengte'        => 'amendo_stengte_perioder',
        'kategoriregler' => 'amendo_kategori_regler',
        'antallregler'   => 'amendo_antall_regler',
    ]);
}

/** Versjonen av optionen nå. */
function amendo_lagring_versjon($del) {
    $option = amendo_lagring_deler()[$del] ?? null;
    return $option ? md5(maybe_serialize(get_option($option, ''))) : '';
}

/** Skal delen lagres? Se toppen: '1' = ja, '' = nei, mangler = ja (som før). */
function amendo_lagring_skal_lagre(array $post, $del) {
    if (!isset($post['amendo_endret']) || !is_array($post['amendo_endret'])) return true;
    if (!array_key_exists($del, $post['amendo_endret'])) return true;
    return $post['amendo_endret'][$del] === '1';
}

/**
 * Delene som er endret i nettleseren, men som er endret på serveren siden
 * siden ble lastet. Tom liste = trygt å lagre.
 */
function amendo_lagring_konflikter(array $post) {
    $ut = [];
    foreach (array_keys(amendo_lagring_deler()) as $del) {
        if (($post['amendo_endret'][$del] ?? null) !== '1') continue;
        $sendt = (string) ($post['amendo_versjon'][$del] ?? '');
        if ($sendt !== '' && !hash_equals(amendo_lagring_versjon($del), $sendt)) $ut[] = $del;
    }
    return $ut;
}

/** De to skjulte feltene. Skrives inne i elementet med data-amendo-del. */
function amendo_lagring_felt($del) {
    printf(
        '<input type="hidden" class="amendo-endret" name="amendo_endret[%1$s]" value=""><input type="hidden" name="amendo_versjon[%1$s]" value="%2$s">',
        esc_attr($del),
        esc_attr(amendo_lagring_versjon($del))
    );
}

/** Navnene brukeren ser, til beskjeden om avvist lagring. */
function amendo_lagring_navn($del) {
    $navn = apply_filters('amendo_lagring_navn', [
        'forside' => 'Forside', 'meny' => 'Meny', 'kategorirad' => 'Kategoriraden',
        'avdelinger' => 'Avdelinger', 'stengte' => 'Stengte perioder', 'kategoriregler' => 'Kategori-overstyringer', 'antallregler' => 'Antallsregler',
    ]);
    return $navn[$del] ?? $del;
}
