<?php
/**
 * Modulen B2B. Lastes BARE når modulen er på — se includes/moduler.php.
 *
 * Fanen 🏢 B2B har tre underfaner. Bedrifter:
 *
 * 1. SØKNADER. `/bedrift/registrer` i frontenden lager en kunde med
 *        _orgnr_soknad   org.nummeret slik kunden oppga det
 *        _b2b_status     'venter'
 *        _b2b_sokt       ISO-dato
 *    og gir INGEN tilgang. Godkjenn flytter nummeret til `billing_org_number`
 *    — først da får kunden bedriftsvisning, og dermed innsyn i ordrene til
 *    alle med samme org.nummer. Se includes/bedrift.php for de to nøklene.
 *
 *    ⚠⚠ Org.numre er offentlige. Søknaden beviser at FORETAKET finnes, ikke at
 *    søkeren jobber der. Godkjenning er en manuell kontroll, og knappen ber om
 *    bekreftelse av samme grunn.
 *
 *    ⚠ Avvis sletter `_orgnr_soknad` (nummeret ligger igjen i
 *    `_b2b_avvist_orgnr`). Ellers ville `bedrift-sok` svart «søknad til
 *    behandling» for nummeret for alltid, og den ekte bedriften kunne aldri
 *    søkt etter at noen andre hadde brukt nummeret deres.
 *
 * 2. AKTIVE B2B-KUNDER: alle med `billing_org_number`, søkbart på org.nr og
 *    firmanavn (`billing_company`).
 *
 * Kundegrupper (1.4.17): én rad per Tellix-gruppe med rabatt, hvor den
 * gjelder, faktura, bedriftsvisning og levering — se b2b-kundegrupper.php.
 * Erstatter tabellen `amendo_kundegruppe_config` og Helsebygget-lista.
 *
 * Helsebygget (1.4.17): ansatte med personlige PIN-koder og loggen over
 * endringer i sortimentet — se b2b-helsebygget.php og includes/helsebygget.php.
 */

if (!defined('ABSPATH')) exit;

// ── Søknader ────────────────────────────────────────────────────────────────

/** Bare sifrene. */
function amendo_b2b_orgnr($verdi) {
    return preg_replace('/\D/', '', (string) $verdi);
}

/** Søknadene som venter, eldst først. */
function amendo_b2b_soknader() {
    $q = new WP_User_Query([
        'meta_key'   => '_b2b_status',
        'meta_value' => 'venter',
        'orderby'    => 'registered',
        'order'      => 'ASC',
        'number'     => 200,
    ]);
    return $q->get_results();
}

/** Antall brukere som alt er godkjent på org.nummeret. */
function amendo_b2b_antall_med_orgnr($orgnr) {
    if ($orgnr === '') return 0;
    $q = new WP_User_Query(['meta_key' => 'billing_org_number', 'meta_value' => $orgnr, 'fields' => 'ID', 'number' => 1]);
    return (int) $q->get_total();
}

/**
 * Godkjenn eller avvis én søknad. Returnerer 'godkjent', 'avvist' eller en
 * feilkode. Skriver bare til brukere som faktisk har en søknad som venter, så
 * et forfalsket bruker-ID kan ikke gjøre en vilkårlig bruker til bedrift.
 */
function amendo_b2b_behandle($bruker_id, $valg) {
    $bruker_id = (int) $bruker_id;
    if (!$bruker_id || !get_userdata($bruker_id)) return 'ukjent_bruker';
    if (get_user_meta($bruker_id, '_b2b_status', true) !== 'venter') return 'ikke_venter';

    $orgnr = amendo_b2b_orgnr(get_user_meta($bruker_id, '_orgnr_soknad', true));
    $naa   = gmdate('c');

    if ($valg === 'godkjenn') {
        if (strlen($orgnr) !== 9) return 'ugyldig_orgnr';
        update_user_meta($bruker_id, 'billing_org_number', $orgnr);
        update_user_meta($bruker_id, '_b2b_status', 'godkjent');
        $status = 'godkjent';
    } elseif ($valg === 'avvis') {
        update_user_meta($bruker_id, '_b2b_status', 'avvist');
        update_user_meta($bruker_id, '_b2b_avvist_orgnr', $orgnr);
        $status = 'avvist';
    } else {
        return 'ukjent_valg';
    }

    delete_user_meta($bruker_id, '_orgnr_soknad');
    update_user_meta($bruker_id, '_b2b_behandlet', $naa);
    update_user_meta($bruker_id, '_b2b_behandlet_av', get_current_user_id());
    return $status;
}

add_action('admin_post_amendo_b2b_soknad', function() {
    if (!current_user_can(amendo_settings_kapabilitet())) wp_die('Ingen tilgang', 403);
    check_admin_referer('amendo_b2b_soknad');

    $valg = isset($_POST['godkjenn']) ? 'godkjenn' : (isset($_POST['avvis']) ? 'avvis' : '');
    $id   = (int) ($_POST['godkjenn'] ?? $_POST['avvis'] ?? 0);
    $svar = amendo_b2b_behandle($id, $valg);

    wp_redirect(admin_url('admin.php?page=amendo-settings&b2b=' . rawurlencode($svar) . '#b2b'));
    exit;
});

// ── Aktive B2B-kunder ───────────────────────────────────────────────────────

const AMENDO_B2B_PER_SIDE = 50;

/** Brukere med org.nummer, eventuelt filtrert på org.nr eller firmanavn. */
function amendo_b2b_kunder($sok, $side) {
    $har_orgnr = ['key' => 'billing_org_number', 'value' => '', 'compare' => '!='];
    $meta = [$har_orgnr];

    $sok = trim((string) $sok);
    if ($sok !== '') {
        $treff = ['relation' => 'OR', ['key' => 'billing_company', 'value' => $sok, 'compare' => 'LIKE']];
        // «923 207 074» og «923207074» skal gi samme treff.
        $siffer = amendo_b2b_orgnr($sok);
        if ($siffer !== '' && preg_match('/^[\d\s.]+$/', $sok)) {
            $treff[] = ['key' => 'billing_org_number', 'value' => $siffer, 'compare' => 'LIKE'];
        }
        $meta = ['relation' => 'AND', $har_orgnr, $treff];
    }

    $q = new WP_User_Query([
        'meta_query' => $meta,
        'orderby'    => 'registered',
        'order'      => 'DESC',
        'number'     => AMENDO_B2B_PER_SIDE,
        'paged'      => max(1, (int) $side),
    ]);
    return ['kunder' => $q->get_results(), 'totalt' => (int) $q->get_total()];
}

// ── Admin ───────────────────────────────────────────────────────────────────

add_action('amendo_admin_faner', function() {
    echo '<button type="button" class="amendo-tab" data-tab="b2b">🏢 B2B</button>';
});

add_action('amendo_admin_etter_skjema', function() {
    // Egne skjemaer: hovedskjemaet kan ikke inneholde dem. Knappene og
    // søkefeltet i fanen peker hit med `form="…"`.
    ?>
    <form id="amendo-b2b-behandle" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('amendo_b2b_soknad'); ?>
        <input type="hidden" name="action" value="amendo_b2b_soknad">
    </form>
    <form id="amendo-b2b-sok" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>#b2b">
        <input type="hidden" name="page" value="amendo-settings">
    </form>
    <?php
});

const AMENDO_B2B_MELDINGER = [
    'godkjent'      => ['ok', 'Søknaden er godkjent. Kunden har nå bedriftstilgang.'],
    'avvist'        => ['ok', 'Søknaden er avvist.'],
    'ikke_venter'   => ['feil', 'Søknaden er allerede behandlet.'],
    'ugyldig_orgnr' => ['feil', 'Søknaden har ikke et gyldig org.nummer (ni siffer) og kan ikke godkjennes. Rett nummeret på brukeren først.'],
    'ukjent_bruker' => ['feil', 'Fant ikke brukeren.'],
    'ukjent_valg'   => ['feil', 'Ukjent handling.'],
];

add_action('amendo_admin_paneler', function() {
    $melding  = AMENDO_B2B_MELDINGER[(string) ($_GET['b2b'] ?? '')] ?? null;
    $soknader = amendo_b2b_soknader();
    $sok      = sanitize_text_field(wp_unslash((string) ($_GET['b2b_sok'] ?? '')));
    $side     = max(1, (int) ($_GET['b2b_side'] ?? 1));
    $kunder   = amendo_b2b_kunder($sok, $side);
    $sider    = (int) ceil($kunder['totalt'] / AMENDO_B2B_PER_SIDE);
    ?>
    <div class="amendo-panel" id="tab-b2b">
        <?php if ($melding): ?>
            <div class="amendo-b2b-melding amendo-b2b-melding-<?php echo esc_attr($melding[0]); ?>"><?php echo esc_html($melding[1]); ?></div>
        <?php endif; ?>

        <?php // Underfaner. Alt står i hovedskjemaet; admin.js viser én om gangen. ?>
        <div class="amendo-undernav" role="tablist">
            <button type="button" class="amendo-undertab active" data-under="bedrifter">Bedrifter</button>
            <button type="button" class="amendo-undertab" data-under="kundegrupper">Kundegrupper</button>
            <button type="button" class="amendo-undertab" data-under="helsebygget">Helsebygget</button>
        </div>

        <div class="amendo-underpanel active" data-under-panel="bedrifter">

        <div class="amendo-card" id="b2b-soknader">
            <h2>Søknader (<?php echo count($soknader); ?>)</h2>
            <p class="amendo-desc">Bedrifter som har registrert seg på nettsiden. De har ingen bedriftstilgang før søknaden er godkjent. ⚠ Org.numre er offentlige: sjekk at søkeren faktisk jobber i bedriften før du godkjenner — en godkjent kunde ser bestillingene til alle med samme org.nummer.</p>
            <?php if (!$soknader): ?>
                <p class="field-help">Ingen søknader venter.</p>
            <?php else: ?>
            <table class="amendo-tabell">
                <thead><tr><th>Sendt</th><th>Firma</th><th>Org.nr</th><th>Kontaktperson</th><th>Allerede godkjent på nummeret</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($soknader as $u):
                    $orgnr = amendo_b2b_orgnr(get_user_meta($u->ID, '_orgnr_soknad', true));
                    $sokt  = (string) get_user_meta($u->ID, '_b2b_sokt', true);
                    $tid   = strtotime($sokt ?: $u->user_registered);
                    $andre = amendo_b2b_antall_med_orgnr($orgnr);
                ?>
                <tr class="b2b-soknad" data-bruker="<?php echo (int) $u->ID; ?>">
                    <td><?php echo esc_html($tid ? wp_date('d.m.Y H:i', $tid) : '—'); ?></td>
                    <td><?php echo esc_html(get_user_meta($u->ID, 'billing_company', true) ?: '—'); ?></td>
                    <td><?php if ($orgnr): ?><a href="<?php echo esc_url('https://virksomhet.brreg.no/oppslag/enheter/' . $orgnr); ?>" target="_blank" rel="noopener"><?php echo esc_html($orgnr); ?></a><?php else: ?>—<?php endif; ?></td>
                    <td><a href="<?php echo esc_url(get_edit_user_link($u->ID)); ?>"><?php echo esc_html(trim($u->first_name . ' ' . $u->last_name) ?: $u->display_name); ?></a><br><span class="field-help"><?php echo esc_html($u->user_email); ?></span></td>
                    <td><?php echo $andre ? esc_html($andre . ' bruker' . ($andre === 1 ? '' : 'e')) : 'Ingen'; ?></td>
                    <td class="b2b-knapper">
                        <button type="submit" form="amendo-b2b-behandle" name="godkjenn" value="<?php echo (int) $u->ID; ?>" class="amendo-btn-secondary b2b-bekreft" data-bekreft="<?php echo esc_attr('Godkjenne ' . (get_user_meta($u->ID, 'billing_company', true) ?: $orgnr) . '? Kunden får se bestillingene til alle med org.nr ' . $orgnr . '.'); ?>">Godkjenn</button>
                        <button type="submit" form="amendo-b2b-behandle" name="avvis" value="<?php echo (int) $u->ID; ?>" class="amendo-btn-danger b2b-bekreft" data-bekreft="Avvise søknaden? Kunden beholder kontoen som privatkunde.">Avvis</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="amendo-card" id="b2b-kunder">
            <h2>Aktive B2B-kunder (<?php echo (int) $kunder['totalt']; ?>)</h2>
            <p class="amendo-desc">Kunder med godkjent org.nummer. Kunder med samme org.nummer hører til samme bedrift.</p>
            <div class="b2b-sok">
                <input type="search" form="amendo-b2b-sok" name="b2b_sok" value="<?php echo esc_attr($sok); ?>" placeholder="Søk på org.nr eller firmanavn" aria-label="Søk i B2B-kunder">
                <button type="submit" form="amendo-b2b-sok" class="amendo-btn-secondary">Søk</button>
                <?php if ($sok !== ''): ?><a href="<?php echo esc_url(admin_url('admin.php?page=amendo-settings#b2b')); ?>">Vis alle</a><?php endif; ?>
            </div>
            <?php if (!$kunder['kunder']): ?>
                <p class="field-help"><?php echo $sok !== '' ? 'Ingen treff.' : 'Ingen bedriftskunder ennå.'; ?></p>
            <?php else: ?>
            <table class="amendo-tabell">
                <thead><tr><th>Firma</th><th>Org.nr</th><th>Kontaktperson</th><th>Kundegruppe</th><th>Godkjent</th></tr></thead>
                <tbody>
                <?php foreach ($kunder['kunder'] as $u):
                    $behandlet = (string) get_user_meta($u->ID, '_b2b_behandlet', true);
                ?>
                <tr class="b2b-kunde">
                    <td><?php echo esc_html(get_user_meta($u->ID, 'billing_company', true) ?: '—'); ?></td>
                    <td><?php echo esc_html(get_user_meta($u->ID, 'billing_org_number', true)); ?></td>
                    <td><a href="<?php echo esc_url(get_edit_user_link($u->ID)); ?>"><?php echo esc_html(trim($u->first_name . ' ' . $u->last_name) ?: $u->display_name); ?></a><br><span class="field-help"><?php echo esc_html($u->user_email); ?></span></td>
                    <td><?php $gid = (int) get_user_meta($u->ID, '_tellixGroupId', true); echo esc_html($gid ? ((amendo_tellix_grupper()[$gid]['navn'] ?? '') ?: (string) $gid) : '—'); ?></td>
                    <td><?php echo esc_html($behandlet ? wp_date('d.m.Y', strtotime($behandlet)) : '—'); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($sider > 1): ?>
                <p class="b2b-sider">Side <?php echo $side; ?> av <?php echo $sider; ?>
                <?php foreach ([['‹ Forrige', $side - 1], ['Neste ›', $side + 1]] as [$tekst, $til]):
                    if ($til < 1 || $til > $sider) continue; ?>
                    · <a href="<?php echo esc_url(add_query_arg(['page' => 'amendo-settings', 'b2b_sok' => $sok, 'b2b_side' => $til], admin_url('admin.php')) . '#b2b'); ?>"><?php echo esc_html($tekst); ?></a>
                <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        </div>

        <div class="amendo-underpanel" data-under-panel="kundegrupper">
            <?php amendo_kg_kort(); ?>
        </div>

        <div class="amendo-underpanel" data-under-panel="helsebygget">
            <?php amendo_hb_kort(); ?>
        </div>
    </div>
    <?php
});

require_once __DIR__ . '/b2b-tellix.php';
require_once __DIR__ . '/b2b-kundegrupper.php';
require_once __DIR__ . '/b2b-helsebygget.php';
