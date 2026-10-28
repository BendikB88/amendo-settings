<?php
/**
 * Felles mal for frontendene (1.4.23).
 *
 * garcon-frontend er nå én mal for alle butikkene (Garçon, Aanerud, senere
 * emmk). Det som skiller butikkene, skal komme herfra og fra miljøvariabler,
 * aldri fra et butikknavn i koden. Planen: garcon-b2b/docs/mal-plan.md, del 7.
 *
 * ⚠⚠ TOMT FELT = SOM I DAG. Frontenden leser feltene under når de finnes og
 * har en verdi. Mangler de, eller er de tomme, gjør garcon.no akkurat som før
 * 1.4.23. Ingenting her endrer oppførselen før noen fyller det ut. Derfor er
 * standarden alltid tom ('', null eller []), aldri Garçons verdi.
 *
 * I /settings (alltid med, også når modulene er av, fordi det er
 * innstillinger og ikke funksjoner):
 *     butikk.logo_mork      URL til logoen for LYS bakgrunn (headeren).
 *                           butikk.logo er den lyse varianten.
 *     design.tema           nøkkelen til frontendens tema (garcon, bakeri …)
 *     levering.produkt_id   leveringsproduktet (Garçon 9747); prisen kommer
 *                           fra produktet
 *     levering.pris         prisen til visning i kassen (Garçon 200)
 *     henting.gamle_felter  [{dato, tid}]: Checkout Field Editor-parene fra
 *                           den gamle kassen, som frontenden LESER (aldri
 *                           skriver) på eldre ordrer
 *     min_side.lenker       [{tittel, url}]: lenker fra Min side til
 *                           WordPress-fanene, full adresse på WP-verten
 *     sporing               {gtm, klaviyo, facebook}: bare OFFENTLIGE ID-er
 * `helsebygget.navn` kommer fra modulen helsebygget.
 *
 * I /leveringsregler:
 *     avdelinger[].inngang       'nettbutikk' (standard) | 'helsebygget', for
 *                                ALLE avdelinger
 *     levering.min_timer         tidligste levering = senere av første
 *                                produksjonsdag og nå + min_timer
 *     levering.luke_minutter     lengden på leveringsluken
 *     levering.krever_gruppe     true = levering bare for grupper med
 *                                «Levering», false = alle
 * De tre er null til de settes.
 *
 * ⚠ sporing tar ALDRI hemmelige nøkler. Klaviyo har en privat nøkkel
 * («pk_…») som gir full tilgang til kontoen; den avvises og lagres ikke.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_MAL_LISTE_MAKS = 20;

// ── Lesing ──────────────────────────────────────────────────────────────────

function amendo_mal_json($option) {
    $v = json_decode((string) get_option($option, ''), true);
    return is_array($v) ? $v : [];
}

/** Heltall i [min, maks] eller null for tomt/ugyldig. */
function amendo_mal_tall($verdi, $min, $maks) {
    if ($verdi === null || $verdi === '' || !is_numeric($verdi)) return null;
    $t = (int) $verdi;
    return ($t >= $min && $t <= $maks) ? $t : null;
}

/** 'nettbutikk' | 'helsebygget'. Alt annet, og mangler, er 'nettbutikk'. */
function amendo_inngang_rens($verdi) {
    return $verdi === 'helsebygget' ? 'helsebygget' : 'nettbutikk';
}

function amendo_avdeling_inngang($avd) {
    return amendo_inngang_rens(is_array($avd) ? ($avd['inngang'] ?? null) : null);
}

/** Meta-nøkkel fra Checkout Field Editor: små bokstaver, tall, _ og -. */
function amendo_mal_metanokkel($v) {
    $v = strtolower(trim((string) $v));
    return preg_match('/^[a-z0-9_\-]{1,64}$/', $v) ? $v : '';
}

function amendo_henting_gamle_felter() {
    $ut = [];
    foreach (amendo_mal_json('amendo_henting_gamle_felter') as $p) {
        $dato = amendo_mal_metanokkel($p['dato'] ?? '');
        $tid  = amendo_mal_metanokkel($p['tid'] ?? '');
        if ($dato !== '' && $tid !== '') $ut[] = ['dato' => $dato, 'tid' => $tid];
    }
    return $ut;
}

function amendo_min_side_lenker() {
    $ut = [];
    foreach (amendo_mal_json('amendo_min_side_lenker') as $l) {
        $tittel = sanitize_text_field((string) ($l['tittel'] ?? ''));
        $url    = amendo_mal_url($l['url'] ?? '');
        if ($tittel !== '' && $url !== '') $ut[] = ['tittel' => $tittel, 'url' => $url];
    }
    return $ut;
}

/** Bare full http(s)-adresse. */
function amendo_mal_url($v) {
    $v = trim((string) $v);
    if (!preg_match('#^https?://[^\s/]+#i', $v)) return '';
    return esc_url_raw($v, ['http', 'https']);
}

/** Bare de offentlige ID-ene, i formen de har. Alt annet blir ''. */
function amendo_sporing_rens(array $s) {
    $gtm = strtoupper(trim((string) ($s['gtm'] ?? '')));
    $kl  = trim((string) ($s['klaviyo'] ?? ''));
    $fb  = preg_replace('/\s+/', '', (string) ($s['facebook'] ?? ''));
    return [
        'gtm'      => preg_match('/^GTM-[A-Z0-9]{4,12}$/', $gtm) ? $gtm : '',
        // Klaviyos offentlige nøkkel («site ID») er seks tegn. Den private
        // begynner med «pk_» og stoppes av mønsteret.
        'klaviyo'  => preg_match('/^[A-Za-z0-9]{6}$/', $kl) ? $kl : '',
        'facebook' => preg_match('/^\d{8,20}$/', $fb) ? $fb : '',
    ];
}

function amendo_sporing() {
    return amendo_sporing_rens(amendo_mal_json('amendo_sporing'));
}

/** Leveringsproduktet, eller null. */
function amendo_levering_produkt_id() {
    $id = amendo_mal_tall(get_option('amendo_levering_produkt_id', ''), 1, PHP_INT_MAX);
    return $id;
}

function amendo_levering_pris() {
    $p = get_option('amendo_levering_pris', '');
    if ($p === '' || !is_numeric($p) || (float) $p < 0) return null;
    $f = round((float) $p, 2);
    return floor($f) == $f ? (int) $f : $f;
}

/** null (ikke satt), true eller false. */
function amendo_levering_krever_gruppe() {
    $v = (string) get_option('amendo_levering_krever_gruppe', '');
    return $v === '' ? null : $v === '1';
}

// ── Til REST ────────────────────────────────────────────────────────────────

/** Feltene i /settings. Kalles fra amendo_get_settings() (ikke et filter: kjernen). */
function amendo_mal_settings(array $svar) {
    $svar['butikk']['logo_mork'] = (string) get_option('amendo_butikk_logo_mork', '');
    $svar['design']['tema']      = (string) get_option('amendo_design_tema', '');
    $svar['levering'] = [
        'produkt_id' => amendo_levering_produkt_id(),
        'pris'       => amendo_levering_pris(),
    ];
    $svar['henting']  = ['gamle_felter' => amendo_henting_gamle_felter()];
    $svar['min_side'] = ['lenker' => amendo_min_side_lenker()];
    $svar['sporing']  = amendo_sporing();
    $svar['moduler_kjente'] = amendo_moduler_kjente();
    return $svar;
}

/** De nye feltene under `levering` i /leveringsregler. */
function amendo_mal_levering_regler() {
    return [
        'min_timer'     => amendo_mal_tall(get_option('amendo_levering_min_timer', ''), 0, 168),
        'luke_minutter' => amendo_mal_tall(get_option('amendo_levering_luke_minutter', ''), 5, 240),
        'krever_gruppe' => amendo_levering_krever_gruppe(),
    ];
}

// ── Lagring ─────────────────────────────────────────────────────────────────

/** Tekstfelt med «a, b» per linje → [[a, b], …]. */
function amendo_mal_linjer($tekst, $skille) {
    $ut = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $tekst) as $linje) {
        if (trim($linje) === '') continue;
        $deler = array_map('trim', explode($skille, $linje, 2));
        $ut[] = [$deler[0], $deler[1] ?? ''];
    }
    return $ut;
}

/**
 * Fra $_POST (allerede wp_unslash-et). Bare med markøren `mal_finnes`, så et
 * skjema uten feltene aldri tømmer dem.
 */
function amendo_mal_lagre(array $post) {
    if (!isset($post['mal_finnes'])) return;

    update_option('amendo_butikk_logo_mork', amendo_mal_url($post['butikk_logo_mork'] ?? ''));
    update_option('amendo_design_tema', sanitize_key((string) ($post['design_tema'] ?? '')));

    $id = amendo_mal_tall($post['levering_produkt_id'] ?? '', 1, PHP_INT_MAX);
    update_option('amendo_levering_produkt_id', $id === null ? '' : (string) $id);
    $pris = trim(str_replace(',', '.', (string) ($post['levering_pris'] ?? '')));
    update_option('amendo_levering_pris', is_numeric($pris) && (float) $pris >= 0 ? (string) round((float) $pris, 2) : '');
    $t = amendo_mal_tall($post['levering_min_timer'] ?? '', 0, 168);
    update_option('amendo_levering_min_timer', $t === null ? '' : (string) $t);
    $l = amendo_mal_tall($post['levering_luke_minutter'] ?? '', 5, 240);
    update_option('amendo_levering_luke_minutter', $l === null ? '' : (string) $l);
    $k = (string) ($post['levering_krever_gruppe'] ?? '');
    update_option('amendo_levering_krever_gruppe', in_array($k, ['1', '0'], true) ? $k : '');

    $par = [];
    foreach (amendo_mal_linjer($post['henting_gamle_felter'] ?? '', ',') as [$dato, $tid]) {
        $dato = amendo_mal_metanokkel($dato);
        $tid  = amendo_mal_metanokkel($tid);
        if ($dato !== '' && $tid !== '' && count($par) < AMENDO_MAL_LISTE_MAKS) $par[] = ['dato' => $dato, 'tid' => $tid];
    }
    update_option('amendo_henting_gamle_felter', wp_json_encode($par));

    $lenker = [];
    foreach (amendo_mal_linjer($post['min_side_lenker'] ?? '', '|') as [$tittel, $url]) {
        $tittel = sanitize_text_field($tittel);
        $url    = amendo_mal_url($url);
        if ($tittel !== '' && $url !== '' && count($lenker) < AMENDO_MAL_LISTE_MAKS) $lenker[] = ['tittel' => $tittel, 'url' => $url];
    }
    update_option('amendo_min_side_lenker', wp_json_encode($lenker));

    update_option('amendo_sporing', wp_json_encode(amendo_sporing_rens([
        'gtm'      => $post['sporing_gtm'] ?? '',
        'klaviyo'  => $post['sporing_klaviyo'] ?? '',
        'facebook' => $post['sporing_facebook'] ?? '',
    ])));
}

// ── Admin ───────────────────────────────────────────────────────────────────
// Alle kortene er inne i hovedskjemaet. Markøren står i Butikk-kortet, som
// alltid er på siden.

/** Logoen for lys bakgrunn, under den vanlige logoen. Samme opplasting som avdelingsbildene i admin.js. */
function amendo_mal_logo_felt() {
    $mork = (string) get_option('amendo_butikk_logo_mork', '');
    ?>
    <input type="hidden" name="mal_finnes" value="1">
    <div class="amendo-field">
        <label>Logo for lys bakgrunn</label>
        <div class="amendo-logo-upload">
            <?php if ($mork): ?>
                <img class="avd-bilde-preview" src="<?php echo esc_url($mork); ?>" alt="" style="max-height:60px;max-width:200px;border:1px solid #e5e7eb;border-radius:6px;padding:4px;">
            <?php else: ?>
                <div class="avd-bilde-preview logo-placeholder">Ingen logo valgt</div>
            <?php endif; ?>
            <input type="hidden" name="butikk_logo_mork" class="avd-bilde-input" value="<?php echo esc_attr($mork); ?>">
            <button type="button" class="amendo-btn-secondary upload-avd-bilde">Last opp logo</button>
            <?php if ($mork): ?><button type="button" class="amendo-btn-danger fjern-avd-bilde">Fjern</button><?php endif; ?>
        </div>
        <p class="field-help">Den mørke logoen, til headeren og andre lyse flater. Logoen over er den lyse varianten. Tom = nettsiden velger selv som før.</p>
    </div>
    <?php
}

function amendo_mal_butikk_kort() {
    $lenker = implode("\n", array_map(function($l) { return $l['tittel'] . ' | ' . $l['url']; }, amendo_min_side_lenker()));
    $s = amendo_sporing();
    ?>
    <div class="amendo-card" id="mal-min-side">
        <h2>Min side</h2>
        <div class="amendo-field">
            <label for="min_side_lenker">Lenker til WordPress</label>
            <textarea id="min_side_lenker" name="min_side_lenker" rows="3" placeholder="Bursdagskaker | https://butikk.no/min-konto/ab-bursdagskaker/"><?php echo esc_textarea($lenker); ?></textarea>
            <p class="field-help">Én lenke per linje: tittel | full adresse. Vises på Min side og åpnes på WordPress-siden, der kunden må logge inn på nytt.</p>
        </div>
    </div>

    <div class="amendo-card" id="mal-sporing">
        <h2>Sporing</h2>
        <p class="amendo-desc">Brukes av nettsiden når modulen Sporing er på, og bare når kunden har samtykket. Bare offentlige ID-er — aldri hemmelige nøkler.</p>
        <div class="amendo-grid-2">
            <div class="amendo-field">
                <label for="sporing_gtm">Google Tag Manager</label>
                <input type="text" id="sporing_gtm" name="sporing_gtm" value="<?php echo esc_attr($s['gtm']); ?>" placeholder="GTM-XXXXXXX">
            </div>
            <div class="amendo-field">
                <label for="sporing_facebook">Facebook-piksel</label>
                <input type="text" id="sporing_facebook" name="sporing_facebook" value="<?php echo esc_attr($s['facebook']); ?>" placeholder="123456789012345" inputmode="numeric">
            </div>
        </div>
        <div class="amendo-field">
            <label for="sporing_klaviyo">Klaviyo (offentlig nøkkel / site ID)</label>
            <input type="text" id="sporing_klaviyo" name="sporing_klaviyo" value="<?php echo esc_attr($s['klaviyo']); ?>" placeholder="AbC123" maxlength="6">
            <p class="field-help">Seks tegn. Den private nøkkelen («pk_…») skal aldri hit, og lagres ikke. Et felt i feil form lagres som tomt.</p>
        </div>
    </div>
    <?php
}

function amendo_mal_tema_felt() {
    ?>
    <div class="amendo-card">
        <h2>Tema</h2>
        <div class="amendo-field">
            <label for="design_tema">Tema på nettsiden</label>
            <input type="text" id="design_tema" name="design_tema" value="<?php echo esc_attr(get_option('amendo_design_tema', '')); ?>" placeholder="garcon">
            <p class="field-help">Nøkkelen til et av nettsidens temaer (f.eks. garcon eller bakeri). Tom = nettsidens eget valg.</p>
        </div>
    </div>
    <?php
}

/** I Levering-kortet. */
function amendo_mal_levering_felt() {
    $kg = (string) get_option('amendo_levering_krever_gruppe', '');
    ?>
    <div class="amendo-grid-2">
        <div class="amendo-field">
            <label for="levering_produkt_id">Leveringsprodukt (ID)</label>
            <input type="number" id="levering_produkt_id" name="levering_produkt_id" value="<?php echo esc_attr(get_option('amendo_levering_produkt_id', '')); ?>" min="1" placeholder="9747">
            <p class="field-help">Varen som legges i ordren ved levering med fast pris (modulen Levering). Prisen tas fra varen.</p>
        </div>
        <div class="amendo-field">
            <label for="levering_pris">Pris til visning (kr)</label>
            <input type="text" id="levering_pris" name="levering_pris" value="<?php echo esc_attr(get_option('amendo_levering_pris', '')); ?>" inputmode="decimal" placeholder="200">
            <p class="field-help">Det kassen viser før ordren lages. Bør være lik prisen på varen.</p>
        </div>
    </div>
    <div class="amendo-grid-2">
        <div class="amendo-field">
            <label for="levering_min_timer">Tidligst levering (timer fram)</label>
            <input type="number" id="levering_min_timer" name="levering_min_timer" value="<?php echo esc_attr(get_option('amendo_levering_min_timer', '')); ?>" min="0" max="168" placeholder="Som før">
            <p class="field-help">Tidligste levering er den seneste av første produksjonsdag og nå + så mange timer. Tom = som før.</p>
        </div>
        <div class="amendo-field">
            <label for="levering_luke_minutter">Leveringsluke (minutter)</label>
            <input type="number" id="levering_luke_minutter" name="levering_luke_minutter" value="<?php echo esc_attr(get_option('amendo_levering_luke_minutter', '')); ?>" min="5" max="240" placeholder="Som før">
            <p class="field-help">Lengden på hver luke kunden kan velge (Wolt: 15). Tom = som før.</p>
        </div>
    </div>
    <div class="amendo-field">
        <label for="levering_krever_gruppe">Hvem kan få levering</label>
        <select id="levering_krever_gruppe" name="levering_krever_gruppe">
            <option value="" <?php selected($kg, ''); ?>>Som før (ikke valgt)</option>
            <option value="1" <?php selected($kg, '1'); ?>>Bare kundegrupper med «Levering» krysset av</option>
            <option value="0" <?php selected($kg, '0'); ?>>Alle kunder</option>
        </select>
    </div>
    <?php
}

/** I Henting-kortet. */
function amendo_mal_henting_felt() {
    $par = implode("\n", array_map(function($p) { return $p['dato'] . ', ' . $p['tid']; }, amendo_henting_gamle_felter()));
    ?>
    <div class="amendo-field">
        <label for="henting_gamle_felter">Felt fra den gamle kassen</label>
        <textarea id="henting_gamle_felter" name="henting_gamle_felter" rows="4" placeholder="leveringsdato, leveringstidspunkt"><?php echo esc_textarea($par); ?></textarea>
        <p class="field-help">Ett par per linje: datofelt, tidsfelt (Checkout Field Editor). Nettsiden leser dem bare, for å vise hentetid på eldre ordrer — den skriver aldri til dem.</p>
    </div>
    <?php
}

/** Inngangen på én avdeling. `$i` er et tall, eller «__i__» i malen admin.js bruker. */
function amendo_avdeling_inngang_felt($i, $avd) {
    $verdi = amendo_avdeling_inngang($avd);
    $hb_paa = amendo_modul_aktiv('helsebygget');
    ?>
    <div class="amendo-field avdeling-inngang">
        <label>Inngang</label>
        <select name="avdelinger[<?php echo esc_attr($i); ?>][inngang]">
            <option value="nettbutikk" <?php selected($verdi, 'nettbutikk'); ?>>Nettbutikken</option>
            <?php if ($hb_paa || $verdi === 'helsebygget'): ?>
            <option value="helsebygget" <?php selected($verdi, 'helsebygget'); ?>><?php echo esc_html($hb_paa ? 'Helsebygget' : 'Helsebygget (modulen er av)'); ?></option>
            <?php endif; ?>
        </select>
        <p class="field-help">Hvilken inngang på nettsiden avdelingen er hentested for.</p>
    </div>
    <?php
}
