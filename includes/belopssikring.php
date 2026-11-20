<?php
/**
 * Beløpssikring (1.4.41): er det Adyen meldte BETALT ikke lik ordretotalen
 * når betalingen fullføres, settes ordren til «On hold» med et notat — ikke
 * «Behandler».
 *
 * Hvorfor: headless-kassen gjenbruker den ventende ordren (én ordre per
 * kjøp) og kan endre linjene på den. En betaling som ble startet før
 * endringen, og som fullføres etterpå, ville ellers gjort ordren til
 * «Behandler» med en annen sum enn den kunden betalte — og butikken ville
 * plukket og sendt varer ingen har betalt for.
 *
 * To veier inn:
 *  1. Gatewayens Adyen-webhook (`POST /wp-json/aorder/v1/amendo-adyen`,
 *     woocommerce-amendo-gateway): vi kan ikke endre gatewayen, så beløpet
 *     leses av webhookens kropp FØR gatewayen kjører
 *     (`rest_request_before_callbacks`), og statusen settes når gatewayen
 *     kaller payment_complete() (`woocommerce_payment_complete_order_status`).
 *     Gjelder Vipps, Klarna og kort i sesjonsflyten.
 *  2. Kortbetalingen uten sesjon (includes/kortbetaling.php, Authorised):
 *     sjekker beløpet selv og bruker amendo_belopssikring_on_hold().
 *
 * ⚠ Ordre-ID-en leses av `merchantReference` (`<vert>/<id>`, se
 * Adyen::reference() og betaling.php) — siste del etter siste skråstrek.
 * Kassen sender ordrens NUMMER som referanse; nummeret er ID-en så lenge
 * butikken ikke har en plugin for løpenummer.
 *
 * ⚠ Bare `AUTHORISATION` med `success: true` teller. Uten et beløp fra
 * webhooken (andre gatewayer, wp-admin) gjør filteret ingenting.
 *
 * ── 1.4.44: BELØPET LAGRES PÅ ORDREN, OG SENDES VIDERE ─────────────────────
 * Fram til 1.4.43 levde beløpet bare i en statisk variabel i den forespørselen
 * webhooken kom i, og kortflyten sjekket bare når Adyen SELV hadde med
 * `amount` i svaret. `/payments/details` gjør som regel ikke det, og da sto
 * frontendens sikringsnett av: den logget «gatewayen oppga ikke beløp» for
 * hver kortbetaling (målt på aanerudbakeri.no, ordre 37821 og 37823).
 *
 * Nå:
 *  • `amendo_belopssikring_lagre()` skriver beløp, valuta og kilde på ordren
 *    (`_amendo_betalt_ore`, `_amendo_betalt_valuta`, `_amendo_betalt_kilde`),
 *    både fra webhooken og fra kortflyten. Da overlever det forespørselen, og
 *    butikken kan se det i wp-admin.
 *  • Kortrutene svarer med `amount: { value, currency }`, som er det
 *    frontendens `lib/belopssikring.ts` leser.
 *
 * ⚠⚠ ØRE, IKKE KRONER. Adyen oppgir beløp i minste enhet — `value: 9600` er
 * 96,00 kr. Alt her regner i øre, og `amount.value` ut er det samme tallet.
 * Frontenden godtar i dag BEGGE tolkningene fordi enheten aldri var lest av en
 * ekte betaling; når den er bekreftet, kan den strammes inn der.
 *
 * ⚠ Valutaen sammenlignes også. 100 EUR og 100 NOK er samme tall og to helt
 * ulike beløp.
 */

if (!defined('ABSPATH')) exit;

/** Øre Adyen meldte betalt, per ordre-ID, i denne forespørselen. */
function amendo_belopssikring_betalt($ordre_id, $ore = null) {
    static $kart = [];
    if ($ore !== null) $kart[(int) $ordre_id] = (int) $ore;
    return $kart[(int) $ordre_id] ?? null;
}

/** Valutaen Adyen meldte, per ordre-ID, i denne forespørselen. */
function amendo_belopssikring_valuta($ordre_id, $valuta = null) {
    static $kart = [];
    if ($valuta !== null) $kart[(int) $ordre_id] = (string) $valuta;
    return $kart[(int) $ordre_id] ?? null;
}

/**
 * Lagrer beløpet på ordren, så det overlever forespørselen.
 *
 * ⚠ `$kilde` sier hvor tallet kommer fra, og det er ikke pynt: «adyen» er
 * beløpet Adyen BEKREFTET, «forespurt» er det vi ba Adyen trekke. Det siste er
 * godt nok til å fange at ordren er endret etter at betalingen ble startet —
 * som er nettopp det denne sikringen finnes for — men det er ikke en
 * bekreftelse fra banken, og notatet skal ikke påstå at det er det.
 */
function amendo_belopssikring_lagre(WC_Order $ordre, int $ore, string $valuta, string $kilde) {
    if ($ore <= 0) return;
    $ordre->update_meta_data('_amendo_betalt_ore', $ore);
    if ($valuta !== '') $ordre->update_meta_data('_amendo_betalt_valuta', $valuta);
    $ordre->update_meta_data('_amendo_betalt_kilde', $kilde);
    $ordre->save();
}

/**
 * Beløpet vi ba Adyen trekke, lagret ved /adyen-betal og lest igjen ved
 * /adyen-detaljer.
 *
 * ⚠⚠ DET MÅ LAGRES VED BETALINGSSTART, ikke regnes på nytt. Ved /adyen-detaljer
 * er ordretotalen den NÅVÆRENDE — og hele poenget er å oppdage at den har
 * endret seg siden betalingen ble startet. Regnet vi tallet på nytt der, ville
 * de to alltid vært like, og sjekken ville aldri sagt fra.
 */
function amendo_belopssikring_forespurt(WC_Order $ordre, $ore = null, $valuta = null) {
    if ($ore !== null) {
        $ordre->update_meta_data('_amendo_belop_forespurt_ore', (int) $ore);
        $ordre->update_meta_data('_amendo_belop_forespurt_valuta', (string) $valuta);
        return null;
    }
    $lagret = $ordre->get_meta('_amendo_belop_forespurt_ore');
    if (!is_numeric($lagret) || (int) $lagret <= 0) return null;
    return [
        'ore'    => (int) $lagret,
        'valuta' => (string) $ordre->get_meta('_amendo_belop_forespurt_valuta'),
    ];
}

/** Setter ordren «On hold» med notat. Brukt av webhook-veien og kortbetalingen. */
function amendo_belopssikring_on_hold(WC_Order $ordre, int $betalt_ore, int $ordre_ore, string $kilde) {
    $tekst = sprintf(
        'Beløpssikring (%s): Adyen meldte %s betalt, men ordretotalen er %s. Ordren er satt til «On hold», ikke «Behandler» — kontroller betalingen i Adyen før ordren behandles.',
        $kilde,
        number_format($betalt_ore / 100, 2, ',', ' ') . ' ' . $ordre->get_currency(),
        number_format($ordre_ore / 100, 2, ',', ' ') . ' ' . $ordre->get_currency()
    );
    if (function_exists('amendo_betaling_logg')) {
        amendo_betaling_logg("beløpssikring: ordre {$ordre->get_id()} betalt $betalt_ore øre, total $ordre_ore øre ($kilde) — on-hold");
    }
    return $tekst;
}

// 1a) Webhooken: beløpet per ordre, før gatewayen behandler den.
add_filter('rest_request_before_callbacks', function ($svar, $handler, $request) {
    if (!($request instanceof WP_REST_Request) || strpos($request->get_route(), '/aorder/v1/amendo-adyen') !== 0) {
        return $svar;
    }
    $kropp = json_decode((string) $request->get_body(), true);
    foreach ((is_array($kropp) ? ($kropp['notificationItems'] ?? []) : []) as $element) {
        $n = is_array($element) ? ($element['NotificationRequestItem'] ?? null) : null;
        if (!is_array($n) || ($n['eventCode'] ?? '') !== 'AUTHORISATION') continue;
        if (!in_array($n['success'] ?? '', ['true', true], true)) continue;
        $id     = (int) preg_replace('~^.*/~', '', (string) ($n['merchantReference'] ?? ''));
        $verdi  = $n['amount']['value'] ?? null;
        $valuta = (string) ($n['amount']['currency'] ?? '');
        if ($id > 0 && is_numeric($verdi)) {
            amendo_belopssikring_betalt($id, (int) $verdi);
            if ($valuta !== '') amendo_belopssikring_valuta($id, $valuta);
            // ⚠ Og på ordren, ikke bare i minnet: webhooken kan komme i en
            // annen forespørsel enn den som fullfører betalingen (kortflyten
            // fullfører selv ved /adyen-detaljer), og da ville tallet vært
            // borte. Gjelder Vipps og Klarna, som bare har denne veien inn.
            $ordre = wc_get_order($id);
            if ($ordre instanceof WC_Order) {
                amendo_belopssikring_lagre($ordre, (int) $verdi, $valuta, 'adyen-webhook');
            }
        }
    }
    return $svar;
}, 10, 3);

// 1b) payment_complete(): avviker beløpet, blir statusen «On hold».
add_filter('woocommerce_payment_complete_order_status', function ($status, $ordre_id, $ordre = null) {
    $betalt = amendo_belopssikring_betalt($ordre_id);
    if ($betalt === null) return $status;
    $ordre = $ordre instanceof WC_Order ? $ordre : wc_get_order($ordre_id);
    if (!$ordre) return $status;
    $forventet = (int) round(((float) $ordre->get_total()) * 100);
    // ⚠ Valutaen teller med: 100 EUR og 100 NOK er samme tall og to helt
    // ulike beløp. Tom valuta fra webhooken er ikke et avvik i seg selv.
    $valuta  = (string) amendo_belopssikring_valuta($ordre_id);
    $vStemmer = $valuta === '' || $valuta === $ordre->get_currency();
    if ($betalt === $forventet && $vStemmer) return $status;
    $ordre->add_order_note(amendo_belopssikring_on_hold($ordre, $betalt, $forventet, 'Adyen-webhook'));
    return 'on-hold';
}, 99, 3);
