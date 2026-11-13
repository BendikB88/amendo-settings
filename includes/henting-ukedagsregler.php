<?php
/**
 * Egne regler for ukedager (1.4.40), i kortet Henting rett under «Stengt for
 * henting». En regel sier at én ukedag bare kan brukes til henting når
 *
 *   - alle varene i kurven er i de tillatte kategoriene (underkategorier teller
 *     med: «Kaker» tillater også «Kaker › Bløtkaker»), og
 *   - bestillingsfristen ikke er passert: en ukedag + klokkeslett FØR dagen,
 *     f.eks. søndag med frist torsdag 13:00 = torsdagen tre dager før den
 *     søndagen. Samme ukedag som hentedagen = en uke før. Klokkeslettet er i
 *     butikkens tidssone (wp_timezone()), og kl. 13:00:00 er fristen passert.
 *
 * Ellers er dagen stengt. Bare henting (`_leveringstype = levering` røres ikke).
 * Uten kategorier gjelder bare fristen; uten frist bare kategoriene. En regel
 * uten noen av dem lagres ikke. Én regel per ukedag (den første vinner).
 *
 * ⚠ «Stengt for henting» (includes/henting-stengt.php) går foran: en dag som er
 * stengt der, er stengt uansett hva regelen sier, og kassen svarer med
 * `amendo_henting_stengt`, ikke med denne regelens kode.
 *
 *   amendo_henting_ukedagsregler  JSON-liste med
 *       { ukedag, kategorier: [term_id], frist_ukedag: ''|dag, frist_tid: ''|'HH:MM' }
 *
 * Ut i `/leveringsregler` som `global.ukedagsregler` (alltid med, [] til noe er
 * lagret), én per regel i ukerekkefølge:
 *       { ukedag, kategorier: [{ id, slug, navn }] (slettet kategori: slug/navn null),
 *         tillatte_kategori_ider: [ID-ene + alle underkategoriene],
 *         frist: { ukedag, tid, dager_for } | null }
 * Frontenden bør bruke den til å skjule dagen i datovelgeren; sjekken i kassen
 * er sikringen.
 *
 * Kassen: en ordre som LAGES via wc/v3/orders med `_hentedato` (Y-m-d) på en dag
 * med regel, avvises med 400 `amendo_henting_ukedagsregel` før den lagres, med
 * `grunn` = 'frist' (sjekkes først — da hjelper det ikke å fjerne varer) eller
 * 'kategori' (og `varer` utenfor). Ikke levering, ikke den gamle kassen (uten
 * `_hentedato`), ikke PUT — som amendo_henting_stengt. En vare uten produkt
 * eller kategori er utenfor.
 *
 * Lagres som én option: markøren `henting_ukedagsregler[finnes]` og
 * lagringsdelen `ukedagsregler` (includes/lagring.php), så en lagring fra en
 * annen fane eller en gammel side ikke overskriver reglene.
 *
 * Filteret `amendo_henting_naa` (tidspunktet fristen sammenlignes med) finnes
 * for testene.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_UKEDAGSREGEL_MAKS_KATEGORIER = 50;

add_filter('amendo_lagring_deler', function($deler) { return $deler + ['ukedagsregler' => 'amendo_henting_ukedagsregler']; });
add_filter('amendo_lagring_navn', function($navn) { return $navn + ['ukedagsregler' => 'Egne regler for ukedager']; });

/** De lagrede reglene, renset. */
function amendo_henting_ukedagsregler() {
    $data = json_decode((string) get_option('amendo_henting_ukedagsregler', '[]'), true);
    return amendo_henting_ukedagsregler_rens(is_array($data) ? $data : []);
}

/** 'HH:MM' eller ''. «9:5» godtas ikke, «9:05» blir «09:05». */
function amendo_ukedagsregel_tid($v) {
    if (!is_string($v) || !preg_match('/^(\d{1,2}):(\d{2})$/', trim($v), $m)) return '';
    return ((int) $m[1] < 24 && (int) $m[2] < 60) ? sprintf('%02d:%02d', $m[1], $m[2]) : '';
}

/** Gyldige regler, én per ukedag (den første), i ukerekkefølge. */
function amendo_henting_ukedagsregler_rens(array $inn) {
    $per_dag = [];
    foreach ($inn as $r) {
        if (!is_array($r)) continue;
        $dag = amendo_henting_stengt_rens_ukedager([$r['ukedag'] ?? ''])[0] ?? null;
        if ($dag === null || isset($per_dag[$dag])) continue;
        $kat = [];
        foreach ((is_array($r['kategorier'] ?? null) ? $r['kategorier'] : []) as $id) {
            $id = is_numeric($id) ? (int) $id : 0;
            if ($id > 0 && !in_array($id, $kat, true)) $kat[] = $id;
            if (count($kat) >= AMENDO_UKEDAGSREGEL_MAKS_KATEGORIER) break;
        }
        $frist_dag = amendo_henting_stengt_rens_ukedager([$r['frist_ukedag'] ?? ''])[0] ?? '';
        $frist_tid = amendo_ukedagsregel_tid($r['frist_tid'] ?? '');
        if ($frist_dag === '' || $frist_tid === '') $frist_dag = $frist_tid = '';
        if (!$kat && $frist_dag === '') continue;
        $per_dag[$dag] = ['ukedag' => $dag, 'kategorier' => $kat, 'frist_ukedag' => $frist_dag, 'frist_tid' => $frist_tid];
    }
    $ut = [];
    foreach (AMENDO_HENTING_STENGT_UKEDAGER as $dag) if (isset($per_dag[$dag])) $ut[] = $per_dag[$dag];
    return $ut;
}

/** Regelen for ukedagen til Y-m-d-datoen, eller null (også for ugyldig dato). */
function amendo_ukedagsregel_for($ymd) {
    $d = amendo_ukedagsregel_dato($ymd);
    if (!$d) return null;
    $dag = AMENDO_HENTING_STENGT_UKEDAGER[(int) $d->format('N')];
    foreach (amendo_henting_ukedagsregler() as $r) if ($r['ukedag'] === $dag) return $r;
    return null;
}

/** Midnatt på datoen i butikkens tidssone, eller null. */
function amendo_ukedagsregel_dato($ymd) {
    $ymd = trim((string) $ymd);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, wp_timezone());
    return ($d && $d->format('Y-m-d') === $ymd) ? $d : null;
}

/** Antall dager fristen er før hentedagen (1–7). */
function amendo_ukedagsregel_dager_for($ukedag, $frist_ukedag) {
    $nr = array_flip(AMENDO_HENTING_STENGT_UKEDAGER);
    return (($nr[$ukedag] - $nr[$frist_ukedag]) % 7 + 7) % 7 ?: 7;
}

/** Fristen for henting på datoen, i butikkens tidssone, eller null uten frist. */
function amendo_ukedagsregel_frist(array $r, DateTimeImmutable $dato) {
    if ($r['frist_ukedag'] === '') return null;
    [$t, $m] = array_map('intval', explode(':', $r['frist_tid']));
    return $dato->modify('-' . amendo_ukedagsregel_dager_for($r['ukedag'], $r['frist_ukedag']) . ' days')->setTime($t, $m);
}

/** De tillatte kategoriene med alle underkategoriene. */
function amendo_ukedagsregel_tillatte(array $r) {
    $ut = [];
    foreach ($r['kategorier'] as $id) {
        $ut[] = $id;
        $barn = get_term_children($id, 'product_cat');
        if (is_array($barn)) foreach ($barn as $b) $ut[] = (int) $b;
    }
    $ut = array_values(array_unique($ut));
    sort($ut);
    return $ut;
}

/** Er produktet (varianter: forelderen) i en av kategoriene? */
function amendo_ukedagsregel_produkt_ok($produkt_id, array $tillatte) {
    $produkt_id = (int) $produkt_id;
    if ($produkt_id <= 0) return false;
    $p = wc_get_product($produkt_id);
    if (!$p) return false;
    if ($p->get_parent_id()) $produkt_id = $p->get_parent_id();
    return (bool) array_intersect(array_map('intval', wc_get_product_term_ids($produkt_id, 'product_cat')), $tillatte);
}

function amendo_ukedagsregel_kategorinavn(array $r) {
    $navn = [];
    foreach ($r['kategorier'] as $id) {
        $t = get_term($id, 'product_cat');
        if ($t && !is_wp_error($t)) $navn[] = wp_specialchars_decode($t->name, ENT_QUOTES);
    }
    return $navn;
}

/** «A», «A eller B», «A, B eller C». */
function amendo_ukedagsregel_liste(array $navn, $og = 'eller') {
    if (count($navn) < 2) return implode('', $navn);
    $siste = array_pop($navn);
    return implode(', ', $navn) . ' ' . $og . ' ' . $siste;
}

/**
 * Bryter henting på $ymd med disse produkt-ID-ene regelen? null = nei (også når
 * dagen ikke har regel eller datoen er ugyldig). Ellers
 * ['grunn' => 'frist', 'frist' => DateTimeImmutable] eller
 * ['grunn' => 'kategori', 'utenfor' => [indeksene i $produkter]].
 */
function amendo_ukedagsregel_brudd($ymd, array $produkter, $naa = null) {
    $r = amendo_ukedagsregel_for($ymd);
    if (!$r) return null;
    $naa = $naa ?? (int) apply_filters('amendo_henting_naa', time());
    $frist = amendo_ukedagsregel_frist($r, amendo_ukedagsregel_dato($ymd));
    if ($frist && $naa >= $frist->getTimestamp()) return ['grunn' => 'frist', 'frist' => $frist, 'regel' => $r];
    if ($r['kategorier']) {
        $tillatte = amendo_ukedagsregel_tillatte($r);
        $utenfor = [];
        foreach (array_values($produkter) as $i => $pid) if (!amendo_ukedagsregel_produkt_ok($pid, $tillatte)) $utenfor[] = $i;
        if ($utenfor) return ['grunn' => 'kategori', 'utenfor' => $utenfor, 'regel' => $r];
    }
    return null;
}

// ── Kassen ──────────────────────────────────────────────────────────────────

// Etter amendo_henting_stengt (prioritet 5): da er $ordre allerede en WP_Error.
add_filter('woocommerce_rest_pre_insert_shop_order_object', function($ordre, $request, $ny) {
    if (!$ny || !$ordre instanceof WC_Order) return $ordre;
    if ($ordre->get_meta('_leveringstype') === 'levering') return $ordre;
    $dato = (string) $ordre->get_meta('_hentedato');
    if ($dato === '' || amendo_henting_stengt($dato) !== null) return $ordre;
    $varer = array_values($ordre->get_items('line_item'));
    $brudd = amendo_ukedagsregel_brudd($dato, array_map(function($v) { return $v->get_variation_id() ?: $v->get_product_id(); }, $varer));
    if (!$brudd) return $ordre;

    $d = amendo_ukedagsregel_dato($dato);
    $dagen = AMENDO_HENTING_STENGT_UKEDAGER[(int) $d->format('N')] . ' ' . $d->format('d.m');
    $data = ['status' => 400, 'hentedato' => $dato, 'grunn' => $brudd['grunn']];
    if ($brudd['grunn'] === 'frist') {
        $f = $brudd['frist'];
        $data['frist'] = $f->format('c');
        $melding = sprintf('Fristen for å bestille til henting %s var %s %s kl. %s. Velg en annen dag.',
            $dagen, AMENDO_HENTING_STENGT_UKEDAGER[(int) $f->format('N')], $f->format('d.m'), $f->format('H:i'));
    } else {
        $data['varer'] = array_map(function($i) use ($varer) {
            return ['produkt_id' => (int) $varer[$i]->get_product_id(), 'variant_id' => (int) $varer[$i]->get_variation_id(), 'navn' => $varer[$i]->get_name()];
        }, $brudd['utenfor']);
        $data['tillatte_kategorier'] = amendo_ukedagsregel_kategorinavn($brudd['regel']);
        $melding = sprintf('Til henting %s kan du bare bestille %s. Fjern %s fra kurven, eller velg en annen dag.',
            $dagen, amendo_ukedagsregel_liste($data['tillatte_kategorier']) ?: 'enkelte varer',
            amendo_ukedagsregel_liste(array_column($data['varer'], 'navn'), 'og'));
    }
    return new WP_Error('amendo_henting_ukedagsregel', $melding, $data);
}, 6, 3);

// ── /leveringsregler ────────────────────────────────────────────────────────

/** `global.ukedagsregler` — se toppen. */
function amendo_henting_ukedagsregler_rest() {
    return array_map(function($r) {
        return [
            'ukedag' => $r['ukedag'],
            'kategorier' => array_map(function($id) {
                $t = get_term($id, 'product_cat');
                $ok = $t && !is_wp_error($t);
                return ['id' => $id, 'slug' => $ok ? $t->slug : null, 'navn' => $ok ? wp_specialchars_decode($t->name, ENT_QUOTES) : null];
            }, $r['kategorier']),
            'tillatte_kategori_ider' => amendo_ukedagsregel_tillatte($r),
            'frist' => $r['frist_ukedag'] === '' ? null : [
                'ukedag' => $r['frist_ukedag'],
                'tid' => $r['frist_tid'],
                'dager_for' => amendo_ukedagsregel_dager_for($r['ukedag'], $r['frist_ukedag']),
            ],
        ];
    }, amendo_henting_ukedagsregler());
}

// ── Lagring ─────────────────────────────────────────────────────────────────

/** Fra $_POST. Krever markøren og at delen er endret (includes/lagring.php). */
add_action('amendo_lagre_innstillinger', function(array $post) {
    $inn = $post['henting_ukedagsregler'] ?? null;
    if (!is_array($inn) || empty($inn['finnes']) || !amendo_lagring_skal_lagre($post, 'ukedagsregler')) return;
    $ut = amendo_henting_ukedagsregler_rens(is_array($inn['regler'] ?? null) ? array_values($inn['regler']) : []);
    update_option('amendo_henting_ukedagsregler', wp_json_encode($ut, JSON_UNESCAPED_UNICODE));
});

// ── Admin ───────────────────────────────────────────────────────────────────

/** Én kategori i en regel. */
function amendo_ukedagsregel_kategori($i, $id) {
    echo '<div class="ukedagsregel-kategori" style="display:flex;gap:6px;align-items:center;margin-bottom:6px;">';
    amendo_kategori_velger('henting_ukedagsregler[regler][' . $i . '][kategorier][]', $id, '— Velg kategori —', 'style="flex:1;"');
    echo '<button type="button" class="button ukedagsregel-fjern-kategori" aria-label="Fjern kategorien">×</button></div>';
}

/** Én regel. `$i` er et tall, eller «__i__» i malen. */
function amendo_ukedagsregel_rad($i, array $r) {
    $p = 'henting_ukedagsregler[regler][' . $i . ']';
    $dag_valg = function($name, $valgt, $tom) {
        echo '<select name="' . esc_attr($name) . '">';
        if ($tom !== null) echo '<option value="">' . esc_html($tom) . '</option>';
        foreach (AMENDO_HENTING_STENGT_UKEDAGER as $dag) {
            echo '<option value="' . esc_attr($dag) . '"' . selected($valgt, $dag, false) . '>' . esc_html(ucfirst($dag)) . '</option>';
        }
        echo '</select>';
    };
    ?>
    <div class="ukedagsregel-rad" data-nr="<?php echo esc_attr($i); ?>" style="border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin-bottom:10px;">
        <div class="amendo-grid-2">
            <div class="amendo-field">
                <label>Ukedag</label>
                <?php $dag_valg($p . '[ukedag]', $r['ukedag'] ?? 'søndag', null); ?>
            </div>
            <div class="amendo-field">
                <label>Bestillingsfrist (før dagen)</label>
                <div style="display:flex;gap:6px;">
                    <?php $dag_valg($p . '[frist_ukedag]', $r['frist_ukedag'] ?? '', '— Ingen frist —'); ?>
                    <input type="time" name="<?php echo esc_attr($p); ?>[frist_tid]" value="<?php echo esc_attr($r['frist_tid'] ?? ''); ?>">
                </div>
            </div>
        </div>
        <div class="amendo-field">
            <label>Tillatte kategorier (underkategorier teller med; ingen = alle)</label>
            <div class="ukedagsregel-kategorier">
                <?php foreach (($r['kategorier'] ?? []) ?: [0] as $id) amendo_ukedagsregel_kategori($i, $id); ?>
            </div>
            <button type="button" class="button ukedagsregel-ny-kategori">+ Kategori</button>
        </div>
        <button type="button" class="ukedagsregel-slett amendo-btn-danger">Slett regel</button>
    </div>
    <?php
}

/** Feltet i kortet Henting, rett under «Stengt for henting». */
function amendo_henting_ukedagsregler_felt() {
    ?>
    <div class="amendo-field" id="henting-ukedagsregler" data-amendo-del="ukedagsregler">
        <?php amendo_lagring_felt('ukedagsregler'); ?>
        <input type="hidden" name="henting_ukedagsregler[finnes]" value="1">
        <label>Egne regler for ukedager</label>
        <p class="field-help">En ukedag som bare kan brukes til henting når alle varene er i de tillatte kategoriene og bestillingsfristen ikke er passert, f.eks. søndag: bare Kaker, frist torsdag 13:00 (torsdagen før). Ellers er dagen stengt. Fristen er i butikkens tidssone; samme ukedag som dagen betyr uka før. Gjelder bare henting. «Stengt for henting» går foran. Kassen avviser en ordre som bryter regelen.</p>
        <div id="ukedagsregler-liste">
            <?php foreach (amendo_henting_ukedagsregler() as $i => $r) amendo_ukedagsregel_rad($i, $r); ?>
        </div>
        <button type="button" class="button" id="ukedagsregel-legg-til">+ Legg til regel</button>
        <template id="ukedagsregel-mal"><?php amendo_ukedagsregel_rad('__i__', []); ?></template>
        <template id="ukedagsregel-kategori-mal"><?php amendo_ukedagsregel_kategori('__i__', 0); ?></template>
    </div>
    <?php
}
