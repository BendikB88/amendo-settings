<?php
/**
 * Bestillingsvindu, antallsregler og helligdager i /leveringsregler (1.4.18).
 *
 * Pluginen regner ikke ut hentedatoer selv: frontenden gjør det ut fra
 * /leveringsregler. Her ligger bare reglene og hvordan de lagres og vises.
 *
 * - Bestillingsvindu per avdeling: `avdelinger[].bestillingsvindu_dager`,
 *   kalenderdager fram fra i dag som kan velges. Standard 60, også for
 *   avdelinger lagret før 1.4.18 (feltet er alltid med i svaret).
 * - Antallsregler (`global.antall_regler`): «Fra X varer i kurven → minst N
 *   virkedager». Uten kategori telles alle varene i kurven, med kategori bare
 *   varene i den kategorien. `unntatt_avdelinger` er avdelingsnavn, som i
 *   includes/stengte.php (avdelinger har ingen ID).
 * - Helligdager (`global.stengt_helligdager`, `global.helligdager`): norske
 *   helligdager de neste 12 månedene, regnet ut her — de bevegelige ut fra
 *   påskedag. Når bryteren er på (standard), teller de ikke som virkedager og
 *   kan ikke velges. Av: tom liste.
 *
 * Kunden får den LENGSTE tiden av avdelingens produksjonstid, kategoriens
 * produksjonstid (kategori_regler) og antallsreglene. Det står også i admin.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_BESTILLINGSVINDU_STANDARD = 60;
const AMENDO_ANTALL_REGLER_MAKS        = 50;

/** Avdelingens bestillingsvindu i dager (1–365), standard 60. */
function amendo_bestillingsvindu($avd) {
    $d = is_array($avd) ? ($avd['bestillingsvindu_dager'] ?? null) : null;
    return is_numeric($d) && (int) $d >= 1 ? min(365, (int) $d) : AMENDO_BESTILLINGSVINDU_STANDARD;
}

// ── Antallsregler ───────────────────────────────────────────────────────────

/** De lagrede reglene, rensede. */
function amendo_antall_regler() {
    $raa = json_decode((string) get_option('amendo_antall_regler', '[]'), true);
    $ut = [];
    foreach (is_array($raa) ? $raa : [] as $r) {
        $r = amendo_antall_regel_rens(is_array($r) ? $r : []);
        if ($r) $ut[] = $r;
    }
    return $ut;
}

/** Én regel, eller null når den mangler antall. */
function amendo_antall_regel_rens(array $r) {
    $fra = isset($r['fra_antall']) && is_numeric($r['fra_antall']) ? (int) $r['fra_antall'] : 0;
    if ($fra < 1) return null;
    $min = isset($r['min_virkedager']) && is_numeric($r['min_virkedager']) ? (int) $r['min_virkedager'] : 0;
    $kat = isset($r['kategori_id']) && is_numeric($r['kategori_id']) && (int) $r['kategori_id'] > 0 ? (int) $r['kategori_id'] : null;
    $unntatt = [];
    foreach ((array) ($r['unntatt_avdelinger'] ?? []) as $n) {
        $n = sanitize_text_field((string) $n);
        if ($n !== '' && !in_array($n, $unntatt, true)) $unntatt[] = $n;
    }
    return [
        'fra_antall'         => min(9999, $fra),
        'min_virkedager'     => max(0, min(60, $min)),
        'kategori_id'        => $kat,
        'unntatt_avdelinger' => $unntatt,
    ];
}

/** Fra $_POST (allerede wp_unslash-et). */
function amendo_antall_regler_lagre(array $post) {
    $regler = [];
    foreach (is_array($post['antall_regler'] ?? null) ? $post['antall_regler'] : [] as $r) {
        $r = is_array($r) ? amendo_antall_regel_rens($r) : null;
        if ($r && count($regler) < AMENDO_ANTALL_REGLER_MAKS) $regler[] = $r;
    }
    update_option('amendo_antall_regler', wp_json_encode($regler));
}

/** Navnet på kategorien, eller null (ingen kategori, eller slettet). */
function amendo_leveringsregler_kategorinavn($id) {
    if (!$id) return null;
    $t = get_term((int) $id, 'product_cat');
    return $t && !is_wp_error($t) ? wp_specialchars_decode($t->name, ENT_QUOTES) : null;
}

/** Til /leveringsregler: med kategorinavnet. */
function amendo_antall_regler_rest() {
    return array_map(function($r) {
        return $r + ['kategori_navn' => amendo_leveringsregler_kategorinavn($r['kategori_id'])];
    }, amendo_antall_regler());
}

// ── Helligdager ─────────────────────────────────────────────────────────────

function amendo_stengt_helligdager() {
    return get_option('amendo_stengt_helligdager', '1') === '1';
}

/** Påskedag (gregoriansk, «anonym» algoritme). Ikke easter_date(): krever calendar-utvidelsen. */
function amendo_paaskedag($aar) {
    $a = $aar % 19; $b = intdiv($aar, 100); $c = $aar % 100;
    $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $maaned = intdiv($h + $l - 7 * $m + 114, 31);
    $dag = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $aar, $maaned, $dag));
}

/** Norske helligdager i ett år: ['Y-m-d' => navn], sortert. */
function amendo_helligdager_aar($aar) {
    $p = amendo_paaskedag($aar);
    $flytt = function($dager) use ($p) { return $p->modify(($dager >= 0 ? '+' : '') . $dager . ' days')->format('Y-m-d'); };
    $ut = [
        "$aar-01-01" => '1. nyttårsdag',
        $flytt(-3)   => 'Skjærtorsdag',
        $flytt(-2)   => 'Langfredag',
        $flytt(0)    => '1. påskedag',
        $flytt(1)    => '2. påskedag',
        "$aar-05-01" => '1. mai',
        "$aar-05-17" => '17. mai',
        $flytt(39)   => 'Kristi himmelfartsdag',
        $flytt(49)   => '1. pinsedag',
        $flytt(50)   => '2. pinsedag',
        "$aar-12-25" => '1. juledag',
        "$aar-12-26" => '2. juledag',
    ];
    ksort($ut);
    return $ut;
}

/**
 * Helligdagene fra i dag (butikkens tidssone) og 12 måneder fram:
 * ['Y-m-d' => navn]. $fra er til testene.
 */
function amendo_helligdager_neste($fra = null) {
    $start = $fra ? new DateTimeImmutable($fra, wp_timezone()) : new DateTimeImmutable('today', wp_timezone());
    $slutt = $start->modify('+12 months');
    $ut = [];
    for ($aar = (int) $start->format('Y'); $aar <= (int) $slutt->format('Y'); $aar++) {
        foreach (amendo_helligdager_aar($aar) as $dato => $navn) {
            if ($dato >= $start->format('Y-m-d') && $dato < $slutt->format('Y-m-d')) $ut[$dato] = $navn;
        }
    }
    return $ut;
}

/** Til /leveringsregler: datoene, eller tom liste når bryteren er av. */
function amendo_helligdager_rest() {
    return amendo_stengt_helligdager() ? array_keys(amendo_helligdager_neste()) : [];
}

// ── Admin ───────────────────────────────────────────────────────────────────

/** Én antallsregel. `$i` er et tall, eller «__i__» i malen admin.js kloner. */
function amendo_antall_regel_rad($i, array $r) {
    $p = 'antall_regler[' . $i . ']';
    $unntatt = (array) ($r['unntatt_avdelinger'] ?? []);
    $avdelinger = amendo_stengte_avdelingsnavn();
    // Avdelinger som er unntatt, men har fått nytt navn: vises, så de kan rettes.
    foreach ($unntatt as $n) if (!in_array($n, $avdelinger, true)) $avdelinger[] = $n;
    ?>
    <div class="antall-regel-rad">
        <div class="antall-regel-linje">
            <span>Fra</span>
            <input type="number" name="<?php echo esc_attr($p); ?>[fra_antall]" value="<?php echo esc_attr($r['fra_antall'] ?? ''); ?>" min="1" max="9999" placeholder="10" aria-label="Antall varer">
            <span>varer i kurven →</span>
            <span>minst</span>
            <input type="number" name="<?php echo esc_attr($p); ?>[min_virkedager]" value="<?php echo esc_attr($r['min_virkedager'] ?? ''); ?>" min="0" max="60" placeholder="2" aria-label="Virkedager">
            <span>virkedager</span>
        </div>
        <div class="amendo-grid-2">
            <div class="amendo-field">
                <label>Bare varer i kategorien</label>
                <?php amendo_kategori_velger($p . '[kategori_id]', $r['kategori_id'] ?? 0, '— Alle varer i kurven —'); ?>
            </div>
            <div class="amendo-field">
                <label>Gjelder ikke for</label>
                <?php if (!$avdelinger): ?>
                    <p class="field-help">Ingen avdelinger ennå.</p>
                <?php endif; ?>
                <?php foreach ($avdelinger as $n): ?>
                <label class="antall-regel-unntak">
                    <input type="checkbox" name="<?php echo esc_attr($p); ?>[unntatt_avdelinger][]" value="<?php echo esc_attr($n); ?>" <?php checked(in_array($n, $unntatt, true)); ?>>
                    <?php echo esc_html(in_array($n, amendo_stengte_avdelingsnavn(), true) ? $n : '(finnes ikke: ' . $n . ')'); ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="button" class="slett-antall-regel amendo-btn-danger">Slett regel</button>
    </div>
    <?php
}

/** Kortet under Levering & Henting. */
function amendo_antall_regler_kort() {
    ?>
    <div class="amendo-card" id="antall-regler" data-amendo-del="antallregler">
        <?php amendo_lagring_felt('antallregler'); ?>
        <input type="hidden" name="antall_regler_finnes" value="1">
        <h2>Antallsregler</h2>
        <p class="amendo-desc">Store bestillinger trenger mer tid. Eksempel: fra 20 varer i kurven må kunden bestille minst 3 virkedager før.</p>
        <p class="amendo-desc amendo-lengste-tid"><strong>Kunden får den lengste tiden av: avdelingens produksjonstid, kategoriens produksjonstid og antallsreglene.</strong></p>
        <div id="antall-regler-liste">
            <?php foreach (amendo_antall_regler() as $i => $r) amendo_antall_regel_rad($i, $r); ?>
        </div>
        <template id="antall-regel-mal"><?php amendo_antall_regel_rad('__i__', []); ?></template>
        <button type="button" class="amendo-btn-secondary" id="legg-til-antall-regel">+ Legg til regel</button>
    </div>
    <?php
}

/** Bryteren i Henting-kortet, med de neste helligdagene. */
function amendo_helligdager_felt() {
    $neste = array_slice(amendo_helligdager_neste(), 0, 4, true);
    $tekst = [];
    foreach ($neste as $dato => $navn) $tekst[] = wp_date('j.n.', strtotime($dato . ' 12:00')) . ' ' . $navn;
    ?>
    <div class="amendo-field">
        <label for="stengt_helligdager">Stengt på helligdager</label>
        <label class="toggle">
            <input type="checkbox" id="stengt_helligdager" name="stengt_helligdager" <?php checked(amendo_stengt_helligdager()); ?>>
            <span class="toggle-slider"></span>
        </label>
        <p class="field-help">Norske helligdager teller ikke som virkedager, og kunden kan ikke velge dem. Neste: <?php echo esc_html(implode(', ', $tekst)); ?>.</p>
    </div>
    <?php
}
