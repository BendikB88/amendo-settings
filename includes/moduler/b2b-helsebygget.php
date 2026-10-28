<?php
/**
 * B2B → Helsebygget (1.4.17): ansatte med personlige PIN-koder til
 * Helsebygget-admin i frontenden, og loggen over endringer i sortimentet.
 * Lastes av modulen helsebygget (1.4.23, includes/moduler/helsebygget.php),
 * ikke lenger av B2B. PIN, token og ruter: includes/helsebygget.php (kjernen,
 * fordi /helsebygget-produkter er en kjernerute).
 *
 * Handlingene (legg til, ny PIN, slett) er et eget skjema utenfor
 * hovedskjemaet, med nonce. En ny PIN vises ÉN gang, på siden man kommer
 * tilbake til, og lagres bare i en transient for brukeren i 10 minutter.
 */

if (!defined('ABSPATH')) exit;

add_action('amendo_admin_etter_skjema', function() {
    ?>
    <form id="amendo-hb-ansatt" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('amendo_hb_ansatt'); ?>
        <input type="hidden" name="action" value="amendo_hb_ansatt">
    </form>
    <?php
});

add_action('admin_post_amendo_hb_ansatt', function() {
    if (!current_user_can(amendo_settings_kapabilitet())) wp_die('Ingen tilgang', 403);
    check_admin_referer('amendo_hb_ansatt');
    $p = wp_unslash($_POST);
    $resultat = null;
    if (isset($p['ny_ansatt'])) {
        $resultat = amendo_hb_legg_til($p['navn'] ?? '');
        $melding = $resultat ? 'lagt_til' : 'mangler_navn';
    } elseif (isset($p['ny_pin'])) {
        $resultat = amendo_hb_bytt_pin((string) $p['ny_pin']);
        $melding = $resultat ? 'ny_pin' : 'ukjent';
    } elseif (isset($p['slett'])) {
        $melding = amendo_hb_slett((string) $p['slett']) ? 'slettet' : 'ukjent';
    } else {
        $melding = 'ukjent';
    }
    if ($resultat) {
        set_transient('amendo_hb_ny_pin_' . get_current_user_id(), ['navn' => $resultat[0]['navn'], 'pin' => $resultat[1]], 10 * MINUTE_IN_SECONDS);
    }
    // Underfanen i B2B, eller egen fane når B2B er av (1.4.23, includes/moduler/helsebygget.php).
    $anker = function_exists('amendo_b2b_soknader') ? '#b2b-helsebygget' : '#helsebygget';
    wp_redirect(admin_url('admin.php?page=amendo-settings&hb=' . $melding . $anker));
    exit;
});

const AMENDO_HB_MELDINGER = [
    'lagt_til'     => 'Den ansatte er lagt til.',
    'ny_pin'       => 'Ny PIN er laget. Den gamle virker ikke lenger.',
    'slettet'      => 'Den ansatte er slettet og kan ikke logge inn lenger.',
    'mangler_navn' => 'Skriv navnet til den ansatte.',
    'ukjent'       => 'Fant ikke den ansatte.',
];

/** «La til: Rundstykke, Bolle. Tok bort: Kanelsnurr.» */
function amendo_hb_endring_tekst(array $rad) {
    $navn = function($ider) {
        return implode(', ', array_map(function($id) {
            $t = get_the_title((int) $id);
            return $t !== '' ? wp_specialchars_decode($t, ENT_QUOTES) : 'vare #' . (int) $id;
        }, $ider));
    };
    $deler = [];
    if (!empty($rad['lagt_til'])) $deler[] = 'La til: ' . $navn($rad['lagt_til']) . '.';
    if (!empty($rad['fjernet'])) $deler[] = 'Tok bort: ' . $navn($rad['fjernet']) . '.';
    if (isset($rad['skjulte_foer'], $rad['skjulte_etter'])) {
        $deler[] = 'Skjulte varianter: ' . (int) $rad['skjulte_foer'] . ' → ' . (int) $rad['skjulte_etter'] . '.';
    }
    return $deler ? implode(' ', $deler) : 'Ingen endring.';
}

/** [produkt-ID => antall skjulte varianter], for oversikten i fanen (1.4.21). */
function amendo_hb_skjulte_per_produkt() {
    $ut = [];
    foreach (amendo_hb_skjulte_varianter() as $id) {
        $forelder = wp_get_post_parent_id($id);
        if ($forelder) $ut[$forelder] = ($ut[$forelder] ?? 0) + 1;
    }
    return $ut;
}

/** Underfanen. Kalles inne i hovedskjemaet; knappene peker på #amendo-hb-ansatt. */
function amendo_hb_kort() {
    $melding = AMENDO_HB_MELDINGER[(string) ($_GET['hb'] ?? '')] ?? null;
    $ny = get_transient('amendo_hb_ny_pin_' . get_current_user_id());
    if ($ny) delete_transient('amendo_hb_ny_pin_' . get_current_user_id());
    $ansatte = amendo_hb_ansatte();
    uasort($ansatte, function($a, $b) { return strnatcasecmp($a['navn'], $b['navn']); });
    $logg = amendo_hb_logg();
    ?>
    <div class="amendo-card" id="b2b-helsebygget">
        <h2>Ansatte i Helsebygget-admin</h2>
        <p class="amendo-desc">Hver ansatt logger inn i Helsebygget-admin på nettsiden med sin egen PIN-kode. Da ser du under hvem som har endret sortimentet. Innloggingen varer i 12 timer.</p>
        <?php if ($melding): ?><p class="amendo-b2b-melding amendo-hb-melding"><?php echo esc_html($melding); ?></p><?php endif; ?>
        <?php if (is_array($ny)): ?>
            <div class="amendo-ny-pin">
                <p><strong>PIN-koden til <?php echo esc_html($ny['navn']); ?>: <span class="amendo-pin"><?php echo esc_html($ny['pin']); ?></span></strong></p>
                <p class="field-help">Gi den til den ansatte nå. Den vises bare denne ene gangen — trenger noen den igjen, lag en ny.</p>
            </div>
        <?php endif; ?>
        <?php if ((string) get_option('amendo_kasse_secret', '') === ''): ?>
            <p class="amendo-advarsel amendo-hb-stengt">⚠ Kasse-secret er ikke satt (fanen Butikk). Ingen kan logge inn eller endre sortimentet før den er satt.</p>
        <?php endif; ?>
        <?php if ($ansatte): ?>
        <table class="amendo-tabell">
            <thead><tr><th>Navn</th><th>Lagt til</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($ansatte as $a): ?>
                <tr class="hb-ansatt">
                    <td><?php echo esc_html($a['navn']); ?></td>
                    <td><?php echo esc_html(wp_date('d.m.Y', (int) ($a['opprettet'] ?? 0))); ?></td>
                    <td class="b2b-knapper">
                        <button type="submit" form="amendo-hb-ansatt" name="ny_pin" value="<?php echo esc_attr($a['id']); ?>" class="amendo-btn-secondary b2b-bekreft" data-bekreft="<?php echo esc_attr('Lage ny PIN til ' . $a['navn'] . '? Den gamle slutter å virke med en gang.'); ?>">Ny PIN</button>
                        <button type="submit" form="amendo-hb-ansatt" name="slett" value="<?php echo esc_attr($a['id']); ?>" class="amendo-btn-danger b2b-bekreft" data-bekreft="<?php echo esc_attr('Slette ' . $a['navn'] . '? Hen blir logget ut med en gang.'); ?>">Slett</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="field-help">Ingen ansatte ennå. Ingen kan endre sortimentet før noen er lagt til.</p>
        <?php endif; ?>
        <div class="b2b-sok" style="margin-top:12px">
            <input type="text" form="amendo-hb-ansatt" name="navn" placeholder="Navn på den ansatte" maxlength="80" aria-label="Navn på den ansatte">
            <button type="submit" form="amendo-hb-ansatt" name="ny_ansatt" value="1" class="amendo-btn-secondary">+ Legg til ansatt</button>
        </div>
        <p class="field-help">PIN-koden lages automatisk (6 sifre).</p>
    </div>

    <?php $skjulte = amendo_hb_skjulte_per_produkt(); ?>
    <div class="amendo-card" id="b2b-helsebygget-skjulte">
        <h2>Skjulte varianter</h2>
        <p class="amendo-desc">Varianter som ikke kan bestilles på Helsebygget, selv om produktet er med. De ansatte velger dem i Helsebygget-admin på nettsiden. Nettbutikken påvirkes ikke.</p>
        <?php if (!$skjulte): ?>
            <p class="field-help">Ingen varianter er skjult.</p>
        <?php else: ?>
        <table class="amendo-tabell">
            <thead><tr><th>Produkt</th><th>Skjulte varianter</th></tr></thead>
            <tbody>
            <?php foreach ($skjulte as $produkt => $antall): ?>
                <tr class="hb-skjulte">
                    <td><?php echo esc_html(get_the_title($produkt) !== '' ? wp_specialchars_decode(get_the_title($produkt), ENT_QUOTES) : 'vare #' . (int) $produkt); ?></td>
                    <td><?php echo (int) $antall; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="amendo-card" id="b2b-helsebygget-logg">
        <h2>Endringer i sortimentet</h2>
        <p class="amendo-desc">De siste <?php echo (int) AMENDO_HB_LOGG_MAKS; ?> endringene som er gjort i Helsebygget-admin, nyeste først.</p>
        <?php if (!$logg): ?>
            <p class="field-help">Ingen endringer ennå.</p>
        <?php else: ?>
        <table class="amendo-tabell">
            <thead><tr><th>Tid</th><th>Hvem</th><th>Endring</th><th>Varer i sortimentet</th></tr></thead>
            <tbody>
            <?php foreach ($logg as $rad): ?>
                <tr class="hb-logg">
                    <td><?php echo esc_html(wp_date('d.m.Y H:i', (int) $rad['tid'])); ?></td>
                    <td><?php echo esc_html($rad['navn']); ?></td>
                    <td><?php echo esc_html(amendo_hb_endring_tekst($rad)); ?></td>
                    <td><?php echo (int) $rad['antall']; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
