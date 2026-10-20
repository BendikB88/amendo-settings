<?php
/**
 * Kundegrupper og rabatt fra Tellix (1.4.14). Del av B2B-modulen: lastes bare
 * når B2B er på — se includes/moduler.php.
 *
 * Tellix snakkes med GJENNOM amendo-core 3.0.1 (must-use), aldri direkte.
 * Klassen er \Amendo\Customers, og metodene er public static:
 *     get_tellix_discounts()           [customerGroupId => stdClass {discountPercent,
 *                                       categories: [term_id => prosent]}], bare aktive
 *     search_customers($epost, $tlf)   [id, groupId, uuid, name, email, phone] eller null
 *                                      (telefon som +47XXXXXXXX)
 *     get_tellix_customer_groups()     gruppenavnene
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
 * ⚠⚠ Er `amendo_helsebygget_grupper` ikke satt opp (og ikke kan utledes av
 * `amendo-store-groups`), svarer rabattrutene 503. Ellers ville NAV-gruppene
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
const AMENDO_TELLIX_TREG_SEKUNDER   = 2.0;
const AMENDO_TELLIX_MELLOMLAGRE     = 10 * MINUTE_IN_SECONDS;
const AMENDO_TELLIX_NAVN_LAGRES     = 12 * HOUR_IN_SECONDS;
const AMENDO_TELLIX_TLF_GRENSE      = 10;
const AMENDO_TELLIX_TLF_VINDU       = 15 * MINUTE_IN_SECONDS;

// ── Adapter mot amendo-core ─────────────────────────────────────────────────

function amendo_tellix_tilgjengelig() {
    return class_exists(AMENDO_TELLIX_KLASSE)
        && method_exists(AMENDO_TELLIX_KLASSE, 'get_tellix_discounts')
        && method_exists(AMENDO_TELLIX_KLASSE, 'search_customers');
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

/** Tellix-kunden fra search_customers, normalisert — eller null. */
function amendo_tellix_kunde_fra($raa) {
    if (is_object($raa)) $raa = get_object_vars($raa);
    if (!is_array($raa)) return null;
    $id = $raa['id'] ?? null;
    if ($id === null || $id === '') return null;
    $gruppe = $raa['groupId'] ?? null;
    return [
        'id'        => (string) $id,
        'uuid'      => (string) ($raa['uuid'] ?? ''),
        'gruppe_id' => is_numeric($gruppe) ? (int) $gruppe : 0,
    ];
}

// ── Oppslag ─────────────────────────────────────────────────────────────────

/** +47XXXXXXXX, eller '' når det ikke er et norsk mobil-/fastnummer. */
function amendo_tellix_telefon($nr) {
    $siffer = preg_replace('/\D/', '', (string) $nr);
    if (strlen($siffer) === 12 && strpos($siffer, '0047') === 0) $siffer = substr($siffer, 4);
    if (strlen($siffer) === 10 && strpos($siffer, '47') === 0) $siffer = substr($siffer, 2);
    return strlen($siffer) === 8 ? '+47' . $siffer : '';
}

function amendo_tellix_logg($melding) {
    if (function_exists('wc_get_logger')) wc_get_logger()->info($melding, ['source' => 'amendo-tellix']);
}

/**
 * search_customers med tidtaking. Tar kallet over 2 s, mellomlagres SVARENE i
 * 10 minutter (nøklet på hash av e-post og telefon) så lenge Tellix er treg.
 * Kaster ikke; null betyr «ikke funnet eller feil».
 */
function amendo_tellix_sok($epost, $telefon) {
    if (!amendo_tellix_tilgjengelig()) return null;
    $nokkel = 'amendo_tellix_sok_' . md5(strtolower(trim((string) $epost)) . '|' . $telefon);
    if (get_transient('amendo_tellix_treg')) {
        $lagret = get_transient($nokkel);
        if (is_array($lagret)) return $lagret['kunde'];
    }
    $start = microtime(true);
    try {
        $raa = call_user_func([AMENDO_TELLIX_KLASSE, 'search_customers'], (string) $epost, (string) $telefon);
    } catch (Throwable $e) {
        amendo_tellix_logg('search_customers feilet (' . get_class($e) . ')');
        return null;
    }
    $tid   = microtime(true) - $start;
    $kunde = amendo_tellix_kunde_fra($raa);
    if ($tid > AMENDO_TELLIX_TREG_SEKUNDER) {
        if (!get_transient('amendo_tellix_treg')) amendo_tellix_logg(sprintf('search_customers tok %.1f s — mellomlagrer i 10 min', $tid));
        set_transient('amendo_tellix_treg', 1, AMENDO_TELLIX_MELLOMLAGRE);
    }
    if (get_transient('amendo_tellix_treg')) set_transient($nokkel, ['kunde' => $kunde], AMENDO_TELLIX_MELLOMLAGRE);
    return $kunde;
}

/**
 * Slå opp WordPress-brukeren i Tellix og skriv gruppen tilbake. Returnerer
 * gruppe-ID-en (fra Tellix når den fant kunden, ellers den lagrede).
 */
function amendo_tellix_oppdater_bruker($bruker_id) {
    $u = get_userdata((int) $bruker_id);
    if (!$u) return null;
    $telefoner = array_values(array_unique(array_filter([
        amendo_tellix_telefon(get_user_meta($u->ID, 'billing_phone', true)),
        amendo_tellix_telefon(get_user_meta($u->ID, '_amendo_otp_match', true)),
    ])));
    $kunde = null;
    foreach ($telefoner ?: [''] as $tlf) {
        $kunde = amendo_tellix_sok($u->user_email, $tlf);
        if ($kunde) break;
    }
    if ($kunde) {
        update_user_meta($u->ID, '_tellixGroupId', (string) $kunde['gruppe_id']);
        update_user_meta($u->ID, '_tellixCustomerId', $kunde['id']);
        if ($kunde['uuid'] !== '') update_user_meta($u->ID, '_tellixCustomerUuId', $kunde['uuid']);
        return $kunde['gruppe_id'];
    }
    // ⚠ Ikke funnet eller feil: behold det som står.
    $lagret = get_user_meta($u->ID, '_tellixGroupId', true);
    return is_numeric($lagret) ? (int) $lagret : 0;
}

// ── Helsebygget-gruppene ────────────────────────────────────────────────────

/**
 * {gruppe_id => prosent}, eller null når ingen har satt dem opp. Uten lagret
 * option forsøkes `amendo-store-groups` (grupper bundet til Helsebygget).
 */
function amendo_helsebygget_grupper() {
    $lagret = get_option('amendo_helsebygget_grupper', null);
    if ($lagret !== null && $lagret !== false) {
        $data = is_array($lagret) ? $lagret : json_decode((string) $lagret, true);
        return amendo_helsebygget_rens(is_array($data) ? $data : []);
    }
    return amendo_helsebygget_fra_store_groups();
}

/**
 * Lista slik den er LAGRET av en administrator, eller null. Ikke utledet av
 * amendo-store-groups: bare en lagret liste gjør Helsebygget-kolonnene i
 * amendo_kundegruppe_config skrivebeskyttet (se b2b.php). Butikker som aldri
 * har lagret den, merker ingen endring.
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
 * Utleder gruppene fra `amendo-store-groups` når den finnes. Formatet på
 * garcon.no er {gruppe_id: "helsebygget" | "0"}, f.eks.
 *     {"1":"0","4":"helsebygget","5":"helsebygget","6":"helsebygget","7":"helsebygget","14":"0"}
 * Grupper med verdien «helsebygget» er Helsebygget-grupper, med satsen fra
 * Tellix. ⚠ Gruppe 19 (Personalrabatt 50) er ikke bundet der, og må legges inn
 * for hånd i feltet. Finnes ingen, returneres null (= ikke satt opp).
 */
function amendo_helsebygget_fra_store_groups() {
    $raa = get_option('amendo-store-groups', null);
    if (is_string($raa)) $raa = json_decode($raa, true);
    if (!is_array($raa)) return null;
    $tellix = amendo_tellix_grupper();
    $ut = [];
    foreach ($raa as $id => $binding) {
        if (!is_numeric($id) || (int) $id <= 0 || strtolower(trim((string) $binding)) !== 'helsebygget') continue;
        if (isset($tellix[(int) $id])) $ut[(int) $id] = $tellix[(int) $id]['prosent'];
    }
    return $ut ? amendo_helsebygget_rens($ut) : null;
}

/** «4:40, 5:40, 19:50» ⇄ {4: 40, 5: 40, 19: 50}. */
function amendo_helsebygget_tekst(array $grupper) {
    $deler = [];
    foreach ($grupper as $id => $pst) $deler[] = $id . ':' . rtrim(rtrim(number_format($pst, 2, '.', ''), '0'), '.');
    return implode(', ', $deler);
}

function amendo_helsebygget_fra_tekst($tekst) {
    $ut = [];
    foreach (preg_split('/[\s,;]+/', (string) $tekst, -1, PREG_SPLIT_NO_EMPTY) as $del) {
        if (preg_match('/^(\d+)[:=](\d+(?:[.,]\d+)?)$/', $del, $m)) $ut[$m[1]] = str_replace(',', '.', $m[2]);
    }
    return amendo_helsebygget_rens($ut);
}

/** Bare administrator — se toppen av b2b.php for hvorfor den ikke er butikkens valg. */
add_action('amendo_lagre_innstillinger', function(array $post) {
    $inn = $post['helsebygget_grupper'] ?? null;
    if (!is_array($inn) || empty($inn['finnes']) || !current_user_can('manage_options')) return;
    update_option('amendo_helsebygget_grupper', wp_json_encode((object) amendo_helsebygget_fra_tekst($inn['verdi'] ?? '')), false);
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
    $kunde = amendo_tellix_sok('', $tlf);
    return ['telefon' => $tlf] + amendo_tellix_svar($kunde ? $kunde['gruppe_id'] : 0, $h);
}

// ── Admin: B2B → Kundegrupper ───────────────────────────────────────────────

/** «12,5 %», «40 %». */
function amendo_tellix_pst($pst) {
    return rtrim(rtrim(number_format((float) $pst, 2, ',', ''), '0'), ',') . ' %';
}

add_action('amendo_b2b_kort', function() {
    $tilgjengelig = amendo_tellix_tilgjengelig();
    $grupper = amendo_tellix_grupper();
    $h = amendo_helsebygget_grupper();
    ?>
    <div class="amendo-card" id="b2b-tellix">
        <h2>Kundegrupper fra Tellix</h2>
        <p class="amendo-desc">Satsene settes i Tellix og vises her bare til informasjon. Gruppene under Helsebygget får rabatt bare på Helsebygget-siden, ikke i nettbutikken.</p>
        <?php if (!$tilgjengelig): ?>
            <p class="amendo-advarsel amendo-tellix-mangler">⚠ Fant ikke amendo-core (Customers med get_tellix_discounts og search_customers). Ingen kunder får rabatt fra Tellix før den er på plass.</p>
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

    <?php if (current_user_can('manage_options')): ?>
    <div class="amendo-card" id="b2b-helsebygget">
        <h2>Helsebygget-grupper</h2>
        <p class="amendo-desc">Fast oppsett, bare for administrator. Gruppe-ID og sats: <code>4:40, 5:40, 6:40, 7:40, 19:50</code>. Gruppene her får rabatt bare via /helsebygget.</p>
        <?php if ($h === null): ?>
            <p class="amendo-advarsel">⚠ Ikke satt opp. Rabattrutene svarer 503 til feltet er lagret, så ingen gruppe får feil rabatt i mellomtiden.</p>
        <?php endif; ?>
        <input type="hidden" name="helsebygget_grupper[finnes]" value="1">
        <div class="amendo-field">
            <label for="helsebygget_grupper">Gruppe:prosent</label>
            <input type="text" id="helsebygget_grupper" name="helsebygget_grupper[verdi]" value="<?php echo esc_attr(amendo_helsebygget_tekst($h ?? [])); ?>" placeholder="4:40, 5:40, 6:40, 7:40, 19:50">
        </div>
    </div>
    <?php endif;
});
