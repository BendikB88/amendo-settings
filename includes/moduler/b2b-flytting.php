<?php
/**
 * Flytting fra B2B Wholesale Suite (1.4.28). Del av B2B-modulen: lastes bare
 * når B2B er på — se includes/moduler.php.
 *
 * Aanerud har godkjent bedriftskunder i B2B Wholesale Suite (b2bwhs) og
 * koblet dem til Tellix med garcon-tellix-b2b-sync. Begge skal av, og da må
 * kundene ha amendo-settings sin egen godkjenning og Tellix-kobling:
 *
 *     b2bwhs                              →  amendo-settings
 *     b2bwhs_b2buser = yes                    _b2b_status = godkjent
 *     billing_org_number / b2bwhs_custom_field_*  billing_org_number
 *     _garcon_tellix_customer_id (live)       _tellixCustomerId/-UuId/GroupId
 *
 * Kortet «Flytt fra B2B Wholesale Suite» i B2B → Bedrifter:
 *   1. TØRRKJØR: vurderer hver kunde og lagrer planen. Ingenting annet skrives.
 *   2. FLYTT NESTE 25: utfører planen for de neste 25. Krever tørrkjøringen
 *      admin så på (planens nøkkel), og at den er under et døgn gammel. Er
 *      kunden endret siden tørrkjøringen, hoppes den over («endret»).
 *
 * Reglene:
 *   - Kandidat: b2bwhs_b2buser = yes, eller en b2bwhs-kundegruppe (ikke 'no',
 *     som b2bwhs bruker for privatkunder).
 *   - ⚠⚠ Org.nr gir innsyn i ordrene til alle med samme nummer (b2b.php). Ingen
 *     godkjennes uten ett entydig, nisifret org.nr, og ingen som venter eller
 *     er avvist i b2bwhs, i sync-pluginen eller i Søknader. De står som
 *     «manuelt» med grunnen.
 *   - Tellix: koblet fra før røres ikke. Kunde-ID fra sync-pluginen brukes
 *     bare når den ble laget i live-miljøet OG finnes i Tellix-lista nå.
 *     Ellers samme oppslag/opprettelse som massehandlingen (b2b-tellix-kunder.php),
 *     uten kundegruppe (1.4.27). Valg og feil havner i kortet «Tellix-kobling».
 *   - b2bwhs-feltene røres ikke: kakeabonnementet leser dem til det er
 *     oppdatert, og flyttingen kan gjøres om.
 *
 * ⚠ Ingen kundedata i loggen — bare antall.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_B2B_FLYTT_PER_RUNDE = 25;
const AMENDO_B2B_FLYTT_MAKS      = 1000;
const AMENDO_B2B_FLYTT_GYLDIG    = DAY_IN_SECONDS;

/** Kandidatene: B2B-kunder i b2bwhs. ID-er, eldst først. */
function amendo_b2b_flytt_kandidater() {
    $q = new WP_User_Query([
        'meta_query' => [
            'relation' => 'OR',
            ['key' => 'b2bwhs_b2buser', 'value' => 'yes'],
            ['key' => 'b2bwhs_customergroup', 'value' => ['', 'no'], 'compare' => 'NOT IN'],
        ],
        'fields'  => 'ID',
        'orderby' => 'ID',
        'order'   => 'ASC',
        'number'  => AMENDO_B2B_FLYTT_MAKS,
    ]);
    return array_map('intval', $q->get_results());
}

/** Org.nr og hvor det kom fra, eller en grunn til at det mangler. */
function amendo_b2b_flytt_orgnr($id) {
    $b = amendo_b2b_orgnr(get_user_meta($id, 'billing_org_number', true));
    if (strlen($b) === 9) return ['orgnr' => $b, 'kilde' => 'billing'];
    $funn = [];
    foreach (get_user_meta($id) as $k => $v) {
        if (strpos($k, 'b2bwhs_custom_field_') !== 0) continue;
        $s = amendo_b2b_orgnr($v[0] ?? '');
        if (strlen($s) === 9) $funn[$s] = true;
    }
    if (count($funn) === 1) return ['orgnr' => (string) array_key_first($funn), 'kilde' => 'b2bwhs'];
    return ['orgnr' => '', 'kilde' => '', 'grunn' => $funn ? 'flere_orgnr' : 'mangler_orgnr'];
}

/**
 * Hva flyttingen vil gjøre med én kunde. Bare lesing.
 * godkjenning: 'ny' | 'allerede' | 'manuelt' (med grunn)
 * tellix:      'allerede' | 'sync' (kunde-ID fra sync-pluginen) | 'oppslag' | 'ingen'
 */
function amendo_b2b_flytt_vurder($id) {
    $v = ['godkjenning' => 'ny', 'grunn' => '', 'orgnr' => '', 'kilde' => '', 'tellix' => 'ingen', 'sync_id' => ''];
    $status = (string) get_user_meta($id, '_b2b_status', true);
    if ($status === 'godkjent') {
        $v['godkjenning'] = 'allerede';
        $v['orgnr'] = amendo_b2b_orgnr(get_user_meta($id, 'billing_org_number', true));
    } else {
        $grunn = '';
        if ($status === 'avvist') $grunn = 'avvist_i_amendo';
        elseif ($status === 'venter') $grunn = 'venter_i_soknader';
        elseif (get_user_meta($id, 'b2bwhs_account_approved', true) === 'no') $grunn = 'venter_i_b2bwhs';
        elseif (get_user_meta($id, '_garcon_b2b_declined', true)) $grunn = 'avslatt_i_sync';
        if ($grunn === '') {
            $o = amendo_b2b_flytt_orgnr($id);
            $grunn = $o['grunn'] ?? '';
            $v['orgnr'] = $o['orgnr'];
            $v['kilde'] = $o['kilde'];
        }
        if ($grunn !== '') {
            $v['godkjenning'] = 'manuelt';
            $v['grunn'] = $grunn;
            return $v;
        }
    }
    if (!amendo_b2b_tellix_i_bruk()) return $v;
    if (amendo_b2b_tellix_er_koblet($id)) {
        $v['tellix'] = 'allerede';
        return $v;
    }
    $sync = trim((string) get_user_meta($id, '_garcon_tellix_customer_id', true));
    if ($sync !== '' && $sync !== '0' && get_user_meta($id, '_garcon_tellix_environment', true) === 'live') {
        $v['tellix'] = 'sync';
        $v['sync_id'] = $sync;
    } else {
        $v['tellix'] = 'oppslag';
    }
    return $v;
}

function amendo_b2b_flytt_plan() {
    $p = get_option('amendo_b2b_flytting', null);
    return is_array($p) && isset($p['nokkel'], $p['brukere']) ? $p : null;
}

/** Tørrkjøringen: vurderer alle kandidatene og lagrer planen. */
function amendo_b2b_flytt_torrkjor() {
    $brukere = [];
    foreach (amendo_b2b_flytt_kandidater() as $id) $brukere[$id] = amendo_b2b_flytt_vurder($id);
    $plan = ['nokkel' => wp_generate_password(20, false), 'tid' => time(), 'av' => get_current_user_id(), 'brukere' => $brukere, 'resultat' => []];
    update_option('amendo_b2b_flytting', $plan, false);
    $a = amendo_b2b_flytt_tall($plan);
    amendo_tellix_logg(sprintf('B2B-flytting, tørrkjøring: %d kunder, %d godkjennes, %d manuelt, %d til Tellix', count($brukere), $a['ny'], $a['manuelt'], $a['sync'] + $a['oppslag']));
    return $plan;
}

/** Antall per utfall i planen. */
function amendo_b2b_flytt_tall(array $plan) {
    $t = ['ny' => 0, 'allerede' => 0, 'manuelt' => 0, 'sync' => 0, 'oppslag' => 0, 'tellix_allerede' => 0, 'utfort' => count($plan['resultat'])];
    foreach ($plan['brukere'] as $v) {
        $t[$v['godkjenning']]++;
        if ($v['tellix'] === 'allerede') $t['tellix_allerede']++;
        elseif (isset($t[$v['tellix']])) $t[$v['tellix']]++;
    }
    return $t;
}

/** De som gjenstår: ikke utført, og noe å gjøre. */
function amendo_b2b_flytt_gjenstar(array $plan) {
    $ider = [];
    foreach ($plan['brukere'] as $id => $v) {
        if (isset($plan['resultat'][$id])) continue;
        if ($v['godkjenning'] === 'manuelt') continue;
        if ($v['godkjenning'] === 'allerede' && in_array($v['tellix'], ['allerede', 'ingen'], true)) continue;
        $ider[] = (int) $id;
    }
    return $ider;
}

/**
 * Utfør planen for de neste 25. Returnerer en kode til beskjeden:
 * 'flyttet', 'ingen_plan', 'gammel_plan', 'annen_plan', 'ferdig' eller 'opptatt'.
 */
function amendo_b2b_flytt_kjor($nokkel) {
    $plan = amendo_b2b_flytt_plan();
    if (!$plan) return 'ingen_plan';
    if (!hash_equals($plan['nokkel'], (string) $nokkel)) return 'annen_plan';
    if (time() - (int) $plan['tid'] > AMENDO_B2B_FLYTT_GYLDIG) return 'gammel_plan';
    $ider = array_slice(amendo_b2b_flytt_gjenstar($plan), 0, AMENDO_B2B_FLYTT_PER_RUNDE);
    if (!$ider) return 'ferdig';

    // Én runde om gangen: to klikk skal ikke flytte de samme to ganger.
    $las = 'amendo_b2b_flytting_las';
    if (!add_option($las, (string) time(), '', false)) {
        if (time() - (int) get_option($las) <= 600) return 'opptatt';
        delete_option($las);
        if (!add_option($las, (string) time(), '', false)) return 'opptatt';
    }
    $start = microtime(true);
    try {
        $res = [];
        $tellix_sync = [];
        $tellix_oppslag = [];
        foreach ($ider as $id) {
            $v = amendo_b2b_flytt_vurder($id);
            if (!get_userdata($id) || $v !== $plan['brukere'][$id]) {
                $res[$id] = ['godkjenning' => 'endret', 'tellix' => '', 'melding' => 'Endret siden tørrkjøringen. Tørrkjør på nytt.'];
                continue;
            }
            if ($v['godkjenning'] === 'ny') {
                update_user_meta($id, 'billing_org_number', $v['orgnr']);
                update_user_meta($id, '_b2b_status', 'godkjent');
                update_user_meta($id, '_b2b_behandlet', gmdate('c'));
                update_user_meta($id, '_b2b_behandlet_av', get_current_user_id());
                update_user_meta($id, '_b2b_kilde', 'b2bwhs');
            }
            $res[$id] = ['godkjenning' => $v['godkjenning'] === 'ny' ? 'godkjent' : 'allerede', 'tellix' => $v['tellix'], 'melding' => ''];
            if ($v['tellix'] === 'sync') $tellix_sync[$id] = $v['sync_id'];
            if ($v['tellix'] === 'oppslag') $tellix_oppslag[] = $id;
        }

        // Kunde-ID fra sync-pluginen: bare når den finnes i Tellix nå.
        if ($tellix_sync) {
            $liste = amendo_b2b_tellix_liste();
            foreach ($tellix_sync as $id => $kid) {
                $k = null;
                foreach (is_wp_error($liste) ? [] : $liste as $kk) if ($kk['id'] === (string) $kid) { $k = $kk; break; }
                if ($k) {
                    amendo_b2b_tellix_skriv($id, $k, null);
                    $d = amendo_b2b_tellix_status($id, 'koblet', 'Koblet til Tellix-kunde ' . $k['id'] . ($k['navn'] !== '' ? ' (' . $k['navn'] . ')' : '') . ' fra garcon-tellix-b2b-sync, med ' . amendo_b2b_tellix_gruppenavn($k['gruppe_id'] ?? null) . '.', null);
                    $res[$id]['tellix'] = 'koblet';
                    $res[$id]['melding'] = $d['melding'];
                } elseif (is_wp_error($liste)) {
                    $d = amendo_b2b_tellix_status($id, 'feil', $liste->get_error_message(), null);
                    $res[$id]['tellix'] = 'feil';
                    $res[$id]['melding'] = $d['melding'];
                } else {
                    // Finnes ikke lenger (slettet eller fra et annet miljø): slå opp som andre.
                    $tellix_oppslag[] = $id;
                }
            }
        }
        if ($tellix_oppslag) {
            foreach (amendo_b2b_tellix_kjor(array_fill_keys($tellix_oppslag, null)) as $id => $r) {
                $res[$id]['tellix'] = $r['status'];
                $res[$id]['melding'] = $r['melding'] ?? '';
            }
        }

        // Planen leses på nytt: skriv bare resultatene, ikke en gammel kopi.
        $ny = amendo_b2b_flytt_plan();
        if ($ny && $ny['nokkel'] === $plan['nokkel']) {
            $ny['resultat'] = $res + $ny['resultat'];
            update_option('amendo_b2b_flytting', $ny, false);
        }
        $a = array_count_values(array_column($res, 'tellix'));
        $g = array_count_values(array_column($res, 'godkjenning'));
        amendo_tellix_logg(sprintf('B2B-flytting: %d godkjent, %d endret, Tellix %d koblet, %d opprettet, %d valg, %d feil (%.1f s)',
            $g['godkjent'] ?? 0, $g['endret'] ?? 0, $a['koblet'] ?? 0, $a['opprettet'] ?? 0, $a['valg'] ?? 0, $a['feil'] ?? 0, microtime(true) - $start));
    } finally {
        delete_option($las);
    }
    return 'flyttet';
}

add_action('admin_post_amendo_b2b_flytting', function() {
    if (!current_user_can(amendo_settings_kapabilitet())) wp_die('Ingen tilgang', 403);
    check_admin_referer('amendo_b2b_flytting');
    $handling = sanitize_key((string) ($_POST['flytting'] ?? ''));
    if ($handling === 'torrkjor') {
        amendo_b2b_flytt_torrkjor();
        $svar = 'torrkjort';
    } elseif ($handling === 'flytt') {
        $svar = amendo_b2b_flytt_kjor(is_scalar($_POST['plan'] ?? null) ? (string) wp_unslash($_POST['plan']) : '');
    } elseif ($handling === 'forkast') {
        delete_option('amendo_b2b_flytting');
        $svar = 'forkastet';
    } else {
        $svar = 'ukjent';
    }
    wp_redirect(admin_url('admin.php?page=amendo-settings&flytting=' . rawurlencode($svar) . '#b2b'));
    exit;
});

add_action('amendo_admin_etter_skjema', function() {
    ?>
    <form id="amendo-b2b-flytting" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('amendo_b2b_flytting'); ?>
        <input type="hidden" name="action" value="amendo_b2b_flytting">
    </form>
    <?php
});

const AMENDO_B2B_FLYTT_GRUNNER = [
    'avvist_i_amendo'   => 'Avvist under Søknader',
    'venter_i_soknader' => 'Venter under Søknader: behandle den der',
    'venter_i_b2bwhs'   => 'Ikke godkjent i B2B Wholesale Suite',
    'avslatt_i_sync'    => 'Avslått i garcon-tellix-b2b-sync',
    'mangler_orgnr'     => 'Mangler org.nr (ni siffer)',
    'flere_orgnr'       => 'Flere ulike org.nr i b2bwhs-feltene',
];

const AMENDO_B2B_FLYTT_MELDINGER = [
    'torrkjort'   => ['ok', 'Tørrkjøringen er ferdig. Se over listen før du flytter. Ingenting er endret.'],
    'flyttet'     => ['ok', 'Neste runde er flyttet. Se resultatet i listen.'],
    'ferdig'      => ['ok', 'Ingen flere å flytte i denne tørrkjøringen.'],
    'forkastet'   => ['ok', 'Tørrkjøringen er forkastet.'],
    'ingen_plan'  => ['feil', 'Tørrkjør først.'],
    'annen_plan'  => ['feil', 'Noen har tørrkjørt på nytt siden siden ble lastet. Se over den nye listen.'],
    'gammel_plan' => ['feil', 'Tørrkjøringen er over et døgn gammel. Tørrkjør på nytt.'],
    'opptatt'     => ['feil', 'En flytting kjører allerede. Vent litt og last siden på nytt.'],
    'ukjent'      => ['feil', 'Ukjent handling.'],
];

/** Tellix-delen av planen eller resultatet, som tekst. */
function amendo_b2b_flytt_tellix_tekst($t, $sync_id = '') {
    $m = [
        'allerede' => 'Koblet fra før', 'ingen' => '—', 'sync' => 'Kunde ' . $sync_id . ' fra garcon-tellix-b2b-sync (sjekkes mot Tellix)',
        'oppslag' => 'Slås opp på org.nr/e-post, ellers opprettes', 'koblet' => 'Koblet', 'opprettet' => 'Opprettet i Tellix',
        'valg' => 'Må velges i Tellix-kobling', 'feil' => 'Feilet: se Tellix-kobling',
    ];
    return $m[$t] ?? $t;
}

/** Kortet i B2B → Bedrifter. Vises bare når det finnes noe å flytte, eller en tørrkjøring. */
function amendo_b2b_flytt_kort() {
    $plan = amendo_b2b_flytt_plan();
    if (!$plan && !amendo_b2b_flytt_kandidater()) return;
    $melding = AMENDO_B2B_FLYTT_MELDINGER[(string) ($_GET['flytting'] ?? '')] ?? null;
    ?>
    <div class="amendo-card" id="b2b-flytting">
        <h2>Flytt fra B2B Wholesale Suite</h2>
        <p class="amendo-desc">Gir bedriftskundene fra B2B Wholesale Suite godkjenning og Tellix-kobling her, så B2B Wholesale Suite og garcon-tellix-b2b-sync kan slås av. Tørrkjør først: da vises hva som vil skje med hver kunde, og ingenting endres. Kunder uten ett entydig org.nr, eller som ikke er godkjent, flyttes ikke. Feltene i B2B Wholesale Suite røres ikke.</p>
        <?php if ($melding): ?>
            <div class="amendo-b2b-melding amendo-b2b-melding-<?php echo esc_attr($melding[0]); ?>" id="b2b-flytting-melding"><?php echo esc_html($melding[1]); ?></div>
        <?php endif; ?>
        <?php if (!amendo_b2b_tellix_i_bruk()): ?>
            <p class="field-help">Tellix er ikke satt opp: bare godkjenningen flyttes.</p>
        <?php endif; ?>
        <p class="b2b-knapper">
            <button type="submit" form="amendo-b2b-flytting" name="flytting" value="torrkjor" class="amendo-btn-secondary"><?php echo $plan ? 'Tørrkjør på nytt' : 'Tørrkjør'; ?></button>
        </p>
        <?php if ($plan):
            $t = amendo_b2b_flytt_tall($plan);
            $igjen = count(amendo_b2b_flytt_gjenstar($plan));
            $gammel = time() - (int) $plan['tid'] > AMENDO_B2B_FLYTT_GYLDIG;
        ?>
        <p class="b2b-flytt-sammendrag">Tørrkjørt <?php echo esc_html(wp_date('d.m.Y H:i', (int) $plan['tid'])); ?>: <?php echo (int) count($plan['brukere']); ?> kunder.
            <?php echo (int) $t['ny']; ?> godkjennes, <?php echo (int) $t['allerede']; ?> er godkjent fra før, <?php echo (int) $t['manuelt']; ?> må behandles manuelt.
            Tellix: <?php echo (int) $t['tellix_allerede']; ?> koblet fra før, <?php echo (int) $t['sync']; ?> med kunde-ID fra garcon-tellix-b2b-sync, <?php echo (int) $t['oppslag']; ?> slås opp eller opprettes.
            <?php echo (int) $t['utfort']; ?> utført, <?php echo (int) $igjen; ?> igjen.</p>
        <?php if ($gammel): ?>
            <p class="field-help">⚠ Tørrkjøringen er over et døgn gammel. Tørrkjør på nytt før du flytter.</p>
        <?php elseif ($igjen): ?>
        <p class="b2b-knapper">
            <input type="hidden" form="amendo-b2b-flytting" name="plan" value="<?php echo esc_attr($plan['nokkel']); ?>">
            <button type="submit" form="amendo-b2b-flytting" name="flytting" value="flytt" class="amendo-btn-secondary b2b-bekreft" data-bekreft="<?php echo esc_attr('Flytte de neste ' . min($igjen, AMENDO_B2B_FLYTT_PER_RUNDE) . ' kundene? De godkjennes her og kobles til eller opprettes i Tellix.'); ?>">Flytt neste <?php echo (int) min($igjen, AMENDO_B2B_FLYTT_PER_RUNDE); ?></button>
            <button type="submit" form="amendo-b2b-flytting" name="flytting" value="forkast" class="amendo-btn-secondary">Forkast tørrkjøringen</button>
        </p>
        <?php endif; ?>
        <table class="amendo-tabell b2b-flytt-tabell">
            <thead><tr><th>Firma</th><th>Org.nr</th><th>Godkjenning</th><th>Tellix</th><th>Resultat</th></tr></thead>
            <tbody>
            <?php foreach ($plan['brukere'] as $id => $v):
                $u = get_userdata((int) $id);
                $r = $plan['resultat'][$id] ?? null;
                $godk = $v['godkjenning'] === 'manuelt' ? 'Manuelt: ' . (AMENDO_B2B_FLYTT_GRUNNER[$v['grunn']] ?? $v['grunn']) : ($v['godkjenning'] === 'ny' ? 'Godkjennes' : 'Godkjent fra før');
            ?>
            <tr class="b2b-flytt-rad b2b-flytt-<?php echo esc_attr($v['godkjenning']); ?>" data-bruker="<?php echo (int) $id; ?>">
                <td><?php if ($u): ?><a href="<?php echo esc_url(get_edit_user_link($u->ID)); ?>"><?php echo esc_html(get_user_meta($u->ID, 'billing_company', true) ?: ($u->display_name ?: '—')); ?></a><?php else: ?>Slettet bruker<?php endif; ?></td>
                <td><?php echo esc_html($v['orgnr'] ?: '—'); ?><?php if ($v['kilde'] === 'b2bwhs'): ?><br><span class="field-help">fra b2bwhs-feltet</span><?php endif; ?></td>
                <td><?php echo esc_html($godk); ?></td>
                <td><?php echo esc_html(amendo_b2b_flytt_tellix_tekst($v['tellix'], $v['sync_id'])); ?></td>
                <td class="b2b-flytt-resultat"><?php
                    if (!$r) echo '—';
                    elseif ($r['godkjenning'] === 'endret') echo esc_html($r['melding']);
                    else echo esc_html(($r['godkjenning'] === 'godkjent' ? 'Godkjent' : 'Godkjent fra før') . ($r['tellix'] && $r['tellix'] !== 'ingen' ? '. Tellix: ' . amendo_b2b_flytt_tellix_tekst($r['tellix']) : '') . '.');
                ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
