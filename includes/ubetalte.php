<?php
/**
 * Ubetalte ordrer fra headless-kassen (1.4.10).
 *
 * ⚠⚠ WooCommerce AVBRYTER IKKE ubetalte REST-ordrer av seg selv.
 * wc_cancel_unpaid_orders() (planlagt hvert «Hold lager (minutter)») spør
 * filteret `woocommerce_cancel_unpaid_order`, som er sant bare for
 * `created_via = checkout`. Kassen i Next.js lager ordren via REST
 * (`created_via = rest-api`) FØR betalingen — så hver kunde som går fra
 * kassen, la igjen en `pending`-ordre for alltid. Og den holdt en eventuell
 * rabattkode.
 *
 * Her gjelder butikkens EGEN regel også for headless-ordrene: ordrer merket
 * `_nextjs_kasse = 1` avbrytes etter «Hold lager (minutter)», som ordrer fra
 * WooCommerce sin egen kasse. Krever at lagerstyring er på og at feltet har en
 * verdi — ellers kjører ikke WooCommerce sin rydding i det hele tatt.
 *
 * ⚠ Aldri en ordre med `date_paid` eller `transaction_id`: en betaling kan være
 * på vei (Vipps/Klarna er `pending` til Adyen-webhooken kommer).
 */
if (!defined('ABSPATH')) exit;

add_filter('woocommerce_cancel_unpaid_order', 'amendo_avbryt_ubetalt_headless', 10, 2);

/**
 * @param bool     $avbryt WooCommerce sitt svar (sant for created_via = checkout)
 * @param WC_Order $ordre
 */
function amendo_avbryt_ubetalt_headless($avbryt, $ordre) {
    if (!$ordre instanceof WC_Order) return $avbryt;
    if ($ordre->get_meta('_nextjs_kasse') !== '1') return $avbryt;
    if ($ordre->get_date_paid() || $ordre->get_transaction_id() !== '') return false;
    return true;
}
