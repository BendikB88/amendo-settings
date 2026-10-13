<?php
/**
 * Modulen B2B. Lastes BARE når modulen er på — se includes/moduler.php.
 *
 * Fanen 🏢 B2B har tre deler:
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
 * 3. RETTIGHETER PER KUNDEGRUPPE (`_tellixGroupId`). Lagres i
 *    `amendo_kundegruppe_config`, og ligger i /settings som
 *        kundegruppe_config: { "3": { faktura: 1, b2b: 0, helsebygget: 0, helsebygget_rabatt: 0 }, … }
 *    — formen Garçon-frontenden leser i lib/kundegruppe-config.ts.
 *
 *    ⚠⚠ FRONTENDEN BYTTER KILDE SÅ SNART TABELLEN HAR ÉN RAD. Mens
 *    `kundegruppe_config` er tom, bruker den miljøvariablene i Vercel
 *    (FAKTURA_KUNDEGRUPPER, HELSEBYGGET_GRUPPE_IDS, BEDRIFT_GRUPPE_IDS); med
 *    én rad bruker den BARE tabellen. Derfor:
 *    - alle fire rettighetene står i samme tabell, ikke bare faktura. En tabell
 *      med bare faktura ville tatt Helsebygget-rabatten og B2B-visningen fra
 *      alle andre grupper i det øyeblikket den ble lagret.
 *    - en rad uten noen rettighet lagres IKKE. Gruppene vi finner hos kundene
 *      vises som tomme rader til utfylling; ble de lagret tomme, ville første
 *      lagring av hvilken som helst fane gjort tabellen ikke-tom og slått av
 *      alt for alle.
 *
 *    ⚠ Gruppenavnet vises bare i wp-admin og er IKKE med i /settings, som er
 *    offentlig. «Personalrabatt 50» angår ingen utenfor butikken.
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

// ── Rettigheter per kundegruppe ─────────────────────────────────────────────

const AMENDO_B2B_RETTIGHETER = ['faktura' => 'Faktura', 'b2b' => 'B2B-visning', 'helsebygget' => 'Helsebygget'];

/** Lagret: [gruppe-id => [navn, faktura, b2b, helsebygget, helsebygget_rabatt]]. */
function amendo_b2b_kundegrupper() {
    $data = json_decode((string) get_option('amendo_kundegruppe_config', ''), true);
    return is_array($data) ? $data : [];
}

/** Gruppe-ID-ene kundene faktisk har, med antall kunder. */
function amendo_b2b_grupper_hos_kunder() {
    global $wpdb;
    $rader = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_value AS id, COUNT(*) AS antall FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' GROUP BY meta_value",
        '_tellixGroupId'
    ));
    $ut = [];
    foreach ($rader ?: [] as $r) $ut[(string) $r->id] = (int) $r->antall;
    uksort($ut, 'strnatcmp');
    return $ut;
}

/** Fra $_POST. Gjør ingenting uten markøren `kundegruppe_config[finnes]`. */
function amendo_b2b_kundegrupper_lagre(array $post) {
    $inn = $post['kundegruppe_config'] ?? null;
    if (!is_array($inn) || empty($inn['finnes'])) return;

    $ut = [];
    foreach (is_array($inn['rader'] ?? null) ? $inn['rader'] : [] as $rad) {
        if (!is_array($rad)) continue;
        $id = trim((string) ($rad['id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id) || isset($ut[$id])) continue;

        $gruppe = ['navn' => mb_substr(sanitize_text_field((string) ($rad['navn'] ?? '')), 0, 80)];
        foreach (array_keys(AMENDO_B2B_RETTIGHETER) as $r) $gruppe[$r] = !empty($rad[$r]) ? 1 : 0;
        // Satsen betyr bare noe med Helsebygget-rett — samme tolkning som frontenden.
        $gruppe['helsebygget_rabatt'] = $gruppe['helsebygget'] ? min(100, max(0, (int) ($rad['helsebygget_rabatt'] ?? 0))) : 0;

        // ⚠ Rad uten rettigheter lagres ikke — se toppen av fila.
        if (!$gruppe['faktura'] && !$gruppe['b2b'] && !$gruppe['helsebygget']) continue;
        $ut[$id] = $gruppe;
    }
    update_option('amendo_kundegruppe_config', wp_json_encode((object) $ut), false);
}

add_action('amendo_lagre_innstillinger', 'amendo_b2b_kundegrupper_lagre');

// ── /settings ───────────────────────────────────────────────────────────────

add_filter('amendo_settings_svar', function($svar) {
    $config = [];
    foreach (amendo_b2b_kundegrupper() as $id => $g) {
        // Ikke navnet: /settings er offentlig.
        $config[(string) $id] = [
            'faktura'            => (int) !empty($g['faktura']),
            'b2b'                => (int) !empty($g['b2b']),
            'helsebygget'        => (int) !empty($g['helsebygget']),
            'helsebygget_rabatt' => (int) ($g['helsebygget_rabatt'] ?? 0),
        ];
    }
    // Objekt, også når det er tomt: `{}`, ikke `[]`.
    $svar['kundegruppe_config'] = (object) $config;
    return $svar;
});

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

/** Én rad i kundegruppe-tabellen. `$i` er et tall, eller «__i__» i malen. */
function amendo_b2b_gruppe_rad($i, $id, array $g, $antall) {
    $p = "kundegruppe_config[rader][$i]";
    ?>
    <tr class="kundegruppe-rad">
        <td><input type="text" name="<?php echo esc_attr($p); ?>[id]" value="<?php echo esc_attr($id); ?>" placeholder="ID" size="5" aria-label="Gruppe-ID"></td>
        <td><input type="text" name="<?php echo esc_attr($p); ?>[navn]" value="<?php echo esc_attr($g['navn'] ?? ''); ?>" placeholder="Navn (bare her)" aria-label="Navn"></td>
        <td class="kundegruppe-antall"><?php echo $antall === null ? '' : esc_html((string) $antall); ?></td>
        <?php foreach (AMENDO_B2B_RETTIGHETER as $r => $etikett): ?>
            <td><input type="checkbox" name="<?php echo esc_attr($p . "[$r]"); ?>" value="1" <?php checked(!empty($g[$r])); ?> aria-label="<?php echo esc_attr($etikett); ?>"></td>
        <?php endforeach; ?>
        <td><input type="number" name="<?php echo esc_attr($p); ?>[helsebygget_rabatt]" value="<?php echo esc_attr(!empty($g['helsebygget']) ? (int) ($g['helsebygget_rabatt'] ?? 0) : ''); ?>" min="0" max="100" style="width:70px" aria-label="Helsebygget-rabatt i prosent"></td>
        <td><button type="button" class="amendo-btn-danger kundegruppe-fjern">Fjern</button></td>
    </tr>
    <?php
}

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
    $grupper  = amendo_b2b_kundegrupper();
    $hos_kunder = amendo_b2b_grupper_hos_kunder();
    $sider    = (int) ceil($kunder['totalt'] / AMENDO_B2B_PER_SIDE);
    ?>
    <div class="amendo-panel" id="tab-b2b">
        <?php if ($melding): ?>
            <div class="amendo-b2b-melding amendo-b2b-melding-<?php echo esc_attr($melding[0]); ?>"><?php echo esc_html($melding[1]); ?></div>
        <?php endif; ?>

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
                    <td><?php echo esc_html(get_user_meta($u->ID, '_tellixGroupId', true) ?: '—'); ?></td>
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

        <div class="amendo-card" id="b2b-kundegrupper">
            <h2>Faktura og rettigheter per kundegruppe</h2>
            <p class="amendo-desc">Hva hver kundegruppe (<code>_tellixGroupId</code>) får på nettsiden. Rader uten noen avkrysning lagres ikke.</p>
            <p class="amendo-advarsel">⚠ Så lenge tabellen er tom, bruker nettsiden innstillingene sine i Vercel. Når den første raden lagres, bruker den <strong>bare denne tabellen</strong> — fyll derfor inn alle gruppene som skal ha faktura, B2B-visning eller Helsebygget-rabatt før du lagrer.</p>
            <input type="hidden" name="kundegruppe_config[finnes]" value="1">
            <table class="amendo-tabell kundegruppe-tabell">
                <thead><tr><th>ID</th><th>Navn</th><th>Kunder</th><?php foreach (AMENDO_B2B_RETTIGHETER as $etikett): ?><th><?php echo esc_html($etikett); ?></th><?php endforeach; ?><th>Rabatt %</th><th></th></tr></thead>
                <tbody class="kundegruppe-liste">
                <?php
                $i = 0;
                foreach ($grupper as $id => $g) amendo_b2b_gruppe_rad($i++, (string) $id, $g, $hos_kunder[(string) $id] ?? 0);
                // Gruppene kundene har, men som ikke står i tabellen ennå.
                foreach ($hos_kunder as $id => $antall) {
                    if (!isset($grupper[$id])) amendo_b2b_gruppe_rad($i++, (string) $id, [], $antall);
                }
                ?>
                </tbody>
            </table>
            <template class="kundegruppe-mal"><?php amendo_b2b_gruppe_rad('__i__', '', [], null); ?></template>
            <button type="button" class="amendo-btn-secondary" id="legg-til-kundegruppe" style="margin-top:12px">+ Legg til kundegruppe</button>
            <p class="field-help">Rabatten gjelder bare på Helsebygget-siden, og bare for grupper med Helsebygget krysset av.</p>
        </div>
    </div>
    <?php
});
