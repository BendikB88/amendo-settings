<?php
/**
 * Gratis-frakt-grensen fra Holio Shipping i POST /frakt (1.4.47).
 *
 * `/frakt` meldte bare grensen til WooCommerces egen `free_shipping` i sonen.
 * Holio Shipping (testet mot 1.4.2) har ingen slik metode: sonen har én
 * `holio_shipping`-metode, og grensen står på hvert av Holios fraktvalg
 * (`is_free_shipping` + `free_shipping_threshold`). Fraktvalgene ligger i Holios
 * egen backend og hentes over REST av `Holio_Shipping_Option_Repository`.
 * Raten blir 0 kr over grensen, men `gratis_frakt_grense` var null
 * (målt på en butikk: 149 kr → 0 kr for en kurv på 6 999 kr).
 *
 * Her LESER vi bare: Holios egen `find_enabled_by_shop_id()`, aldri noe som
 * skriver, og ingenting i Holio endres. Bare fraktvalg som
 *   - har en rate i dette svaret (`holio_option_id` i ratens metadata) — et
 *     deaktivert valg, eller et som ikke gjelder lageret, gir ingen rate, og
 *   - ikke er deaktivert i lista (`is_enabled` false teller aldri, i tilfelle
 *     Holios backend svarer med alle valgene uansett hva som ble spurt om),
 * teller. Prisregler (`pricing_rule`) tas ikke med: en regel som gir 0 kr kan
 * ha vekt- og fraktklassebetingelser vi ikke kan vurdere for en annen kurv.
 * Er en regel med «standardpris» i bruk, kan Holio la være å gi gratis frakt
 * selv over grensen — da er tallet vi melder for lavt.
 *
 * Kurvsummen regnes som Holio gjør (`get_package_total()`): `line_total`, pluss
 * `line_tax` når butikken viser priser med mva — etter kuponger.
 *
 * Mellomlager: Holio spør sin backend ved hver fraktberegning. Vi legger ikke på
 * et kall til for hver `/frakt`, men husker grensene i 10 minutter (1 minutt når
 * lista er tom, fordi Holio svarer med en tom liste også når kallet feiler).
 * Bare valg-ID-er og tall lagres. Mangler Holio, eller kaster den, er grensen
 * null som før.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_HOLIO_METODE    = 'holio_shipping';
const AMENDO_HOLIO_META_VALG = 'holio_option_id';
const AMENDO_HOLIO_MELLOMLAGER = 600;

/** [valg-ID => grense] for Holios aktive fraktvalg med gratisgrense, eller null når Holio ikke kan spørres. */
function amendo_frakt_holio_grenser() {
    static $svar = false;
    if ($svar !== false) return $svar;
    if (!class_exists('Holio_Shipping_Option_Repository') || !class_exists('Holio_Database')
        || !method_exists('Holio_Shipping_Option_Repository', 'find_enabled_by_shop_id') || !method_exists('Holio_Database', 'get_shop_id')) {
        return $svar = null;
    }
    try {
        $butikk = (string) Holio_Database::get_shop_id();
    } catch (Throwable $e) {
        return $svar = null;
    }
    if ($butikk === '') return $svar = null;

    $nokkel = 'amendo_holio_gratis_' . md5($butikk);
    $lagret = get_transient($nokkel);
    if (is_array($lagret) && isset($lagret['grenser']) && is_array($lagret['grenser'])) return $svar = $lagret['grenser'];

    try {
        $valg = (new Holio_Shipping_Option_Repository())->find_enabled_by_shop_id($butikk);
    } catch (Throwable $e) {
        return $svar = null;
    }
    if (!is_array($valg)) return $svar = null;

    $grenser = [];
    foreach ($valg as $v) {
        if (!is_array($v) || !isset($v['id']) || !is_scalar($v['id'])) continue;
        if (array_key_exists('is_enabled', $v) && !filter_var($v['is_enabled'], FILTER_VALIDATE_BOOLEAN)) continue;
        if (!filter_var($v['is_free_shipping'] ?? false, FILTER_VALIDATE_BOOLEAN)) continue;
        $grense = $v['free_shipping_threshold'] ?? null;
        if (!is_numeric($grense) || (float) $grense <= 0) continue;
        $grenser[(string) $v['id']] = (float) $grense;
    }
    set_transient($nokkel, ['grenser' => $grenser], $grenser ? AMENDO_HOLIO_MELLOMLAGER : MINUTE_IN_SECONDS);
    return $svar = $grenser;
}

/** Grensen til én rate, eller null (ikke Holio, ingen grense på valget). */
function amendo_frakt_holio_rategrense(WC_Shipping_Rate $rate, array $grenser) {
    if ($rate->get_method_id() !== AMENDO_HOLIO_METODE) return null;
    $meta = $rate->get_meta_data();
    $id = $meta[AMENDO_HOLIO_META_VALG] ?? null;
    if (!is_scalar($id) || (string) $id === '') return null;
    return $grenser[(string) $id] ?? null;
}

/**
 * Laveste Holio-grense blant ratene i pakken og hvor mye som gjenstår, pluss
 * grensen per rate-ID. Ingen Holio-rate = ingen kall mot Holio.
 */
function amendo_frakt_holio_gratisgrense(array $pakke) {
    $tom = ['grense' => null, 'gjenstaar' => null, 'per_rate' => []];
    $rater = array_filter($pakke['rates'] ?? [], function($r) {
        return $r instanceof WC_Shipping_Rate && $r->get_method_id() === AMENDO_HOLIO_METODE;
    });
    if (!$rater) return $tom;
    $grenser = amendo_frakt_holio_grenser();
    if (!$grenser) return $tom;

    $per_rate = [];
    $laveste = null;
    foreach ($rater as $rate) {
        $g = amendo_frakt_holio_rategrense($rate, $grenser);
        if ($g === null) continue;
        $per_rate[$rate->get_id()] = $g;
        if ($laveste === null || $g < $laveste) $laveste = $g;
    }
    if ($laveste === null) return $tom;

    $med_mva = get_option('woocommerce_tax_display_cart') === 'incl';
    $sum = 0.0;
    foreach ($pakke['contents'] ?? [] as $linje) {
        $sum += (float) ($linje['line_total'] ?? 0);
        if ($med_mva) $sum += (float) ($linje['line_tax'] ?? 0);
    }
    $desimaler = wc_get_price_decimals();
    return [
        'grense'    => round($laveste, $desimaler),
        'gjenstaar' => round(max(0, $laveste - round($sum, $desimaler)), $desimaler),
        'per_rate'  => array_map(function($g) use ($desimaler) { return round($g, $desimaler); }, $per_rate),
    ];
}
