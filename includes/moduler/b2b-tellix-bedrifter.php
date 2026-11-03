<?php
/**
 * Bedriftskunder fra Tellix (1.4.29). Del av B2B-modulen: lastes bare når B2B
 * er på — se includes/moduler.php.
 *
 * Prinsippet: TELLIX BESTEMMER HVEM SOM ER BEDRIFT. En bedrift er en
 * Tellix-kunde med org.nr (ni siffer). Tre veier inn, hver med sin bryter i
 * B2B → Bedrifter → «Bedriftskunder fra Tellix», alle av som standard:
 *
 * 1. AUTOMATISK GODKJENNING (amendo_b2b_auto). Ved innlogging (wp_login,
 *    authenticate — jwt-auth — og rabatt/kunde, som frontendene kaller ved
 *    innlogging) og ved registrering (POST /b2b-soknad): finnes e-posten som
 *    Tellix-kunde med org.nr, kobles brukeren og godkjennes. Ingen søknad.
 *
 *    ⚠⚠ BARE E-POST, ALDRI ORG.NR ALENE. Org.numre er offentlige — hele
 *    Enhetsregisteret kan lastes ned. Godkjente vi på org.nr, kunne hvem som
 *    helst registrert seg med en kjent bedrifts nummer og fått se bedriftens
 *    ordrer og handle på faktura. Søker noen med et org.nr som finnes i
 *    Tellix, men med en annen e-post, går søknaden til Søknader som før, med
 *    et hint om Tellix-kunden. Samme når e-posten finnes på flere
 *    Tellix-kunder, når org.nr i søknaden er et annet enn Tellix sitt, når
 *    brukeren er koblet til en annen Tellix-kunde, er avvist, eller er en
 *    butikkbruker.
 *    E-posten er det rabatt/kunde allerede kobler på (1.4.14), og som gir
 *    faktura når Tellix-kunden har org.nr (1.4.27).
 *
 * 2. JOBBEN HVER TIME (amendo_b2b_tellix_jobb_kjor). Nye Tellix-kunder med
 *    org.nr og e-post får nettbutikk-konto (finnes e-posten: bare kobling og
 *    godkjenning), og velkomst-e-posten hvis den er slått på. «Nye» = ikke i
 *    grunnlinjen: første kjøring etter at jobben slås på lagrer alle
 *    kunde-ID-ene som finnes da, og oppretter ingenting. Tellix-lista har
 *    ingen opprettelsesdato vi kan stole på, så det er den sikre måten å la
 *    eksisterende kunder være i fred. Maks 50 per kjøring; resten neste time.
 *
 * 3. «SEND INVITASJON» for de eksisterende: tørrkjøring først, så 25 om
 *    gangen mot nøyaktig den tørrkjøringen (som flytteverktøyet).
 *
 * Uten e-post, med samme e-post på flere Tellix-kunder eller andre usikre
 * treff: lista «Til manuell behandling». Ingenting gjettes.
 *
 * ── Velkomst-e-posten ──────────────────────────────────────────────────────
 * Emne, tekst og knapp fra feltene i kortet, logoen fra Butikk-fanen (den for
 * lys bakgrunn, ellers den vanlige), {butikk} fra butikknavnet. Ingen
 * butikknavn i koden. Lenken går til frontenden:
 *     <Frontend-URL>/sett-passord?key=<get_password_reset_key>&login=<user_login>
 * Frontendens /sett-passord (garcon-b2b 825eecb) bruker WordPress sin egen
 * tilbakestilling (check_password_reset_key / reset_password) — ingen egen
 * nøkkel og ingen egen rute. Nøklene gjelder i 7 dager
 * (password_reset_expiration), og «Glemt passord» fra WordPress peker til
 * samme side når Frontend-URL er satt (retrieve_password_message). Begge
 * deler bare med B2B på: andre butikker har kanskje ikke siden.
 *
 * ⚠ Ingen kundedata i loggen — bare antall.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_B2B_JOBB_MAKS          = 50;
const AMENDO_B2B_INVITER_PER_RUNDE  = 25;
const AMENDO_B2B_INVITER_GYLDIG     = DAY_IN_SECONDS;
const AMENDO_B2B_NOKKEL_GYLDIG      = 7 * DAY_IN_SECONDS;

const AMENDO_B2B_VELKOMST_STANDARD = [
    'emne'  => 'Velkommen som bedriftskunde',
    'tekst' => "Hei!\n\n{firma} er nå registrert som bedriftskunde hos {butikk}, og vi har laget en konto til deg i nettbutikken.\n\nVelg et passord for å logge inn. Da ser du bedriftens priser og bestillinger, og kan handle på faktura.",
    'knapp' => 'Velg passord',
];

function amendo_b2b_bryter($navn) {
    return get_option('amendo_b2b_' . $navn, '') === '1';
}

// ── Felles: oppslag og godkjenning ──────────────────────────────────────────

/** Butikkbruker: får aldri bedriftstilgang automatisk. */
function amendo_b2b_er_butikkbruker($id) {
    return user_can($id, 'edit_users') || user_can($id, amendo_settings_kapabilitet());
}

/** Kopien av kundelista, hentet på nytt når den er eldre enn 5 min (uten å tvinge). Null uten Tellix. */
function amendo_b2b_auto_liste() {
    if (!amendo_tellix_api_tilgjengelig()) return null;
    $d = amendo_tellix_liste_les();
    if (!$d || time() - (int) $d['hentet'] > AMENDO_TELLIX_LISTE_MAKS_ALDER) {
        if (amendo_tellix_liste_hent()) $d = amendo_tellix_liste_les();
    }
    return $d ? $d['kunder'] : null;
}

/** Alle Tellix-kunder med e-posten (renset), med og uten org.nr. */
function amendo_b2b_tellix_med_epost(array $liste, $epost) {
    $e = amendo_tellix_epost($epost);
    if ($e === '') return [];
    return array_values(array_filter($liste, function($k) use ($e) { return $k['epost'] !== '' && amendo_tellix_epost($k['epost']) === $e; }));
}

function amendo_b2b_har_orgnr(array $k) {
    return strlen((string) ($k['orgnr'] ?? '')) === 9;
}

/** Godkjenn og koble brukeren til Tellix-kunden. $hvordan står i meldingen i Tellix-kobling. */
function amendo_b2b_godkjenn_fra_tellix($id, array $k, $hvordan) {
    amendo_b2b_tellix_skriv($id, $k, null);
    update_user_meta($id, 'billing_org_number', $k['orgnr']);
    // Godkjent for hånd fra før (bare koblingen manglet): den godkjenningen står.
    if ((string) get_user_meta($id, '_b2b_status', true) !== 'godkjent') {
        update_user_meta($id, '_b2b_status', 'godkjent');
        update_user_meta($id, '_b2b_kilde', 'tellix');
        update_user_meta($id, '_b2b_behandlet', gmdate('c'));
        update_user_meta($id, '_b2b_behandlet_av', 0);
    }
    delete_user_meta($id, '_orgnr_soknad');
    if ((string) get_user_meta($id, 'billing_company', true) === '' && $k['navn'] !== '') update_user_meta($id, 'billing_company', $k['navn']);
    amendo_b2b_tellix_status($id, 'koblet', 'Godkjent automatisk (' . $hvordan . '): Tellix-kunde ' . $k['id'] . ($k['navn'] !== '' ? ' (' . $k['navn'] . ')' : '') . ' med org.nr ' . $k['orgnr'] . '.', $k['gruppe_id'] ?? null);
}

// ── 1. Automatisk godkjenning ───────────────────────────────────────────────

/**
 * Godkjenn brukeren hvis e-posten er en bedrift i Tellix. $orgnr_soknad: org.nr
 * fra søknaden, hvis det er en. Returnerer 'godkjent', eller hvorfor ikke:
 * 'av', 'ukjent', 'butikk', 'avvist', 'allerede', 'ingen_liste', 'ingen',
 * 'annet_orgnr', 'flere' eller 'koblet_annen'.
 */
function amendo_b2b_auto($id, $orgnr_soknad = '') {
    if (!amendo_b2b_bryter('auto')) return 'av';
    $u = get_userdata((int) $id);
    if (!$u) return 'ukjent';
    if (amendo_b2b_er_butikkbruker($u->ID)) return 'butikk';
    if ((string) get_user_meta($u->ID, '_b2b_status', true) === 'avvist') return 'avvist';
    if ((string) get_user_meta($u->ID, 'billing_org_number', true) !== '') return 'allerede';

    $liste = amendo_b2b_auto_liste();
    if ($liste === null) return 'ingen_liste';
    $alle = amendo_b2b_tellix_med_epost($liste, $u->user_email);
    $treff = array_values(array_filter($alle, 'amendo_b2b_har_orgnr'));
    if (!$treff) return 'ingen';
    // Samme e-post på flere Tellix-kunder (også uten org.nr): ikke gjett.
    if (count($alle) > 1) return 'flere';
    $k = $treff[0];
    $orgnr_soknad = amendo_b2b_orgnr($orgnr_soknad);
    if (strlen($orgnr_soknad) === 9 && $orgnr_soknad !== $k['orgnr']) return 'annet_orgnr';
    $soknad = amendo_b2b_orgnr(get_user_meta($u->ID, '_orgnr_soknad', true));
    if (strlen($soknad) === 9 && $soknad !== $k['orgnr']) return 'annet_orgnr';
    $kid = (string) get_user_meta($u->ID, '_tellixCustomerId', true);
    if ($kid !== '' && $kid !== '0' && $kid !== $k['id']) return 'koblet_annen';

    amendo_b2b_godkjenn_fra_tellix($u->ID, $k, 'e-posten finnes i Tellix');
    amendo_tellix_logg('B2B: 1 bruker godkjent automatisk fra Tellix');
    return 'godkjent';
}

/** Én gang per bruker per forespørsel (authenticate og wp_login kommer etter hverandre). */
function amendo_b2b_auto_en_gang($id) {
    static $sett = [];
    if (isset($sett[(int) $id])) return $sett[(int) $id];
    return $sett[(int) $id] = amendo_b2b_auto((int) $id);
}

add_action('wp_login', function($login, $u = null) {
    if ($u instanceof WP_User) amendo_b2b_auto_en_gang($u->ID);
}, 10, 2);

// jwt-auth (e-post/passord fra frontendene) kaller wp_authenticate(), ikke wp_login.
add_filter('authenticate', function($u) {
    if ($u instanceof WP_User) amendo_b2b_auto_en_gang($u->ID);
    return $u;
}, 1000);

// rabatt/kunde kalles av frontendene ved innlogging (også Vipps og OTP).
add_action('amendo_tellix_kunde_oppslag', 'amendo_b2b_auto_en_gang');

// ── 2 og 3. Tellix → nettbutikk ─────────────────────────────────────────────

/**
 * Hva som skal skje med én Tellix-kunde. Endrer ingenting.
 * ['handling' => 'inviter'|'koble'|'send'|'manuell'|'ferdig'|'ikke_bedrift', 'grunn' => '', 'bruker' => id]
 *   inviter: ingen konto → opprett og send velkomst-e-posten
 *   koble:   konto finnes → koble og godkjenn (ingen e-post: de har passord)
 *   send:    kontoen ble laget her, men e-posten er ikke sendt
 */
function amendo_b2b_tellix_vurder(array $k, array $liste) {
    $v = function($h, $g = '', $b = 0) { return ['handling' => $h, 'grunn' => $g, 'bruker' => (int) $b]; };
    if (!amendo_b2b_har_orgnr($k) || $k['id'] === '') return $v('ikke_bedrift');
    $e = (string) $k['epost'];
    if ($e === '' || !is_email($e) || substr($e, -10) === '@dummy.com') return $v('manuell', 'uten_epost');
    if (count(amendo_b2b_tellix_med_epost($liste, $e)) > 1) return $v('manuell', 'flere_med_epost');
    $u = get_user_by('email', $e);
    if (!$u) return $v('inviter');
    if (amendo_b2b_er_butikkbruker($u->ID)) return $v('manuell', 'butikkbruker', $u->ID);
    if ((string) get_user_meta($u->ID, '_b2b_status', true) === 'avvist') return $v('manuell', 'avvist', $u->ID);
    $kid = (string) get_user_meta($u->ID, '_tellixCustomerId', true);
    if ($kid !== '' && $kid !== '0' && $kid !== $k['id']) return $v('manuell', 'koblet_annen', $u->ID);
    $bo = amendo_b2b_orgnr(get_user_meta($u->ID, 'billing_org_number', true));
    $so = amendo_b2b_orgnr(get_user_meta($u->ID, '_orgnr_soknad', true));
    if (($bo !== '' && $bo !== $k['orgnr']) || (strlen($so) === 9 && $so !== $k['orgnr'])) return $v('manuell', 'annet_orgnr', $u->ID);
    if ($bo === $k['orgnr'] && $kid === $k['id'] && (string) get_user_meta($u->ID, '_b2b_status', true) === 'godkjent') {
        return $v(get_user_meta($u->ID, '_amendo_invitasjon', true) === 'ikke_sendt' ? 'send' : 'ferdig', '', $u->ID);
    }
    return $v('koble', '', $u->ID);
}

/** Legg kunden på lista til manuell behandling. */
function amendo_b2b_manuell_legg_til(array $k, $grunn) {
    $m = get_option('amendo_b2b_tellix_manuell', []);
    $m = is_array($m) ? $m : [];
    $m[$k['id']] = ['navn' => $k['navn'], 'orgnr' => $k['orgnr'], 'epost' => $k['epost'], 'grunn' => $grunn, 'tid' => time()];
    update_option('amendo_b2b_tellix_manuell', $m, false);
}

/** Ledig brukernavn: e-posten, ellers e-posten med et tall. */
function amendo_b2b_brukernavn($epost) {
    $base = sanitize_user($epost, true) ?: 'bedrift';
    $navn = $base;
    for ($i = 2; username_exists($navn); $i++) $navn = $base . '-' . $i;
    return $navn;
}

/**
 * Gjør det vurderingen sier. $send: send velkomst-e-posten. Returnerer
 * 'invitert', 'opprettet' (uten e-post), 'koblet', 'manuell', 'ferdig', 'feil'.
 */
function amendo_b2b_tellix_utfor(array $k, array $v, $send) {
    switch ($v['handling']) {
        case 'manuell':
            amendo_b2b_manuell_legg_til($k, $v['grunn']);
            return 'manuell';
        case 'koble':
            amendo_b2b_godkjenn_fra_tellix($v['bruker'], $k, 'kunden finnes i Tellix');
            return 'koblet';
        case 'send':
            if (!$send) return 'ferdig';
            return amendo_b2b_velkomst_send($v['bruker']) ? 'invitert' : 'feil';
        case 'inviter':
            $id = wp_insert_user([
                'user_login'   => amendo_b2b_brukernavn($k['epost']),
                'user_email'   => $k['epost'],
                'user_pass'    => wp_generate_password(32, true, true),
                'display_name' => $k['navn'] ?: $k['epost'],
                'role'         => 'customer',
            ]);
            if (is_wp_error($id)) return 'feil';
            update_user_meta($id, 'billing_email', $k['epost']);
            if ($k['telefon'] !== '') update_user_meta($id, 'billing_phone', $k['telefon']);
            update_user_meta($id, '_amendo_fra_tellix', gmdate('c'));
            update_user_meta($id, '_amendo_invitasjon', 'ikke_sendt');
            amendo_b2b_godkjenn_fra_tellix($id, $k, 'opprettet fra Tellix');
            if ($send && amendo_b2b_velkomst_send($id)) return 'invitert';
            return 'opprettet';
    }
    return 'ferdig';
}

// ── 2. Jobben ───────────────────────────────────────────────────────────────

add_action('amendo_b2b_tellix_jobb', 'amendo_b2b_tellix_jobb_kjor');

// Planlagt så lenge bryteren er på (også etter at B2B er slått av og på igjen).
add_action('init', function() {
    if (amendo_b2b_bryter('jobb') && !wp_next_scheduled('amendo_b2b_tellix_jobb')) {
        wp_schedule_event(time() + 60, 'hourly', 'amendo_b2b_tellix_jobb');
    }
});

/**
 * Én kjøring. Returnerer ['status' => 'av'|'opptatt'|'feil'|'grunnlinje'|'kjort', …tall].
 */
function amendo_b2b_tellix_jobb_kjor() {
    if (!amendo_b2b_bryter('jobb')) return ['status' => 'av'];
    $las = 'amendo_b2b_tellix_jobb_las';
    if (!add_option($las, (string) time(), '', false)) {
        if (time() - (int) get_option($las) <= 15 * MINUTE_IN_SECONDS) return ['status' => 'opptatt'];
        delete_option($las);
        if (!add_option($las, (string) time(), '', false)) return ['status' => 'opptatt'];
    }
    $start = microtime(true);
    try {
        $liste = amendo_b2b_tellix_liste();
        if (is_wp_error($liste)) {
            amendo_tellix_logg('B2B-jobb: fikk ikke hentet kundelista fra Tellix');
            return amendo_b2b_jobb_sist(['status' => 'feil']);
        }
        $kjente = get_option('amendo_b2b_tellix_kjente', null);
        if (!is_array($kjente)) {
            $kjente = [];
            foreach ($liste as $k) if ($k['id'] !== '') $kjente[$k['id']] = 1;
            update_option('amendo_b2b_tellix_kjente', $kjente, false);
            amendo_tellix_logg(sprintf('B2B-jobb: grunnlinje med %d Tellix-kunder', count($kjente)));
            return amendo_b2b_jobb_sist(['status' => 'grunnlinje', 'antall' => count($kjente)]);
        }
        $tall = ['invitert' => 0, 'opprettet' => 0, 'koblet' => 0, 'manuell' => 0, 'feil' => 0, 'igjen' => 0];
        $behandlet = 0;
        $send = amendo_b2b_bryter('epost');
        foreach ($liste as $k) {
            if ($k['id'] === '' || isset($kjente[$k['id']])) continue;
            if ($behandlet >= AMENDO_B2B_JOBB_MAKS) { $tall['igjen']++; continue; }
            $behandlet++;
            $v = amendo_b2b_tellix_vurder($k, $liste);
            $r = in_array($v['handling'], ['ikke_bedrift', 'ferdig'], true) ? 'ferdig' : amendo_b2b_tellix_utfor($k, $v, $send);
            if (isset($tall[$r])) $tall[$r]++;
            // Feil prøves igjen neste time; alt annet er behandlet.
            if ($r !== 'feil') $kjente[$k['id']] = 1;
        }
        update_option('amendo_b2b_tellix_kjente', $kjente, false);
        amendo_tellix_logg(sprintf('B2B-jobb: %d invitert, %d opprettet uten e-post, %d koblet, %d til manuell behandling, %d feil, %d til neste kjøring (%.1f s)',
            $tall['invitert'], $tall['opprettet'], $tall['koblet'], $tall['manuell'], $tall['feil'], $tall['igjen'], microtime(true) - $start));
        return amendo_b2b_jobb_sist(['status' => 'kjort'] + $tall);
    } finally {
        delete_option($las);
    }
}

/** Lagrer siste kjøring til kortet. */
function amendo_b2b_jobb_sist(array $r) {
    update_option('amendo_b2b_tellix_jobb_sist', $r + ['tid' => time()], false);
    return $r;
}

// ── 3. «Send invitasjon» ────────────────────────────────────────────────────

function amendo_b2b_inviter_plan() {
    $p = get_option('amendo_b2b_invitasjon', null);
    return is_array($p) && isset($p['nokkel'], $p['kunder']) ? $p : null;
}

/** Tørrkjøringen: alle Tellix-kunder med org.nr der noe gjenstår. Endrer ingenting. */
function amendo_b2b_inviter_torrkjor() {
    $liste = amendo_b2b_tellix_liste();
    if (is_wp_error($liste)) return 'tellix_feil';
    $kunder = [];
    foreach ($liste as $k) {
        $v = amendo_b2b_tellix_vurder($k, $liste);
        if (in_array($v['handling'], ['ikke_bedrift', 'ferdig'], true)) continue;
        $kunder[$k['id']] = $v + ['navn' => $k['navn'], 'orgnr' => $k['orgnr'], 'epost' => $k['epost']];
    }
    update_option('amendo_b2b_invitasjon', ['nokkel' => wp_generate_password(20, false), 'tid' => time(), 'av' => get_current_user_id(), 'kunder' => $kunder, 'resultat' => []], false);
    $a = array_count_values(array_column($kunder, 'handling'));
    amendo_tellix_logg(sprintf('B2B-invitasjon, tørrkjøring: %d inviteres, %d kobles, %d sendes på nytt, %d manuelt',
        $a['inviter'] ?? 0, $a['koble'] ?? 0, $a['send'] ?? 0, $a['manuell'] ?? 0));
    return 'torrkjort';
}

function amendo_b2b_inviter_gjenstar(array $plan) {
    return array_values(array_diff(array_map('strval', array_keys($plan['kunder'])), array_map('strval', array_keys($plan['resultat']))));
}

/** De neste 25. Kode til beskjeden. */
function amendo_b2b_inviter_kjor($nokkel) {
    if (!amendo_b2b_bryter('epost')) return 'epost_av';
    if (amendo_frontend_url() === '') return 'mangler_frontend';
    $plan = amendo_b2b_inviter_plan();
    if (!$plan) return 'ingen_plan';
    if (!hash_equals($plan['nokkel'], (string) $nokkel)) return 'annen_plan';
    if (time() - (int) $plan['tid'] > AMENDO_B2B_INVITER_GYLDIG) return 'gammel_plan';
    $ider = array_slice(amendo_b2b_inviter_gjenstar($plan), 0, AMENDO_B2B_INVITER_PER_RUNDE);
    if (!$ider) return 'ferdig';

    $las = 'amendo_b2b_invitasjon_las';
    if (!add_option($las, (string) time(), '', false)) {
        if (time() - (int) get_option($las) <= 600) return 'opptatt';
        delete_option($las);
        if (!add_option($las, (string) time(), '', false)) return 'opptatt';
    }
    $start = microtime(true);
    try {
        $liste = amendo_b2b_tellix_liste();
        if (is_wp_error($liste)) return 'tellix_feil';
        $per_id = [];
        foreach ($liste as $k) $per_id[$k['id']] = $k;
        $res = [];
        foreach ($ider as $tid) {
            $gammel = $plan['kunder'][$tid];
            $k = $per_id[$tid] ?? null;
            $v = $k ? amendo_b2b_tellix_vurder($k, $liste) : null;
            if (!$v || $v['handling'] !== $gammel['handling'] || $v['grunn'] !== $gammel['grunn'] || $v['bruker'] !== $gammel['bruker']) {
                $res[$tid] = 'endret';
                continue;
            }
            $res[$tid] = amendo_b2b_tellix_utfor($k, $v, true);
        }
        $ny = amendo_b2b_inviter_plan();
        if ($ny && $ny['nokkel'] === $plan['nokkel']) {
            $ny['resultat'] = $res + $ny['resultat'];
            update_option('amendo_b2b_invitasjon', $ny, false);
        }
        $a = array_count_values($res);
        amendo_tellix_logg(sprintf('B2B-invitasjon: %d invitert, %d koblet, %d manuelt, %d endret, %d feil (%.1f s)',
            $a['invitert'] ?? 0, $a['koblet'] ?? 0, $a['manuell'] ?? 0, $a['endret'] ?? 0, ($a['feil'] ?? 0) + ($a['opprettet'] ?? 0), microtime(true) - $start));
    } finally {
        delete_option($las);
    }
    return 'sendt';
}

// ── Velkomst-e-posten ───────────────────────────────────────────────────────

function amendo_b2b_velkomst_tekster() {
    $l = get_option('amendo_b2b_velkomst', []);
    $l = is_array($l) ? $l : [];
    $ut = [];
    foreach (AMENDO_B2B_VELKOMST_STANDARD as $f => $std) $ut[$f] = trim((string) ($l[$f] ?? '')) !== '' ? (string) $l[$f] : $std;
    return $ut;
}

/** <Frontend-URL>/sett-passord?key=…&login=…, eller '' uten frontend-URL. Nøkkelen er WordPress sin (bare a–z, A–Z, 0–9). */
function amendo_b2b_sett_passord_lenke($login, $nokkel) {
    $base = amendo_frontend_url();
    if ($base === '') return '';
    return untrailingslashit($base) . '/sett-passord?key=' . $nokkel . '&login=' . rawurlencode($login);
}

/** Emne og HTML for brukeren og lenken. */
function amendo_b2b_velkomst_innhold(WP_User $u, $lenke) {
    $t = amendo_b2b_velkomst_tekster();
    $butikk = (string) get_option('amendo_butikk_navn', '') ?: get_bloginfo('name');
    $firma = (string) get_user_meta($u->ID, 'billing_company', true) ?: $u->display_name;
    $erstatt = function($s) use ($butikk, $firma) { return strtr($s, ['{butikk}' => $butikk, '{firma}' => $firma]); };
    $logo = (string) get_option('amendo_butikk_logo_mork', '') ?: (string) get_option('amendo_butikk_logo', '');

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#222;max-width:560px;margin:0 auto;padding:24px">';
    if ($logo !== '') $html .= '<p style="margin:0 0 24px"><img src="' . esc_url($logo) . '" alt="' . esc_attr($butikk) . '" style="max-width:200px;max-height:80px"></p>';
    foreach (preg_split('/\n{2,}/', trim($erstatt($t['tekst']))) as $avsnitt) {
        $html .= '<p style="margin:0 0 16px">' . nl2br(esc_html($avsnitt)) . '</p>';
    }
    $html .= '<p style="margin:24px 0"><a href="' . esc_url($lenke) . '" style="display:inline-block;background:#222;color:#fff;text-decoration:none;padding:12px 24px;border-radius:6px">' . esc_html($erstatt($t['knapp'])) . '</a></p>';
    $html .= '<p style="margin:0;font-size:13px;color:#666">Lenken virker i 7 dager. Fungerer ikke knappen, kopier denne adressen inn i nettleseren:<br>' . esc_html($lenke) . '</p>';
    $html .= '</div>';
    return ['emne' => $erstatt($t['emne']), 'html' => $html];
}

/** Send velkomst-e-posten. True når wp_mail tok imot den. */
function amendo_b2b_velkomst_send($id) {
    $u = get_userdata((int) $id);
    if (!$u || !is_email($u->user_email)) return false;
    $nokkel = get_password_reset_key($u);
    if (is_wp_error($nokkel)) return false;
    $lenke = amendo_b2b_sett_passord_lenke($u->user_login, $nokkel);
    if ($lenke === '') return false;
    $e = amendo_b2b_velkomst_innhold($u, $lenke);
    if (!wp_mail($u->user_email, $e['emne'], $e['html'], ['Content-Type: text/html; charset=UTF-8'])) return false;
    update_user_meta($u->ID, '_amendo_invitasjon', 'sendt');
    update_user_meta($u->ID, '_amendo_invitasjon_tid', gmdate('c'));
    return true;
}

// ── Tilbakestilling via frontenden ──────────────────────────────────────────

// Velkomstlenken og «Glemt passord» gjelder i 7 dager (WordPress: ett døgn).
add_filter('password_reset_expiration', function() { return AMENDO_B2B_NOKKEL_GYLDIG; });

// «Glemt passord»: lenken i WordPress sin e-post går til frontendens
// /sett-passord i stedet for wp-login.php. Uten Frontend-URL: som før. Hele
// lenken byttes: wp-login.php?login=…&key=…&action=rp&wp_lang=… (WordPress 6),
// også med <> rundt som i eldre versjoner. Bare når ingen slik lenke finnes
// (en annen plugin har skrevet om teksten), legges vår til nederst.
add_filter('retrieve_password_message', function($melding, $nokkel, $login) {
    $lenke = amendo_b2b_sett_passord_lenke((string) $login, (string) $nokkel);
    if ($lenke === '') return $melding;
    $antall = 0;
    $ny = preg_replace_callback('#<?[^\s<>]*wp-login\.php\?[^\s<>]*action=rp[^\s<>]*>?#', function() use ($lenke) { return $lenke; }, (string) $melding, -1, $antall);
    return $antall ? $ny : rtrim((string) $melding) . "\r\n\r\n" . $lenke . "\r\n";
}, 10, 3);

// Admin får ikke «Passord endret» fra WordPress når en konto vi har opprettet
// fra Tellix setter passordet fra velkomst-e-posten (én e-post per bedrift).
// Andre kunder: som før. Fjernes bare for denne ene tilbakestillingen.
add_action('after_password_reset', function($u) {
    if ($u instanceof WP_User && get_user_meta($u->ID, '_amendo_fra_tellix', true) !== '') {
        remove_action('after_password_reset', 'wp_password_change_notification');
        add_action('after_password_reset', 'amendo_b2b_varsel_tilbake', 11);
    }
}, 0);
function amendo_b2b_varsel_tilbake() {
    remove_action('after_password_reset', 'amendo_b2b_varsel_tilbake', 11);
    add_action('after_password_reset', 'wp_password_change_notification');
}

// ── Admin ───────────────────────────────────────────────────────────────────

add_action('admin_post_amendo_b2b_auto', function() {
    if (!current_user_can(amendo_settings_kapabilitet())) wp_die('Ingen tilgang', 403);
    check_admin_referer('amendo_b2b_auto');
    $p = wp_unslash($_POST);
    $handling = isset($p['b2b_fjern']) ? 'fjern' : sanitize_key((string) ($p['b2b_auto'] ?? ''));
    $svar = 'ukjent';
    if ($handling === 'lagre') {
        $jobb_for = amendo_b2b_bryter('jobb');
        foreach (['auto', 'jobb', 'epost'] as $b) update_option('amendo_b2b_' . $b, !empty($p['b2b_bryter_' . $b]) ? '1' : '', false);
        if (amendo_b2b_bryter('jobb') && !$jobb_for) {
            // Ny grunnlinje: bare kunder som opprettes i Tellix fra nå av.
            delete_option('amendo_b2b_tellix_kjente');
            wp_clear_scheduled_hook('amendo_b2b_tellix_jobb');
            wp_schedule_event(time() + 60, 'hourly', 'amendo_b2b_tellix_jobb');
        }
        if (!amendo_b2b_bryter('jobb')) wp_clear_scheduled_hook('amendo_b2b_tellix_jobb');
        update_option('amendo_b2b_velkomst', [
            'emne'  => sanitize_text_field((string) ($p['b2b_velkomst_emne'] ?? '')),
            'tekst' => sanitize_textarea_field((string) ($p['b2b_velkomst_tekst'] ?? '')),
            'knapp' => sanitize_text_field((string) ($p['b2b_velkomst_knapp'] ?? '')),
        ], false);
        $svar = 'lagret';
    } elseif ($handling === 'torrkjor') {
        $svar = amendo_b2b_inviter_torrkjor();
    } elseif ($handling === 'send') {
        $svar = amendo_b2b_inviter_kjor(is_scalar($p['plan'] ?? null) ? (string) $p['plan'] : '');
    } elseif ($handling === 'forkast') {
        delete_option('amendo_b2b_invitasjon');
        $svar = 'forkastet';
    } elseif ($handling === 'fjern') {
        $m = get_option('amendo_b2b_tellix_manuell', []);
        if (is_array($m)) {
            unset($m[is_scalar($p['b2b_fjern']) ? (string) $p['b2b_fjern'] : '']);
            update_option('amendo_b2b_tellix_manuell', $m, false);
        }
        $svar = 'fjernet';
    }
    wp_redirect(admin_url('admin.php?page=amendo-settings&b2b_auto=' . rawurlencode($svar) . '#b2b'));
    exit;
});

add_action('amendo_admin_etter_skjema', function() {
    ?>
    <form id="amendo-b2b-auto" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('amendo_b2b_auto'); ?>
        <input type="hidden" name="action" value="amendo_b2b_auto">
    </form>
    <?php
});

const AMENDO_B2B_AUTO_MELDINGER = [
    'lagret'           => ['ok', 'Innstillingene for bedriftskunder fra Tellix er lagret.'],
    'torrkjort'        => ['ok', 'Tørrkjøringen er ferdig. Se over listen før du sender. Ingenting er endret.'],
    'sendt'            => ['ok', 'Neste runde er behandlet. Se resultatet i listen.'],
    'ferdig'           => ['ok', 'Ingen flere å invitere i denne tørrkjøringen.'],
    'forkastet'        => ['ok', 'Tørrkjøringen er forkastet.'],
    'fjernet'          => ['ok', 'Fjernet fra listen.'],
    'epost_av'         => ['feil', 'Velkomst-e-posten er slått av. Slå den på før du sender invitasjoner.'],
    'mangler_frontend' => ['feil', 'Frontend-URL mangler (Butikk-fanen). Lenken i e-posten går dit.'],
    'tellix_feil'      => ['feil', 'Fikk ikke hentet kundelista fra Tellix. Prøv igjen om litt.'],
    'ingen_plan'       => ['feil', 'Tørrkjør først.'],
    'annen_plan'       => ['feil', 'Noen har tørrkjørt på nytt siden siden ble lastet. Se over den nye listen.'],
    'gammel_plan'      => ['feil', 'Tørrkjøringen er over et døgn gammel. Tørrkjør på nytt.'],
    'opptatt'          => ['feil', 'En runde kjører allerede. Vent litt og last siden på nytt.'],
    'ukjent'           => ['feil', 'Ukjent handling.'],
];

const AMENDO_B2B_MANUELL_GRUNNER = [
    'uten_epost'      => 'Ingen e-post i Tellix',
    'flere_med_epost' => 'Samme e-post på flere Tellix-kunder',
    'butikkbruker'    => 'E-posten tilhører en butikkbruker',
    'avvist'          => 'Søknaden er avvist her',
    'koblet_annen'    => 'Kontoen er koblet til en annen Tellix-kunde',
    'annet_orgnr'     => 'Kontoen har et annet org.nr',
];

const AMENDO_B2B_HANDLINGER = [
    'inviter' => 'Får konto og velkomst-e-post',
    'koble'   => 'Har konto: kobles og godkjennes (ingen e-post)',
    'send'    => 'Har konto herfra: velkomst-e-posten sendes',
    'manuell' => 'Manuelt',
];

const AMENDO_B2B_RESULTATER = [
    'invitert' => 'Invitert', 'opprettet' => 'Konto laget, e-posten ble ikke sendt', 'koblet' => 'Koblet og godkjent',
    'manuell' => 'Lagt til manuell behandling', 'endret' => 'Endret siden tørrkjøringen. Tørrkjør på nytt.', 'feil' => 'Feilet', 'ferdig' => 'Ingenting å gjøre',
];

/** Org.nr i en søknad som finnes i Tellix, til hintet i Søknader. Bare fra kopien, aldri en henting. */
function amendo_b2b_tellix_hint($orgnr) {
    if (strlen($orgnr) !== 9) return null;
    $d = amendo_tellix_liste_les();
    foreach ($d ? $d['kunder'] : [] as $k) if (($k['orgnr'] ?? '') === $orgnr) return $k;
    return null;
}

/** Kortet i B2B → Bedrifter. Bare når kundelista fra Tellix kan leses. */
function amendo_b2b_tellix_bedrifter_kort() {
    if (!amendo_tellix_api_tilgjengelig()) return;
    $melding = AMENDO_B2B_AUTO_MELDINGER[(string) ($_GET['b2b_auto'] ?? '')] ?? null;
    $t = amendo_b2b_velkomst_tekster();
    $lagret = get_option('amendo_b2b_velkomst', []);
    $lagret = is_array($lagret) ? $lagret : [];
    $sist = get_option('amendo_b2b_tellix_jobb_sist', null);
    $plan = amendo_b2b_inviter_plan();
    $manuell = get_option('amendo_b2b_tellix_manuell', []);
    $manuell = is_array($manuell) ? $manuell : [];
    $frontend = amendo_frontend_url();
    ?>
    <div class="amendo-card" id="b2b-tellix-bedrifter">
        <h2>Bedriftskunder fra Tellix</h2>
        <p class="amendo-desc">Tellix bestemmer hvem som er bedrift: en Tellix-kunde med org.nr. Er e-posten til en kunde som logger inn eller registrerer seg en bedrift i Tellix, godkjennes og kobles kunden uten søknad. Org.nr alene godkjenner aldri — org.numre er offentlige — så da går søknaden til Søknader som før.</p>
        <?php if ($melding): ?>
            <div class="amendo-b2b-melding amendo-b2b-melding-<?php echo esc_attr($melding[0]); ?>" id="b2b-auto-melding"><?php echo esc_html($melding[1]); ?></div>
        <?php endif; ?>

        <p><label><input type="checkbox" form="amendo-b2b-auto" name="b2b_bryter_auto" value="1"<?php checked(amendo_b2b_bryter('auto')); ?>> Godkjenn automatisk når e-posten er en bedrift i Tellix (ved innlogging og registrering)</label></p>
        <p><label><input type="checkbox" form="amendo-b2b-auto" name="b2b_bryter_jobb" value="1"<?php checked(amendo_b2b_bryter('jobb')); ?>> Lag nettbutikk-konto for nye bedrifter i Tellix (hver time)</label><br>
            <span class="field-help">Gjelder bare kunder som opprettes i Tellix etter at dette slås på. Finnes e-posten fra før, kobles kontoen bare.
            <?php if (is_array($sist)): ?>Siste kjøring <?php echo esc_html(wp_date('d.m.Y H:i', (int) $sist['tid'])); ?>: <?php
                echo esc_html($sist['status'] === 'grunnlinje' ? 'grunnlinje lagret (' . (int) ($sist['antall'] ?? 0) . ' kunder)'
                    : ($sist['status'] === 'feil' ? 'fikk ikke hentet kundelista'
                    : sprintf('%d invitert, %d uten e-post, %d koblet, %d manuelt, %d feil', $sist['invitert'] ?? 0, $sist['opprettet'] ?? 0, $sist['koblet'] ?? 0, $sist['manuell'] ?? 0, $sist['feil'] ?? 0))); ?>.<?php endif; ?></span></p>
        <p><label><input type="checkbox" form="amendo-b2b-auto" name="b2b_bryter_epost" value="1"<?php checked(amendo_b2b_bryter('epost')); ?>> Send velkomst-e-post</label><br>
            <span class="field-help">Lenken går til <?php echo esc_html($frontend !== '' ? untrailingslashit($frontend) . '/sett-passord' : '/sett-passord på frontenden'); ?>, der kunden velger passord.
            <?php if ($frontend === ''): ?><strong>⚠ Frontend-URL mangler (Butikk-fanen): e-posten kan ikke sendes.</strong><?php endif; ?>
            Logoen og {butikk} hentes fra Butikk-fanen.</span></p>
        <div class="amendo-field"><label for="b2b_velkomst_emne">Emne</label>
            <input type="text" id="b2b_velkomst_emne" form="amendo-b2b-auto" name="b2b_velkomst_emne" value="<?php echo esc_attr((string) ($lagret['emne'] ?? '')); ?>" placeholder="<?php echo esc_attr(AMENDO_B2B_VELKOMST_STANDARD['emne']); ?>"></div>
        <div class="amendo-field"><label for="b2b_velkomst_tekst">Tekst</label>
            <textarea id="b2b_velkomst_tekst" form="amendo-b2b-auto" name="b2b_velkomst_tekst" rows="6" placeholder="<?php echo esc_attr(AMENDO_B2B_VELKOMST_STANDARD['tekst']); ?>"><?php echo esc_textarea((string) ($lagret['tekst'] ?? '')); ?></textarea>
            <p class="field-help">{butikk} = butikknavnet, {firma} = firmanavnet. Tomt felt = teksten i feltet. Knappen med lenken kommer etter teksten.</p></div>
        <div class="amendo-field"><label for="b2b_velkomst_knapp">Knapp</label>
            <input type="text" id="b2b_velkomst_knapp" form="amendo-b2b-auto" name="b2b_velkomst_knapp" value="<?php echo esc_attr((string) ($lagret['knapp'] ?? '')); ?>" placeholder="<?php echo esc_attr(AMENDO_B2B_VELKOMST_STANDARD['knapp']); ?>"></div>
        <p class="b2b-knapper"><button type="submit" form="amendo-b2b-auto" name="b2b_auto" value="lagre" class="amendo-btn-secondary">Lagre</button></p>

        <h3>Send invitasjon til eksisterende bedrifter</h3>
        <p class="field-help">Tellix-kunder med org.nr som ikke er koblet her ennå. Tørrkjør først: ingenting endres. Så <?php echo (int) AMENDO_B2B_INVITER_PER_RUNDE; ?> om gangen.</p>
        <p class="b2b-knapper"><button type="submit" form="amendo-b2b-auto" name="b2b_auto" value="torrkjor" class="amendo-btn-secondary"><?php echo $plan ? 'Tørrkjør på nytt' : 'Tørrkjør'; ?></button></p>
        <?php if ($plan):
            $igjen = count(amendo_b2b_inviter_gjenstar($plan));
            $a = array_count_values(array_column($plan['kunder'], 'handling'));
            $gammel = time() - (int) $plan['tid'] > AMENDO_B2B_INVITER_GYLDIG;
        ?>
        <p class="b2b-inviter-sammendrag">Tørrkjørt <?php echo esc_html(wp_date('d.m.Y H:i', (int) $plan['tid'])); ?>: <?php echo (int) count($plan['kunder']); ?> bedrifter.
            <?php echo (int) ($a['inviter'] ?? 0); ?> får konto og e-post, <?php echo (int) ($a['koble'] ?? 0); ?> kobles, <?php echo (int) ($a['send'] ?? 0); ?> får e-posten, <?php echo (int) ($a['manuell'] ?? 0); ?> må behandles manuelt.
            <?php echo (int) count($plan['resultat']); ?> utført, <?php echo (int) $igjen; ?> igjen.</p>
        <?php if ($gammel): ?>
            <p class="field-help">⚠ Tørrkjøringen er over et døgn gammel. Tørrkjør på nytt før du sender.</p>
        <?php elseif ($igjen): ?>
        <p class="b2b-knapper">
            <input type="hidden" form="amendo-b2b-auto" name="plan" value="<?php echo esc_attr($plan['nokkel']); ?>">
            <button type="submit" form="amendo-b2b-auto" name="b2b_auto" value="send" class="amendo-btn-secondary b2b-bekreft"<?php disabled(!amendo_b2b_bryter('epost')); ?> data-bekreft="<?php echo esc_attr('Behandle de neste ' . min($igjen, AMENDO_B2B_INVITER_PER_RUNDE) . '? Nye kontoer får velkomst-e-posten.'); ?>">Send neste <?php echo (int) min($igjen, AMENDO_B2B_INVITER_PER_RUNDE); ?></button>
            <button type="submit" form="amendo-b2b-auto" name="b2b_auto" value="forkast" class="amendo-btn-secondary">Forkast tørrkjøringen</button>
            <?php if (!amendo_b2b_bryter('epost')): ?><span class="field-help">Slå på velkomst-e-posten først.</span><?php endif; ?>
        </p>
        <?php endif; ?>
        <table class="amendo-tabell b2b-inviter-tabell">
            <thead><tr><th>Firma</th><th>Org.nr</th><th>E-post</th><th>Hva skjer</th><th>Resultat</th></tr></thead>
            <tbody>
            <?php foreach ($plan['kunder'] as $tid => $v): $r = $plan['resultat'][$tid] ?? null; ?>
            <tr class="b2b-inviter-rad b2b-inviter-<?php echo esc_attr($v['handling']); ?>" data-tellix="<?php echo esc_attr($tid); ?>">
                <td><?php echo esc_html($v['navn'] ?: '—'); ?><br><span class="field-help">Tellix-kunde <?php echo esc_html($tid); ?></span></td>
                <td><?php echo esc_html($v['orgnr']); ?></td>
                <td><?php echo esc_html($v['epost'] ?: '—'); ?></td>
                <td><?php echo esc_html((AMENDO_B2B_HANDLINGER[$v['handling']] ?? $v['handling']) . ($v['handling'] === 'manuell' ? ': ' . (AMENDO_B2B_MANUELL_GRUNNER[$v['grunn']] ?? $v['grunn']) : '')); ?></td>
                <td class="b2b-inviter-resultat"><?php echo esc_html($r ? (AMENDO_B2B_RESULTATER[$r] ?? $r) : '—'); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h3>Til manuell behandling<?php echo $manuell ? ' (' . count($manuell) . ')' : ''; ?></h3>
        <?php if (!$manuell): ?>
            <p class="field-help">Ingen.</p>
        <?php else: ?>
        <p class="field-help">Bedrifter i Tellix som ikke kunne kobles automatisk. Rett opp i Tellix eller på kontoen, og fjern dem fra listen når de er behandlet.</p>
        <table class="amendo-tabell b2b-manuell-tabell">
            <thead><tr><th>Firma</th><th>Org.nr</th><th>E-post</th><th>Hvorfor</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($manuell as $tid => $m): ?>
            <tr class="b2b-manuell-rad" data-tellix="<?php echo esc_attr($tid); ?>">
                <td><?php echo esc_html($m['navn'] ?: '—'); ?><br><span class="field-help">Tellix-kunde <?php echo esc_html($tid); ?></span></td>
                <td><?php echo esc_html($m['orgnr']); ?></td>
                <td><?php echo esc_html($m['epost'] ?: '—'); ?></td>
                <td><?php echo esc_html(AMENDO_B2B_MANUELL_GRUNNER[$m['grunn']] ?? $m['grunn']); ?></td>
                <td><button type="submit" form="amendo-b2b-auto" name="b2b_fjern" value="<?php echo esc_attr($tid); ?>" class="amendo-btn-secondary b2b-manuell-fjern">Fjern</button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
