<?php
/**
 * Kategorivelgeren i admin (1.4.17): ALLE steder der man velger en
 * WooCommerce-kategori — kategori-reglene under Levering, kategoriflisene på
 * Forside og kategoriraden under Meny — bruker samme nedtrekksliste, med navn
 * (med foreldre: «Bakevarer › Brød») og antall produkter. admin.js legger et
 * søkefelt foran hver liste.
 *
 * ⚠ Verdien som lagres er fortsatt term_id. Ingenting i lagringen eller i
 * REST-svarene er endret; bare hvordan den velges.
 *
 * Før: kategori-regler lagt til med «+ Legg til regel» fikk et tallfelt for
 * kategori-ID-en, og de lagrede reglene en liste uten søk med bare de 100
 * første kategoriene.
 */

if (!defined('ABSPATH')) exit;

/**
 * Alle produktkategoriene, sortert på hele navnet:
 * [term_id => ['navn' => «Forelder › Barn», 'antall' => int]]. Én gang per
 * forespørsel, og på nytt når en kategori er laget, endret eller slettet
 * (WordPress sin `last_changed` for termer).
 */
function amendo_kategorier_til_velger() {
    static $ut = null, $nokkel = null;
    $naa = wp_cache_get_last_changed('terms');
    if ($ut !== null && $nokkel === $naa) return $ut;
    $nokkel = $naa;
    $ut = [];
    $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    if (is_wp_error($terms)) return $ut;
    $per_id = [];
    foreach ($terms as $t) $per_id[$t->term_id] = $t;
    foreach ($terms as $t) {
        $navn = [wp_specialchars_decode($t->name, ENT_QUOTES)];
        for ($p = $t->parent, $i = 0; $p && isset($per_id[$p]) && $i < 10; $p = $per_id[$p]->parent, $i++) {
            array_unshift($navn, wp_specialchars_decode($per_id[$p]->name, ENT_QUOTES));
        }
        // WooCommerce teller produkter i underkategorier her; $t->count gjør ikke det.
        $antall = get_term_meta($t->term_id, 'product_count_product_cat', true);
        $ut[(int) $t->term_id] = ['navn' => implode(' › ', $navn), 'antall' => is_numeric($antall) ? (int) $antall : (int) $t->count];
    }
    uasort($ut, function($a, $b) { return strnatcasecmp($a['navn'], $b['navn']); });
    return $ut;
}

/**
 * <select> med alle kategoriene. $valgt er term_id-en som er lagret (0 = ingen).
 * Er den lagrede kategorien slettet, står den som «Slettet kategori (#ID)», så
 * en lagring ikke stille bytter den ut med den første i lista.
 */
function amendo_kategori_velger($name, $valgt, $tom = '— Velg kategori —', $attr = '') {
    $valgt = (int) $valgt;
    $kategorier = amendo_kategorier_til_velger();
    ?>
    <select name="<?php echo esc_attr($name); ?>" class="amendo-kategori-velger" <?php echo $attr; ?>>
        <option value=""><?php echo esc_html($tom); ?></option>
        <?php if ($valgt > 0 && !isset($kategorier[$valgt])): ?>
            <option value="<?php echo $valgt; ?>" selected><?php echo esc_html('Slettet kategori (#' . $valgt . ')'); ?></option>
        <?php endif; ?>
        <?php foreach ($kategorier as $id => $k): ?>
            <option value="<?php echo (int) $id; ?>" <?php selected($valgt, (int) $id); ?>><?php echo esc_html($k['navn'] . ' (' . $k['antall'] . ')'); ?></option>
        <?php endforeach; ?>
    </select>
    <?php
}

/** Én kategori-regel under Levering & Henting. `$i` er et tall, eller «__i__» i malen. */
function amendo_kategori_regel_rad($i, array $r) {
    $p = 'kategori_regler[' . $i . ']';
    ?>
    <div class="kategori-regel-rad" style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-bottom:12px;">
        <div class="amendo-grid-2">
            <div class="amendo-field">
                <label>Kategori</label>
                <?php amendo_kategori_velger($p . '[kategori_id]', $r['kategori_id'] ?? 0, '— Velg kategori —', 'style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;"'); ?>
            </div>
            <div class="amendo-field">
                <label>Prosesseringstid (virkedager, tom = standard)</label>
                <input type="number" name="<?php echo esc_attr($p); ?>[prosesseringstid]" value="<?php echo esc_attr($r['prosesseringstid'] ?? ''); ?>" min="0" max="30" placeholder="Standard">
            </div>
        </div>
        <div class="amendo-grid-2">
            <div class="amendo-field">
                <label>Steng fra dato</label>
                <input type="date" name="<?php echo esc_attr($p); ?>[steng_fra]" value="<?php echo esc_attr($r['steng_fra'] ?? ''); ?>">
            </div>
            <div class="amendo-field">
                <label>Steng til dato</label>
                <input type="date" name="<?php echo esc_attr($p); ?>[steng_til]" value="<?php echo esc_attr($r['steng_til'] ?? ''); ?>">
            </div>
        </div>
        <button type="button" class="slett-kategori-regel amendo-btn-danger">Slett regel</button>
    </div>
    <?php
}
