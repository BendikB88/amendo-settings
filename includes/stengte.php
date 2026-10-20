<?php
/**
 * Stengte perioder per avdeling (1.4.14).
 *
 * Hver periode i `amendo_stengte_perioder` kan ha `avdeling`: navnet på en
 * avdeling, slik det står i `avdelinger[].navn`. Tom streng betyr ALLE
 * avdelinger — og det er det alle perioder lagret før 1.4.14 betyr, så de
 * får `avdeling: ""` når de leses. Ingenting lagret endres.
 *
 * I /leveringsregler som `global.stengte_perioder[].avdeling`, alltid med.
 *
 * ⚠ Navnet, ikke en ID: avdelinger har ingen ID (de er en liste i én option).
 * Får en avdeling nytt navn, står perioden igjen med det gamle navnet og
 * gjelder da ingen avdeling. Admin viser den som «(finnes ikke: …)», så det
 * er synlig og kan rettes.
 */

if (!defined('ABSPATH')) exit;

/** Periodene, med `avdeling` på alle (tom = alle avdelinger). */
function amendo_stengte_perioder() {
    $perioder = json_decode((string) get_option('amendo_stengte_perioder', '[]'), true);
    if (!is_array($perioder)) return [];
    return array_values(array_map(function($p) {
        $p = is_array($p) ? $p : [];
        $p['avdeling'] = (string) ($p['avdeling'] ?? '');
        return $p;
    }, $perioder));
}

/** Navnene på avdelingene, i lagret rekkefølge, uten tomme. */
function amendo_stengte_avdelingsnavn() {
    $avdelinger = json_decode((string) get_option('amendo_avdelinger', '[]'), true) ?: [];
    $navn = [];
    foreach ($avdelinger as $a) {
        $n = trim((string) ($a['navn'] ?? ''));
        if ($n !== '' && !in_array($n, $navn, true)) $navn[] = $n;
    }
    return $navn;
}

/** Fra $_POST (allerede wp_unslash-et). */
function amendo_stengte_lagre(array $post) {
    $perioder = [];
    foreach (is_array($post['stengte_perioder'] ?? null) ? $post['stengte_perioder'] : [] as $p) {
        if (!is_array($p) || empty($p['fra']) || empty($p['til'])) continue;
        $perioder[] = [
            'label'    => sanitize_text_field($p['label'] ?? ''),
            'fra'      => sanitize_text_field($p['fra']),
            'til'      => sanitize_text_field($p['til']),
            'avdeling' => sanitize_text_field($p['avdeling'] ?? ''),
        ];
    }
    update_option('amendo_stengte_perioder', json_encode($perioder));
}

/** Velgeren. `$i` er et tall, eller «__i__» i malen admin.js kloner. */
function amendo_stengte_velger($i, $valgt) {
    $navn = amendo_stengte_avdelingsnavn();
    ?>
    <select name="stengte_perioder[<?php echo esc_attr($i); ?>][avdeling]" class="steng-avdeling" aria-label="Avdeling">
        <option value="">Alle avdelinger</option>
        <?php foreach ($navn as $n): ?>
            <option value="<?php echo esc_attr($n); ?>" <?php selected($valgt, $n); ?>><?php echo esc_html($n); ?></option>
        <?php endforeach; ?>
        <?php if ($valgt !== '' && !in_array($valgt, $navn, true)): ?>
            <option value="<?php echo esc_attr($valgt); ?>" selected><?php echo esc_html('(finnes ikke: ' . $valgt . ')'); ?></option>
        <?php endif; ?>
    </select>
    <?php
}
