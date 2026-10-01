<?php
/**
 * Kategoriraden i frontendens meny, styrt fra Amendo → Meny.
 *
 * Lagres i optionen `amendo_kategorirad` som JSON:
 *   { "rad":   [{ "term_id": 12, "kortnavn": "Ringer", "uthevet": false }, …],
 *     "panel": [{ "term_id": 30, "kortnavn": "" }, …] }
 *
 * ⚠ Bare term_id lagres. Slug og navn slås opp ved HVER lesing, så en
 *   kategori som får nytt navn eller ny slug i WooCommerce blir stående i
 *   raden. Slettede kategorier hoppes stille over (og forsvinner fra lista
 *   neste gang innstillingene lagres).
 *
 * I /amendo-settings/v1/settings som `kategorirad` (toppnivå — `meny` er en
 * liste med lenker, og en nøkkel der ville gjort den om til et objekt):
 *   [{ term_id, slug, navn, kortnavn, uthevet, bare_panel }]
 * Raden først, i valgt rekkefølge med uthevede sist, deretter «Bare som
 * panel». Kategorier som ikke er med, viser frontenden under «Mer».
 */

if (!defined('ABSPATH')) exit;

const AMENDO_KATEGORIRAD_MAKS = 30;

/** Lagret struktur, med tomme lister som standard. */
function amendo_kategorirad_lagret() {
    $data = json_decode((string) get_option('amendo_kategorirad', ''), true);
    $data = is_array($data) ? $data : [];
    return [
        'rad'   => is_array($data['rad'] ?? null) ? $data['rad'] : [],
        'panel' => is_array($data['panel'] ?? null) ? $data['panel'] : [],
    ];
}

/** En product_cat-term, eller null (slettet eller ikke en produktkategori). */
function amendo_kategorirad_term($term_id) {
    $term = get_term((int) $term_id, 'product_cat');
    return ($term instanceof WP_Term) ? $term : null;
}

/** Toppnivåkategoriene, sortert på navn, med antall produkter (inkl. underkategorier). */
function amendo_kategorirad_toppnivaa() {
    $terms = get_terms(['taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => false, 'orderby' => 'name']);
    if (is_wp_error($terms)) return [];
    return array_map(function($t) {
        // WooCommerce teller produkter i underkategorier her; $t->count gjør ikke det.
        $antall = get_term_meta($t->term_id, 'product_count_product_cat', true);
        return ['term_id' => $t->term_id, 'navn' => amendo_kategorirad_navn($t), 'antall' => is_numeric($antall) ? (int) $antall : (int) $t->count];
    }, $terms);
}

/** WordPress lagrer «&» som «&amp;» i termnavn; frontenden skal få teksten. */
function amendo_kategorirad_navn(WP_Term $term) {
    return wp_specialchars_decode($term->name, ENT_QUOTES);
}

/**
 * Lagrer fra $_POST (allerede wp_unslash-et). Gjør ingenting uten markøren
 * `kategorirad[finnes]`, så andre skjemaer aldri tømmer lista.
 */
function amendo_kategorirad_lagre(array $post) {
    $inn = $post['kategorirad'] ?? null;
    if (!is_array($inn) || empty($inn['finnes'])) return;

    $sett = [];
    $rens = function($rader, $med_uthevet) use (&$sett) {
        $ut = [];
        foreach (is_array($rader) ? $rader : [] as $rad) {
            if (!is_array($rad)) continue;
            $id = absint($rad['term_id'] ?? 0);
            // Hver kategori bare én gang; raden vinner over panel-lista.
            if (!$id || isset($sett[$id]) || !amendo_kategorirad_term($id)) continue;
            $sett[$id] = true;
            $ren = [
                'term_id'  => $id,
                'kortnavn' => mb_substr(sanitize_text_field((string) ($rad['kortnavn'] ?? '')), 0, 40),
            ];
            if ($med_uthevet) $ren['uthevet'] = !empty($rad['uthevet']);
            $ut[] = $ren;
            if (count($ut) >= AMENDO_KATEGORIRAD_MAKS) break;
        }
        return $ut;
    };
    $data = ['rad' => $rens($inn['rad'] ?? [], true)];
    $data['panel'] = $rens($inn['panel'] ?? [], false);
    update_option('amendo_kategorirad', wp_json_encode($data), false);
}

/** For /settings. Se toppen av fila for formen. */
function amendo_kategorirad_rest() {
    $lagret = amendo_kategorirad_lagret();
    $vanlige = $uthevede = $panel = [];

    foreach ($lagret['rad'] as $rad) {
        $term = amendo_kategorirad_term($rad['term_id'] ?? 0);
        if (!$term) continue;
        $element = amendo_kategorirad_element($term, $rad, !empty($rad['uthevet']), false);
        if ($element['uthevet']) $uthevede[] = $element; else $vanlige[] = $element;
    }
    foreach ($lagret['panel'] as $rad) {
        $term = amendo_kategorirad_term($rad['term_id'] ?? 0);
        if ($term) $panel[] = amendo_kategorirad_element($term, $rad, false, true);
    }
    return array_merge($vanlige, $uthevede, $panel);
}

function amendo_kategorirad_element(WP_Term $term, array $rad, $uthevet, $bare_panel) {
    return [
        'term_id'    => (int) $term->term_id,
        'slug'       => $term->slug,
        'navn'       => amendo_kategorirad_navn($term),
        'kortnavn'   => (string) ($rad['kortnavn'] ?? ''),
        'uthevet'    => (bool) $uthevet,
        'bare_panel' => (bool) $bare_panel,
    ];
}

// ── Admin ───────────────────────────────────────────────────────────────────

/** Én rad. `$i` er et tall, eller «__i__» i malen (der navn og antall fylles inn av admin.js). */
function amendo_kategorirad_admin_rad($liste, $i, $rad, $navn, $antall) {
    $p = "kategorirad[$liste][$i]";
    ?>
    <div class="meny-rad kategorirad-rad">
        <div class="meny-drag" title="Dra for å endre rekkefølge">⠿</div>
        <input type="hidden" class="kategorirad-term" name="<?php echo esc_attr($p); ?>[term_id]" value="<?php echo esc_attr($rad['term_id'] ?? ''); ?>">
        <span class="kategorirad-navn"><?php echo esc_html($navn); ?></span>
        <span class="kategorirad-antall"><?php echo $antall === null ? '' : esc_html('(' . (int) $antall . ')'); ?></span>
        <input type="text" class="kategorirad-kortnavn" name="<?php echo esc_attr($p); ?>[kortnavn]" value="<?php echo esc_attr($rad['kortnavn'] ?? ''); ?>" placeholder="Kortnavn (valgfritt)" maxlength="40" aria-label="Kortnavn">
        <?php if ($liste === 'rad'): ?>
            <label class="kategorirad-uthev"><input type="checkbox" name="<?php echo esc_attr($p); ?>[uthevet]" value="1" <?php checked(!empty($rad['uthevet'])); ?>> Uthev (alltid sist)</label>
        <?php endif; ?>
        <button type="button" class="amendo-btn-secondary kategorirad-opp" aria-label="Flytt opp">↑</button>
        <button type="button" class="amendo-btn-secondary kategorirad-ned" aria-label="Flytt ned">↓</button>
        <button type="button" class="amendo-btn-danger kategorirad-fjern">Fjern</button>
    </div>
    <?php
}

/** Én liste (raden eller «Bare som panel») med velger for å legge til. */
function amendo_kategorirad_admin_liste($liste, array $rader, array $kategorier) {
    $antall_per_id = array_column($kategorier, 'antall', 'term_id');
    ?>
    <div class="kategorirad-liste-wrap" data-liste="<?php echo esc_attr($liste); ?>">
        <div class="kategorirad-liste">
            <?php foreach ($rader as $i => $rad):
                $term = amendo_kategorirad_term($rad['term_id'] ?? 0);
                if (!$term) continue; // slettet: forsvinner ved neste lagring
                amendo_kategorirad_admin_rad($liste, $i, $rad, amendo_kategorirad_navn($term), $antall_per_id[$term->term_id] ?? null);
            endforeach; ?>
        </div>
        <template class="kategorirad-mal"><?php amendo_kategorirad_admin_rad($liste, '__i__', [], '', null); ?></template>
        <div class="kategorirad-legg-til">
            <select class="kategorirad-velg" aria-label="Velg kategori">
                <option value="">Velg kategori …</option>
                <?php foreach ($kategorier as $k): ?>
                    <option value="<?php echo esc_attr($k['term_id']); ?>" data-navn="<?php echo esc_attr($k['navn']); ?>" data-antall="<?php echo esc_attr($k['antall']); ?>"><?php echo esc_html($k['navn'] . ' (' . $k['antall'] . ')'); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="amendo-btn-secondary kategorirad-legg-til-knapp">+ Legg til</button>
        </div>
    </div>
    <?php
}

/** Seksjonen i fanen Meny. Kalles inne i <form>. */
function amendo_kategorirad_admin() {
    $lagret = amendo_kategorirad_lagret();
    $kategorier = amendo_kategorirad_toppnivaa();
    ?>
    <div class="amendo-card" id="kategorirad">
        <h2>Kategoriraden</h2>
        <p class="amendo-desc">Kategoriene som vises i menyen, i denne rekkefølgen. Dra eller bruk pilene for å flytte. En uthevet kategori vises alltid sist. Kategorier som ikke er valgt her eller under, havner i «Mer».</p>
        <input type="hidden" name="kategorirad[finnes]" value="1">
        <?php amendo_kategorirad_admin_liste('rad', $lagret['rad'], $kategorier); ?>

        <h3 style="margin-top:24px">Bare som panel</h3>
        <p class="amendo-desc">Vises i menyen, men ikke i footeren — f.eks. Merker eller Epoker.</p>
        <?php amendo_kategorirad_admin_liste('panel', $lagret['panel'], $kategorier); ?>
        <?php if (!$kategorier): ?><p class="field-help">Ingen produktkategorier i WooCommerce ennå.</p><?php endif; ?>
    </div>
    <?php
}

// ── Varsle frontenden når en kategori i raden endres i WooCommerce ──────────
// Navn og slug slås opp ved lesing (se toppen), men /settings ligger i
// hurtigbufferen. Nytt navn eller ny slug, eller sletting, på en kategori som
// er MED i raden eller panel-lista, sender derfor ["innstillinger"]. Andre
// kategorier, og endringer av bare beskrivelse o.l., gir ikke varsel.

/** term_id-ene i raden og panel-lista. */
function amendo_kategorirad_ider() {
    $lagret = amendo_kategorirad_lagret();
    return array_map(function($r) { return (int) ($r['term_id'] ?? 0); }, array_merge($lagret['rad'], $lagret['panel']));
}

// Navn og slug før endringen, for å se om de faktisk endret seg.
add_action('edit_terms', function($term_id, $taxonomy = '') {
    if ($taxonomy !== 'product_cat') return;
    $term = get_term((int) $term_id, 'product_cat');
    if ($term instanceof WP_Term) $GLOBALS['amendo_kategorirad_foer'][(int) $term_id] = [$term->name, $term->slug];
}, 10, 2);

add_action('edited_product_cat', function($term_id) {
    $foer = $GLOBALS['amendo_kategorirad_foer'][(int) $term_id] ?? null;
    unset($GLOBALS['amendo_kategorirad_foer'][(int) $term_id]);
    $term = get_term((int) $term_id, 'product_cat');
    if (!$term instanceof WP_Term || ($foer !== null && $foer === [$term->name, $term->slug])) return;
    amendo_kategorirad_varsle($term_id);
});

add_action('delete_product_cat', function($term_id) {
    amendo_kategorirad_varsle($term_id);
});

/** Én gang per forespørsel — en masseoperasjon kan endre mange kategorier. */
function amendo_kategorirad_varsle($term_id) {
    static $sendt = false;
    if ($sendt || !in_array((int) $term_id, amendo_kategorirad_ider(), true)) return;
    $sendt = true;
    amendo_revalider(['innstillinger']);
}
