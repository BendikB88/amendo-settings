<?php
/**
 * Kundegrupper og rabatt fra Tellix (1.4.14). Del av B2B-modulen: lastes bare
 * når B2B er på — se includes/moduler.php.
 *
 * Tellix snakkes med GJENNOM amendo-core 3.0.1 (must-use), aldri direkte.
 * Klassen er \Amendo\Customers, og metodene er public static:
 *     get_tellix_discounts()           [customerGroupId => stdClass {discountPercent,
 *                                       categories: [term_id => prosent]}], bare aktive
 *     get_tellix_customer_groups()     gruppenavnene (se under)
 * Og \Amendo\TellixAPI::call_API('v2/customer/list', …) for kundelista — se
 * «Oppslag» under. ⚠ IKKE search_customers(): den leter i en 8-timers kopi
 * av én side (1.4.16).
 *
 * ⚠⚠ get_tellix_customer_groups() LOGGER HELE TELLIX-SVARET til error_log.
 * Den kalles derfor bare når transienten med navnene er tom (høyst hver 12.
 * time), aldri per forespørsel. Navnene er pynt: uten dem er gruppe_navn null,
 * rabatten er den samme.
 *
 * ── Adapteren ─────────────────────────────────────────────────────────────
 * Alt som vet hvordan amendo-core ser ut, står i delen «Adapter» under. Resten
 * av fila ser bare den normaliserte formen:
 *     gruppe: [id => int, navn => string, prosent => float, kategorier => [term_id => float]]
 *     kunde:  [id => string, uuid => string, gruppe_id => int]
 *
 * Finnes ikke klassen eller metodene (class_exists/method_exists), gis INGEN
 * rabatt (begge satsene null), og B2B-fanen viser en advarsel. Det er den
 * trygge retningen: en kunde som ikke får rabatt ringer, en rabatt som gis feil
 * koster penger.
 *
 * ── Hvem får hva ──────────────────────────────────────────────────────────
 * - Gruppene i `amendo_helsebygget_grupper` ({gruppe_id: prosent}) får rabatt
 *   BARE via /helsebygget, og ingen i vanlig nettbutikk.
 * - Alle andre grupper får Tellix-satsen i nettbutikken. Har varens kategori
 *   egen sats, gjelder den, ellers gruppens (som get_product_discount i
 *   amendo-core). Vi sender begge; frontenden velger per vare.
 *
 * ⚠⚠ Er `amendo_helsebygget_grupper` ikke LAGRET av en administrator, svarer
 * rabattrutene 503 (amendo-store-groups foreslår bare gruppe-ID-ene, aldri satsen). Ellers ville NAV-gruppene
 * fått Tellix-satsen i hele nettbutikken den dagen B2B ble slått på.
 *
 * ── Hvorfor rabatt/kunde alltid spør Tellix ───────────────────────────────
 * amendo-core setter `_tellixGroupId` én gang: set_current_user hopper over
 * kunder som alt har `_tellixCustomerId`. En kunde som senere flyttes til en
 * gruppe i Tellix (nye NAV-ansatte) blir stående med 0. Rutene slår derfor opp
 * på nytt og skriver gruppen tilbake når Tellix har kunden. Feltene tømmes
 * ALDRI når oppslaget feiler eller ikke finner noe.
 *
 * ⚠ Ingen kundedata eller Tellix-svar i loggen — bare tid.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_TELLIX_KLASSE          = 'Amendo\Customers';
const AMENDO_TELLIX_NAVN_LAGRES     = 12 * HOUR_IN_SECONDS;
const AMENDO_TELLIX_TLF_GRENSE      = 10;
const AMENDO_TELLIX_TLF_VINDU       = 15 * MINUTE_IN_SECONDS;

// ── Adapter mot amendo-core ─────────────────────────────────────────────────

/** Rabattene (Customers) og kundelista (TellixAPI) må begge finnes i amendo-core. */
function amendo_tellix_tilgjengelig() {
    return class_exists(AMENDO_TELLIX_KLASSE)
        && method_exists(AMENDO_TELLIX_KLASSE, 'get_tellix_discounts')
        && amendo_tellix_api_tilgjengelig();
}

/** Felt fra stdClass eller array, eller null. */
function amendo_tellix_felt($rad, $navn) {
    if (is_object($rad)) $rad = get_object_vars($rad);
    if (!is_array($rad) || !array_key_exists($navn, $rad)) return null;
    return $rad[$navn];
}

/** Kategorisatser som [term_id => prosent]. */
function amendo_tellix_kategorier($raa) {
    if (is_object($raa)) $raa = get_object_vars($raa);
    $ut = [];
    foreach (is_array($raa) ? $raa : [] as $term => $pst) {
        if (is_numeric($term) && (int) $term > 0 && is_numeric($pst)) $ut[(int) $term] = (float) $pst;
    }
    return $ut;
}

/**
 * Gruppenavnene [id => navn], fra transienten. get_tellix_customer_groups()
 * kalles bare når den er tom — se toppen. Feiler kallet, lagres en tom liste
 * i en time, så det ikke prøves på nytt i hver forespørsel.
 */
function amendo_tellix_gruppenavn() {
    $lagret = get_transient('amendo_tellix_gruppenavn');
    if (is_array($lagret)) return $lagret;
    $navn = [];
    if (class_exists(AMENDO_TELLIX_KLASSE) && method_exists(AMENDO_TELLIX_KLASSE, 'get_tellix_customer_groups')) {
        try {
            $raa = call_user_func([AMENDO_TELLIX_KLASSE, 'get_tellix_customer_groups']);
            foreach (is_array($raa) ? $raa : [] as $nokkel => $g) {
                if (is_scalar($g)) {
                    if (is_numeric($nokkel)) $navn[(int) $nokkel] = (string) $g;
                    continue;
                }
                $id = amendo_tellix_felt($g, 'customerGroupId') ?? amendo_tellix_felt($g, 'id') ?? (is_numeric($nokkel) ? $nokkel : null);
                $n  = amendo_tellix_felt($g, 'name') ?? amendo_tellix_felt($g, 'customerGroupName');
                if (is_numeric($id) && is_string($n)) $navn[(int) $id] = $n;
            }
        } catch (Throwable $e) {
            amendo_tellix_logg('get_tellix_customer_groups feilet (' . get_class($e) . ')');
        }
    }
    set_transient('amendo_tellix_gruppenavn', $navn, $navn ? AMENDO_TELLIX_NAVN_LAGRES : HOUR_IN_SECONDS);
    return $navn;
}

/** Aktive grupper fra Tellix, normalisert og nøklet på ID. Én gang per forespørsel. */
function amendo_tellix_grupper() {
    static $svar = null;
    if ($svar !== null) return $svar;
    $svar = [];
    if (!amendo_tellix_tilgjengelig()) return $svar;
    try {
        $raa = call_user_func([AMENDO_TELLIX_KLASSE, 'get_tellix_discounts']);
    } catch (Throwable $e) {
        amendo_tellix_logg('get_tellix_discounts feilet (' . get_class($e) . ')');
        return $svar;
    }
    if (!is_array($raa) || !$raa) return $svar;
    $navn = amendo_tellix_gruppenavn();
    foreach ($raa as $id => $g) {
        if (!is_numeric($id) || (int) $id <= 0) continue;
        $svar[(int) $id] = [
            'id'         => (int) $id,
            'navn'       => (string) ($navn[(int) $id] ?? ''),
            'prosent'    => (float) (amendo_tellix_felt($g, 'discountPercent') ?? 0),
            'kategorier' => amendo_tellix_kategorier(amendo_tellix_felt($g, 'categories')),
        ];
    }
    return $svar;
}

// ── Oppslag: vår egen kopi av kundelista (1.4.16) ───────────────────────────
//
// ⚠⚠ IKKE amendo-core sin search_customers(). Den leter i
// get_tellix_customers(), som henter ÉN side (limit =
// AMENDO_CUSTOMER_SYNC_SIZE) og mellomlagrer den i 8 timer. En kunde som
// registreres eller flyttes i Tellix, får da feil rabatt i opptil 8 timer, og
// kunder etter grensen finnes aldri.
//
// Tellix-API-et har ikke noe oppslag på telefon eller e-post: amendo-core
// bruker bare v2/customer/list (offset/limit) og v3/customer/view (på
// customerId), og ingenting vi har tilgang til viser at customer/list
// filtrerer. Derfor holder vi en egen kopi av HELE lista (alle sider, offset/
// limit til tom side), hentet med \Amendo\TellixAPI::call_API():
//   - aldri eldre enn 5 minutter;
//   - finnes ikke kunden, hentes den på nytt med en gang hvis kopien er eldre
//     enn 30 sekunder (en kunde som nettopp er registrert, kan handle med
//     riktig rabatt med én gang);
//   - maks én full henting per 30 sekunder for hele butikken (atomisk lås i
//     databasen), uansett hvor mange som logger inn samtidig.
// Feiler en side, beholdes den forrige kopien hel — aldri en halv liste.
//
// Kopien ligger i optionen amendo_tellix_kundeliste (ikke autoload), med bare
// feltene vi trenger. Den logges aldri; loggen får bare antall og tid.

const AMENDO_TELLIX_LISTE_MAKS_ALDER = 5 * MINUTE_IN_SECONDS;
const AMENDO_TELLIX_LISTE_PAUSE      = 30;
const AMENDO_TELLIX_SIDE             = 1000;

function amendo_tellix_api_tilgjengelig() {
    return class_exists('Amendo\\TellixAPI') && method_exists('Amendo\\TellixAPI', 'call_API');
}

/** +47XXXXXXXX, eller '' når det ikke er et norsk mobil-/fastnummer. Til nummer kunden skriver inn. */
function amendo_tellix_telefon($nr) {
    $siffer = preg_replace('/\D/', '', (string) $nr);
    if (strlen($siffer) === 12 && strpos($siffer, '0047') === 0) $siffer = substr($siffer, 4);
    if (strlen($siffer) === 10 && strpos($siffer, '47') === 0) $siffer = substr($siffer, 2);
    return strlen($siffer) === 8 ? '+47' . $siffer : '';
}

/** Telefonnummer fra Tellix renset som amendo-core gjør det: siste åtte sifre. */
function amendo_tellix_telefon_fra_tellix($nr) {
    $siffer = ltrim(preg_replace('/\D/', '', (string) $nr), '0');
    return strlen($siffer) >= 8 ? '+47' . substr($siffer, -8) : '';
}

function amendo_tellix_logg($melding) {
    if (function_exists('wc_get_logger')) wc_get_logger()->info($melding, ['source' => 'amendo-tellix']);
}

/** Kopien: ['hentet' => tid, 'kunder' => [...]], eller null. */
function amendo_tellix_liste_les() {
    $data = get_option('amendo_tellix_kundeliste', null);
    return is_array($data) && isset($data['hentet'], $data['kunder']) && is_array($data['kunder']) ? $data : null;
}

/**
 * Tar låsen for en full henting: true bare for ÉN forespørsel per 30
 * sekunder for hele butikken. Atomisk i databasen, så to samtidige
 * innlogginger ikke henter lista hver sin gang.
 */
function amendo_tellix_liste_las() {
    global $wpdb;
    add_option('amendo_tellix_liste_las', '0', '', false);
    $naa = time();
    $tatt = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'amendo_tellix_liste_las' AND option_value <= %s",
        (string) $naa, (string) ($naa - AMENDO_TELLIX_LISTE_PAUSE)
    ));
    wp_cache_delete('amendo_tellix_liste_las', 'options');
    return $tatt === 1;
}

/**
 * Henter hele lista fra Tellix og lagrer kopien. Returnerer true når den ble
 * hentet. Gjør ingenting (false) når låsen er tatt, eller når Tellix svarer
 * feil på en side — da står den forrige kopien.
 */
function amendo_tellix_liste_hent() {
    if (!amendo_tellix_api_tilgjengelig() || !amendo_tellix_liste_las()) return false;
    $start  = microtime(true);
    $side   = max(1, (int) apply_filters('amendo_tellix_side', AMENDO_TELLIX_SIDE));
    $kunder = [];
    $offset = 0;
    for ($i = 0; $i < 1000; $i++) {
        try {
            $r = call_user_func(['Amendo\\TellixAPI', 'call_API'], 'v2/customer/list', ['offset' => $offset, 'limit' => $side], 'GET');
        } catch (Throwable $e) {
            amendo_tellix_logg('kundelista: Tellix feilet (' . get_class($e) . ') — beholder forrige kopi');
            return false;
        }
        if (!is_object($r) || !isset($r->customers) || !is_array($r->customers)) {
            amendo_tellix_logg('kundelista: uventet svar fra Tellix på side ' . ($i + 1) . ' — beholder forrige kopi');
            return false;
        }
        if (!$r->customers) break;
        foreach ($r->customers as $k) {
            // Som amendo-core: bare aktive, ikke slettede.
            if (empty($k->isActive) || !empty($k->isDeleted)) continue;
            $kunder[] = [
                'id'        => (string) ($k->customerId ?? ''),
                'uuid'      => (string) ($k->customerUuid ?? ''),
                'gruppe_id' => is_numeric($k->customerGroupId ?? null) ? (int) $k->customerGroupId : 0,
                'navn'      => trim((string) ($k->name ?? '')),
                'epost'     => strtolower(trim((string) ($k->email ?? ''))),
                'telefon'   => amendo_tellix_telefon_fra_tellix($k->phoneNumber ?? ''),
            ];
        }
        $offset += count($r->customers);
    }
    update_option('amendo_tellix_kundeliste', ['hentet' => time(), 'kunder' => $kunder], false);
    amendo_tellix_logg(sprintf('kundelista hentet: %d kunder, %d sider, %.1f s', count($kunder), $i, microtime(true) - $start));
    return true;
}

/** Leter i kopien. Kobling (ID/UUID) foran telefon foran e-post. */
function amendo_tellix_let(array $liste, array $s) {
    $id    = (string) ($s['id'] ?? '');
    $uuid  = (string) ($s['uuid'] ?? '');
    $epost = strtolower(trim((string) ($s['epost'] ?? '')));
    $tlf   = array_values(array_filter((array) ($s['telefoner'] ?? [])));
    $koblet = ($id !== '' && $id !== '0') || ($uuid !== '' && $uuid !== '0');
    if ($koblet) {
        foreach ($liste as $k) {
            if (($id !== '' && $id !== '0' && $k['id'] === $id) || ($uuid !== '' && $uuid !== '0' && $k['uuid'] === $uuid)) {
                return ['kunde' => $k, 'via' => 'kobling'];
            }
        }
    }
    foreach ($tlf as $t) {
        foreach ($liste as $k) if ($k['telefon'] !== '' && $k['telefon'] === $t) return ['kunde' => $k, 'via' => 'kontakt'];
    }
    if ($epost !== '') {
        foreach ($liste as $k) if ($k['epost'] !== '' && $k['epost'] === $epost) return ['kunde' => $k, 'via' => 'kontakt'];
    }
    return null;
}

/**
 * Finn Tellix-kunden. $s: id, uuid, epost, telefoner (+47XXXXXXXX).
 * Returnerer ['kunde' => [...], 'via' => 'kobling'|'kontakt'] eller null.
 */
function amendo_tellix_finn(array $s) {
    if (!amendo_tellix_api_tilgjengelig()) return null;
    $data = amendo_tellix_liste_les();
    if (!$data || time() - (int) $data['hentet'] > AMENDO_TELLIX_LISTE_MAKS_ALDER) {
        if (amendo_tellix_liste_hent()) $data = amendo_tellix_liste_les();
    }
    if (!$data) return null;
    $treff = amendo_tellix_let($data['kunder'], $s);
    if ($treff) return $treff;
    // Ikke funnet: kanskje registrert akkurat nå. Hent på nytt hvis kopien er
    // eldre enn 30 sekunder (og låsen tillater det).
    if (time() - (int) $data['hentet'] > AMENDO_TELLIX_LISTE_PAUSE && amendo_tellix_liste_hent()) {
        $data = amendo_tellix_liste_les();
        return $data ? amendo_tellix_let($data['kunder'], $s) : null;
    }
    return null;
}

/**
 * Slå opp WordPress-brukeren i Tellix og skriv det Tellix vet tilbake.
 * Returnerer gruppe-ID-en som gjelder (0 = ingen gruppe).
 *
 * ⚠⚠ Tellix er sannheten for en KOBLET kunde (1.4.16): har brukeren
 * `_tellixCustomerId` eller `_tellixCustomerUuId`, og Tellix har den kunden,
 * gjelder Tellix-gruppen — også når den er tom. Da settes 0.
 * Er treffet bare på telefon eller e-post, skrives aldri en gruppe over 0 ned
 * til 0: `_tellixGroupId` kan være satt for hånd. Kunde-ID og UUID skrives
 * likevel, så kunden er koblet neste gang.
 * Finnes ikke kunden i Tellix, gjelder `_tellixGroupId` på brukeren hvis den
 * er over 0 (1.4.15).
 */
function amendo_tellix_oppdater_bruker($bruker_id) {
    $u = get_userdata((int) $bruker_id);
    if (!$u) return null;
    $treff = amendo_tellix_finn([
        'id'        => (string) get_user_meta($u->ID, '_tellixCustomerId', true),
        'uuid'      => (string) get_user_meta($u->ID, '_tellixCustomerUuId', true),
        'epost'     => $u->user_email,
        'telefoner' => array_values(array_unique(array_filter([
            amendo_tellix_telefon(get_user_meta($u->ID, 'billing_phone', true)),
            amendo_tellix_telefon(get_user_meta($u->ID, '_amendo_otp_match', true)),
        ]))),
    ]);
    if ($treff) {
        $k = $treff['kunde'];
        if ($k['id'] !== '') update_user_meta($u->ID, '_tellixCustomerId', $k['id']);
        if ($k['uuid'] !== '') update_user_meta($u->ID, '_tellixCustomerUuId', $k['uuid']);
        if ($treff['via'] === 'kobling' || $k['gruppe_id'] > 0) {
            update_user_meta($u->ID, '_tellixGroupId', (string) $k['gruppe_id']);
            return $k['gruppe_id'];
        }
    }
    $lagret = get_user_meta($u->ID, '_tellixGroupId', true);
    return is_numeric($lagret) && (int) $lagret > 0 ? (int) $lagret : 0;
}

/**
 * Fornavn, etternavn og e-post fra Tellix-kunden, til rabatt/telefon.
 * Navnet deles på siste mellomrom: siste ord er etternavnet. E-post som er
 * tom eller slutter på @dummy.com (amendo-core sin plassholder) blir null.
 */
function amendo_tellix_kontakt(?array $k) {
    $navn = $k ? preg_replace('/\s+/', ' ', trim($k['navn'])) : '';
    $pos  = strrpos($navn, ' ');
    $epost = $k ? $k['epost'] : '';
    return [
        'fornavn'   => $navn === '' ? null : ($pos === false ? $navn : substr($navn, 0, $pos)),
        'etternavn' => $navn === '' || $pos === false ? null : substr($navn, $pos + 1),
        'epost'     => ($epost === '' || preg_match('/@dummy\.com$/i', $epost)) ? null : $epost,
    ];
}

/**
 * Reserve for rabatt/telefon når Tellix ikke kjenner nummeret: WordPress-
 * brukerne med samme nummer i `billing_phone` eller `_amendo_otp_match`. Har
 * NØYAKTIG én gruppe over 0, brukes den; flere ulike grupper eller ingen gir 0.
 * Samme nummer kan ha flere brukere (41128080: 3626 med gruppe 4 og 1732 uten
 * gruppe → 4).
 *
 * ⚠ Numrene står i mange former («41128080», «+47 411 28 080»). Spørringen
 * siler på de to siste sifrene, og sammenligningen gjøres etter rensing.
 */
function amendo_tellix_gruppe_fra_wp($telefon) {
    global $wpdb;
    $siffer = substr($telefon, -8);
    if (strlen($siffer) !== 8) return 0;
    $rader = $wpdb->get_results($wpdb->prepare(
        "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ('billing_phone', '_amendo_otp_match') AND meta_value LIKE %s",
        '%' . $wpdb->esc_like(substr($siffer, -2)) . '%'
    ));
    $grupper = [];
    foreach ($rader ?: [] as $r) {
        if (amendo_tellix_telefon($r->meta_value) !== $telefon) continue;
        $g = get_user_meta((int) $r->user_id, '_tellixGroupId', true);
        if (is_numeric($g) && (int) $g > 0) $grupper[(int) $g] = true;
    }
    return count($grupper) === 1 ? (int) array_key_first($grupper) : 0;
}

// ── Helsebygget-gruppene ────────────────────────────────────────────────────

/**
 * {gruppe_id => prosent} slik en administrator har LAGRET den, eller null når
 * den ikke er satt opp (da svarer rabattrutene 503).
 *
 * ⚠ Satsen fylles ALDRI inn automatisk (1.4.15). 1.4.14 tok den fra Tellix,
 * som er 0 for NAV-gruppene, og garcon.no fikk {"4":0,"5":0,"6":0,"7":0}.
 * amendo-store-groups brukes bare til å foreslå gruppe-ID-ene i feltet.
 */
function amendo_helsebygget_grupper() {
    return amendo_helsebygget_lagret();
}

/**
 * Lista slik den er LAGRET av en administrator, eller null. Bare en lagret
 * liste gjør Helsebygget-kolonnene i amendo_kundegruppe_config
 * skrivebeskyttet (se b2b.php). Butikker som aldri har lagret den, merker
 * ingen endring.
 */
function amendo_helsebygget_lagret() {
    $lagret = get_option('amendo_helsebygget_grupper', null);
    if ($lagret === null || $lagret === false) return null;
    $data = is_array($lagret) ? $lagret : json_decode((string) $lagret, true);
    return amendo_helsebygget_rens(is_array($data) ? $data : []);
}

/** Bare positive gruppe-ID-er og satser 0–100. */
function amendo_helsebygget_rens(array $data) {
    $ut = [];
    foreach ($data as $id => $pst) {
        if (!is_numeric($id) || (int) $id <= 0 || !is_numeric($pst)) continue;
        $ut[(int) $id] = max(0, min(100, (float) $pst));
    }
    ksort($ut);
    return $ut;
}

/**
 * Gruppe-ID-ene som er bundet til Helsebygget i `amendo-store-groups`
 * ({gruppe_id: "helsebygget" | "0"} på garcon.no), sortert. Bare ID-ene —
 * aldri en sats. ⚠ Gruppe 19 (Personalrabatt 50) er ikke bundet der, og må
 * legges inn for hånd.
 */
function amendo_helsebygget_fra_store_groups() {
    $raa = get_option('amendo-store-groups', null);
    if (is_string($raa)) $raa = json_decode($raa, true);
    if (!is_array($raa)) return [];
    $ider = [];
    foreach ($raa as $id => $binding) {
        if (is_numeric($id) && (int) $id > 0 && strtolower(trim((string) $binding)) === 'helsebygget') $ider[] = (int) $id;
    }
    sort($ider);
    return $ider;
}

/** «4:40, 5:40, 19:50» ⇄ {4: 40, 5: 40, 19: 50}. */
function amendo_helsebygget_tekst(array $grupper) {
    $deler = [];
    foreach ($grupper as $id => $pst) $deler[] = $id . ':' . rtrim(rtrim(number_format($pst, 2, '.', ''), '0'), '.');
    return implode(', ', $deler);
}

/**
 * Tolker feltet. Returnerer ['grupper' => {id: prosent}, 'feil' => [...]]:
 * en gruppe uten sats («4:» eller «4») og alt som ikke kan tolkes er feil.
 */
function amendo_helsebygget_tolk($tekst) {
    $ut = [];
    $feil = [];
    foreach (preg_split('/[\s,;]+/', (string) $tekst, -1, PREG_SPLIT_NO_EMPTY) as $del) {
        if (preg_match('/^(\d+)[:=](\d+(?:[.,]\d+)?)$/', $del, $m) && (int) $m[1] > 0) {
            $ut[$m[1]] = str_replace(',', '.', $m[2]);
        } else {
            $feil[] = $del;
        }
    }
    return ['grupper' => amendo_helsebygget_rens($ut), 'feil' => $feil];
}

function amendo_helsebygget_fra_tekst($tekst) {
    return amendo_helsebygget_tolk($tekst)['grupper'];
}

/**
 * Bare administrator — se toppen av b2b.php for hvorfor den ikke er butikkens
 * valg. ⚠ Avvises HELT når en gruppe mangler sats: lista blir stående som den
 * var, og fanen viser hva som var feil.
 */
add_action('amendo_lagre_innstillinger', function(array $post) {
    $inn = $post['helsebygget_grupper'] ?? null;
    if (!is_array($inn) || empty($inn['finnes']) || !current_user_can('manage_options')) return;
    $tolket = amendo_helsebygget_tolk($inn['verdi'] ?? '');
    if ($tolket['feil']) {
        set_transient('amendo_helsebygget_avvist_' . get_current_user_id(), ['verdi' => (string) ($inn['verdi'] ?? ''), 'feil' => $tolket['feil']], 10 * MINUTE_IN_SECONDS);
        return;
    }
    // Et tidligere avslag gjelder ikke lenger.
    delete_transient('amendo_helsebygget_avvist_' . get_current_user_id());
    update_option('amendo_helsebygget_grupper', wp_json_encode((object) $tolket['grupper']), false);
});

// ── Svaret ──────────────────────────────────────────────────────────────────

/** {gruppe_id, gruppe_navn, nettbutikk, helsebygget} for en gruppe. */
function amendo_tellix_svar($gruppe_id, array $helsebygget) {
    $tom = ['gruppe_id' => null, 'gruppe_navn' => null, 'nettbutikk' => null, 'helsebygget' => null];
    $gruppe_id = (int) $gruppe_id;
    if ($gruppe_id <= 0 || !amendo_tellix_tilgjengelig()) return $tom;

    $g = amendo_tellix_grupper()[$gruppe_id] ?? null;
    $svar = ['gruppe_id' => $gruppe_id, 'gruppe_navn' => $g ? $g['navn'] : null, 'nettbutikk' => null, 'helsebygget' => null];
    if (array_key_exists($gruppe_id, $helsebygget)) {
        // ⚠ Helsebygget-gruppe: ingenting i vanlig nettbutikk.
        $svar['helsebygget'] = ['prosent' => $helsebygget[$gruppe_id]];
    } elseif ($g) {
        $kat = [];
        foreach ($g['kategorier'] as $term => $pst) $kat[(string) $term] = $pst;
        $svar['nettbutikk'] = ['prosent' => $g['prosent'], 'kategorier' => (object) $kat];
    }
    return $svar;
}

/** 503 når Helsebygget-gruppene ikke er satt opp; ellers lista. */
function amendo_tellix_helsebygget_eller_feil() {
    $h = amendo_helsebygget_grupper();
    if ($h !== null) return $h;
    return new WP_REST_Response([
        'code'    => 'amendo_helsebygget_ikke_satt',
        'message' => 'Helsebygget-gruppene er ikke satt opp i Amendo → B2B.',
    ], 503);
}

// ── REST ────────────────────────────────────────────────────────────────────

add_action('rest_api_init', function() {
    // Samme fail-closed secret som /bedrift-sok: tom secret avviser alt.
    register_rest_route('amendo-settings/v1', '/rabatt/kunde/(?P<user_id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'amendo_tellix_rest_kunde',
        'permission_callback' => 'amendo_bedrift_sok_tilgang',
    ]);
    register_rest_route('amendo-settings/v1', '/rabatt/telefon', [
        'methods'             => 'GET',
        'callback'            => 'amendo_tellix_rest_telefon',
        'permission_callback' => 'amendo_bedrift_sok_tilgang',
        'args'                => ['nr' => ['required' => true, 'type' => 'string']],
    ]);
});

function amendo_tellix_rest_kunde(WP_REST_Request $req) {
    $h = amendo_tellix_helsebygget_eller_feil();
    if ($h instanceof WP_REST_Response) return $h;
    $id = (int) $req['user_id'];
    if (!get_userdata($id)) return amendo_tellix_svar(0, $h);
    return amendo_tellix_svar(amendo_tellix_oppdater_bruker($id), $h);
}

function amendo_tellix_rest_telefon(WP_REST_Request $req) {
    $tlf = amendo_tellix_telefon($req->get_param('nr'));
    if ($tlf === '') {
        return new WP_REST_Response(['code' => 'amendo_rabatt_ugyldig_telefon', 'message' => 'nr må være et norsk telefonnummer.'], 400);
    }
    // Per nummer: engangskoden/Vipps-flyten skal ikke kunne brukes til å
    // gå gjennom Tellix-kundelisten nummer for nummer.
    $nokkel = 'amendo_rabatt_tlf_' . md5($tlf);
    $naa = time();
    $t = get_transient($nokkel);
    if (!is_array($t) || ($naa - (int) $t['start']) >= AMENDO_TELLIX_TLF_VINDU) $t = ['start' => $naa, 'antall' => 0];
    if ($t['antall'] >= AMENDO_TELLIX_TLF_GRENSE) {
        $svar = new WP_REST_Response(['code' => 'amendo_rabatt_rate_limit', 'message' => 'For mange oppslag på dette nummeret. Prøv igjen senere.'], 429);
        $svar->header('Retry-After', (string) max(1, AMENDO_TELLIX_TLF_VINDU - ($naa - $t['start'])));
        return $svar;
    }
    $t['antall']++;
    set_transient($nokkel, $t, AMENDO_TELLIX_TLF_VINDU);

    $h = amendo_tellix_helsebygget_eller_feil();
    if ($h instanceof WP_REST_Response) return $h;
    // Tellix først. Finner ikke Tellix nummeret, eller har kunden ingen
    // gruppe: WordPress-brukerne med samme nummer (se amendo_tellix_gruppe_fra_wp).
    $treff = amendo_tellix_finn(['telefoner' => [$tlf]]);
    $kunde = $treff ? $treff['kunde'] : null;
    $gruppe = $kunde && $kunde['gruppe_id'] > 0 ? $kunde['gruppe_id'] : amendo_tellix_gruppe_fra_wp($tlf);
    // ⚠⚠ FORUTSETNING for fornavn, etternavn og epost (1.4.16): ruten kalles
    // BARE fra frontendens server, etter at kunden har bekreftet nummeret med
    // en SMS-kode (Helsebygget-innloggingen). Da er det kundens egne
    // opplysninger. Ruten er bak X-Amendo-Secret og grensen per nummer, men
    // den vet ikke selv om koden er bekreftet — kalles den fra et annet sted,
    // er den et oppslag av navn og e-post på telefonnummer. Ikke gjør det.
    // Verdiene kommer fra Tellix-kunden, aldri fra WordPress-brukeren; null
    // når Tellix ikke har kunden.
    return ['telefon' => $tlf] + amendo_tellix_svar($gruppe, $h) + amendo_tellix_kontakt($kunde);
}

// ── Admin: B2B → Kundegrupper ───────────────────────────────────────────────

/** «12,5 %», «40 %». */
function amendo_tellix_pst($pst) {
    return rtrim(rtrim(number_format((float) $pst, 2, ',', ''), '0'), ',') . ' %';
}

/**
 * Er kundelista fra amendo-core trolig avkortet? amendo-core henter ÉN side
 * med v2/customer/list, med limit = AMENDO_CUSTOMER_SYNC_SIZE (500 uten
 * konstanten), og mellomlagrer den i en transient: `tellix_345customers` i
 * amendo-core 3.0.1 på garcon.no, `tellix_customers3` i eldre utgaver. Begge
 * leses. Rabattrutene her bruker vår egen, komplette kopi; advarselen gjelder
 * amendo-core sin egen bruk av lista (set_current_user og prisene).
 * Kunder etter grensen finnes aldri (garcon.no: 2500 av 2922, 20.10.2026).
 *
 * ⚠ Lista telles ETTER at amendo-core har tatt ut inaktive og slettede
 * kunder, så en avkortet liste er sjelden nøyaktig lik grensen. Advarselen
 * gis derfor når antallet er innenfor 5 % av grensen. Bare transienten leses
 * — dette gjør aldri et kall til Tellix.
 *
 * @return array{antall:int, grense:int}|null  null når lista ikke er lastet
 */
function amendo_tellix_listeinfo() {
    $kunder = null;
    foreach (['tellix_345customers', 'tellix_customers3'] as $navn) {
        $k = get_transient($navn);
        if (is_array($k) && ($kunder === null || count($k) > count($kunder))) $kunder = $k;
    }
    if ($kunder === null) return null;
    $grense = (int) (defined('AMENDO_CUSTOMER_SYNC_SIZE') ? AMENDO_CUSTOMER_SYNC_SIZE : 500);
    return ['antall' => count($kunder), 'grense' => $grense];
}

function amendo_tellix_liste_avkortet() {
    $info = amendo_tellix_listeinfo();
    return $info !== null && $info['grense'] > 0 && $info['antall'] >= (int) floor($info['grense'] * 0.95);
}

add_action('amendo_b2b_kort', function() {
    $tilgjengelig = amendo_tellix_tilgjengelig();
    $grupper = amendo_tellix_grupper();
    $h = amendo_helsebygget_grupper();
    $info = $tilgjengelig ? amendo_tellix_listeinfo() : null;
    ?>
    <div class="amendo-card" id="b2b-tellix">
        <h2>Kundegrupper fra Tellix</h2>
        <p class="amendo-desc">Satsene settes i Tellix og vises her bare til informasjon. Gruppene under Helsebygget får rabatt bare på Helsebygget-siden, ikke i nettbutikken.</p>
        <?php if ($tilgjengelig && amendo_tellix_liste_avkortet()): ?>
            <p class="amendo-advarsel amendo-tellix-avkortet">⚠ Tellix-lista kan være avkortet: hev AMENDO_CUSTOMER_SYNC_SIZE. amendo-core har <?php echo (int) $info['antall']; ?> kunder, og grensen er <?php echo (int) $info['grense']; ?>. Kunder etter grensen blir aldri funnet i Tellix.</p>
        <?php endif; ?>
        <?php if (!$tilgjengelig): ?>
            <p class="amendo-advarsel amendo-tellix-mangler">⚠ Fant ikke amendo-core (\Amendo\Customers::get_tellix_discounts og \Amendo\TellixAPI::call_API). Ingen kunder får rabatt fra Tellix før den er på plass.</p>
        <?php elseif (!$grupper): ?>
            <p class="field-help">Tellix har ingen aktive kundegrupper.</p>
        <?php else: ?>
        <table class="amendo-tabell">
            <thead><tr><th>ID</th><th>Navn</th><th>Sats</th><th>Kategorisatser</th><th>Gjelder</th></tr></thead>
            <tbody>
            <?php foreach ($grupper as $g):
                $kat = [];
                foreach ($g['kategorier'] as $term => $pst) {
                    $t = get_term($term, 'product_cat');
                    $kat[] = ($t instanceof WP_Term ? wp_specialchars_decode($t->name) : '#' . $term) . ': ' . amendo_tellix_pst($pst);
                }
            ?>
            <tr class="tellix-gruppe">
                <td><?php echo (int) $g['id']; ?></td>
                <td><?php echo esc_html($g['navn']); ?></td>
                <td><?php echo esc_html(amendo_tellix_pst($g['prosent'])); ?></td>
                <td><?php echo $kat ? esc_html(implode(', ', $kat)) : '—'; ?></td>
                <td><?php echo $h !== null && array_key_exists($g['id'], $h) ? esc_html('Bare Helsebygget (' . amendo_tellix_pst($h[$g['id']]) . ')') : 'Nettbutikk'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php if (current_user_can('manage_options')):
        $avvist = get_transient('amendo_helsebygget_avvist_' . get_current_user_id());
        if ($avvist) delete_transient('amendo_helsebygget_avvist_' . get_current_user_id());
        // Verdien i feltet: det som ble avvist (så det kan rettes), ellers den
        // lagrede lista, ellers bare ID-ene fra amendo-store-groups — uten sats.
        if (is_array($avvist)) {
            $verdi = (string) $avvist['verdi'];
        } elseif ($h !== null) {
            $verdi = amendo_helsebygget_tekst($h);
        } else {
            $verdi = implode(', ', array_map(function($id) { return $id . ':'; }, amendo_helsebygget_fra_store_groups()));
        }
        $nuller = $h ? array_keys(array_filter($h, function($p) { return (float) $p <= 0; })) : [];
    ?>
    <div class="amendo-card" id="b2b-helsebygget">
        <h2>Helsebygget-grupper</h2>
        <p class="amendo-desc">Fast oppsett, bare for administrator. Gruppe-ID og sats: <code>4:40, 5:40, 6:40, 7:40, 19:50</code>. Gruppene her får rabatt bare via /helsebygget. Satsen fylles aldri inn automatisk.</p>
        <?php if (is_array($avvist)): ?>
            <p class="amendo-advarsel amendo-helsebygget-avvist">⚠ Ikke lagret: <?php echo esc_html(implode(', ', $avvist['feil'])); ?> mangler sats eller kan ikke tolkes. Skriv gruppe:prosent for hver gruppe. Lista er uendret.</p>
        <?php endif; ?>
        <?php if ($h === null): ?>
            <p class="amendo-advarsel">⚠ Ikke satt opp. Rabattrutene svarer 503 til feltet er lagret, så ingen gruppe får feil rabatt i mellomtiden.<?php if (amendo_helsebygget_fra_store_groups()): ?> Gruppene som er bundet til Helsebygget i amendo-store-groups, er fylt inn — skriv satsen for hver av dem (og legg til grupper som ikke er bundet der, som 19).<?php endif; ?></p>
        <?php endif; ?>
        <?php if ($nuller): ?>
            <p class="amendo-advarsel amendo-helsebygget-null">⚠ Gruppe <?php echo esc_html(implode(', ', $nuller)); ?> har 0 % — de får ingen rabatt på Helsebygget. Er det meningen?</p>
        <?php endif; ?>
        <input type="hidden" name="helsebygget_grupper[finnes]" value="1">
        <div class="amendo-field">
            <label for="helsebygget_grupper">Gruppe:prosent</label>
            <input type="text" id="helsebygget_grupper" name="helsebygget_grupper[verdi]" value="<?php echo esc_attr($verdi); ?>" placeholder="4:40, 5:40, 6:40, 7:40, 19:50">
        </div>
    </div>
    <?php endif;
});
