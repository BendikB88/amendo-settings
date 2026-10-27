<?php
/**
 * Tellix-kunde ved godkjenning (1.4.22). Del av B2B-modulen: lastes bare når
 * B2B er på — se includes/moduler.php.
 *
 * Før gjorde garcon-tellix-b2b-sync dette når en søknad ble godkjent i B2B
 * Wholesale Suite. Søknader fra /bedrift/registrer går ikke den veien, så
 * godkjenningen i B2B → Søknader satte bare `_b2b_status` — kunden fantes
 * ikke i Tellix, og faktura virket ikke.
 *
 * Nå, ved godkjenning (og som massehandling i Brukere for dem som mangler
 * kobling):
 *   1. Slå opp i vår kopi av Tellix-kundelista (b2b-tellix.php), hentet på
 *      nytt: først org.nr, så e-post. Ett treff → koble. Flere treff → IKKE
 *      gjett: kandidatene lagres, og admin velger i kortet «Tellix-kobling».
 *      Et treff på e-post med et ANNET org.nr er en annen bedrift: også valg.
 *      Og har lista ingen org.nr i det hele tatt (feltnavnet er ikke bekreftet,
 *      se amendo_tellix_orgnr_fra_rad), er et bom ikke et bevis: også valg.
 *      Kan lista ikke hentes, opprettes ingenting.
 *   2. Ingen treff → opprett kunden i Tellix med kundegruppen admin valgte.
 *   3. `_tellixCustomerId`, `_tellixCustomerUuId` og `_tellixGroupId` skrives
 *      med en gang, så faktura (enabled_customer_group_<id> i gatewayen)
 *      virker.
 *   4. Feiler Tellix, står godkjenningen likevel. Feilen vises i kortet med
 *      «Prøv igjen».
 *
 * ── Web-API-et ─────────────────────────────────────────────────────────────
 * Kundelista leses gjennom amendo-core som før. Å OPPRETTE en kunde kan ikke
 * amendo-core, så det gjøres som i garcon-tellix-b2b-sync 0.7.4 (samme
 * endepunkt, felter og innlogging):
 *     POST {url}web/{v}/getaccesstoken   username, password, apikey (skjema)
 *     POST {url}web/{v}/customer/save    {data: [kunde]}, Authorization: <token>
 *     POST {url}web/{v}/customer/update/{id}  {data: kunde}
 * Innloggingen er butikkens egen (B2B → Bedrifter → Tellix-tilgang). Er den
 * tom, brukes innstillingene til garcon-tellix-b2b-sync hvis pluginen har
 * lagret dem (garcon.no), i miljøet den står på.
 *
 * ⚠⚠ Kundegruppen sendes som `customerGroupId`, navnet feltet har i
 * kundelista. Sync-pluginen sendte aldri gruppe, så det er ikke prøvd mot
 * Tellix. Derfor hentes lista på nytt etter opprettelsen: har Tellix en
 * annen gruppe på kunden, er det den som skrives, og admin får beskjed.
 *
 * ⚠ Ingen kundedata, Tellix-svar eller innlogging i loggen — bare antall.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_B2B_TELLIX_MASSE    = 25;
const AMENDO_B2B_TELLIX_URL      = 'https://api.tellix.no/';
const AMENDO_B2B_TELLIX_VERSJON  = 'v3';

// ── Innlogging ──────────────────────────────────────────────────────────────

/** Det som er lagret i B2B-fanen, med standardverdier. */
function amendo_b2b_tellix_web_lagret() {
    $l = get_option('amendo_tellix_web', []);
    $l = is_array($l) ? $l : [];
    return [
        'url'     => (string) ($l['url'] ?? '') ?: AMENDO_B2B_TELLIX_URL,
        'versjon' => (string) ($l['versjon'] ?? '') ?: AMENDO_B2B_TELLIX_VERSJON,
        'bruker'  => (string) ($l['bruker'] ?? ''),
        'passord' => (string) ($l['passord'] ?? ''),
        'nokkel'  => (string) ($l['nokkel'] ?? ''),
    ];
}

/**
 * Innloggingen som brukes, eller null: butikkens egen, ellers
 * garcon-tellix-b2b-sync sin. 'kilde' og 'miljo' vises i admin.
 */
function amendo_b2b_tellix_tilgang() {
    $e = amendo_b2b_tellix_web_lagret();
    if ($e['bruker'] !== '' && $e['passord'] !== '' && $e['nokkel'] !== '') {
        return ['url' => trailingslashit($e['url']), 'versjon' => $e['versjon'], 'bruker' => $e['bruker'], 'passord' => $e['passord'], 'nokkel' => $e['nokkel'], 'kilde' => 'egen', 'miljo' => ''];
    }
    $g = get_option('garcon_tellix_b2b_sync_settings', []);
    if (!is_array($g)) return null;
    $miljo = in_array($g['environment'] ?? '', ['test', 'live'], true) ? $g['environment'] : 'test';
    $url = (string) ($g[$miljo . '_base_url'] ?? '') ?: ($miljo === 'live' ? AMENDO_B2B_TELLIX_URL : 'https://api.tellixtestserver.com/');
    $t = [
        'url' => trailingslashit($url), 'versjon' => (string) ($g['api_version'] ?? '') ?: AMENDO_B2B_TELLIX_VERSJON,
        'bruker' => (string) ($g[$miljo . '_username'] ?? ''), 'passord' => (string) ($g[$miljo . '_password'] ?? ''),
        'nokkel' => (string) ($g[$miljo . '_apikey'] ?? ''), 'kilde' => 'garcon-tellix-b2b-sync', 'miljo' => $miljo,
    ];
    return $t['bruker'] !== '' && $t['passord'] !== '' && $t['nokkel'] !== '' ? $t : null;
}

/** Tellix i det hele tatt: kundelista (amendo-core) eller web-innloggingen. */
function amendo_b2b_tellix_i_bruk() {
    return amendo_tellix_api_tilgjengelig() || amendo_b2b_tellix_tilgang() !== null;
}

/** Token, eller WP_Error. Én innlogging per forespørsel. */
function amendo_b2b_tellix_token(array $t) {
    static $tokens = [];
    $n = md5($t['url'] . '|' . $t['bruker']);
    if (isset($tokens[$n])) return $tokens[$n];
    $r = wp_remote_post($t['url'] . 'web/' . $t['versjon'] . '/getaccesstoken', [
        'timeout' => 30,
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded', 'source' => 'wordpress'],
        'body'    => ['username' => $t['bruker'], 'password' => $t['passord'], 'apikey' => $t['nokkel']],
    ]);
    if (is_wp_error($r)) return new WP_Error('tellix', 'Fikk ikke kontakt med Tellix.');
    $kode = (int) wp_remote_retrieve_response_code($r);
    $body = json_decode(wp_remote_retrieve_body($r), true);
    if ($kode !== 200 || empty($body['token']) || !is_string($body['token'])) {
        return new WP_Error('tellix', "Innloggingen til Tellix feilet (HTTP $kode). Sjekk Tellix-tilgangen.");
    }
    return $tokens[$n] = $body['token'];
}

/** POST til web-API-et. ['kode' => int, 'json' => array|null], eller WP_Error. */
function amendo_b2b_tellix_post(array $t, $sti, array $data) {
    $token = amendo_b2b_tellix_token($t);
    if (is_wp_error($token)) return $token;
    $r = wp_remote_post($t['url'] . 'web/' . $t['versjon'] . '/' . $sti, [
        'timeout' => 30,
        'headers' => ['Authorization' => $token, 'Content-Type' => 'application/json', 'source' => 'wordpress'],
        'body'    => wp_json_encode($data),
    ]);
    if (is_wp_error($r)) return new WP_Error('tellix', 'Fikk ikke kontakt med Tellix.');
    $json = json_decode(wp_remote_retrieve_body($r), true);
    return ['kode' => (int) wp_remote_retrieve_response_code($r), 'json' => is_array($json) ? $json : null];
}

// ── Kunden som sendes ───────────────────────────────────────────────────────

/**
 * Navn og adresse fra Enhetsregisteret, eller null. Registreringen på
 * garcon.no lagrer ikke adresse, så uten dette fikk Tellix-kunden ingen.
 * Forretningsadressen, ellers postadressen.
 */
function amendo_b2b_brreg($orgnr) {
    if (strlen($orgnr) !== 9) return null;
    $r = wp_remote_get('https://data.brreg.no/enhetsregisteret/api/enheter/' . $orgnr, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) return null;
    $e = json_decode(wp_remote_retrieve_body($r), true);
    if (!is_array($e)) return null;
    $a = is_array($e['forretningsadresse'] ?? null) ? $e['forretningsadresse'] : (is_array($e['postadresse'] ?? null) ? $e['postadresse'] : []);
    return [
        'navn'    => trim((string) ($e['navn'] ?? '')),
        'adresse' => implode(', ', array_filter(array_map('trim', array_map('strval', (array) ($a['adresse'] ?? []))))),
        'zip'     => trim((string) ($a['postnummer'] ?? '')),
        'sted'    => trim((string) ($a['poststed'] ?? '')),
    ];
}

/** Org.nummeret på brukeren: det godkjente, ellers søknaden. Bare sifre. */
function amendo_b2b_tellix_orgnr($bruker_id) {
    return amendo_b2b_orgnr(get_user_meta($bruker_id, 'billing_org_number', true) ?: get_user_meta($bruker_id, '_orgnr_soknad', true));
}

/** Feltene til customer/save, som map_user_to_customer_data() i sync-pluginen. */
function amendo_b2b_tellix_kunde_data($bruker_id, $gruppe) {
    $u = get_userdata($bruker_id);
    $m = function($k) use ($bruker_id) { return trim((string) get_user_meta($bruker_id, $k, true)); };
    $orgnr = amendo_b2b_tellix_orgnr($bruker_id);
    $adresse = trim($m('billing_address_1') . ' ' . $m('billing_address_2'));
    $zip = $m('billing_postcode');
    $sted = $m('billing_city');
    $navn = $m('billing_company');
    if ($adresse === '' || $navn === '') {
        $b = amendo_b2b_brreg($orgnr);
        if ($b) {
            if ($navn === '') $navn = $b['navn'];
            if ($adresse === '') [$adresse, $zip, $sted] = [$b['adresse'], $b['zip'], $b['sted']];
        }
    }
    if ($navn === '') $navn = trim($m('first_name') . ' ' . $m('last_name')) ?: (string) $u->display_name;
    $land = $m('billing_country') ?: 'NO';
    // Tellix bruker «Norge», ikke «NO».
    if (strtoupper($land) === 'NO' || strtolower($land) === 'norway') $land = 'Norge';

    $k = [
        'customerName' => $navn,
        'name'         => $navn,
        'email'        => sanitize_email($u->user_email ?: $m('billing_email')),
        'phoneNumber'  => $m('billing_phone'),
        'address'      => $adresse,
        'zip'          => $zip,
        'location'     => $sted,
        'country'      => $land,
        'isActive'     => true,
    ];
    if ($orgnr !== '') $k['orgNumber'] = $orgnr;
    if ($gruppe > 0) $k['customerGroupId'] = (int) $gruppe;
    return $k;
}

// ── Oppslag ─────────────────────────────────────────────────────────────────

/** Kundelista hentet på nytt nå, eller WP_Error. Aldri en gammel kopi: da kunne vi opprettet en kunde som finnes. */
function amendo_b2b_tellix_liste() {
    if (!amendo_tellix_api_tilgjengelig()) return new WP_Error('tellix', 'amendo-core mangler, så kundelista fra Tellix kan ikke leses.');
    if (!amendo_tellix_liste_hent(true)) return new WP_Error('tellix', 'Fikk ikke hentet kundelista fra Tellix.');
    $d = amendo_tellix_liste_les();
    return $d ? $d['kunder'] : new WP_Error('tellix', 'Fikk ikke hentet kundelista fra Tellix.');
}

/** Har minst én kunde i lista org.nr? Hvis ikke, sier ikke et bom på org.nr noe. */
function amendo_b2b_tellix_liste_har_orgnr(array $liste) {
    foreach ($liste as $k) if (($k['orgnr'] ?? '') !== '') return true;
    return false;
}

/**
 * Tellix-kundene som passer: org.nr først, så e-post.
 * ['via' => 'orgnr'|'epost'|null, 'kunder' => [...], 'annet_orgnr' => bool]
 */
function amendo_b2b_tellix_kandidater(array $liste, $orgnr, $epost) {
    if (strlen($orgnr) === 9) {
        $t = array_values(array_filter($liste, function($k) use ($orgnr) { return ($k['orgnr'] ?? '') === $orgnr; }));
        if ($t) return ['via' => 'orgnr', 'kunder' => $t, 'annet_orgnr' => false];
    }
    $e = amendo_tellix_epost($epost);
    if ($e !== '') {
        $t = array_values(array_filter($liste, function($k) use ($e) { return $k['epost'] !== '' && amendo_tellix_epost($k['epost']) === $e; }));
        if ($t) {
            $annet = false;
            foreach ($t as $k) if (($k['orgnr'] ?? '') !== '' && strlen($orgnr) === 9 && $k['orgnr'] !== $orgnr) $annet = true;
            return ['via' => 'epost', 'kunder' => $t, 'annet_orgnr' => $annet];
        }
    }
    return ['via' => null, 'kunder' => [], 'annet_orgnr' => false];
}

// ── Kobling ─────────────────────────────────────────────────────────────────

function amendo_b2b_tellix_er_koblet($bruker_id) {
    foreach (['_tellixCustomerId', '_tellixCustomerUuId'] as $n) {
        $v = (string) get_user_meta($bruker_id, $n, true);
        if ($v !== '' && $v !== '0') return true;
    }
    return false;
}

/** Gruppenavnet til visning: «Bedriftskunder», «gruppe 14» eller «ingen gruppe». */
function amendo_b2b_tellix_gruppenavn($g) {
    if ($g === null) return 'ukjent gruppe';
    if ((int) $g <= 0) return 'ingen gruppe';
    $navn = amendo_tellix_grupper()[(int) $g]['navn'] ?? '';
    return $navn !== '' ? $navn : 'gruppe ' . (int) $g;
}

/** Lagrer resultatet på brukeren. Statusen ligger også for seg, så kortet kan søke på den. */
function amendo_b2b_tellix_status($bruker_id, $status, $melding, $gruppe, array $kandidater = []) {
    $data = ['status' => $status, 'melding' => $melding, 'tid' => gmdate('c'), 'gruppe' => $gruppe, 'kandidater' => $kandidater];
    update_user_meta($bruker_id, '_b2b_tellix_status', $status);
    update_user_meta($bruker_id, '_b2b_tellix', $data);
    return $data;
}

/** Skriv koblingen. Tellix er sannheten for gruppen, som i amendo_tellix_oppdater_bruker(). */
function amendo_b2b_tellix_skriv($bruker_id, array $k, $gruppe) {
    update_user_meta($bruker_id, '_tellixCustomerId', (string) $k['id']);
    if (($k['uuid'] ?? '') !== '') update_user_meta($bruker_id, '_tellixCustomerUuId', (string) $k['uuid']);
    $g = $k['gruppe_id'] ?? null;
    if ($g === null && $gruppe !== null) $g = (int) $gruppe;
    if ($g !== null) update_user_meta($bruker_id, '_tellixGroupId', (string) (int) $g);
}

/** Atomisk lås per bruker i to minutter, så to klikk ikke lager to kunder. */
function amendo_b2b_tellix_las($bruker_id) {
    $n = 'amendo_b2b_tellix_las_' . (int) $bruker_id;
    if (add_option($n, (string) time(), '', false)) return true;
    if (time() - (int) get_option($n) <= 120) return false;
    delete_option($n);
    return add_option($n, (string) time(), '', false);
}

/**
 * Koble eller opprett for hver bruker. $grupper: [bruker_id => int|null]
 * (null = ikke valgt). $valg: [bruker_id => ['kunde' => id] | ['ny' => true]]
 * fra kortet. Returnerer [bruker_id => resultat]; 'status' er 'koblet',
 * 'opprettet', 'valg', 'feil' eller 'allerede' (koblet fra før, ingenting gjort).
 */
function amendo_b2b_tellix_kjor(array $grupper, array $valg = []) {
    $start = microtime(true);
    $ut = [];
    $ider = [];
    foreach ($grupper as $id => $g) {
        if (!get_userdata((int) $id)) continue;
        if (amendo_b2b_tellix_er_koblet((int) $id)) {
            delete_user_meta((int) $id, '_b2b_tellix_status');
            $ut[(int) $id] = ['status' => 'allerede', 'melding' => 'Allerede koblet til Tellix.'];
            continue;
        }
        $ider[] = (int) $id;
    }
    if (!$ider) return $ut;

    $liste = amendo_b2b_tellix_liste();
    if (is_wp_error($liste)) {
        foreach ($ider as $id) $ut[$id] = amendo_b2b_tellix_status($id, 'feil', $liste->get_error_message(), $grupper[$id]);
        return $ut;
    }

    $opprettet = [];
    foreach ($ider as $id) {
        if (!amendo_b2b_tellix_las($id)) {
            $ut[$id] = ['status' => 'feil', 'melding' => 'Kunden behandles allerede. Vent litt og last siden på nytt.'];
            continue;
        }
        try {
            $ut[$id] = amendo_b2b_tellix_en($id, $grupper[$id], $valg[$id] ?? [], $liste);
            if ($ut[$id]['status'] === 'opprettet') $opprettet[$id] = $ut[$id];
        } finally {
            delete_option('amendo_b2b_tellix_las_' . $id);
        }
    }

    // Etter opprettelsen: UUID og gruppe slik Tellix faktisk lagret dem.
    if ($opprettet) {
        $ny = amendo_b2b_tellix_liste();
        foreach ($opprettet as $id => $r) {
            $kid = (string) get_user_meta($id, '_tellixCustomerId', true);
            $k = null;
            foreach (is_wp_error($ny) ? [] : $ny as $kk) if ($kk['id'] === $kid) { $k = $kk; break; }
            $melding = $r['melding'];
            if ($k) {
                if ($k['uuid'] !== '') update_user_meta($id, '_tellixCustomerUuId', $k['uuid']);
                if ($k['gruppe_id'] !== null && $k['gruppe_id'] !== (int) $r['gruppe']) {
                    update_user_meta($id, '_tellixGroupId', (string) $k['gruppe_id']);
                    $melding .= ' ⚠ Tellix lagret kunden med ' . amendo_b2b_tellix_gruppenavn($k['gruppe_id']) . ', ikke ' . amendo_b2b_tellix_gruppenavn($r['gruppe']) . '. Endre gruppen i Tellix.';
                }
            }
            if ((string) get_user_meta($id, '_tellixCustomerUuId', true) === '') {
                $melding .= ' UUID-en fylles inn neste gang kunden slås opp.';
            }
            $ut[$id] = amendo_b2b_tellix_status($id, 'opprettet', $melding, $r['gruppe']);
        }
    }

    $antall = array_count_values(array_column($ut, 'status'));
    amendo_tellix_logg(sprintf('Tellix-kobling: %d koblet, %d opprettet, %d valg, %d feil (%.1f s)',
        $antall['koblet'] ?? 0, $antall['opprettet'] ?? 0, $antall['valg'] ?? 0, $antall['feil'] ?? 0, microtime(true) - $start));
    return $ut;
}

/** Én bruker. $liste er kundelista, og får nye kunder lagt til (samme org.nr senere i samme kjøring kobles til dem). */
function amendo_b2b_tellix_en($id, $gruppe, array $valg, array &$liste) {
    $u = get_userdata($id);
    $orgnr = amendo_b2b_tellix_orgnr($id);
    $funn = amendo_b2b_tellix_kandidater($liste, $orgnr, $u->user_email);

    if (isset($valg['kunde'])) {
        // Admin har valgt. Bare blant kandidatene som ble vist.
        $lagret = get_user_meta($id, '_b2b_tellix', true);
        $kand = is_array($lagret) ? (array) ($lagret['kandidater'] ?? []) : [];
        $lov = array_map('strval', array_column($kand, 'id'));
        $k = null;
        foreach ($liste as $kk) if ($kk['id'] === (string) $valg['kunde'] && in_array($kk['id'], $lov, true)) { $k = $kk; break; }
        if (!$k) {
            if ($kand) return amendo_b2b_tellix_status($id, 'valg', 'Fant ikke Tellix-kunden du valgte. Velg en av kundene under, eller opprett en ny.', $gruppe, $kand);
            return amendo_b2b_tellix_status($id, 'feil', 'Fant ikke Tellix-kunden du valgte. Prøv igjen.', $gruppe);
        }
        return amendo_b2b_tellix_koblet($id, $k, $gruppe, 'valgt av admin');
    }

    if (empty($valg['ny']) && $funn['kunder']) {
        if (count($funn['kunder']) === 1 && !$funn['annet_orgnr']) {
            return amendo_b2b_tellix_koblet($id, $funn['kunder'][0], $gruppe, $funn['via'] === 'orgnr' ? 'org.nr' : 'e-post');
        }
        $grunn = $funn['annet_orgnr']
            ? 'En Tellix-kunde har samme e-post, men et annet org.nr.'
            : count($funn['kunder']) . ' Tellix-kunder har samme ' . ($funn['via'] === 'orgnr' ? 'org.nr' : 'e-post') . '.';
        $kand = array_map(function($k) { return array_intersect_key($k, array_flip(['id', 'uuid', 'navn', 'gruppe_id', 'orgnr', 'epost'])); }, array_slice($funn['kunder'], 0, 20));
        return amendo_b2b_tellix_status($id, 'valg', $grunn . ' Velg riktig kunde, eller opprett en ny.', $gruppe, $kand);
    }

    // ⚠⚠ Har INGEN kunder i lista org.nr, er oppslaget på org.nr ikke gjort,
    // bare på e-post — bedriften kan finnes i Tellix med en annen e-post.
    // Da opprettes ingenting uten at admin har sett etter i Tellix og trykket
    // «Opprett ny».
    if (empty($valg['ny']) && strlen($orgnr) === 9 && !amendo_b2b_tellix_liste_har_orgnr($liste)) {
        return amendo_b2b_tellix_status($id, 'valg', 'Kundelista fra Tellix har ikke org.nr, så vi kan ikke se om bedriften finnes der fra før. Søk etter ' . $orgnr . ' i Tellix: finnes den, koble kunden der; ellers trykk «Opprett ny».', $gruppe);
    }
    if ($gruppe === null) {
        return amendo_b2b_tellix_status($id, 'feil', 'Fant ikke kunden i Tellix. Velg kundegruppe og trykk «Prøv igjen» for å opprette den.', null);
    }
    $t = amendo_b2b_tellix_tilgang();
    if (!$t) return amendo_b2b_tellix_status($id, 'feil', 'Fant ikke kunden i Tellix, og Tellix-tilgangen for å opprette kunder er ikke satt opp (B2B → Bedrifter → Tellix-tilgang).', $gruppe);

    $data = amendo_b2b_tellix_kunde_data($id, (int) $gruppe);
    $r = amendo_b2b_tellix_post($t, 'customer/save', ['data' => [$data]]);
    if (is_wp_error($r)) return amendo_b2b_tellix_status($id, 'feil', $r->get_error_message() . ' Kunden ble ikke opprettet.', $gruppe);
    $d0 = $r['json']['data'][0] ?? [];
    $cd = is_array($d0['customerData'] ?? null) ? $d0['customerData'] : (is_array($d0) ? $d0 : []);
    $kid = $cd['customerId'] ?? ($d0['customerId'] ?? null);
    if ($r['kode'] !== 200 || !is_scalar($kid) || (string) $kid === '' || (string) $kid === '0') {
        return amendo_b2b_tellix_status($id, 'feil', "Tellix opprettet ikke kunden (HTTP {$r['kode']}). Se i Tellix om den finnes før du prøver igjen — finnes den, kobles den.", $gruppe);
    }
    $uuid = (string) ($cd['customerUuid'] ?? $cd['customerUuId'] ?? $d0['customerUuid'] ?? '');
    $k = ['id' => (string) $kid, 'uuid' => $uuid, 'gruppe_id' => (int) $gruppe, 'navn' => $data['name'], 'epost' => amendo_tellix_epost($data['email']), 'telefon' => '', 'orgnr' => $data['orgNumber'] ?? ''];
    amendo_b2b_tellix_skriv($id, $k, $gruppe);
    $liste[] = $k;

    // Som sync-pluginen: save tar ikke alle feltene, så de sendes på nytt.
    $oppd = $data;
    unset($oppd['customerName']);
    $r2 = amendo_b2b_tellix_post($t, 'customer/update/' . rawurlencode((string) $kid), ['data' => $oppd]);
    $merk = (is_wp_error($r2) || $r2['kode'] !== 200) ? ' ⚠ Oppdateringen etter opprettelsen feilet: sjekk adresse og org.nr på kunden i Tellix.' : '';
    return ['status' => 'opprettet', 'gruppe' => (int) $gruppe,
        'melding' => 'Opprettet i Tellix som kunde ' . $kid . ' med ' . amendo_b2b_tellix_gruppenavn((int) $gruppe) . '.' . $merk];
}

function amendo_b2b_tellix_koblet($id, array $k, $gruppe, $via) {
    amendo_b2b_tellix_skriv($id, $k, $gruppe);
    $melding = 'Koblet til Tellix-kunde ' . $k['id'] . ($k['navn'] !== '' ? ' (' . $k['navn'] . ')' : '') . ' på ' . $via . ', med ' . amendo_b2b_tellix_gruppenavn($k['gruppe_id'] ?? $gruppe) . '.';
    if ($gruppe !== null && ($k['gruppe_id'] ?? null) !== null && (int) $k['gruppe_id'] !== (int) $gruppe) {
        $melding .= ' ⚠ Du valgte ' . amendo_b2b_tellix_gruppenavn($gruppe) . ', men en eksisterende kunde endres ikke. Endre gruppen i Tellix om den skal ha den.';
    }
    return amendo_b2b_tellix_status($id, 'koblet', $melding, $gruppe);
}

// ── Admin: handlinger ───────────────────────────────────────────────────────

/** Kundegruppen fra et skjemafelt: null = ikke valgt, 0 = ingen gruppe. */
function amendo_b2b_tellix_gruppe_fra_skjema($v) {
    $v = is_scalar($v) ? trim((string) $v) : '';
    if ($v === '' || !ctype_digit($v)) return null;
    $g = (int) $v;
    return $g === 0 || isset(amendo_tellix_grupper()[$g]) ? $g : null;
}

// Kortet «Tellix-kobling»: prøv igjen, koble til valgt kunde, opprett ny.
add_action('admin_post_amendo_b2b_tellix', function() {
    if (!current_user_can(amendo_settings_kapabilitet())) wp_die('Ingen tilgang', 403);
    check_admin_referer('amendo_b2b_tellix');
    $handling = isset($_POST['koble']) ? 'koble' : (isset($_POST['ny']) ? 'ny' : 'prov');
    $id = (int) ($_POST[$handling] ?? 0);
    $gruppe = amendo_b2b_tellix_gruppe_fra_skjema(wp_unslash(((array) ($_POST['gruppe'] ?? []))[$id] ?? ''));
    $valg = [];
    if ($handling === 'koble') {
        $kunde = ((array) ($_POST['kunde'] ?? []))[$id] ?? '';
        $valg = ['kunde' => is_scalar($kunde) ? sanitize_text_field(wp_unslash((string) $kunde)) : ''];
    }
    if ($handling === 'ny') $valg = ['ny' => true];
    $r = $id ? (amendo_b2b_tellix_kjor([$id => $gruppe], [$id => $valg])[$id] ?? null) : null;
    wp_redirect(admin_url('admin.php?page=amendo-settings&tellix=' . rawurlencode($r['status'] ?? 'ukjent') . '&tb=' . $id . '#b2b'));
    exit;
});

// Tellix-tilgangen. Bare administrator: det er innloggingen til kassesystemet.
add_action('admin_post_amendo_tellix_web', function() {
    if (!current_user_can('manage_options')) wp_die('Ingen tilgang', 403);
    check_admin_referer('amendo_tellix_web');
    $p = wp_unslash($_POST);
    if (!empty($p['tellix_web_test'])) {
        $t = amendo_b2b_tellix_tilgang();
        $svar = $t && !is_wp_error(amendo_b2b_tellix_token($t)) ? 'ok' : 'feil';
    } else {
        $l = amendo_b2b_tellix_web_lagret();
        if (!empty($p['tellix_web_fjern'])) {
            delete_option('amendo_tellix_web');
        } else {
            $url = esc_url_raw(trim((string) ($p['tellix_web_url'] ?? '')));
            $l['url'] = $url !== '' ? trailingslashit($url) : AMENDO_B2B_TELLIX_URL;
            $v = trim((string) ($p['tellix_web_versjon'] ?? ''));
            $l['versjon'] = preg_match('/^v\d+$/', $v) ? $v : AMENDO_B2B_TELLIX_VERSJON;
            $l['bruker'] = sanitize_text_field((string) ($p['tellix_web_bruker'] ?? ''));
            // Tomt felt = behold. Passord og nøkkel vises aldri.
            foreach (['passord', 'nokkel'] as $f) {
                $ny = trim((string) ($p['tellix_web_' . $f] ?? ''));
                if ($ny !== '') $l[$f] = $ny;
            }
            update_option('amendo_tellix_web', $l, false);
        }
        $svar = 'lagret';
    }
    wp_redirect(admin_url('admin.php?page=amendo-settings&tellix_web=' . $svar . '#b2b'));
    exit;
});

// ── Admin: massehandling i Brukere ──────────────────────────────────────────
//
// For butikker med bedriftskunder fra før (Aanerud): Brukere → filteret
// «Uten Tellix-kobling» → velg → «Koble til Tellix» med kundegruppen over
// tabellen. Maks 25 om gangen: hver ny kunde er flere kall til Tellix.

add_filter('bulk_actions-users', function($handlinger) {
    if (current_user_can(amendo_settings_kapabilitet())) $handlinger['amendo_tellix_koble'] = 'Koble til Tellix';
    return $handlinger;
});

add_action('restrict_manage_users', function($hvor = 'top') {
    if ($hvor !== 'top' || !current_user_can(amendo_settings_kapabilitet())) return;
    echo '<label class="screen-reader-text" for="amendo_tellix_gruppe">Kundegruppe for nye Tellix-kunder</label>';
    echo '<select name="amendo_tellix_gruppe" id="amendo_tellix_gruppe" style="float:none;margin-left:8px">';
    amendo_b2b_tellix_gruppevalg(null, 'Kundegruppe for nye Tellix-kunder');
    echo '</select>';
});

add_filter('handle_bulk_actions-users', function($til, $handling, $ider) {
    if ($handling !== 'amendo_tellix_koble') return $til;
    if (!current_user_can(amendo_settings_kapabilitet())) wp_die('Ingen tilgang', 403);
    $gruppe = amendo_b2b_tellix_gruppe_fra_skjema(wp_unslash($_REQUEST['amendo_tellix_gruppe'] ?? ''));
    $ider = array_values(array_unique(array_map('intval', (array) $ider)));
    $resten = max(0, count($ider) - AMENDO_B2B_TELLIX_MASSE);
    $ider = array_slice($ider, 0, AMENDO_B2B_TELLIX_MASSE);
    $ut = amendo_b2b_tellix_kjor(array_fill_keys($ider, $gruppe));
    $antall = array_count_values(array_column($ut, 'status'));
    $tall = [];
    foreach (['koblet', 'opprettet', 'valg', 'feil', 'allerede'] as $s) $tall[] = (int) ($antall[$s] ?? 0);
    $tall[] = $resten;
    return add_query_arg('amendo_tellix', implode('-', $tall), remove_query_arg('amendo_tellix', $til));
}, 10, 3);

// Beskjeden vises etter omdirigeringen tilbake til Brukere (amendo_tellix i
// adressen), som WordPress sine egne massehandlinger.
add_action('admin_notices', function() {
    global $pagenow;
    if ($pagenow !== 'users.php' || !isset($_GET['amendo_tellix'])) return;
    amendo_b2b_tellix_masse_beskjed((string) $_GET['amendo_tellix']);
});

/** $tall: «koblet-opprettet-valg-feil-allerede-resten» fra adressen. */
function amendo_b2b_tellix_masse_beskjed($tall) {
    $t = array_map('intval', explode('-', $tall)) + array_fill(0, 6, 0);
    $deler = [];
    foreach (['koblet til en Tellix-kunde', 'opprettet i Tellix', 'må velges for hånd', 'feilet', 'var koblet fra før'] as $i => $tekst) {
        if ($t[$i]) $deler[] = $t[$i] . ' ' . $tekst;
    }
    $feil = $t[2] + $t[3];
    echo '<div class="notice notice-' . ($feil ? 'warning' : 'success') . ' is-dismissible"><p><strong>Tellix:</strong> ' . esc_html(implode(', ', $deler ?: ['ingen brukere behandlet'])) . '.';
    if ($t[5]) echo ' ' . esc_html($t[5] . ' ble ikke behandlet (maks ' . AMENDO_B2B_TELLIX_MASSE . ' om gangen). Kjør igjen for resten.');
    if ($feil) echo ' <a href="' . esc_url(admin_url('admin.php?page=amendo-settings#b2b')) . '">Se B2B → Bedrifter → Tellix-kobling</a>.';
    echo '</p></div>';
}

// Filteret «Uten Tellix-kobling» i Brukere.
add_filter('views_users', function($visninger) {
    if (!current_user_can(amendo_settings_kapabilitet())) return $visninger;
    $aktiv = !empty($_GET['amendo_tellix_ukoblet']);
    $visninger['amendo_tellix_ukoblet'] = '<a href="' . esc_url(admin_url('users.php?amendo_tellix_ukoblet=1')) . '"' . ($aktiv ? ' class="current" aria-current="page"' : '') . '>Uten Tellix-kobling</a>';
    return $visninger;
});

add_action('pre_get_users', function($q) {
    global $pagenow;
    if (!is_admin() || $pagenow !== 'users.php' || empty($_GET['amendo_tellix_ukoblet'])) return;
    $mangler = function($n) { return ['relation' => 'OR', ['key' => $n, 'compare' => 'NOT EXISTS'], ['key' => $n, 'value' => ['', '0'], 'compare' => 'IN']]; };
    $meta = $q->get('meta_query');
    $meta = is_array($meta) ? $meta : [];
    $meta[] = ['relation' => 'AND', $mangler('_tellixCustomerId'), $mangler('_tellixCustomerUuId')];
    $q->set('meta_query', $meta);
});

// ── Admin: visning ──────────────────────────────────────────────────────────

/** <option>-ene: ikke valgt, ingen gruppe, og Tellix-gruppene. */
function amendo_b2b_tellix_gruppevalg($valgt, $tom = '— Kundegruppe —') {
    echo '<option value=""' . selected($valgt === null, true, false) . '>' . esc_html($tom) . '</option>';
    echo '<option value="0"' . selected($valgt === 0, true, false) . '>Ingen gruppe</option>';
    foreach (amendo_tellix_grupper() as $g) {
        echo '<option value="' . (int) $g['id'] . '"' . selected($valgt === $g['id'], true, false) . '>' . esc_html(($g['navn'] !== '' ? $g['navn'] : 'Gruppe ' . $g['id']) . ' (' . $g['id'] . ')') . '</option>';
    }
}

/** Brukerne med valg eller feil. */
function amendo_b2b_tellix_apne() {
    $q = new WP_User_Query(['meta_key' => '_b2b_tellix_status', 'meta_value' => ['valg', 'feil'], 'meta_compare' => 'IN', 'orderby' => 'registered', 'order' => 'DESC', 'number' => 100]);
    return $q->get_results();
}

/** Beskjeden øverst i fanen etter en handling, eller null. */
function amendo_b2b_tellix_melding() {
    if (isset($_GET['tellix_web'])) {
        $m = ['lagret' => ['ok', 'Tellix-tilgangen er lagret.'], 'ok' => ['ok', 'Innloggingen til Tellix virker.'], 'feil' => ['feil', 'Innloggingen til Tellix feilet. Sjekk brukernavn, passord, API-nøkkel og adresse.']];
        return $m[(string) $_GET['tellix_web']] ?? null;
    }
    $id = (int) ($_GET['tb'] ?? 0);
    $status = (string) ($_GET['tellix'] ?? '');
    if (!$id || $status === '') return null;
    if ($status === 'allerede') return ['ok', 'Kunden var allerede koblet til Tellix.'];
    $d = get_user_meta($id, '_b2b_tellix', true);
    if (!is_array($d)) return null;
    $nivaa = ['koblet' => 'ok', 'opprettet' => 'ok', 'valg' => 'advarsel'][$d['status']] ?? 'feil';
    return [$nivaa, 'Tellix: ' . $d['melding']];
}

function amendo_b2b_tellix_kort() {
    $t = amendo_b2b_tellix_tilgang();
    $l = amendo_b2b_tellix_web_lagret();
    $apne = amendo_b2b_tellix_apne();
    $admin = current_user_can('manage_options');
    ?>
    <div class="amendo-card" id="b2b-tellix">
        <h2>Tellix-kobling<?php echo $apne ? ' (' . count($apne) . ')' : ''; ?></h2>
        <p class="amendo-desc">Når en søknad godkjennes, kobles kunden til Tellix-kunden med samme org.nr (ellers e-post), eller opprettes i Tellix med kundegruppen du velger. Faktura virker når kunden er koblet. Kunder som ble godkjent før dette, kobles i <a href="<?php echo esc_url(admin_url('users.php?amendo_tellix_ukoblet=1')); ?>">Brukere → Uten Tellix-kobling</a> med massehandlingen «Koble til Tellix».</p>
        <?php if (!$apne): ?>
            <p class="field-help">Ingen kunder venter på kobling.</p>
        <?php else: ?>
        <table class="amendo-tabell">
            <thead><tr><th>Firma</th><th>Kontaktperson</th><th>Status</th><th>Kundegruppe</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($apne as $u):
                $d = get_user_meta($u->ID, '_b2b_tellix', true);
                $d = is_array($d) ? $d : [];
                $g = isset($d['gruppe']) && $d['gruppe'] !== null ? (int) $d['gruppe'] : null;
                $orgnr = amendo_b2b_tellix_orgnr($u->ID);
            ?>
            <tr class="b2b-tellix-rad b2b-tellix-<?php echo esc_attr($d['status'] ?? ''); ?>" data-bruker="<?php echo (int) $u->ID; ?>">
                <td><?php echo esc_html(get_user_meta($u->ID, 'billing_company', true) ?: '—'); ?><br><span class="field-help"><?php echo esc_html($orgnr ?: '—'); ?></span></td>
                <td><a href="<?php echo esc_url(get_edit_user_link($u->ID)); ?>"><?php echo esc_html(trim($u->first_name . ' ' . $u->last_name) ?: $u->display_name); ?></a><br><span class="field-help"><?php echo esc_html($u->user_email); ?></span></td>
                <td>
                    <?php echo esc_html($d['melding'] ?? ''); ?>
                    <?php if (($d['status'] ?? '') === 'valg' && !empty($d['kandidater'])): ?>
                        <fieldset class="b2b-tellix-kandidater">
                        <?php foreach ($d['kandidater'] as $i => $k): ?>
                            <label><input type="radio" form="amendo-b2b-tellix" name="kunde[<?php echo (int) $u->ID; ?>]" value="<?php echo esc_attr($k['id']); ?>"<?php checked($i, 0); ?>>
                            <?php echo esc_html(($k['navn'] ?: 'Uten navn') . ' · kunde ' . $k['id'] . ' · ' . amendo_b2b_tellix_gruppenavn($k['gruppe_id'] ?? null) . ' · org.nr ' . (($k['orgnr'] ?? '') ?: '—') . ' · ' . (($k['epost'] ?? '') ?: '—')); ?></label><br>
                        <?php endforeach; ?>
                        </fieldset>
                    <?php endif; ?>
                </td>
                <td><select form="amendo-b2b-tellix" name="gruppe[<?php echo (int) $u->ID; ?>]"><?php amendo_b2b_tellix_gruppevalg($g); ?></select></td>
                <td class="b2b-knapper">
                    <?php if (($d['status'] ?? '') === 'valg'): ?>
                        <?php if (!empty($d['kandidater'])): ?>
                        <button type="submit" form="amendo-b2b-tellix" name="koble" value="<?php echo (int) $u->ID; ?>" class="amendo-btn-secondary">Koble til valgt</button>
                        <?php endif; ?>
                        <button type="submit" form="amendo-b2b-tellix" name="ny" value="<?php echo (int) $u->ID; ?>" class="amendo-btn-secondary b2b-bekreft" data-bekreft="Opprette en ny kunde i Tellix, selv om det finnes kunder med samme org.nr eller e-post?">Opprett ny</button>
                    <?php else: ?>
                        <button type="submit" form="amendo-b2b-tellix" name="prov" value="<?php echo (int) $u->ID; ?>" class="amendo-btn-secondary">Prøv igjen</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h3>Tellix-tilgang</h3>
        <p class="field-help">
            <?php if (!$t): ?>
                <strong>Ikke satt opp.</strong> Kunder som ikke finnes i Tellix, kan ikke opprettes før tilgangen er lagt inn.
            <?php elseif ($t['kilde'] === 'egen'): ?>
                Bruker innloggingen under, mot <?php echo esc_html($t['url']); ?>.
            <?php else: ?>
                Bruker innloggingen fra Garcon Tellix Sync (miljø: <strong><?php echo esc_html($t['miljo']); ?></strong>, <?php echo esc_html($t['url']); ?>). Fyll inn under for å bruke en egen.
                <?php if ($t['miljo'] !== 'live'): ?><br>⚠ Det er testmiljøet: kundene opprettes ikke i den ekte Tellix-basen.<?php endif; ?>
            <?php endif; ?>
        </p>
        <?php if ($admin): ?>
        <div class="b2b-tellix-web">
            <p><label>Adresse <input type="url" form="amendo-tellix-web" name="tellix_web_url" value="<?php echo esc_attr($l['url']); ?>"></label>
               <label>API-versjon <input type="text" form="amendo-tellix-web" name="tellix_web_versjon" value="<?php echo esc_attr($l['versjon']); ?>" size="4"></label></p>
            <p><label>Brukernavn <input type="text" form="amendo-tellix-web" name="tellix_web_bruker" value="<?php echo esc_attr($l['bruker']); ?>" autocomplete="off"></label>
               <label>Passord <input type="password" form="amendo-tellix-web" name="tellix_web_passord" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($l['passord'] !== '' ? 'Satt — tomt felt beholder det' : 'Ikke satt'); ?>"></label>
               <label>API-nøkkel <input type="password" form="amendo-tellix-web" name="tellix_web_nokkel" value="" autocomplete="off" placeholder="<?php echo esc_attr($l['nokkel'] !== '' ? 'Satt — tomt felt beholder den' : 'Ikke satt'); ?>"></label></p>
            <p class="b2b-knapper">
                <button type="submit" form="amendo-tellix-web" class="amendo-btn-secondary">Lagre Tellix-tilgang</button>
                <button type="submit" form="amendo-tellix-web" name="tellix_web_test" value="1" class="amendo-btn-secondary">Test innloggingen</button>
                <?php if ($l['bruker'] !== '' || $l['passord'] !== ''): ?>
                <button type="submit" form="amendo-tellix-web" name="tellix_web_fjern" value="1" class="amendo-btn-danger b2b-bekreft" data-bekreft="Fjerne den egne Tellix-tilgangen?">Fjern</button>
                <?php endif; ?>
            </p>
        </div>
        <?php else: ?>
            <p class="field-help">Bare en administrator kan endre Tellix-tilgangen.</p>
        <?php endif; ?>
    </div>
    <?php
}

add_action('amendo_admin_etter_skjema', function() {
    ?>
    <form id="amendo-b2b-tellix" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('amendo_b2b_tellix'); ?>
        <input type="hidden" name="action" value="amendo_b2b_tellix">
    </form>
    <?php if (current_user_can('manage_options')): ?>
    <form id="amendo-tellix-web" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('amendo_tellix_web'); ?>
        <input type="hidden" name="action" value="amendo_tellix_web">
    </form>
    <?php endif;
});
