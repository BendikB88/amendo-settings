<?php
/**
 * Kundegruppene (1.4.17). Del av B2B-modulen: lastes bare når B2B er på.
 *
 * ÉN tabell i B2B → Kundegrupper erstatter tre ting som før sa hver sin del:
 * «Kundegrupper fra Tellix» (skrivebeskyttet), «Faktura og rettigheter per
 * kundegruppe» (amendo_kundegruppe_config, med ID-felt for hånd) og
 * Helsebygget-lista (amendo_helsebygget_grupper, «4:40, 5:40 …»).
 *
 * - Én rad per aktiv Tellix-gruppe, automatisk, med navnet fra Tellix.
 * - Rabatten (sats og kategorisatser) settes i Tellix og vises bare her.
 *   ⚠⚠ Helsebygget har INGEN faste satser lenger: rabatt/telefon og
 *   rabatt/kunde svarer med Tellix-satsene, i samme form som nettbutikk
 *   ({prosent, kategorier}). Fasit fra garcon.no: gruppe 4, 6 og 7 har 0 % i
 *   grunnsats og 40 % på 187, 162 og 262; 5 har det samme pluss 249; 19 har
 *   50 % i grunnsats og 50 % på 221.
 * - «Hvor gjelder rabatten» per gruppe: nettbutikk | helsebygget | ingen.
 *   Bare administrator kan endre den (penger).
 * - Faktura leser og skriver GATEWAYENS innstilling
 *   (woocommerce_amendo-invoice_settings → enabled_customer_group_<id>), så
 *   wp-admin og nettsiden aldri er uenige.
 * - Bedriftsvisning (b2b) og levering lagres i `amendo_kundegrupper`:
 *       { "4": { gjelder: "helsebygget", b2b: 0, levering: 0 }, … }
 *
 * ── Migrering ─────────────────────────────────────────────────────────────
 * For en gruppe som ikke står i `amendo_kundegrupper` (aldri lagret, eller ny
 * i Tellix), regnes verdiene ut fra det gamle:
 *   gjelder   «Bare Helsebygget» når gruppen står i Helsebygget-lista. Er lista
 *             aldri lagret: når amendo_kundegruppe_config har helsebygget = 1
 *             eller amendo-store-groups binder den til «helsebygget» (den trygge
 *             retningen). Ellers «Nettbutikken».
 *   faktura   gatewayens innstilling; mangler den for gruppen,
 *             amendo_kundegruppe_config.
 *   b2b, levering   amendo_kundegruppe_config.
 * De gamle optionene røres ikke.
 *
 * ── /settings ─────────────────────────────────────────────────────────────
 * `kundegruppe_config` har én rad per gruppe med de gamle feltene (faktura,
 * b2b, helsebygget, helsebygget_rabatt) og de nye (gjelder, levering, rabatt).
 * ⚠⚠ Den er `{}` til tabellen er LAGRET første gang (med mindre den gamle
 * tabellen hadde rader). Frontenden bytter fra miljøvariablene sine så snart
 * den har én rad, og migreringen kjenner ikke miljøvariablene (garcon.no har
 * bedriftsvisningen bare der). Butikken må se over radene og lagre selv.
 * ⚠ `helsebygget_rabatt` er grunnsatsen fra Tellix (0 for NAV-gruppene).
 * Kategorisatsene står i `rabatt.kategorier`.
 * ⚠ Ingen gruppenavn: /settings er offentlig.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_KG_GJELDER = [
    'nettbutikk'  => 'Nettbutikken',
    'helsebygget' => 'Bare Helsebygget',
    'ingen'       => 'Ingen rabatt på nett',
];
const AMENDO_KG_FAKTURA_OPTION = 'woocommerce_amendo-invoice_settings';

// ── Kildene ─────────────────────────────────────────────────────────────────

/** Det som er lagret i tabellen, eller null når den aldri er lagret. */
function amendo_kg_lagret() {
    $raa = get_option('amendo_kundegrupper', null);
    if ($raa === null || $raa === false) return null;
    $data = json_decode((string) $raa, true);
    return is_array($data) ? $data : [];
}

/** Den gamle tabellen (≤ 1.4.16): [id => [navn, faktura, b2b, helsebygget, helsebygget_rabatt]]. */
function amendo_kg_gammel() {
    $data = json_decode((string) get_option('amendo_kundegruppe_config', ''), true);
    return is_array($data) ? $data : [];
}

/** Gatewayens innstillinger (fakturagatewayen i Amendo Gateway). */
function amendo_kg_faktura_innstillinger() {
    $f = get_option(AMENDO_KG_FAKTURA_OPTION, []);
    return is_array($f) ? $f : [];
}

/** Er tabellen klar til bruk (lagret, eller den gamle Helsebygget-lista lagret)? */
function amendo_kg_satt_opp() {
    return amendo_kg_lagret() !== null || amendo_helsebygget_lagret() !== null;
}

/** Hvor gjelder rabatten for en gruppe som ikke er lagret? Se toppen. */
function amendo_kg_gjelder_migrert($id) {
    $liste = amendo_helsebygget_lagret();
    if ($liste !== null) return array_key_exists((int) $id, $liste) ? 'helsebygget' : 'nettbutikk';
    $gammel = amendo_kg_gammel()[(string) $id] ?? [];
    if (!empty($gammel['helsebygget']) || in_array((int) $id, amendo_helsebygget_fra_store_groups(), true)) return 'helsebygget';
    return 'nettbutikk';
}

/** Alt som gjelder for én gruppe: [gjelder, faktura, b2b, levering] (bool for de tre siste). */
function amendo_kg_innstilling($id) {
    $id = (string) (int) $id;
    $lagret = amendo_kg_lagret()[$id] ?? null;
    $gammel = amendo_kg_gammel()[$id] ?? [];
    $f = amendo_kg_faktura_innstillinger();
    $fnokkel = 'enabled_customer_group_' . $id;
    $gjelder = is_array($lagret) && isset(AMENDO_KG_GJELDER[$lagret['gjelder'] ?? '']) ? $lagret['gjelder'] : amendo_kg_gjelder_migrert($id);
    return [
        'gjelder'  => $gjelder,
        'faktura'  => array_key_exists($fnokkel, $f) ? $f[$fnokkel] === 'yes' : !empty($gammel['faktura']),
        'b2b'      => is_array($lagret) ? !empty($lagret['b2b']) : !empty($gammel['b2b']),
        'levering' => is_array($lagret) ? !empty($lagret['levering']) : !empty($gammel['levering']),
    ];
}

/**
 * Antall kunder per gruppe: fra vår kopi av Tellix-kundelista når den finnes
 * (b2b-tellix.php), ellers WordPress-brukerne med `_tellixGroupId`.
 * @return array{antall: array<int,int>, kilde: string}
 */
function amendo_kg_antall() {
    $liste = function_exists('amendo_tellix_liste_les') ? amendo_tellix_liste_les() : null;
    $ut = [];
    if ($liste) {
        foreach ($liste['kunder'] as $k) if ($k['gruppe_id'] > 0) $ut[$k['gruppe_id']] = ($ut[$k['gruppe_id']] ?? 0) + 1;
        return ['antall' => $ut, 'kilde' => 'tellix'];
    }
    global $wpdb;
    $rader = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_value AS id, COUNT(*) AS antall FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' GROUP BY meta_value",
        '_tellixGroupId'
    ));
    foreach ($rader ?: [] as $r) if ((int) $r->id > 0) $ut[(int) $r->id] = (int) $r->antall;
    return ['antall' => $ut, 'kilde' => 'wordpress'];
}

/** Tellix-rabatten som {prosent, kategorier}, kategoriene som objekt. */
function amendo_kg_rabatt(?array $g) {
    if (!$g) return null;
    $kat = [];
    foreach ($g['kategorier'] as $term => $pst) $kat[(string) $term] = $pst;
    return ['prosent' => $g['prosent'], 'kategorier' => (object) $kat];
}

/**
 * Rabatten som setninger, én per sats: ['10 % på alt', '15 % på Ringer'],
 * ['40 % på Påsmurt, Bakevarer, Smoothie']. En kategori med samme sats som
 * grunnsatsen nevnes ikke («50 % på alt»).
 */
function amendo_kg_rabatt_tekst(?array $g) {
    if (!$g) return [];
    $deler = [];
    if ($g['prosent'] > 0) $deler[] = amendo_tellix_pst($g['prosent']) . ' på alt';
    $per_sats = [];
    foreach ($g['kategorier'] as $term => $pst) {
        if ($pst <= 0 || abs($pst - $g['prosent']) < 0.001) continue;
        $t = get_term((int) $term, 'product_cat');
        $per_sats[(string) $pst][] = $t instanceof WP_Term ? wp_specialchars_decode($t->name, ENT_QUOTES) : 'kategori #' . (int) $term;
    }
    uksort($per_sats, function($a, $b) { return (float) $b <=> (float) $a; });
    foreach ($per_sats as $pst => $navn) $deler[] = amendo_tellix_pst((float) $pst) . ' på ' . implode(', ', $navn);
    return $deler;
}

// ── Svaret til rabattrutene ─────────────────────────────────────────────────

/**
 * {gruppe_id, gruppe_navn, nettbutikk, helsebygget}. Rabatten fra Tellix havner
 * i feltet «Hvor gjelder rabatten» sier — aldri begge.
 */
function amendo_tellix_svar($gruppe_id) {
    $tom = ['gruppe_id' => null, 'gruppe_navn' => null, 'nettbutikk' => null, 'helsebygget' => null];
    $gruppe_id = (int) $gruppe_id;
    if ($gruppe_id <= 0 || !amendo_tellix_tilgjengelig()) return $tom;
    $g = amendo_tellix_grupper()[$gruppe_id] ?? null;
    $gjelder = amendo_kg_innstilling($gruppe_id)['gjelder'];
    return [
        'gruppe_id'   => $gruppe_id,
        'gruppe_navn' => $g ? $g['navn'] : null,
        'nettbutikk'  => $gjelder === 'nettbutikk' ? amendo_kg_rabatt($g) : null,
        'helsebygget' => $gjelder === 'helsebygget' ? amendo_kg_rabatt($g) : null,
    ];
}

/**
 * 503 når verken tabellen eller den gamle Helsebygget-lista er lagret. Ellers
 * kunne en NAV-gruppe fått rabatten i hele nettbutikken den dagen B2B ble slått
 * på. Koden er den samme som i 1.4.14–1.4.16.
 */
function amendo_kg_klar_eller_feil() {
    if (amendo_kg_satt_opp()) return null;
    return new WP_REST_Response([
        'code'    => 'amendo_helsebygget_ikke_satt',
        'message' => 'Kundegruppene er ikke lagret i Amendo → B2B → Kundegrupper.',
    ], 503);
}

// ── /settings ───────────────────────────────────────────────────────────────

add_filter('amendo_settings_svar', function($svar) {
    $config = [];
    // ⚠ {} til tabellen er lagret — se toppen.
    if (amendo_kg_lagret() !== null || amendo_kg_gammel()) {
        $grupper = amendo_tellix_grupper();
        $ider = array_merge(array_keys($grupper), array_keys(amendo_kg_lagret() ?? []), array_keys(amendo_kg_gammel()), array_keys(amendo_helsebygget_lagret() ?? []));
        $ider = array_values(array_unique(array_filter(array_map('intval', $ider), function($id) { return $id > 0; })));
        sort($ider);
        foreach ($ider as $id) {
            $i = amendo_kg_innstilling($id);
            $g = $grupper[$id] ?? null;
            $helse = $i['gjelder'] === 'helsebygget';
            $config[(string) $id] = [
                'faktura'            => (int) $i['faktura'],
                'b2b'                => (int) $i['b2b'],
                'helsebygget'        => (int) $helse,
                'helsebygget_rabatt' => $helse && $g ? (int) round($g['prosent']) : 0,
                'gjelder'            => $i['gjelder'],
                'levering'           => (int) $i['levering'],
                'rabatt'             => amendo_kg_rabatt($g),
            ];
        }
    }
    // Objekt, også når det er tomt: `{}`, ikke `[]`.
    $svar['kundegruppe_config'] = (object) $config;
    return $svar;
});

// ── Lagring ─────────────────────────────────────────────────────────────────

add_filter('amendo_lagring_deler', function($deler) { return $deler + ['kundegrupper' => 'amendo_kundegrupper']; });
add_filter('amendo_lagring_navn', function($navn) { return $navn + ['kundegrupper' => 'B2B-kundegrupper']; });

/**
 * Fra $_POST. Krever markøren `kundegrupper[finnes]` og at delen er endret
 * (includes/lagring.php). Bare radene som er sendt endres; andre grupper står.
 * «Hvor gjelder rabatten» tas bare fra en administrator.
 */
add_action('amendo_lagre_innstillinger', function(array $post) {
    $inn = $post['kundegrupper'] ?? null;
    if (!is_array($inn) || empty($inn['finnes']) || !amendo_lagring_skal_lagre($post, 'kundegrupper')) return;
    $admin  = current_user_can('manage_options');
    $lagret = amendo_kg_lagret() ?? [];
    $f      = amendo_kg_faktura_innstillinger();
    $f_foer = $f;
    foreach (is_array($inn['rader'] ?? null) ? $inn['rader'] : [] as $id => $rad) {
        if (!is_array($rad) || empty($rad['med']) || !preg_match('/^\d{1,9}$/', (string) $id) || (int) $id <= 0) continue;
        $id = (string) (int) $id;
        $naa = amendo_kg_innstilling($id);
        $gjelder = $admin && isset(AMENDO_KG_GJELDER[$rad['gjelder'] ?? '']) ? $rad['gjelder'] : $naa['gjelder'];
        $lagret[$id] = ['gjelder' => $gjelder, 'b2b' => (int) !empty($rad['b2b']), 'levering' => (int) !empty($rad['levering'])];
        $f['enabled_customer_group_' . $id] = !empty($rad['faktura']) ? 'yes' : 'no';
    }
    ksort($lagret, SORT_NATURAL);
    update_option('amendo_kundegrupper', wp_json_encode((object) $lagret), false);
    // Gatewayens innstilling: bare når noe er endret, og aldri opprettet bare
    // for å si «nei» (butikker uten fakturagatewayen).
    $finnes = get_option(AMENDO_KG_FAKTURA_OPTION, null) !== null;
    if ($f !== $f_foer && ($finnes || in_array('yes', $f, true))) {
        update_option(AMENDO_KG_FAKTURA_OPTION, $f);
    }
});

// ── Admin ───────────────────────────────────────────────────────────────────

/** Én rad. */
function amendo_kg_rad(array $g, $antall, $admin) {
    $i = amendo_kg_innstilling($g['id']);
    $p = 'kundegrupper[rader][' . (int) $g['id'] . ']';
    $tekst = amendo_kg_rabatt_tekst($g);
    ?>
    <tr class="kundegruppe-rad<?php echo $antall ? '' : ' kundegruppe-tom'; ?>" data-gruppe="<?php echo (int) $g['id']; ?>"<?php echo $antall ? '' : ' hidden'; ?>>
        <td class="kundegruppe-navn"><strong><?php echo esc_html($g['navn'] !== '' ? $g['navn'] : 'Gruppe ' . $g['id']); ?></strong><input type="hidden" name="<?php echo esc_attr($p); ?>[med]" value="1"></td>
        <td class="kundegruppe-antall"><?php echo (int) $antall; ?></td>
        <td class="kundegruppe-rabatt"><?php echo $tekst ? implode('<br>', array_map('esc_html', $tekst)) : 'Ingen rabatt'; ?><br><span class="field-help">Settes i Tellix</span></td>
        <td class="kundegruppe-gjelder">
            <?php if ($admin): ?>
                <select name="<?php echo esc_attr($p); ?>[gjelder]" aria-label="Hvor gjelder rabatten">
                    <?php foreach (AMENDO_KG_GJELDER as $verdi => $etikett): ?>
                        <option value="<?php echo esc_attr($verdi); ?>" <?php selected($i['gjelder'], $verdi); ?>><?php echo esc_html($etikett); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <?php echo esc_html(AMENDO_KG_GJELDER[$i['gjelder']]); ?>
            <?php endif; ?>
        </td>
        <td><input type="checkbox" name="<?php echo esc_attr($p); ?>[faktura]" value="1" <?php checked($i['faktura']); ?> aria-label="Faktura"></td>
        <td><input type="checkbox" name="<?php echo esc_attr($p); ?>[b2b]" value="1" <?php checked($i['b2b']); ?> aria-label="Bedriftsvisning"></td>
        <td><input type="checkbox" name="<?php echo esc_attr($p); ?>[levering]" value="1" <?php checked($i['levering']); ?> aria-label="Levering"></td>
    </tr>
    <?php
}

/** Kortet i B2B → Kundegrupper. Kalles inne i hovedskjemaet. */
function amendo_kg_kort() {
    $tilgjengelig = amendo_tellix_tilgjengelig();
    $grupper = amendo_tellix_grupper();
    uasort($grupper, function($a, $b) { return strnatcasecmp($a['navn'], $b['navn']); });
    $antall = amendo_kg_antall();
    $admin = current_user_can('manage_options');
    $tomme = count(array_filter($grupper, function($g) use ($antall) { return empty($antall['antall'][$g['id']]); }));
    $info = $tilgjengelig ? amendo_tellix_listeinfo() : null;
    ?>
    <div class="amendo-card" id="b2b-kundegrupper" data-amendo-del="kundegrupper">
        <?php amendo_lagring_felt('kundegrupper'); ?>
        <h2>Kundegrupper</h2>
        <p class="amendo-desc">Gruppene kommer fra Tellix. Her bestemmer du hva hver gruppe får på nettsiden.</p>
        <?php if ($tilgjengelig && amendo_tellix_liste_avkortet()): ?>
            <p class="amendo-advarsel amendo-tellix-avkortet">⚠ Tellix-lista kan være avkortet: hev AMENDO_CUSTOMER_SYNC_SIZE. amendo-core har <?php echo (int) $info['antall']; ?> kunder, og grensen er <?php echo (int) $info['grense']; ?>. Kunder etter grensen blir aldri funnet i Tellix.</p>
        <?php endif; ?>
        <?php if (!$tilgjengelig): ?>
            <p class="amendo-advarsel amendo-tellix-mangler">⚠ Fant ikke amendo-core (\Amendo\Customers::get_tellix_discounts og \Amendo\TellixAPI::call_API). Ingen kunder får rabatt fra Tellix før den er på plass.</p>
        <?php elseif (!$grupper): ?>
            <p class="field-help">Tellix har ingen aktive kundegrupper.</p>
        <?php else: ?>
            <?php if (amendo_kg_lagret() === null): ?>
                <p class="amendo-advarsel amendo-kundegrupper-ikke-lagret">⚠ Tabellen er ikke lagret ennå. Valgene under er hentet fra de gamle innstillingene. Se over hver rad — særlig Bedriftsvisning og Levering — og lagre. Til da bruker nettsiden de gamle innstillingene sine.</p>
            <?php endif; ?>
            <input type="hidden" name="kundegrupper[finnes]" value="1">
            <table class="amendo-tabell kundegruppe-tabell">
                <thead><tr>
                    <th>Kundegruppe<span class="kolonne-hjelp">Navnet i Tellix. Nye grupper kommer av seg selv.</span></th>
                    <th>Kunder<span class="kolonne-hjelp"><?php echo $antall['kilde'] === 'tellix' ? 'Kunder i gruppen i Tellix.' : 'Kunder i gruppen på nettsiden.'; ?></span></th>
                    <th>Rabatt<span class="kolonne-hjelp">Rabatten gruppen har i Tellix. Endres i Tellix.</span></th>
                    <th>Hvor gjelder rabatten<span class="kolonne-hjelp">Nettbutikken, bare på Helsebygget-siden, eller ingen rabatt på nett (bare i butikken).<?php echo $admin ? '' : ' Bare administrator kan endre.'; ?></span></th>
                    <th>Faktura<span class="kolonne-hjelp">Kan betale med faktura.</span></th>
                    <th>Bedriftsvisning<span class="kolonne-hjelp">Ser bedriftsvisningen på Min side.</span></th>
                    <th>Levering<span class="kolonne-hjelp">Kan velge levering i kassen.</span></th>
                </tr></thead>
                <tbody class="kundegruppe-liste">
                <?php foreach ($grupper as $g) amendo_kg_rad($g, $antall['antall'][$g['id']] ?? 0, $admin); ?>
                </tbody>
            </table>
            <?php if ($tomme): ?>
                <button type="button" class="amendo-btn-secondary amendo-bare-visning" id="vis-tomme-kundegrupper" style="margin-top:12px">Vis grupper uten kunder (<?php echo (int) $tomme; ?>)</button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}
