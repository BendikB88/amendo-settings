<?php
/**
 * Sesongseksjoner på forsiden (1.4.34): «Julekaker», «Påske» og lignende,
 * som vises en periode og så forsvinner av seg selv.
 *
 * Fanen 🗓️ Sesong har en liste med seksjoner:
 *
 *     overskrift   påkrevd
 *     tekst        kort tekst under overskriften
 *     kilde        'kategori' eller 'etikett' (WooCommerce-kategori eller -etikett)
 *     antall       maks antall produkter, 1–24 (standard 8)
 *     sortering    rekkefølgen på forsiden, lavest først
 *     vis_fra      'Y-m-d' eller tom (= fra nå), første dag den vises
 *     vis_til      'Y-m-d' eller tom (= uten slutt), siste dag den vises
 *
 * Datoene gjelder i butikkens tidssone, og begge dagene er med.
 *
 * I /settings som `sesongseksjoner`: bare seksjonene som er aktive NÅ,
 * sortert, med kategorien eller etiketten slått opp (id, slug, navn) så
 * frontenden kan hente produktene:
 *
 *     [{ id, overskrift, tekst, kilde, kategori: {id, slug, navn}|null,
 *        etikett: {id, slug, navn}|null, antall, sortering, vis_fra, vis_til }]
 *
 * En seksjon med en kategori eller etikett som er slettet, er utelatt. Tom
 * liste når ingen er aktive (feltet er alltid med fra 1.4.34).
 *
 * ⚠ Frontenden mellomlagrer /settings (ISR), så en seksjon som starter ved
 * midnatt, vises når frontenden henter /settings på nytt — ikke på sekundet.
 * Lagring i admin varsler frontenden som før (includes/revalidering.php).
 *
 * Lagres som én option (`amendo_sesongseksjoner`) med flagg og versjon, som
 * forsiden — se includes/lagring.php.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_SESONG_MAKS = 20;
const AMENDO_SESONG_ANTALL = 8;

add_filter('amendo_lagring_deler', function($deler) { return $deler + ['sesong' => 'amendo_sesongseksjoner']; });
add_filter('amendo_lagring_navn', function($navn) { return $navn + ['sesong' => 'Sesongseksjoner']; });

/** 'Y-m-d' når det er en gyldig dato, ellers ''. */
function amendo_sesong_dato($v) {
    $v = is_scalar($v) ? trim((string) $v) : '';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}

/** Alle seksjonene, renset, i rekkefølgen fra admin. */
function amendo_sesong_alle() {
    $raa = json_decode((string) get_option('amendo_sesongseksjoner', '[]'), true);
    return amendo_sesong_rens_liste(is_array($raa) ? $raa : []);
}

/** Renset liste. ID-ene som finnes, står; nye får «sesong-N». */
function amendo_sesong_rens_liste(array $liste) {
    $brukt = [];
    foreach ($liste as $s) {
        $id = is_array($s) && is_scalar($s['id'] ?? null) ? (string) $s['id'] : '';
        if (preg_match('/^sesong-\d{1,6}$/', $id)) $brukt[$id] = false;
    }
    $ut = [];
    $neste = 1;
    foreach (array_values($liste) as $i => $s) {
        if (!is_array($s) || count($ut) >= AMENDO_SESONG_MAKS) continue;
        $overskrift = sanitize_text_field((string) ($s['overskrift'] ?? ''));
        if ($overskrift === '') continue;
        $id = is_scalar($s['id'] ?? null) ? (string) $s['id'] : '';
        if (!array_key_exists($id, $brukt) || $brukt[$id]) {
            while (array_key_exists('sesong-' . $neste, $brukt)) $neste++;
            $id = 'sesong-' . $neste;
        }
        $brukt[$id] = true;
        $kilde = ($s['kilde'] ?? '') === 'etikett' ? 'etikett' : 'kategori';
        $ut[] = [
            'id'         => $id,
            'overskrift' => $overskrift,
            'tekst'      => sanitize_textarea_field((string) ($s['tekst'] ?? '')),
            'kilde'      => $kilde,
            'kategori'   => $kilde === 'kategori' ? max(0, (int) ($s['kategori'] ?? 0)) : 0,
            'etikett'    => $kilde === 'etikett' ? max(0, (int) ($s['etikett'] ?? 0)) : 0,
            'antall'     => max(1, min(24, (int) ($s['antall'] ?? AMENDO_SESONG_ANTALL) ?: AMENDO_SESONG_ANTALL)),
            'sortering'  => is_numeric($s['sortering'] ?? null) ? (int) $s['sortering'] : ($i + 1) * 10,
            'vis_fra'    => amendo_sesong_dato($s['vis_fra'] ?? ''),
            'vis_til'    => amendo_sesong_dato($s['vis_til'] ?? ''),
        ];
    }
    return $ut;
}

/** 'aktiv', 'kommer', 'utlopt' eller 'aldri' (vis til er før vis fra), for dagen $idag ('Y-m-d'). */
function amendo_sesong_status(array $s, $idag = null) {
    $idag = $idag ?: wp_date('Y-m-d');
    if ($s['vis_fra'] !== '' && $s['vis_til'] !== '' && $s['vis_til'] < $s['vis_fra']) return 'aldri';
    if ($s['vis_fra'] !== '' && $idag < $s['vis_fra']) return 'kommer';
    if ($s['vis_til'] !== '' && $idag > $s['vis_til']) return 'utlopt';
    return 'aktiv';
}

/** { id, slug, navn } for termen, eller null når den ikke finnes. */
function amendo_sesong_term($id, $taksonomi) {
    if ($id <= 0) return null;
    $t = get_term($id, $taksonomi);
    return ($t && !is_wp_error($t)) ? ['id' => (int) $t->term_id, 'slug' => (string) $t->slug, 'navn' => (string) $t->name] : null;
}

/** De aktive seksjonene, slik /settings gir dem. */
function amendo_sesong_rest() {
    $ut = [];
    foreach (amendo_sesong_alle() as $s) {
        if (amendo_sesong_status($s) !== 'aktiv') continue;
        $kategori = $s['kilde'] === 'kategori' ? amendo_sesong_term($s['kategori'], 'product_cat') : null;
        $etikett  = $s['kilde'] === 'etikett' ? amendo_sesong_term($s['etikett'], 'product_tag') : null;
        if (!$kategori && !$etikett) continue;
        $ut[] = [
            'id'         => $s['id'],
            'overskrift' => $s['overskrift'],
            'tekst'      => $s['tekst'],
            'kilde'      => $s['kilde'],
            'kategori'   => $kategori,
            'etikett'    => $etikett,
            'antall'     => $s['antall'],
            'sortering'  => $s['sortering'],
            'vis_fra'    => $s['vis_fra'],
            'vis_til'    => $s['vis_til'],
        ];
    }
    usort($ut, function($a, $b) { return $a['sortering'] <=> $b['sortering']; });
    return $ut;
}

add_filter('amendo_settings_svar', function($svar) {
    $svar['sesongseksjoner'] = amendo_sesong_rest();
    return $svar;
}, 5);

// ── Lagring ─────────────────────────────────────────────────────────────────

/** Fra $_POST. Krever markøren `sesong[finnes]` og at delen er endret (includes/lagring.php). */
add_action('amendo_lagre_innstillinger', function(array $post) {
    $inn = $post['sesong'] ?? null;
    if (!is_array($inn) || empty($inn['finnes']) || !amendo_lagring_skal_lagre($post, 'sesong')) return;
    $ut = amendo_sesong_rens_liste(is_array($inn['seksjoner'] ?? null) ? $inn['seksjoner'] : []);
    update_option('amendo_sesongseksjoner', wp_json_encode($ut, JSON_UNESCAPED_UNICODE));
});

// ── Admin ───────────────────────────────────────────────────────────────────

add_action('amendo_admin_faner', function() {
    echo '<button type="button" class="amendo-tab" data-tab="sesong">🗓️ Sesong</button>';
}, 5);

/** Etikettene i en nedtrekksliste (navn og antall produkter). */
function amendo_sesong_etikett_velger($name, $valgt) {
    $valgt = (int) $valgt;
    $etiketter = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false]);
    $etiketter = is_array($etiketter) ? $etiketter : [];
    ?>
    <select name="<?php echo esc_attr($name); ?>">
        <option value="">— Velg etikett —</option>
        <?php if ($valgt > 0 && !in_array($valgt, array_map(function($t) { return (int) $t->term_id; }, $etiketter), true)): ?>
            <option value="<?php echo $valgt; ?>" selected><?php echo esc_html('Slettet etikett (#' . $valgt . ')'); ?></option>
        <?php endif; ?>
        <?php foreach ($etiketter as $t): ?>
            <option value="<?php echo (int) $t->term_id; ?>" <?php selected($valgt, (int) $t->term_id); ?>><?php echo esc_html($t->name . ' (' . (int) $t->count . ')'); ?></option>
        <?php endforeach; ?>
    </select>
    <?php
}

/** Én seksjon. `$i` er et tall, eller «__i__» i malen. */
function amendo_sesong_rad($i, array $s) {
    $n = 'sesong[seksjoner][' . $i . ']';
    $kilde = ($s['kilde'] ?? 'kategori') === 'etikett' ? 'etikett' : 'kategori';
    $status = $s ? amendo_sesong_status($s) : '';
    $tekster = ['aktiv' => 'Vises nå', 'kommer' => 'Vises fra ' . ($s['vis_fra'] ?? ''), 'utlopt' => 'Utløpt', 'aldri' => '⚠ «Vis til» er før «Vis fra» – vises aldri'];
    ?>
    <div class="avdeling-kort sesong-rad">
        <div class="avdeling-header">
            <span class="sesong-tittel"><?php echo esc_html(($s['overskrift'] ?? '') ?: 'Ny seksjon'); ?></span>
            <?php if ($status): ?><span class="sesong-status sesong-status-<?php echo esc_attr($status); ?>"><?php echo esc_html($tekster[$status]); ?></span><?php endif; ?>
            <button type="button" class="amendo-btn-danger sesong-slett">Slett</button>
        </div>
        <div class="avdeling-body">
            <input type="hidden" name="<?php echo esc_attr($n); ?>[id]" value="<?php echo esc_attr($s['id'] ?? ''); ?>">
            <div class="amendo-field"><label>Overskrift</label><input type="text" name="<?php echo esc_attr($n); ?>[overskrift]" value="<?php echo esc_attr($s['overskrift'] ?? ''); ?>" placeholder="Julekaker"></div>
            <div class="amendo-field"><label>Tekst</label><textarea name="<?php echo esc_attr($n); ?>[tekst]" rows="2"><?php echo esc_textarea($s['tekst'] ?? ''); ?></textarea></div>
            <div class="amendo-grid-2">
                <div class="amendo-field"><label>Produkter fra</label>
                    <select name="<?php echo esc_attr($n); ?>[kilde]" class="sesong-kilde">
                        <option value="kategori" <?php selected($kilde, 'kategori'); ?>>Kategori</option>
                        <option value="etikett" <?php selected($kilde, 'etikett'); ?>>Etikett</option>
                    </select>
                </div>
                <div class="amendo-field">
                    <div class="sesong-bare-kategori" <?php echo $kilde === 'kategori' ? '' : 'hidden'; ?>><label>Kategori</label><?php amendo_kategori_velger($n . '[kategori]', (int) ($s['kategori'] ?? 0)); ?></div>
                    <div class="sesong-bare-etikett" <?php echo $kilde === 'etikett' ? '' : 'hidden'; ?>><label>Etikett</label><?php amendo_sesong_etikett_velger($n . '[etikett]', (int) ($s['etikett'] ?? 0)); ?></div>
                </div>
            </div>
            <div class="amendo-grid-2">
                <div class="amendo-field"><label>Maks antall produkter</label><input type="number" name="<?php echo esc_attr($n); ?>[antall]" value="<?php echo (int) ($s['antall'] ?? AMENDO_SESONG_ANTALL); ?>" min="1" max="24" style="width:90px"></div>
                <div class="amendo-field"><label>Sortering</label><input type="number" name="<?php echo esc_attr($n); ?>[sortering]" value="<?php echo esc_attr((string) ($s['sortering'] ?? '')); ?>" style="width:90px"><p class="field-help">Lavest først på forsiden.</p></div>
            </div>
            <div class="amendo-grid-2">
                <div class="amendo-field"><label>Vis fra</label><input type="date" name="<?php echo esc_attr($n); ?>[vis_fra]" value="<?php echo esc_attr($s['vis_fra'] ?? ''); ?>"><p class="field-help">Tomt = fra nå.</p></div>
                <div class="amendo-field"><label>Vis til og med</label><input type="date" name="<?php echo esc_attr($n); ?>[vis_til]" value="<?php echo esc_attr($s['vis_til'] ?? ''); ?>"><p class="field-help">Tomt = til den slettes.</p></div>
            </div>
        </div>
    </div>
    <?php
}

add_action('amendo_admin_paneler', function() {
    $seksjoner = amendo_sesong_alle();
    ?>
    <div class="amendo-panel" id="tab-sesong">
        <div class="amendo-card" id="sesong" data-amendo-del="sesong">
            <?php amendo_lagring_felt('sesong'); ?>
            <input type="hidden" name="sesong[finnes]" value="1">
            <h2>Sesongseksjoner på forsiden</h2>
            <p class="amendo-desc">Seksjoner med produkter fra en kategori eller etikett, som vises i en periode («Julekaker» fra 1. til 24. desember). Nettsiden viser bare dem som gjelder i dag, i butikkens tidssone. Etter lagring oppdateres nettsiden i løpet av et minutt; en seksjon som starter eller slutter ved midnatt, vises eller forsvinner neste gang nettsiden henter innstillingene.</p>
            <div id="sesong-liste" class="amendo-repeter-liste">
                <?php foreach ($seksjoner as $i => $s) amendo_sesong_rad($i, $s); ?>
            </div>
            <button type="button" class="amendo-btn-secondary" id="sesong-legg-til">+ Legg til seksjon</button>
            <template id="sesong-mal"><?php amendo_sesong_rad('__i__', []); ?></template>
        </div>
    </div>
    <?php
}, 5);
