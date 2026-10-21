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
 *
 * 3. FELT FOR FELT (1.4.13): feltene i amendo_forside_skjema_utvidet() —
 *    kategorifliser, historie, lokasjoner, galleri, tall-merket på om oss,
 *    overskriften på nyhetene og nyhetsbrevtekstene — vinner BARE når
 *    Amendo-verdien har innhold. Ellers står ACF-verdien (rå, som før).
 *    Flere av dem (lok_*, galleri_*, historie_*, kategorier_*, produkter_*,
 *    omoss_stat_*) leses allerede fra ACF av Garçon. Ville de vært med i
 *    amendo_forside_skjema(), hadde de forsvunnet første gang fanen ble lagret.
 *    De teller heller IKKE i amendo_forside_har_innhold(): å fylle ut én av
 *    dem skal ikke slå av ACF for heroen.
 *
 * 4. `seksjoner` (1.4.13): rekkefølgen og «Skjul» per seksjon. Heroen er
 *    låst øverst og er ikke med. Står lista i standardrekkefølgen uten noe
 *    skjult, sendes den ikke — en lagring fra en annen fane endrer da ingenting.
 */

if (!defined('ABSPATH')) exit;

/**
 * Feltene Amendo eier, og hvordan de saniteres.
 *   tekst → sanitize_text_field   lang → wp_kses_post
 *   url   → esc_url_raw           lenke → esc_url_raw, relative stier tillatt
 *   bilde → som url, pluss {felt}_id (vedlegget) — se amendo_forside_bilde_id()
 */
function amendo_forside_skjema() {
    return [
        'hero_merkelapp'     => 'tekst',
        'hero_tittel'        => 'tekst',
        'hero_tittel2'       => 'tekst',
        'hero_undertekst'    => 'lang',
        'hero_bilde'         => 'bilde',
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
            'bilde'       => 'bilde',
            'ikon'        => 'tekst',
            'lenke_tekst' => 'tekst',
            'lenke'       => 'lenke',
        ],
        'omoss_etikett'      => 'tekst',
        'omoss_tittel'       => 'tekst',
        'omoss_tekst'        => 'lang',
        'omoss_bilde'        => 'bilde',
        'omoss_knapp1_tekst' => 'tekst',
        'omoss_knapp1_lenke' => 'lenke',
        'omoss_knapp2_tekst' => 'tekst',
        'omoss_knapp2_lenke' => 'lenke',
        'ig_handle'          => 'instagram',
        'ig_tittel'          => 'tekst',
    ];
}

/**
 * Feltene fra 1.4.13, med ACF-reserve FELT FOR FELT — se punkt 3 øverst i fila.
 * Samme typer som amendo_forside_skjema(), pluss:
 *   kategori → term_id i product_cat (int), ellers tom
 *   bryter   → '1' eller ''
 * Feltnavnene er Garçons ACF-navn der de finnes.
 */
function amendo_forside_skjema_utvidet() {
    return [
        'produkter_etikett'    => 'tekst',
        'produkter_tittel'     => 'tekst',
        'kategorier_etikett'   => 'tekst',
        'kategorier_tittel'    => 'tekst',
        'kategorifliser'       => [
            'term_id'     => 'kategori',
            'tittel'      => 'tekst',
            'tekst'       => 'lang',
            'bilde'       => 'bilde',
            'lenke_tekst' => 'tekst',
        ],
        'historie_etikett'     => 'tekst',
        'historie_tittel'      => 'tekst',
        'historie_tekst'       => 'lang',
        'historie_bilde'       => 'bilde',
        'historie_stat_tall'   => 'tekst',
        'historie_stat_tekst'  => 'tekst',
        'historie_knapp_tekst' => 'tekst',
        'historie_knapp_lenke' => 'lenke',
        'omoss_stat_tall'      => 'tekst',
        'omoss_stat_etikett'   => 'tekst',
        'lok_etikett'          => 'tekst',
        'lok_tittel'           => 'tekst',
        'lok_vis'              => 'bryter',
        'lok_lenke_tekst'      => 'tekst',
        'galleri_etikett'      => 'tekst',
        'galleri_tittel'       => 'tekst',
        'galleri_bilder'       => [
            'bilde' => 'bilde',
            'alt'   => 'tekst',
            'lenke' => 'lenke',
        ],
        'nyhetsbrev_tittel'    => 'tekst',
        'nyhetsbrev_tekst'     => 'lang',
    ];
}

/**
 * Seksjonene under heroen, i standardrekkefølgen. ⚠ ID-ene er det frontendene
 * ser — de kan ikke endres. Heroen er låst øverst og står ikke her: den har
 * sidens hovedoverskrift og er det største elementet som lastes.
 */
function amendo_forside_seksjon_ider() {
    return ['tillit', 'nyheter', 'kategorifliser', 'features', 'historie', 'omoss', 'lokasjoner', 'galleri', 'instagram'];
}

/** Navnene i admin. */
function amendo_forside_seksjon_navn($id) {
    $navn = [
        'tillit' => 'Tillitsstripe', 'nyheter' => 'Nyheter', 'kategorifliser' => 'Kategorifliser',
        'features' => 'Features', 'historie' => 'Historie', 'omoss' => 'Om oss',
        'lokasjoner' => 'Lokasjoner', 'galleri' => 'Galleri', 'instagram' => 'Instagram',
    ];
    return $navn[$id] ?? $id;
}

/**
 * `seksjoner` renset: bare kjente ID-er, ingen duplikater, ingen «hero».
 * Tar [{id, skjult}] (skjemaet) og ['id', …] (enkel liste). Seksjoner som
 * mangler, legges til sist i standardrekkefølgen — synlige.
 */
function amendo_forside_seksjoner_saniter($inn) {
    $kjente = amendo_forside_seksjon_ider();
    $ut = [];
    $sett = [];
    foreach ((is_array($inn) ? $inn : []) as $rad) {
        $id = is_array($rad) ? ($rad['id'] ?? '') : $rad;
        if (!is_string($id) || !in_array($id, $kjente, true) || isset($sett[$id])) continue;
        $skjult = is_array($rad) ? ($rad['skjult'] ?? '') : '';
        $ut[] = ['id' => $id, 'skjult' => in_array($skjult, [true, 1, '1', 'on', 'true'], true)];
        $sett[$id] = true;
    }
    foreach ($kjente as $id) {
        if (!isset($sett[$id])) $ut[] = ['id' => $id, 'skjult' => false];
    }
    return $ut;
}

/** Standardrekkefølgen uten noe skjult — sendes ikke (punkt 4 øverst). */
function amendo_forside_seksjoner_er_standard($liste) {
    $standard = array_map(function($id) { return ['id' => $id, 'skjult' => false]; }, amendo_forside_seksjon_ider());
    return $liste === $standard;
}

/** Maks rader i en liste. Samme tak som ACF-gruppen har (12 og 6), med litt slakk. */
function amendo_forside_maks_rader($felt) {
    if ($felt === 'marquee_elementer') return 12;
    if ($felt === 'kategorifliser') return 6;
    return 8;
}

/** Underfeltet en rad ikke kan være uten (en flis uten kategori, et galleribilde uten bilde). */
function amendo_forside_rad_paakrevd($felt) {
    $krav = ['kategorifliser' => 'term_id', 'galleri_bilder' => 'bilde'];
    return $krav[$felt] ?? null;
}

/**
 * En enkeltverdi, sanitert etter type.
 *
 * Tar også ACF-former: et bildefelt kan være en URL, et array med `url`, eller
 * en ID; et lenkefelt kan være et array med `url`.
 */
function amendo_forside_saniter_verdi($verdi, $type) {
    if ($type === 'kategori') {
        // ACF «Taxonomy» kan gi et term-objekt, et array eller en ID.
        if ($verdi instanceof WP_Term) $verdi = $verdi->term_id;
        elseif (is_array($verdi)) $verdi = $verdi['term_id'] ?? $verdi['id'] ?? '';
        if (!is_numeric($verdi) || (int) $verdi <= 0) return '';
        $term = get_term((int) $verdi, 'product_cat');
        return ($term instanceof WP_Term) ? (int) $term->term_id : '';
    }
    if ($type === 'bryter') {
        return in_array($verdi, [true, 1, '1', 'on', 'true'], true) ? '1' : '';
    }
    if (is_array($verdi)) {
        $verdi = $verdi['url'] ?? '';
    } elseif (is_numeric($verdi) && ($type === 'url' || $type === 'bilde')) {
        $verdi = wp_get_attachment_url((int) $verdi) ?: '';
    }
    if (!is_scalar($verdi) || $verdi === false) return '';
    $verdi = trim((string) $verdi);
    if ($verdi === '') return '';

    switch ($type) {
        case 'lang':
            return wp_kses_post($verdi);
        case 'url':
        case 'bilde':
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
 *
 * Bildefelt får {felt}_id ved siden av seg. `$lagring` = true når verdiene
 * kommer fra admin-skjemaet; se amendo_forside_bilde_id().
 */
function amendo_forside_saniter($inn, $lagring = false, $skjema = null) {
    $inn = is_array($inn) ? $inn : [];
    $ut  = [];
    foreach (($skjema ?? amendo_forside_skjema()) as $felt => $type) {
        if (is_array($type)) {
            $rader = [];
            foreach ((is_array($inn[$felt] ?? null) ? $inn[$felt] : []) as $rad) {
                if (!is_array($rad)) continue;
                $ren = [];
                foreach ($type as $under => $undertype) {
                    $ren[$under] = amendo_forside_saniter_verdi($rad[$under] ?? '', $undertype);
                }
                if (implode('', $ren) === '') continue;
                $krav = amendo_forside_rad_paakrevd($felt);
                if ($krav !== null && ($ren[$krav] ?? '') === '') continue;
                foreach ($type as $under => $undertype) {
                    if ($undertype === 'bilde') {
                        $ren[$under . '_id'] = amendo_forside_bilde_id($ren[$under], $rad[$under] ?? '', $rad[$under . '_id'] ?? null, $lagring);
                    }
                }
                $rader[] = $ren;
                if (count($rader) >= amendo_forside_maks_rader($felt)) break;
            }
            $ut[$felt] = $rader;
        } else {
            $ut[$felt] = amendo_forside_saniter_verdi($inn[$felt] ?? '', $type);
            if ($type === 'bilde') {
                $ut[$felt . '_id'] = amendo_forside_bilde_id($ut[$felt], $inn[$felt] ?? '', $inn[$felt . '_id'] ?? null, $lagring);
            }
        }
    }
    return $ut;
}

/**
 * Vedlegg-ID for et bildefelt, eller 0.
 *
 * - Lagring fra skjemaet: ID-en mediebiblioteket satte brukes bare hvis den
 *   fortsatt hører til URL-en (ellers er URL-en endret for hånd). Ellers slås
 *   URL-en opp med attachment_url_to_postid; 0 når den ikke er fra biblioteket.
 * - Lesing: en lagret ID (også 0 = «slått opp, ikke funnet») brukes som den
 *   er. Mangler nøkkelen — verdier lagret før 1.4.5 — slås URL-en opp.
 *   ACF-verdier (array med ID, eller bare ID) gir ID-en direkte.
 */
function amendo_forside_bilde_id($url, $raa, $gitt, $lagring) {
    if ($url === '') return 0;
    if ($gitt === null || $gitt === '') {
        if (is_array($raa) && !empty($raa['ID'] ?? $raa['id'] ?? null)) {
            $gitt = $raa['ID'] ?? $raa['id'];
        } elseif (is_numeric($raa)) {
            $gitt = $raa;
        }
    }
    if (!$lagring && $gitt !== null && $gitt !== '' && is_numeric($gitt)) {
        return max(0, (int) $gitt);
    }
    if (is_numeric($gitt) && (int) $gitt > 0 && wp_get_attachment_url((int) $gitt) === $url) {
        return (int) $gitt;
    }
    return (int) attachment_url_to_postid($url);
}

/** Lagret forside mangler {felt}_id for et bilde (lagret før 1.4.5). */
function amendo_forside_mangler_bilde_id($data) {
    foreach (amendo_forside_skjema() as $felt => $type) {
        if ($type === 'bilde' && ($data[$felt] ?? '') !== '' && !array_key_exists($felt . '_id', $data)) return true;
        if (!is_array($type) || !in_array('bilde', $type, true)) continue;
        foreach ((is_array($data[$felt] ?? null) ? $data[$felt] : []) as $rad) {
            foreach ($type as $under => $undertype) {
                if ($undertype === 'bilde' && is_array($rad) && ($rad[$under] ?? '') !== '' && !array_key_exists($under . '_id', $rad)) return true;
            }
        }
    }
    return false;
}

/**
 * {felt}_bredde og {felt}_hoyde fra vedleggets metadata, rett etter {felt}_id.
 * Utelates når bildet ikke er i mediebiblioteket eller mangler mål.
 */
function amendo_forside_med_dimensjoner(array $felter, $skjema = null) {
    $legg_til = function(array $rad, $felt) {
        $id = (int) ($rad[$felt . '_id'] ?? 0);
        $meta = $id ? wp_get_attachment_metadata($id) : false;
        if (!is_array($meta) || empty($meta['width']) || empty($meta['height'])) return $rad;
        $ut = [];
        foreach ($rad as $k => $v) {
            $ut[$k] = $v;
            if ($k === $felt . '_id') {
                $ut[$felt . '_bredde'] = (int) $meta['width'];
                $ut[$felt . '_hoyde']  = (int) $meta['height'];
            }
        }
        return $ut;
    };
    foreach (($skjema ?? amendo_forside_skjema()) as $felt => $type) {
        if ($type === 'bilde') {
            $felter = $legg_til($felter, $felt);
        } elseif (is_array($type) && is_array($felter[$felt] ?? null)) {
            foreach ($type as $under => $undertype) {
                if ($undertype !== 'bilde') continue;
                foreach ($felter[$felt] as $i => $rad) $felter[$felt][$i] = $legg_til($rad, $under);
            }
        }
    }
    return $felter;
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
 * Lagret forside, sanitert, eller null når den er tom. Mangler bilde-ID-er
 * (lagret før 1.4.5), slås de opp nå og lagres, så det bare skjer én gang.
 */
function amendo_forside_hent_ren() {
    $data = amendo_forside_hent();
    if (!amendo_forside_har_innhold($data)) return null;
    $ren = amendo_forside_saniter($data);
    if (amendo_forside_mangler_bilde_id($data)) {
        // array_merge: feltene fra 1.4.13 og `seksjoner` står i samme option.
        update_option('amendo_forside', wp_json_encode(array_merge($data, $ren)), false);
    }
    return $ren;
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
    $amendo  = amendo_forside_hent_ren();
    $utvidet = amendo_forside_utvidet_med_innhold();
    // Ingenting i Amendo: svaret er ACF, nøyaktig som før (kalleren).
    if ($amendo === null && !$utvidet) return null;

    $side = amendo_finn_side('forside');
    $acf  = amendo_acf_felter($side);
    $felter = $amendo === null
        ? $acf
        // ACF-felter Amendo ikke eier, flettes inn — se punkt 2 øverst i fila.
        : array_merge(array_diff_key($acf, amendo_forside_skjema()), amendo_forside_med_dimensjoner($amendo));

    return [
        'id'     => $side ? $side->ID : 0,
        'slug'   => 'forside',
        'tittel' => $side ? $side->post_title : 'Forside',
        // Felt for felt: bare Amendo-verdier med innhold erstatter ACF — punkt 3.
        'fields' => array_merge($felter, $utvidet),
    ];
}

/** Feltene fra 1.4.13 slik de er lagret, renset (tomme som '' og []). */
function amendo_forside_utvidet_lagret() {
    return amendo_forside_saniter(amendo_forside_hent() ?: [], false, amendo_forside_skjema_utvidet());
}

/** `seksjoner` slik den er lagret, eller null når ingenting er lagret. */
function amendo_forside_seksjoner_lagret() {
    $data = amendo_forside_hent();
    return is_array($data['seksjoner'] ?? null) ? amendo_forside_seksjoner_saniter($data['seksjoner']) : null;
}

/**
 * Bare feltene fra 1.4.13 som HAR innhold (bildefelt med _id og mål), pluss
 * `seksjoner` når den avviker fra standarden. Tomme felter er ikke med, så
 * ACF-verdien står.
 */
function amendo_forside_utvidet_med_innhold() {
    $skjema = amendo_forside_skjema_utvidet();
    $lagret = amendo_forside_utvidet_lagret();
    $ut = [];
    foreach ($skjema as $felt => $type) {
        $v = $lagret[$felt] ?? '';
        if (is_array($v) ? count($v) === 0 : $v === '') continue;
        $ut[$felt] = $v;
        if ($type === 'bilde') $ut[$felt . '_id'] = $lagret[$felt . '_id'] ?? 0;
    }
    $ut = amendo_forside_med_dimensjoner($ut, $skjema);
    $seksjoner = amendo_forside_seksjoner_lagret();
    if ($seksjoner !== null && !amendo_forside_seksjoner_er_standard($seksjoner)) {
        $ut['seksjoner'] = $seksjoner;
    }
    return $ut;
}

/**
 * Feltene fra 1.4.13 til admin-skjemaet: Amendo-verdien når den har innhold,
 * ellers ACF-verdien (renset) — så skjemaet viser det frontenden viser.
 */
function amendo_forside_utvidet_for_skjema() {
    $skjema = amendo_forside_skjema_utvidet();
    $lagret = amendo_forside_utvidet_lagret();
    $acf = null;
    foreach ($skjema as $felt => $type) {
        $v = $lagret[$felt] ?? '';
        if (is_array($v) ? count($v) > 0 : $v !== '') continue;
        if ($acf === null) $acf = amendo_forside_saniter(amendo_acf_felter(amendo_finn_side('forside')), false, $skjema);
        $lagret[$felt] = $acf[$felt];
        if ($type === 'bilde') $lagret[$felt . '_id'] = $acf[$felt . '_id'];
    }
    $lagret['seksjoner'] = amendo_forside_seksjoner_lagret() ?? amendo_forside_seksjoner_saniter([]);
    return $lagret;
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
    $amendo = amendo_forside_hent_ren();
    if ($amendo !== null) {
        return [$amendo, 'amendo'];
    }
    $acf = amendo_forside_saniter(amendo_acf_felter(amendo_finn_side('forside')));
    if (amendo_forside_har_innhold($acf)) return [$acf, 'acf'];
    return [amendo_forside_saniter([]), 'tom'];
}

/** Lagrer fra $_POST (allerede wp_unslash-et av kalleren). */
function amendo_forside_lagre($post) {
    $inn  = $post['forside'] ?? [];
    $data = amendo_forside_saniter($inn, true)
        + amendo_forside_saniter($inn, true, amendo_forside_skjema_utvidet())
        + ['seksjoner' => amendo_forside_seksjoner_saniter($inn['seksjoner'] ?? [])];
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
function amendo_forside_media($name, $etikett, $verdi, $medietype = 'image', $hjelp = '', $vedlegg_id = 0) {
    ?>
    <div class="amendo-field amendo-media" data-type="<?php echo esc_attr($medietype); ?>">
        <label><?php echo esc_html($etikett); ?></label>
        <div class="amendo-media-rad">
            <input type="url" class="amendo-media-url" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($verdi); ?>" placeholder="https://">
            <?php if ($medietype === 'image'): // forside[hero_bilde] → forside[hero_bilde_id] ?>
                <input type="hidden" class="amendo-media-id" name="<?php echo esc_attr(preg_replace('/\]$/', '_id]', $name)); ?>" value="<?php echo $vedlegg_id ? (int) $vedlegg_id : ''; ?>">
            <?php endif; ?>
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
        <?php amendo_forside_media($p . '[bilde]', 'Bilde', $rad['bilde'] ?? '', 'image', '', $rad['bilde_id'] ?? 0); ?>
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

/** Produktkategoriene til velgeren: [term_id => «Forelder › Barn»], sortert på navnet. */
function amendo_forside_kategorivalg() {
    $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    if (is_wp_error($terms)) return [];
    $per_id = [];
    foreach ($terms as $t) $per_id[$t->term_id] = $t;
    $ut = [];
    foreach ($terms as $t) {
        $navn = [wp_specialchars_decode($t->name, ENT_QUOTES)];
        for ($p = $t->parent, $i = 0; $p && isset($per_id[$p]) && $i < 10; $p = $per_id[$p]->parent, $i++) {
            array_unshift($navn, wp_specialchars_decode($per_id[$p]->name, ENT_QUOTES));
        }
        $ut[$t->term_id] = implode(' › ', $navn);
    }
    asort($ut, SORT_NATURAL | SORT_FLAG_CASE);
    return $ut;
}

/** Én kategoriflis. */
function amendo_forside_flis_rad($i, $rad, $kategorier) {
    $p = 'forside[kategorifliser][' . $i . ']';
    $valgt = (int) ($rad['term_id'] ?? 0);
    ?>
    <div class="amendo-repeter-rad forside-kort">
        <div class="amendo-grid-2">
            <div class="amendo-field">
                <label>Kategori</label>
                <select name="<?php echo esc_attr($p); ?>[term_id]">
                    <option value="">— Velg kategori —</option>
                    <?php foreach ($kategorier as $id => $navn): ?>
                        <option value="<?php echo (int) $id; ?>" <?php selected($valgt, (int) $id); ?>><?php echo esc_html($navn); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="amendo-field">
                <label>Tittel (tom = kategoriens navn)</label>
                <input type="text" name="<?php echo esc_attr($p); ?>[tittel]" value="<?php echo esc_attr($rad['tittel'] ?? ''); ?>">
            </div>
        </div>
        <div class="amendo-field">
            <label>Tekst</label>
            <textarea name="<?php echo esc_attr($p); ?>[tekst]" rows="2"><?php echo esc_textarea($rad['tekst'] ?? ''); ?></textarea>
        </div>
        <?php amendo_forside_media($p . '[bilde]', 'Bilde (tomt = kategoriens bilde)', $rad['bilde'] ?? '', 'image', '', $rad['bilde_id'] ?? 0); ?>
        <div class="amendo-field">
            <label>Lenketekst</label>
            <input type="text" name="<?php echo esc_attr($p); ?>[lenke_tekst]" value="<?php echo esc_attr($rad['lenke_tekst'] ?? ''); ?>" placeholder="Se kategorien">
        </div>
        <button type="button" class="amendo-btn-danger amendo-slett-rad">Slett flis</button>
    </div>
    <?php
}

/** Ett galleribilde. */
function amendo_forside_galleri_rad($i, $rad) {
    $p = 'forside[galleri_bilder][' . $i . ']';
    ?>
    <div class="amendo-repeter-rad forside-kort">
        <?php amendo_forside_media($p . '[bilde]', 'Bilde', $rad['bilde'] ?? '', 'image', '', $rad['bilde_id'] ?? 0); ?>
        <div class="amendo-grid-2">
            <div class="amendo-field">
                <label>Alt-tekst (hva bildet viser)</label>
                <input type="text" name="<?php echo esc_attr($p); ?>[alt]" value="<?php echo esc_attr($rad['alt'] ?? ''); ?>">
            </div>
            <div class="amendo-field">
                <label>Lenke (valgfritt)</label>
                <input type="text" name="<?php echo esc_attr($p); ?>[lenke]" value="<?php echo esc_attr($rad['lenke'] ?? ''); ?>" placeholder="/produkt/…">
            </div>
        </div>
        <button type="button" class="amendo-btn-danger amendo-slett-rad">Slett bilde</button>
    </div>
    <?php
}

/** Rekkefølgen på seksjonene. Heroen står fast øverst. */
function amendo_forside_seksjoner_blokk($liste) {
    ?>
    <div class="amendo-card">
        <h2>Rekkefølge</h2>
        <p class="amendo-desc">Dra seksjonene i den rekkefølgen de skal stå. Seksjonen vises bare når den har innhold. Heroen står alltid øverst.</p>
        <div class="forside-seksjon-fast">Hero <span class="amendo-desc">(alltid øverst)</span></div>
        <ul class="forside-seksjon-liste">
            <?php foreach ($liste as $i => $rad): ?>
                <li class="forside-seksjon-rad">
                    <span class="meny-drag">⠿</span>
                    <span class="forside-seksjon-navn"><?php echo esc_html(amendo_forside_seksjon_navn($rad['id'])); ?></span>
                    <input type="hidden" name="forside[seksjoner][<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr($rad['id']); ?>">
                    <label class="forside-seksjon-skjul"><input type="checkbox" name="forside[seksjoner][<?php echo (int) $i; ?>][skjult]" value="1" <?php checked(!empty($rad['skjult'])); ?>> Skjul</label>
                    <button type="button" class="amendo-btn-secondary forside-seksjon-opp" aria-label="Flytt opp">↑</button>
                    <button type="button" class="amendo-btn-secondary forside-seksjon-ned" aria-label="Flytt ned">↓</button>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

/** Hele fanen. Kalles inne i <form> i amendo_settings_page(). */
function amendo_forside_fane() {
    list($f, $kilde) = amendo_forside_for_skjema();
    $u = amendo_forside_utvidet_for_skjema();
    $kategorier = amendo_forside_kategorivalg();
    ?>
    <div class="amendo-panel" id="tab-forside" data-amendo-del="forside">
        <?php amendo_lagring_felt('forside'); ?>
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
                <?php amendo_forside_felt('hero_tittel2', 'Tittel, linje 2 (kursiv)', $f['hero_tittel2']); ?>
            </div>
            <?php amendo_forside_tekstomraade('hero_undertekst', 'Undertekst', $f['hero_undertekst'], 2); ?>
            <?php amendo_forside_media('forside[hero_bilde]', 'Bilde', $f['hero_bilde'], 'image', '', $f['hero_bilde_id']); ?>
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

        <?php amendo_forside_seksjoner_blokk($u['seksjoner']); ?>

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
            <h2>Nyheter</h2>
            <p class="amendo-desc">De nyeste varene på lager. Uten tittel står det «Nyheter».</p>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('produkter_etikett', 'Etikett', $u['produkter_etikett']); ?>
                <?php amendo_forside_felt('produkter_tittel', 'Tittel', $u['produkter_tittel'], 'Nyheter'); ?>
            </div>
        </div>

        <div class="amendo-card">
            <h2>Kategorifliser</h2>
            <p class="amendo-desc">Inntil 6 fliser med egen tekst. Med færre enn 2 vises kategoriene som har bilde i WooCommerce, som før.</p>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('kategorier_etikett', 'Etikett', $u['kategorier_etikett']); ?>
                <?php amendo_forside_felt('kategorier_tittel', 'Tittel', $u['kategorier_tittel']); ?>
            </div>
            <div class="amendo-repeter" data-repeter="kategorifliser">
                <div class="amendo-repeter-liste">
                    <?php foreach ($u['kategorifliser'] as $i => $rad) amendo_forside_flis_rad($i, $rad, $kategorier); ?>
                </div>
                <template class="amendo-repeter-mal"><?php amendo_forside_flis_rad('__i__', [], $kategorier); ?></template>
                <button type="button" class="amendo-btn-secondary amendo-legg-til-rad" style="margin-top:12px">+ Legg til flis</button>
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
            <h2>Historie</h2>
            <p class="amendo-desc">Vises når tittel eller tekst er fylt ut.</p>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('historie_etikett', 'Etikett', $u['historie_etikett']); ?>
                <?php amendo_forside_felt('historie_tittel', 'Tittel', $u['historie_tittel']); ?>
            </div>
            <?php amendo_forside_tekstomraade('historie_tekst', 'Tekst', $u['historie_tekst'], 5); ?>
            <?php amendo_forside_media('forside[historie_bilde]', 'Bilde', $u['historie_bilde'], 'image', '', $u['historie_bilde_id']); ?>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('historie_stat_tall', 'Tall (valgfritt)', $u['historie_stat_tall'], '1972'); ?>
                <?php amendo_forside_felt('historie_stat_tekst', 'Tekst til tallet', $u['historie_stat_tekst'], 'grunnlagt'); ?>
            </div>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('historie_knapp_tekst', 'Knapp tekst', $u['historie_knapp_tekst']); ?>
                <?php amendo_forside_felt('historie_knapp_lenke', 'Knapp lenke', $u['historie_knapp_lenke'], '/om-oss'); ?>
            </div>
        </div>

        <div class="amendo-card">
            <h2>Om oss</h2>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('omoss_etikett', 'Etikett', $f['omoss_etikett']); ?>
                <?php amendo_forside_felt('omoss_tittel', 'Tittel', $f['omoss_tittel']); ?>
            </div>
            <?php amendo_forside_tekstomraade('omoss_tekst', 'Tekst', $f['omoss_tekst'], 4); ?>
            <?php amendo_forside_media('forside[omoss_bilde]', 'Bilde', $f['omoss_bilde'], 'image', '', $f['omoss_bilde_id']); ?>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('omoss_knapp1_tekst', 'Knapp 1 tekst', $f['omoss_knapp1_tekst'], 'Les mer om oss'); ?>
                <?php amendo_forside_felt('omoss_knapp1_lenke', 'Knapp 1 lenke', $f['omoss_knapp1_lenke'], '/om-oss'); ?>
            </div>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('omoss_knapp2_tekst', 'Knapp 2 tekst', $f['omoss_knapp2_tekst']); ?>
                <?php amendo_forside_felt('omoss_knapp2_lenke', 'Knapp 2 lenke', $f['omoss_knapp2_lenke'], '/kontakt'); ?>
            </div>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('omoss_stat_tall', 'Tall-merke (valgfritt)', $u['omoss_stat_tall'], '3'); ?>
                <?php amendo_forside_felt('omoss_stat_etikett', 'Tekst til tallet', $u['omoss_stat_etikett'], 'butikker'); ?>
            </div>
            <p class="field-help">Merket vises bare når begge er fylt ut. Det telles ikke automatisk.</p>
        </div>

        <div class="amendo-card">
            <h2>Lokasjoner</h2>
            <p class="amendo-desc">Kort med adresse og åpningstider fra fanen Avdelinger (inntil 3).</p>
            <div class="amendo-field">
                <label><input type="checkbox" name="forside[lok_vis]" value="1" <?php checked($u['lok_vis'], '1'); ?>> Vis lokasjoner på forsiden</label>
            </div>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('lok_etikett', 'Etikett', $u['lok_etikett']); ?>
                <?php amendo_forside_felt('lok_tittel', 'Tittel', $u['lok_tittel']); ?>
            </div>
            <?php amendo_forside_felt('lok_lenke_tekst', 'Lenketekst', $u['lok_lenke_tekst'], 'Se alle'); ?>
        </div>

        <div class="amendo-card">
            <h2>Galleri</h2>
            <p class="amendo-desc">Inntil 8 bilder. Vises med minst 3. Er Instagram-navnet satt, står @navnet over galleriet, og Instagram-seksjonen vises ikke i tillegg.</p>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('galleri_etikett', 'Etikett', $u['galleri_etikett']); ?>
                <?php amendo_forside_felt('galleri_tittel', 'Tittel', $u['galleri_tittel']); ?>
            </div>
            <div class="amendo-repeter" data-repeter="galleri_bilder">
                <div class="amendo-repeter-liste">
                    <?php foreach ($u['galleri_bilder'] as $i => $rad) amendo_forside_galleri_rad($i, $rad); ?>
                </div>
                <template class="amendo-repeter-mal"><?php amendo_forside_galleri_rad('__i__', []); ?></template>
                <button type="button" class="amendo-btn-secondary amendo-legg-til-rad" style="margin-top:12px">+ Legg til bilde</button>
            </div>
        </div>

        <div class="amendo-card">
            <h2>Instagram</h2>
            <div class="amendo-grid-2">
                <?php amendo_forside_felt('ig_handle', 'Brukernavn (uten @)', $f['ig_handle']); ?>
                <?php amendo_forside_felt('ig_tittel', 'Overskrift', $f['ig_tittel'], 'Følg oss på Instagram'); ?>
            </div>
        </div>

        <div class="amendo-card">
            <h2>Nyhetsbrev</h2>
            <p class="amendo-desc">Over påmeldingsskjemaet i footeren og i popupen. Tomt = frontendens egen tekst.</p>
            <?php amendo_forside_felt('nyhetsbrev_tittel', 'Tittel', $u['nyhetsbrev_tittel'], 'Meld deg på nyhetsbrevet'); ?>
            <?php amendo_forside_tekstomraade('nyhetsbrev_tekst', 'Tekst', $u['nyhetsbrev_tekst'], 2); ?>
        </div>
    </div>
    <?php
}
