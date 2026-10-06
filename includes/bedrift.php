<?php
/**
 * Bedriftsoppslag: GET /amendo-settings/v1/bedrift-sok?org_nr=XXXXXXXXX
 *
 * Svarer på ett spørsmål, og bare det: er dette organisasjonsnummeret allerede
 * registrert hos butikken?
 *
 *     { "finnes": true,  "type": "konto"  }   godkjent bedriftskunde
 *     { "finnes": true,  "type": "soknad" }   søknad til behandling
 *     { "finnes": false }                     ukjent nummer
 *
 * Kalles av `/bedrift/registrer` i Next.js-frontenden, som sjekker nummeret når
 * feltet mister fokus og sperrer «Registrer bedrift» på treff. Uten dette sendte
 * en bedrift som alt hadde søkt enda en søknad, og butikken fikk to kontoer å
 * rydde i.
 *
 * ── ⚠⚠ HVORFOR OPPSLAGET MÅ GJØRES HER, OG IKKE I FRONTENDEN ───────────────
 * Den opplagte spørringen fra frontend VIRKER IKKE:
 *
 *     GET wc/v3/customers?meta_key=billing_org_number&meta_value=923207074
 *
 * WooCommerce IGNORERER meta-parameterne på `customers`, akkurat som på
 * `orders`. Målt mot produksjon 06.10.2026: 1274 kunder MED filteret, 1274
 * uten. En sjekk bygget på den spørringen ville svart «finnes» for hvert
 * org.nummer noen tastet, og sperret registreringen for alle — uten at noe
 * feilet synlig.
 *
 * Alternativet, å skanne kundelista side for side, er ~13 kall à ~1 s for 1274
 * kunder og må kappes et sted; en slik sjekk svarer da «ikke funnet» for kunder
 * lenger bak i registeret, altså galt svar i nøyaktig det tilfellet den finnes
 * for. `WP_User_Query` gjør det samme i én spørring mot `wp_usermeta`.
 *
 * ── TO NØKLER, TO BETYDNINGER ──────────────────────────────────────────────
 *     billing_org_number   GODKJENT bedrift. Gir bedriftsvisning i frontenden.
 *     _orgnr_soknad        SØKNAD som venter. Gir ingen tilgang.
 *
 * ⚠⚠ Rekkefølgen er ikke tilfeldig: `billing_org_number` sjekkes FØRST, og en
 * kunde som har begge regnes som godkjent. Motsatt rekkefølge ville sendt en
 * bedrift som alt er godkjent til «søknaden din behandles», og bedt dem vente
 * på noe som er gjort.
 *
 * ⚠ `_orgnr_soknad` må ALDRI brukes til å gi tilgang noe sted. Den er en
 * søknad, ikke en godkjenning.
 *
 * ── ⚠⚠ SVARET INNEHOLDER INGEN PERSONOPPLYSNINGER ─────────────────────────
 * Ikke e-post, ikke navn, ikke bruker-ID — heller ikke maskert. Org.numre er
 * offentlige: hele Enhetsregisteret kan lastes ned. Et svar med en
 * e-postadresse ville gjort endepunktet til en oppslagstjeneste der den som
 * itererer over registeret kunne kartlagt hvilke bedrifter som er kunder, og
 * fått første tegn i kontaktpersonens adresse og toppdomenet på kjøpet.
 *
 * Skal butikken se hvem kontaktpersonen er, hører det i wp-admin — bak
 * innlogging — ikke i et REST-svar.
 */

if (!defined('ABSPATH')) exit;

/** Maks oppslag per IP per minutt. Kan heves med filteret under. */
const AMENDO_BEDRIFT_SOK_RATE_LIMIT = 10;

add_action('rest_api_init', function() {
    register_rest_route('amendo-settings/v1', '/bedrift-sok', [
        'methods'             => 'GET',
        'callback'            => 'amendo_bedrift_sok_rest',
        'permission_callback' => 'amendo_bedrift_sok_tilgang',
        'args'                => [
            'org_nr' => ['required' => true, 'type' => 'string'],
        ],
    ]);
});

/**
 * Samme hemmelighet og samme header som `/adyen-session` og
 * `/gateway-redirect`: `amendo_kasse_secret` mot `X-Amendo-Secret`.
 *
 * ⚠⚠ MEN ÉN FORSKJELL, OG DEN ER MED VILJE: de to andre rutene svarer
 * `return true` når secreten ikke er satt («ikke konfigurert — tillat
 * midlertidig»). Det gjør IKKE denne.
 *
 * En åpen betalingsrute er et misbruksproblem for butikken selv. En åpen
 * `bedrift-sok` er noe annet: den svarer forskjellig på kjent og ukjent
 * org.nummer, og org.numre er offentlige — uten secret ville hvem som helst
 * kunnet laste ned Enhetsregisteret og kartlegge nøyaktig hvilke bedrifter som
 * handler her. Det er kundenes opplysninger, ikke butikkens.
 *
 * Samme vurdering som kupongsjekken i `frakt.php`: tom secret gir aldri
 * unntak. Frontenden kaller uansett aldri uten secret — den har ingen å sende
 * før `KASSE_WEBHOOK_SECRET` er satt i Vercel — så fail-closed koster
 * ingenting her.
 */
function amendo_bedrift_sok_tilgang(WP_REST_Request $request) {
    $secret = (string) get_option('amendo_kasse_secret', '');
    if ($secret === '') return false;
    return hash_equals($secret, (string) $request->get_header('X-Amendo-Secret'));
}

/**
 * Grense per IP, med fast vindu på 60 sekunder fra første kall. Samme mønster
 * som `amendo_frakt_rate_limit()`.
 *
 * ⚠⚠ KASSEN KALLER FRA VERCEL, DER MANGE KUNDER DELER ÉN IP. Grensen gjelder
 * altså i praksis summen av alle som registrerer seg samtidig, ikke én person.
 * Ti i minuttet holder for et vanlig registreringsvolum, men skal butikken
 * kjøre en kampanje som sender mange bedrifter til skjemaet samtidig, hev den:
 *
 *     add_filter('amendo_bedrift_sok_rate_limit', fn() => 60);
 *
 * ⚠ Å treffe grensen er ufarlig for kunden: frontenden leser 429 som «vet
 * ikke», og slipper registreringen videre framfor å sperre den. Følgen er at
 * duplikatsjekken stilner — ikke at noen blir stengt ute.
 *
 * ⚠ `X-Forwarded-For` brukes ikke. Den kan settes av hvem som helst, og en
 * grense på en header angriperen selv velger er ingen grense.
 *
 * @return array{sperret: bool, vent: int}
 */
function amendo_bedrift_sok_rate_limit() {
    $maks = max(1, (int) apply_filters('amendo_bedrift_sok_rate_limit', AMENDO_BEDRIFT_SOK_RATE_LIMIT));
    $ip   = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $nokkel = 'amendo_bedrift_sok_rl_' . md5($ip);
    $naa    = time();

    $teller = get_transient($nokkel);
    if (!is_array($teller) || ($naa - (int) $teller['start']) >= MINUTE_IN_SECONDS) {
        $teller = ['start' => $naa, 'antall' => 0];
    }
    if ($teller['antall'] >= $maks) {
        return ['sperret' => true, 'vent' => max(1, MINUTE_IN_SECONDS - ($naa - $teller['start']))];
    }
    $teller['antall']++;
    set_transient($nokkel, $teller, MINUTE_IN_SECONDS);
    return ['sperret' => false, 'vent' => 0];
}

/** Finnes det en bruker med denne meta-nøkkelen og verdien? */
function amendo_bedrift_sok_finnes($nokkel, $orgnr) {
    $q = new WP_User_Query([
        'meta_key'    => $nokkel,
        'meta_value'  => $orgnr,
        'number'      => 1,
        'fields'      => 'ID',
        // Ingen grunn til å telle alle treff når vi bare skal vite OM det finnes.
        'count_total' => false,
    ]);
    return !empty($q->get_results());
}

function amendo_bedrift_sok_rest(WP_REST_Request $request) {
    $grense = amendo_bedrift_sok_rate_limit();
    if ($grense['sperret']) {
        $svar = new WP_REST_Response([
            'code'    => 'amendo_bedrift_sok_rate_limit',
            'message' => 'For mange oppslag. Prøv igjen om litt.',
        ], 429);
        $svar->header('Retry-After', (string) $grense['vent']);
        return $svar;
    }

    // Bare sifrene: kunden kan ha tastet «923 207 074».
    $orgnr = preg_replace('/\D/', '', (string) $request->get_param('org_nr'));

    if (strlen($orgnr) !== 9) {
        return new WP_REST_Response([
            'code'    => 'amendo_bedrift_sok_ugyldig',
            'message' => 'org_nr må være ni siffer.',
        ], 400);
    }

    // ⚠ Godkjent FØRST — se toppkommentaren.
    if (amendo_bedrift_sok_finnes('billing_org_number', $orgnr)) {
        return ['finnes' => true, 'type' => 'konto'];
    }
    if (amendo_bedrift_sok_finnes('_orgnr_soknad', $orgnr)) {
        return ['finnes' => true, 'type' => 'soknad'];
    }

    // ⚠ Ingen `type` her. Feltet beskriver treffet, og det finnes ikke noe
    // treff å beskrive.
    return ['finnes' => false];
}
