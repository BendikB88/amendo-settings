<?php
/**
 * Forsideinnholdet til headless-frontendene, lagret i Amendo-admin.
 *
 * Lagres som JSON i optionen `amendo_forside`. Feltnavnene er de SAMME som i
 * ACF-feltgruppen «Forside» (acf-fields.php), slik at frontendene kan lese
 * /amendo-settings/v1/side/forside uendret.
 *
 * ⚠⚠ BAKOVERKOMPATIBILITET — pluginen brukes av flere butikker:
 *
 * 1. Er `amendo_forside` TOM (aldri lagret, eller lagret med bare tomme
 *    felter), svarer /side/forside med ACF-feltene som før. Hele admin-skjemaet
 *    sendes ved HVER lagring, også fra andre faner — et tomt forsideskjema
 *    lagres da som en struktur med tomme verdier, og det skal fortsatt telle
 *    som «tom». Derfor `amendo_forside_har_innhold()`, ikke `!empty()`.
 *
 * 2. Har `amendo_forside` innhold, eier Amendo feltene i AMENDO_FORSIDE_FELTER,
 *    mens ACF-felter Amendo IKKE kjenner (lok_*, b2b_*, galleri_*, kategorier_*,
 *    produkter_*, omoss_stat_*, historie_* …) flettes inn fra ACF som før.
 *    Frontendene til Garçon og Aanerud leser slike felter — uten flettingen ville
 *    de seksjonene forsvunnet idet butikken lagret noe i Forside-fanen.
 */

if (!defined('ABSPATH')) exit;

/**
 * Feltene Amendo eier, og hvordan de saniteres.
 *   tekst → sanitize_text_field   lang → wp_kses_post
 *   url   → esc_url_raw           lenke → esc_url_raw, relative stier tillatt
 */
function amendo_forside_skjema() {
    return [
        'hero_merkelapp'     => 'tekst',
        'hero_tittel'        => 'tekst',
        'hero_tittel2'       => 'tekst',
        'hero_undertekst'    => 'lang',
        'hero_bilde'         => 'url',
        'hero_video'         => 'url',
        'hero_knapp1_tekst'  => 'tekst',
        'hero_knapp1_lenke'  => 'lenke',
        'hero_knapp2_tekst'  => 'tekst',
        'hero_knapp2_lenke'  => 'lenke',
        'marquee_elementer'  => ['tekst' => 'tekst'],
        'features_etikett'   => 'tekst',
        'features_tittel'    => 'tekst',
        // `ikon` er ikke i den nye oppgavens liste, men finnes i ACF-gruppen og
        // leses av eksisterende frontender. Uten den ville en flytting fra ACF
        // mistet ikonene.
        'features_kort'      => [
            'tittel'      => 'tekst',
            'tekst'       => 'lang',
            'bilde'       => 'url',
            'ikon'        => 'tekst',
            'lenke_tekst' => 'tekst',
            'lenke'       => 'lenke',
        ],
        'omoss_etikett'      => 'tekst',
        'omoss_tittel'       => 'tekst',
        'omoss_tekst'        => 'lang',
        'omoss_bilde'        => 'url',
        'omoss_knapp1_tekst' => 'tekst',
        'omoss_knapp1_lenke' => 'lenke',
        'omoss_knapp2_tekst' => 'tekst',
        'omoss_knapp2_lenke' => 'lenke',
        'ig_handle'          => 'instagram',
        'ig_tittel'          => 'tekst',
    ];
}

/** Maks rader i en liste. Samme tak som ACF-gruppen har (12 og 6), med litt slakk. */
function amendo_forside_maks_rader($felt) {
    return $felt === 'marquee_elementer' ? 12 : 8;
}

/**
 * En enkeltverdi, sanitert etter type.
 *
 * Tar også ACF-former: et bildefelt kan være en URL, et array med `url`, eller
 * en ID; et lenkefelt kan være et array med `url`.
 */
function amendo_forside_saniter_verdi($verdi, $type) {
    if (is_array($verdi)) {
        $verdi = $verdi['url'] ?? '';
    } elseif (is_numeric($verdi) && ($type === 'url')) {
        $verdi = wp_get_attachment_url((int) $verdi) ?: '';
    }
    if (!is_scalar($verdi) || $verdi === false) return '';
    $verdi = trim((string) $verdi);
    if ($verdi === '') return '';

    switch ($type) {
        case 'lang':
            return wp_kses_post($verdi);
        case 'url':
            return esc_url_raw($verdi, ['http', 'https']);
        case 'lenke':
            return amendo_saniter_lenke($verdi);
        case 'instagram':
            // «@butikk», «instagram.com/butikk/» og «butikk» → «butikk».
            $verdi = preg_replace('#^(https?://)?(www\.)?instagram\.com/#i', '', $verdi);
            $verdi = preg_replace('#[/?\#].*$#', '', ltrim($verdi, '@'));
            return preg_match('/^[A-Za-z0-9._]{1,30}$/', $verdi) ? $verdi : '';
        default:
            return sanitize_text_field($verdi);
    }
}

/**
 * En lenke fra et knappfelt. Relative stier er det vanlige («/produkter»).
 *
 * ⚠ `esc_url_raw('produkter')` gir «http://produkter» — en lenke til en vert
 * som ikke finnes. En sti uten skråstrek og uten domene får derfor «/» foran.
 * `javascript:` og andre protokoller utenfor lista blir tom streng.
 */
function amendo_saniter_lenke($verdi) {
    $verdi = trim((string) $verdi);
    if ($verdi === '') return '';
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $verdi)) {
        return esc_url_raw($verdi, ['http', 'https', 'mailto', 'tel']);
    }
    if (preg_match('#^[/\#?]#', $verdi)) {
        return esc_url_raw($verdi);
    }
    // «emmk.no/salg» er et domene; «salg» er en sti.
    if (preg_match('#^[^/\s]+\.[a-z]{2,}(/|$)#i', $verdi)) {
        return esc_url_raw('https://' . $verdi, ['https']);
    }
    return esc_url_raw('/' . $verdi);
}

/**
 * Hele forsidestrukturen, sanitert. Alle kjente nøkler er med (tomme som '' og
 * []), ukjente nøkler kastes. Liste-rader uten noen verdi fjernes.
 */
function amendo_forside_saniter($inn) {
    $inn = is_array($inn) ? $inn : [];
    $ut  = [];
    foreach (amendo_forside_skjema() as $felt => $type) {
        if (is_array($type)) {
            $rader = [];
            foreach ((is_array($inn[$felt] ?? null) ? $inn[$felt] : []) as $rad) {
                if (!is_array($rad)) continue;
                $ren = [];
                foreach ($type as $under => $undertype) {
                    $ren[$under] = amendo_forside_saniter_verdi($rad[$under] ?? '', $undertype);
                }
                if (implode('', $ren) !== '') $rader[] = $ren;
                if (count($rader) >= amendo_forside_maks_rader($felt)) break;
            }
            $ut[$felt] = $rader;
        } else {
            $ut[$felt] = amendo_forside_saniter_verdi($inn[$felt] ?? '', $type);
        }
    }
    return $ut;
}

/** Minst én verdi er fylt ut. Se punkt 1 øverst i fila. */
function amendo_forside_har_innhold($data) {
    if (!is_array($data)) return false;
    foreach (amendo_forside_skjema() as $felt => $type) {
        $v = $data[$felt] ?? '';
        if (is_array($v) ? count($v) > 0 : trim((string) $v) !== '') return true;
    }
    return false;
}

/** Det som er lagret i `amendo_forside`, eller null. */
function amendo_forside_hent() {
    $data = json_decode((string) get_option('amendo_forside', ''), true);
    return is_array($data) ? $data : null;
}

/**
 * Siden /side/{slug} leser ACF fra — samme oppslag som før: en publisert side
 * med den sluggen, og for «forside» som reserve siden satt som forside.
 */
function amendo_finn_side($slug) {
    $sider = get_posts(['name' => $slug, 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1]);
    if (empty($sider) && $slug === 'forside') {
        $forside_id = get_option('page_on_front');
        if ($forside_id) $sider = [get_post($forside_id)];
    }
    return (!empty($sider) && $sider[0]) ? $sider[0] : null;
}

/** ACF-feltene på en side, eller []. */
function amendo_acf_felter($side) {
    if (!$side || !function_exists('get_fields')) return [];
    $felter = get_fields($side->ID);
    return is_array($felter) ? $felter : [];
}

/**
 * Svaret på /side/forside når Amendo har innhold, ellers null (kalleren faller
 * da tilbake på ACF som før).
 */
function amendo_forside_rest_svar() {
    $amendo = amendo_forside_hent();
    if (!amendo_forside_har_innhold($amendo)) return null;

    $side = amendo_finn_side('forside');
    // ACF-felter Amendo ikke eier, flettes inn — se punkt 2 øverst i fila.
    $ekstra = array_diff_key(amendo_acf_felter($side), amendo_forside_skjema());

    return [
        'id'     => $side ? $side->ID : 0,
        'slug'   => 'forside',
        'tittel' => $side ? $side->post_title : 'Forside',
        'fields' => array_merge($ekstra, amendo_forside_saniter($amendo)),
    ];
}

/**
 * Det admin-skjemaet skal vise, og hvor det kommer fra:
 *   'amendo' → lagret her
 *   'acf'    → hentet fra ACF på forsiden, fordi Amendo er tom. Lagres skjemaet
 *              (fra HVILKEN SOM HELST fane — hele skjemaet sendes), flyttes
 *              innholdet hit. Frontenden viser det samme før og etter.
 *   'tom'    → ingen av delene
 */
function amendo_forside_for_skjema() {
    $amendo = amendo_forside_hent();
    if (amendo_forside_har_innhold($amendo)) {
        return [amendo_forside_saniter($amendo), 'amendo'];
    }
    $acf = amendo_forside_saniter(amendo_acf_felter(amendo_finn_side('forside')));
    if (amendo_forside_har_innhold($acf)) return [$acf, 'acf'];
    return [amendo_forside_saniter([]), 'tom'];
}

/** Lagrer fra $_POST (allerede wp_unslash-et av kalleren). */
function amendo_forside_lagre($post) {
    $data = amendo_forside_saniter($post['forside'] ?? []);
    update_option('amendo_forside', wp_json_encode($data), false);
}

// ── Admin-fanen ─────────────────────────────────────────────────────────────

/** Et tekstfelt i fanen. */
function amendo_forside_felt($navn, $etikett, $verdi, $placeholder = '', $type = 'text') {
    ?>
    <div class="amendo-field">
        <label for="forside-<?php echo esc_attr($navn); ?>"><?php echo esc_html($etikett); ?></label>
        <input type="<?php echo esc_attr($type); ?>" id="forside-<?php echo esc_attr($navn); ?>" name="forside[<?php echo esc_attr($navn); ?>]" value="<?php echo esc_attr($verdi); ?>" placeholder="<?php echo esc_attr($placeholder); ?>">
    </div>
    <?php
}

/** Et lengre tekstfelt. */
function amendo_forside_tekstomraade($navn, $etikett, $verdi, $rader = 3) {
    ?>
    <div class="amendo-field">
        <label for="forside-<?php echo esc_attr($navn); ?>"><?php echo esc_html($etikett); ?></label>
        <textarea id="forside-<?php echo esc_attr($navn); ?>" name="forside[<?php echo esc_attr($navn); ?>]" rows="<?php echo (int) $rader; ?>"><?php echo esc_textarea($verdi); ?></textarea>
    </div>
    <?php
}

/**
 * Et mediefelt: URL-feltet er synlig og redigerbart (en video kan ligge på en
 * CDN), og knappen fyller det fra mediebiblioteket.
 */
function amendo_forside_media($name, $etikett, $verdi, $medietype = 'image', $hjelp = '') {
    ?>
    <div class="amendo-field amendo-media" data-type="<?php echo esc_attr($medietype); ?>">
        <label><?php echo esc_html($etikett); ?></label>
        <div class="amendo-media-rad">
            <input type="url" class="amendo-media-url" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($verdi); ?>" placeholder="https://">
            <button type="button" class="amendo-btn-secondary amendo-velg-media">Velg fra mediebiblioteket</button>
            <button type="button" class="amendo-btn-danger amendo-fjern-media">Fjern</button>
        </div>
        <?php if ($medietype === 'image'): ?>
            <img class="amendo-media-forhandsvisning" src="<?php echo esc_url($verdi); ?>" alt="" <?php echo $verdi ? '' : 'hidden'; ?>>
        <?php endif; ?>
        <?php if ($hjelp): ?><p class="field-help"><?php echo esc_html($hjelp); ?></p><?php endif; ?>
    </div>
    <?php
}

/** Én rad i «Tillitsstripe». `$i` er et tall, eller «__i__» i malen. */
function amendo_forside_marquee_rad($i, $rad) {
    ?>
    <div class="meny-rad amendo-repeter-rad">
        <div class="meny-drag">⠿</div>
        <input type="text" name="forside[marquee_elementer][<?php echo esc_attr($i); ?>][tekst]" value="<?php echo esc_attr($rad['tekst'] ?? ''); ?>" placeholder="F.eks. Fri frakt over 999 kr">
        <button type="button" class="amendo-btn-danger amendo-slett-rad">Slett</button>
    </div>
    <?php
}

/** Ett feature-kort. */
function amendo_forside_kort_rad($i, $rad) {
    $p = 'forside[features_kort][' . $i . ']';
    ?>
    <div class="amendo-repeter-rad forside-kort">
        <div class="amendo-grid-2">
            <div class="amendo-field">
                <label>Tittel</label>
                <input type="text" name="<?php echo esc_attr($p); ?>[tittel]" value="<?php echo esc_attr($rad['tittel'] ?? ''); ?>">
            </div>
            <div class="amendo-field">
                <label>Ikon (valgfritt)</label>
                <input type="text" name="<?php echo esc_attr($p); ?>[ikon]" value="<?php echo esc_attr($rad['ikon'] ?? ''); ?>">
            </div>
        </div>
        <div class="amendo-field">
            <label>Tekst</label>
            <textarea name="<?php echo esc_attr($p); ?>[tekst]" rows="2"><?php echo esc_textarea($rad['tekst'] ?? ''); ?></textarea>
        </div>
        <?php amendo_forside_media($p . '[bilde]', 'Bilde', $rad['bilde'] ?? ''); ?>
        <div class="amendo-grid-2">
            <div class="amendo-field">
                <label>Lenketekst</label>
                <input type="text" name="<?php echo esc_attr($p); ?>[lenke_tekst]" value="<?php echo esc_attr($rad['lenke_tekst'] ?? ''); ?>" placeholder="Les mer">
            </div>
            <div class="amendo-field">
                <label>Lenke</label>
                <input type="text" name="<?php echo esc_attr($p); ?>[lenke]" value="<?php echo esc_attr($rad['lenke'] ?? ''); ?>" placeholder="/side">
            </div>
        </div>
        <button type="button" class="amendo-btn-danger amendo-slett-rad">Slett kort</button>
    </div>
    <?php
}

/** Hele fanen. Kalles inne i <form> i amendo_settings_page(). */
function amendo_forside_fane() {
    list($f, $kilde) = amendo_forside_for_skjema();
    ?>
    <div class="amendo-panel" id="tab-forside">
        <?php if ($kilde === 'acf'): ?>
            <div class="amendo-card amendo-info">
                <strong>Innholdet under er hentet fra ACF-feltene på forsiden.</strong>
                Nettsiden viser det samme som før. Når du lagrer — fra hvilken som helst fane — flyttes det hit,
                og ACF-feltene brukes ikke lenger for disse feltene.
            </div>
        <?php endif; ?>

        <div class="amendo-card">
            <h2>Hero</h2>
            <p class="amendo-desc">Toppen av forsiden. Uten bilde vises ingen hero.</p>
            <?php amendo_forside_felt('hero_merkelapp', 'Merkelapp', $f['hero_merkelapp'], 'Liten tekst over tittelen'); ?>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('hero_tittel', 'Tittel', $f['hero_tittel']); ?>
                <?php amendo_forside_felt('hero_tittel2', 'Tittel, linje 2', $f['hero_tittel2']); ?>
            </div>
            <?php amendo_forside_tekstomraade('hero_undertekst', 'Undertekst', $f['hero_undertekst'], 2); ?>
            <?php amendo_forside_media('forside[hero_bilde]', 'Bilde', $f['hero_bilde']); ?>
            <?php amendo_forside_media('forside[hero_video]', 'Video (valgfritt)', $f['hero_video'], 'video', 'MP4. Bildet over vises som plakat, og i stedet for videoen på treg linje og når kunden har bedt om redusert bevegelse.'); ?>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('hero_knapp1_tekst', 'Knapp 1 tekst', $f['hero_knapp1_tekst'], 'Handle nå'); ?>
                <?php amendo_forside_felt('hero_knapp1_lenke', 'Knapp 1 lenke', $f['hero_knapp1_lenke'], '/produkter'); ?>
            </div>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('hero_knapp2_tekst', 'Knapp 2 tekst', $f['hero_knapp2_tekst']); ?>
                <?php amendo_forside_felt('hero_knapp2_lenke', 'Knapp 2 lenke', $f['hero_knapp2_lenke'], '/side'); ?>
            </div>
        </div>

        <div class="amendo-card">
            <h2>Tillitsstripe</h2>
            <p class="amendo-desc">Korte punkter under heroen — frakt, bytte og retur, fysisk butikk. 3–4 er nok.</p>
            <div class="amendo-repeter" data-repeter="marquee_elementer">
                <div class="amendo-repeter-liste">
                    <?php foreach ($f['marquee_elementer'] as $i => $rad) amendo_forside_marquee_rad($i, $rad); ?>
                </div>
                <template class="amendo-repeter-mal"><?php amendo_forside_marquee_rad('__i__', []); ?></template>
                <button type="button" class="amendo-btn-secondary amendo-legg-til-rad" style="margin-top:12px">+ Legg til punkt</button>
            </div>
        </div>

        <div class="amendo-card">
            <h2>Features</h2>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('features_etikett', 'Etikett', $f['features_etikett']); ?>
                <?php amendo_forside_felt('features_tittel', 'Tittel', $f['features_tittel']); ?>
            </div>
            <div class="amendo-repeter" data-repeter="features_kort">
                <div class="amendo-repeter-liste">
                    <?php foreach ($f['features_kort'] as $i => $rad) amendo_forside_kort_rad($i, $rad); ?>
                </div>
                <template class="amendo-repeter-mal"><?php amendo_forside_kort_rad('__i__', []); ?></template>
                <button type="button" class="amendo-btn-secondary amendo-legg-til-rad" style="margin-top:12px">+ Legg til kort</button>
            </div>
        </div>

        <div class="amendo-card">
            <h2>Om oss</h2>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('omoss_etikett', 'Etikett', $f['omoss_etikett']); ?>
                <?php amendo_forside_felt('omoss_tittel', 'Tittel', $f['omoss_tittel']); ?>
            </div>
            <?php amendo_forside_tekstomraade('omoss_tekst', 'Tekst', $f['omoss_tekst'], 4); ?>
            <?php amendo_forside_media('forside[omoss_bilde]', 'Bilde', $f['omoss_bilde']); ?>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('omoss_knapp1_tekst', 'Knapp 1 tekst', $f['omoss_knapp1_tekst'], 'Les mer om oss'); ?>
                <?php amendo_forside_felt('omoss_knapp1_lenke', 'Knapp 1 lenke', $f['omoss_knapp1_lenke'], '/om-oss'); ?>
            </div>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('omoss_knapp2_tekst', 'Knapp 2 tekst', $f['omoss_knapp2_tekst']); ?>
                <?php amendo_forside_felt('omoss_knapp2_lenke', 'Knapp 2 lenke', $f['omoss_knapp2_lenke'], '/kontakt'); ?>
            </div>
        </div>

        <div class="amendo-card">
            <h2>Instagram</h2>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('ig_handle', 'Brukernavn (uten @)', $f['ig_handle']); ?>
                <?php amendo_forside_felt('ig_tittel', 'Overskrift', $f['ig_tittel'], 'Følg oss på Instagram'); ?>
            </div>
        </div>
    </div>
    <?php
}
