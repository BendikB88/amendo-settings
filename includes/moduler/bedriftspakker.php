<?php
/**
 * Modulen Bedriftspakker (1.4.31). Lastes BARE når modulen er på — se
 * includes/moduler.php.
 *
 * Landingssiden for bedrifter (`/bedriftsavtale` i malen; callsenteret sender
 * lenken med `?ref=<selgerkode>`) viser faste pakker, og kunden søker om
 * bedriftsavtale med pakkene valgt. Tre typer:
 *
 *     bursdag  kakeabonnement: kunden velger blant `kaker` (produkter eller
 *              varianter, hver med hvor mange den rekker til)
 *     fast     fast levering (fredagskos): valgbare `dager` og `hyppigheter`
 *     engang   engangsbestilling (møtemat)
 *
 * Hver pakke er en oppskrift: `linjer` med et WooCommerce-produkt eller en
 * variant og `antall`, for `per_personer` personer («20 boller og 2 kanner
 * kaffe til 10 personer»).
 *
 * ⚠⚠ Formatene under er FRONTENDENS KONTRAKT (malen, lib/bedriftspakker.ts,
 * e654e7a). Endre dem ikke uten å endre den.
 *
 * ── GET amendo-settings/v1/bedriftspakker ─────────────────────────────────
 * Offentlig, som /settings: ingen kundedata, bare pakkene som er på, sortert
 * på `sortering`. Prisene regnes fra dagens WooCommerce-priser ved hvert kall
 * (tilbudspris når den gjelder):
 *
 *     { valuta, pakker: [{
 *         id, navn, tekst, bilde (URL eller ''), sortering, aktiv (alltid true),
 *         type, per_personer,
 *         linjer: [{ produkt_id, variant_id|null, navn, antall, pris_eks, pris_inkl }],
 *         pris_eks, pris_inkl,                 Σ antall × pris, for per_personer
 *         kaker: [{ produkt_id, variant_id|null, navn, storrelse, personer|null, pris_eks, pris_inkl }],
 *         dager: ['mandag', …],                (tomme utenom fast)
 *         hyppigheter: [{ nokkel, navn, per_maned }],
 *         ufullstendig }] }
 *
 * `produkt_id` er alltid hovedproduktet, `variant_id` varianten (null for et
 * enkelt produkt). Priser per stk. En linje eller kake med et produkt som er
 * slettet, ikke publisert eller uten pris, er utelatt, og pakken har da
 * `ufullstendig: true` (admin viser hvorfor). `personer` på en kake: det som
 * står i admin, ellers tallet i størrelsen («12 personer»), ellers null.
 *
 * ── Søknaden ──────────────────────────────────────────────────────────────
 * POST /b2b-soknad (modulen B2B) tar i tillegg, begge valgfrie:
 *
 *     ref:    selgerkoden, 1–40 tegn: bokstaver, tall, mellomrom, . _ -
 *     pakker: [{ pakke_id, navn, type, antall_personer,
 *                kake_produkt_id, kake_variant_id, kake_navn, dag, hyppighet,
 *                pris_eks_per_levering, anslag_eks_per_maned, fra_eks_per_person }]
 *
 * Valget sjekkes mot pakken FØR noe lagres, og feil gir 400 uten at søknaden
 * lagres: ukjent `pakke_id`, `dag`/`hyppighet` som pakken ikke har (eller på
 * en pakke som ikke er fast), en kake som ikke er i pakkens liste (eller på en
 * pakke som ikke er bursdag), `antall_personer` utenfor 1–10 000. En pakke som
 * er slått av, godtas (kunden kan ha hatt siden åpen).
 *
 * ⚠ Navn, type og kakenavn lagres fra VÅRE pakker. Det frontenden sendte av
 * navn og priser (anslagene) lagres under `nettsiden`, bare til visning — det
 * er ikke en pris noen har avtalt. Lagret på brukeren:
 *
 *     _amendo_bp_onske   [{ pakke_id, navn, type, antall_personer, kake_produkt_id,
 *                           kake_variant_id, kake_navn, dag, hyppighet,
 *                           nettsiden: { navn, kake_navn, pris_eks_per_levering,
 *                                        anslag_eks_per_maned, fra_eks_per_person } }]
 *     _amendo_bp_ref     selgerkoden
 *     _amendo_bp_tid     ISO-tid for siste innsending med pakker eller ref
 *
 * Et nytt kall uten `pakker` (eller uten `ref`) lar det lagrede stå. Svaret
 * får `pakker` og `ref` slik de står på brukeren (frontenden ser at de kom
 * fram). Vises i Søknader (kolonnen Pakke) og på brukerprofilen. Er modulen
 * av, ignoreres feltene, og svaret er som før.
 *
 * ── tellix_id ─────────────────────────────────────────────────────────────
 * Amendo-gatewayen sender varelinjer til Tellix med produktets `tellix_id`
 * (variantens egen for varianter). Fra 1.36.14 har den et reserveprodukt når
 * den mangler: per kategori i termmetaen `_amendo_tellix_fallback_id` (arves
 * fra overkategorien) og som standard i opsjonen
 * `amendo_tellix_fallback_product`. Admin advarer bare når produktet mangler
 * `tellix_id` og verken kategoriene eller standarden har en reserve.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_BP_TYPER = [
    'bursdag' => 'Bursdag (kakeabonnement)',
    'fast'    => 'Fast levering (f.eks. fredagskos)',
    'engang'  => 'Engangsbestilling (møtemat)',
];
// Nøklene er det kunden ser (frontenden setter bare stor forbokstav).
const AMENDO_BP_DAGER = ['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
// nøkkel => [navn, leveringer per måned]
const AMENDO_BP_HYPPIGHETER = [
    'ukentlig'      => ['Ukentlig', 52 / 12],
    'annenhver_uke' => ['Annenhver uke', 26 / 12],
    'manedlig'      => ['Månedlig', 1],
];
const AMENDO_BP_MAKS_PERSONER = 10000;
const AMENDO_BP_MAKS_PAKKER = 20;
const AMENDO_BP_ID = '/^[a-z0-9][a-z0-9-]{0,39}$/';

// ── Pakkene ─────────────────────────────────────────────────────────────────

/** Alle pakkene, også de som er av, i rekkefølgen fra admin. */
function amendo_bp_alle() {
    $raa = json_decode((string) get_option('amendo_bedriftspakker', '[]'), true);
    return amendo_bp_rens_liste(is_array($raa) ? $raa : [], false);
}

/** [id => pakke]. */
function amendo_bp_etter_id() {
    $ut = [];
    foreach (amendo_bp_alle() as $p) $ut[$p['id']] = $p;
    return $ut;
}

/**
 * Pakkene renset, i rekkefølge. ⚠ En pakke beholder ID-en sin uansett hvor
 * den står: ID-ene som alt finnes, settes av først, så en ny pakke med samme
 * navn som en eksisterende («Fredagskos») får «fredagskos-2» også når den
 * står øverst. Frontenden og søknadene viser til ID-en.
 */
function amendo_bp_rens_liste(array $liste, $lagring) {
    $reservert = [];
    foreach ($liste as $p) {
        $id = is_array($p) && is_scalar($p['id'] ?? null) ? strtolower((string) $p['id']) : '';
        if ($id !== '' && preg_match(AMENDO_BP_ID, $id) && sanitize_text_field((string) ($p['navn'] ?? '')) !== '') $reservert[$id] = true;
    }
    $ut = [];
    $brukt = [];
    foreach (array_values($liste) as $i => $p) {
        if (is_array($p) && ($p = amendo_bp_rens($p, $brukt, $reservert, $i, $lagring))) $ut[] = $p;
    }
    return $ut;
}

/** Bare verdiene som finnes i $lov, i lovens rekkefølge. */
function amendo_bp_utvalg($verdi, array $lov) {
    $inn = array_map('strval', is_array($verdi) ? $verdi : []);
    return array_values(array_filter($lov, function($k) use ($inn) { return in_array($k, $inn, true); }));
}

/**
 * Én pakke renset, eller null uten navn. `$brukt` holder ID-ene som er gitt
 * ut, `$reservert` dem som finnes fra før (se amendo_bp_rens_liste). Ved
 * lagring sjekkes produktene mot WooCommerce (ID-er som ikke finnes, tas
 * bort); ved lesing står de, så admin kan advare om et produkt som er slettet
 * etterpå.
 */
function amendo_bp_rens(array $p, array &$brukt, array $reservert, $nr, $lagring) {
    $navn = sanitize_text_field((string) ($p['navn'] ?? ''));
    if ($navn === '') return null;

    $id = is_scalar($p['id'] ?? null) ? strtolower((string) $p['id']) : '';
    if (!preg_match(AMENDO_BP_ID, $id) || isset($brukt[$id])) {
        $id = substr(trim(preg_replace('/[^a-z0-9-]+/', '-', sanitize_title($navn)), '-'), 0, 40) ?: 'pakke';
        $base = substr($id, 0, 36);
        for ($n = 2; isset($brukt[$id]) || isset($reservert[$id]); $n++) $id = $base . '-' . $n;
    }
    $brukt[$id] = true;

    $finnes = function($pid) use ($lagring) { return $pid > 0 && (!$lagring || wc_get_product($pid)); };

    $linjer = [];
    foreach (is_array($p['linjer'] ?? null) ? $p['linjer'] : [] as $l) {
        if (!is_array($l)) continue;
        $pid = (int) ($l['produkt'] ?? 0);
        $antall = round((float) str_replace(',', '.', (string) ($l['antall'] ?? '')), 2);
        if (!$finnes($pid) || $antall <= 0) continue;
        $linjer[] = ['produkt' => $pid, 'antall' => min($antall, 10000.0)];
    }

    $type = array_key_exists((string) ($p['type'] ?? ''), AMENDO_BP_TYPER) ? (string) $p['type'] : 'engang';
    $kaker = [];
    if ($type === 'bursdag') {
        foreach (is_array($p['kaker'] ?? null) ? $p['kaker'] : [] as $k) {
            $k = is_array($k) ? $k : ['produkt' => $k];
            $pid = (int) ($k['produkt'] ?? 0);
            if (!$finnes($pid) || in_array($pid, array_column($kaker, 'produkt'), true)) continue;
            $personer = (int) ($k['personer'] ?? 0);
            $kaker[] = ['produkt' => $pid, 'personer' => $personer > 0 ? min($personer, 1000) : null];
        }
    }

    $bilde = esc_url_raw(trim((string) ($p['bilde'] ?? '')), ['http', 'https']);
    $gitt  = $p['bilde_id'] ?? null;

    return [
        'id'           => $id,
        'navn'         => $navn,
        'tekst'        => sanitize_textarea_field((string) ($p['tekst'] ?? '')),
        'bilde'        => $bilde,
        'bilde_id'     => $bilde === '' ? 0 : ($lagring ? amendo_forside_bilde_id($bilde, null, $gitt, true) : max(0, (int) $gitt)),
        'sortering'    => is_numeric($p['sortering'] ?? null) ? (int) $p['sortering'] : ($nr + 1) * 10,
        'aktiv'        => !empty($p['aktiv']),
        'type'         => $type,
        'per_personer' => max(1, min(1000, (int) ($p['per_personer'] ?? 1))),
        'linjer'       => $linjer,
        'dager'        => $type === 'fast' ? amendo_bp_utvalg($p['dager'] ?? [], AMENDO_BP_DAGER) : [],
        'hyppigheter'  => $type === 'fast' ? amendo_bp_utvalg($p['hyppigheter'] ?? [], array_keys(AMENDO_BP_HYPPIGHETER)) : [],
        'kaker'        => $kaker,
    ];
}

// ── Produktene ──────────────────────────────────────────────────────────────

/** Produktet kan selges: finnes, er publisert (varianten og forelderen) og har pris. */
function amendo_bp_produkt_ok($p) {
    if (!$p || $p->get_status() !== 'publish' || $p->get_price() === '') return false;
    if ($p->is_type('variation')) {
        $forelder = wc_get_product($p->get_parent_id());
        if (!$forelder || $forelder->get_status() !== 'publish') return false;
    }
    return true;
}

/** Hvorfor produktet ikke kan brukes, eller ''. */
function amendo_bp_produkt_feil($pid) {
    $p = wc_get_product($pid);
    if (!$p) return 'finnes ikke lenger';
    if ($p->get_price() === '') return 'har ingen pris';
    if (!amendo_bp_produkt_ok($p)) return 'er ikke publisert';
    return '';
}

/**
 * Gatewayen finner en Tellix-vare for produktet: egen `tellix_id`, ellers
 * reserven fra en av kategoriene (eller en overkategori), ellers standarden.
 * Se toppen.
 */
function amendo_bp_har_tellix($pid) {
    if (trim((string) get_post_meta($pid, 'tellix_id', true)) !== '') return true;
    $p = wc_get_product($pid);
    $hoved = $p && $p->is_type('variation') ? $p->get_parent_id() : (int) $pid;
    $terms = wp_get_post_terms($hoved, 'product_cat', ['fields' => 'ids']);
    foreach (is_array($terms) ? $terms : [] as $t) {
        foreach (array_merge([(int) $t], get_ancestors((int) $t, 'product_cat', 'taxonomy')) as $kat) {
            if (trim((string) get_term_meta($kat, '_amendo_tellix_fallback_id', true)) !== '') return true;
        }
    }
    return trim((string) get_option('amendo_tellix_fallback_product', '')) !== '';
}

/** [produkt_id, variant_id|null]: hovedproduktet og varianten. */
function amendo_bp_ider($p) {
    return $p->is_type('variation') ? [$p->get_parent_id(), $p->get_id()] : [$p->get_id(), null];
}

/** Navnet uten SKU og ID; for varianter forelderens navn (variantdelen er størrelsen). */
function amendo_bp_produktnavn($p) {
    if ($p->is_type('variation') && ($f = wc_get_product($p->get_parent_id()))) return $f->get_name();
    return $p->get_name();
}

function amendo_bp_variant($p) {
    return $p->is_type('variation') ? wp_strip_all_tags(wc_get_formatted_variation($p, true, false, false)) : '';
}

/** «Sjokoladekake – 12 personer», også når produktet er slettet. */
function amendo_bp_visningsnavn($pid) {
    $p = wc_get_product($pid);
    if (!$p) return 'Produkt #' . (int) $pid . ' (slettet)';
    $v = amendo_bp_variant($p);
    return amendo_bp_produktnavn($p) . ($v !== '' ? ' – ' . $v : '');
}

/** Advarslene til admin: produkter uten Tellix-vare og produkter som ikke kan selges. */
function amendo_bp_advarsler(array $pakker) {
    $ut = [];
    foreach ($pakker as $pk) {
        $ider = array_merge(array_column($pk['linjer'], 'produkt'), array_column($pk['kaker'], 'produkt'));
        foreach (array_unique($ider) as $pid) {
            $navn = amendo_bp_visningsnavn($pid) . ' (#' . $pid . ')';
            if ($feil = amendo_bp_produkt_feil($pid)) {
                $ut[] = ['pakke' => $pk['navn'], 'produkt' => $pid, 'tekst' => $navn . ' ' . $feil . '. Det er ikke med på nettsiden.'];
            } elseif (!amendo_bp_har_tellix($pid)) {
                $ut[] = ['pakke' => $pk['navn'], 'produkt' => $pid, 'tekst' => $navn . ' mangler tellix_id, og verken kategorien eller standarden har et reserveprodukt. Varelinjen kommer ikke med til Tellix.'];
            }
        }
    }
    return $ut;
}

// ── GET /bedriftspakker ─────────────────────────────────────────────────────

add_action('rest_api_init', function() {
    register_rest_route('amendo-settings/v1', '/bedriftspakker', [
        'methods'             => 'GET',
        'callback'            => 'amendo_bp_rest',
        'permission_callback' => '__return_true',
    ]);
});

/** «12 personer» → 12. */
function amendo_bp_personer_fra_tekst($tekst) {
    return preg_match('/(\d+)\s*(?:pers|biter|stk)/iu', (string) $tekst, $m) ? (int) $m[1] : null;
}

function amendo_bp_rest() {
    $pakker = array_values(array_filter(amendo_bp_alle(), function($pk) { return $pk['aktiv']; }));
    usort($pakker, function($a, $b) { return $a['sortering'] <=> $b['sortering']; });

    $ut = [];
    foreach ($pakker as $pk) {
        $ufullstendig = false;
        $linjer = [];
        $sum = ['eks' => 0.0, 'inkl' => 0.0];
        foreach ($pk['linjer'] as $l) {
            $p = wc_get_product($l['produkt']);
            if (!amendo_bp_produkt_ok($p)) { $ufullstendig = true; continue; }
            [$hoved, $variant] = amendo_bp_ider($p);
            $eks  = round((float) wc_get_price_excluding_tax($p), 2);
            $inkl = round((float) wc_get_price_including_tax($p), 2);
            $sum['eks']  += $l['antall'] * $eks;
            $sum['inkl'] += $l['antall'] * $inkl;
            $linjer[] = [
                'produkt_id' => $hoved,
                'variant_id' => $variant,
                'navn'       => amendo_bp_visningsnavn($p->get_id()),
                'antall'     => $l['antall'],
                'pris_eks'   => $eks,
                'pris_inkl'  => $inkl,
            ];
        }
        $kaker = [];
        foreach ($pk['kaker'] as $k) {
            $p = wc_get_product($k['produkt']);
            if (!amendo_bp_produkt_ok($p)) { $ufullstendig = true; continue; }
            [$hoved, $variant] = amendo_bp_ider($p);
            $storrelse = amendo_bp_variant($p);
            $kaker[] = [
                'produkt_id' => $hoved,
                'variant_id' => $variant,
                'navn'       => amendo_bp_produktnavn($p),
                'storrelse'  => $storrelse,
                'personer'   => $k['personer'] ?? amendo_bp_personer_fra_tekst($storrelse),
                'pris_eks'   => round((float) wc_get_price_excluding_tax($p), 2),
                'pris_inkl'  => round((float) wc_get_price_including_tax($p), 2),
            ];
        }
        $ut[] = [
            'id'           => $pk['id'],
            'navn'         => $pk['navn'],
            'tekst'        => $pk['tekst'],
            'bilde'        => $pk['bilde'],
            'sortering'    => $pk['sortering'],
            'aktiv'        => true,
            'type'         => $pk['type'],
            'per_personer' => $pk['per_personer'],
            'linjer'       => $linjer,
            'pris_eks'     => round($sum['eks'], 2),
            'pris_inkl'    => round($sum['inkl'], 2),
            'kaker'        => $kaker,
            'dager'        => $pk['dager'],
            'hyppigheter'  => array_map(function($h) {
                return ['nokkel' => $h, 'navn' => AMENDO_BP_HYPPIGHETER[$h][0], 'per_maned' => round(AMENDO_BP_HYPPIGHETER[$h][1], 2)];
            }, $pk['hyppigheter']),
            'ufullstendig' => $ufullstendig,
        ];
    }
    return ['valuta' => get_woocommerce_currency(), 'pakker' => $ut];
}

// ── Søknaden (POST /b2b-soknad i modulen B2B) ───────────────────────────────

function amendo_bp_feil($melding, $kode = 'amendo_b2b_soknad_ugyldig_pakke') {
    return new WP_Error($kode, $melding);
}

/** Et tall fra nettsiden (anslag), eller null. Bare til visning. */
function amendo_bp_belop($v) {
    if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) return null;
    $f = (float) $v;
    return is_finite($f) && $f >= 0 && $f <= 100000000 ? round($f, 2) : null;
}

/** Tekst fra nettsiden, bare til visning. */
function amendo_bp_tekst($v) {
    return is_scalar($v) ? mb_substr(sanitize_text_field((string) $v), 0, 200) : '';
}

/** Hel ID > 0 (tall eller tekst med bare sifre), ellers null. */
function amendo_bp_heltall($v) {
    if (is_int($v)) return $v > 0 ? $v : null;
    return is_string($v) && ctype_digit($v) && (int) $v > 0 ? (int) $v : null;
}

/**
 * `pakker` og `ref` fra søknaden, sjekket mot pakkene. Returnerer det som
 * skal lagres ([] når ingen av feltene er med) eller WP_Error (400).
 */
add_filter('amendo_b2b_soknad_tillegg', function($tillegg, WP_REST_Request $req) {
    $params = $req->get_json_params();
    $params = is_array($params) ? $params : $req->get_params();
    $ut = is_array($tillegg) ? $tillegg : [];

    if (array_key_exists('ref', $params) && $params['ref'] !== null && $params['ref'] !== '') {
        $ref = is_scalar($params['ref']) ? trim((string) $params['ref']) : '';
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\-]{0,39}$/u', $ref)) {
            return amendo_bp_feil('ref (selgerkoden) må være 1–40 tegn: bokstaver, tall, mellomrom, punktum, _ og -.', 'amendo_b2b_soknad_ugyldig_ref');
        }
        $ut['bp_ref'] = $ref;
    }

    if (!array_key_exists('pakker', $params) || $params['pakker'] === null) return $ut;
    $inn = $params['pakker'];
    if (!is_array($inn) || array_values($inn) !== $inn || count($inn) > AMENDO_BP_MAKS_PAKKER) {
        return amendo_bp_feil('pakker må være en liste med høyst ' . AMENDO_BP_MAKS_PAKKER . ' pakker.');
    }

    $alle = amendo_bp_etter_id();
    $onsker = [];
    foreach ($inn as $o) {
        $pk = is_array($o) && is_scalar($o['pakke_id'] ?? null) ? ($alle[(string) $o['pakke_id']] ?? null) : null;
        if (!$pk) return amendo_bp_feil('Ukjent pakke_id.');
        $navn = '«' . $pk['navn'] . '»';

        $personer = null;
        if (($o['antall_personer'] ?? null) !== null && $o['antall_personer'] !== '') {
            $personer = amendo_bp_heltall($o['antall_personer']);
            if ($personer === null || $personer > AMENDO_BP_MAKS_PERSONER) {
                return amendo_bp_feil('antall_personer må være et heltall fra 1 til ' . AMENDO_BP_MAKS_PERSONER . '.');
            }
        }

        $dag = $o['dag'] ?? null;
        $hyppighet = $o['hyppighet'] ?? null;
        if (($dag !== null && !is_string($dag)) || ($hyppighet !== null && !is_string($hyppighet))) {
            return amendo_bp_feil('dag og hyppighet må være tekst.');
        }
        $dag = (string) $dag;
        $hyppighet = (string) $hyppighet;
        if (($dag !== '' || $hyppighet !== '') && $pk['type'] !== 'fast') {
            return amendo_bp_feil('Dag og hyppighet gjelder bare faste leveranser, ikke ' . $navn . '.');
        }
        if ($dag !== '' && !in_array($dag, $pk['dager'], true)) return amendo_bp_feil('Dagen «' . $dag . '» kan ikke velges for ' . $navn . '.');
        if ($hyppighet !== '' && !in_array($hyppighet, $pk['hyppigheter'], true)) return amendo_bp_feil('Hyppigheten «' . $hyppighet . '» kan ikke velges for ' . $navn . '.');

        // Kaken: varianten hvis den er gitt, ellers produktet. Varianten må høre til produktet.
        $kake = null;
        $kp = $o['kake_produkt_id'] ?? null;
        $kv = $o['kake_variant_id'] ?? null;
        if (($kp !== null && $kp !== '') || ($kv !== null && $kv !== '')) {
            if ($pk['type'] !== 'bursdag') return amendo_bp_feil('Kakevalg gjelder bare kakeabonnement, ikke ' . $navn . '.');
            $kp = amendo_bp_heltall($kp);
            $kv = ($kv === null || $kv === '' || $kv === 0) ? null : amendo_bp_heltall($kv);
            $valgt = $kv ?? $kp;
            $p = $valgt ? wc_get_product($valgt) : null;
            if (!$p || $kp === null || !in_array($valgt, array_column($pk['kaker'], 'produkt'), true) || amendo_bp_ider($p) !== [$kp, $kv]) {
                return amendo_bp_feil('Kaken kan ikke velges for ' . $navn . '.');
            }
            $kake = ['produkt' => $kp, 'variant' => $kv, 'navn' => amendo_bp_visningsnavn($valgt)];
        }

        $onsker[] = [
            'pakke_id'        => $pk['id'],
            'navn'            => $pk['navn'],
            'type'            => $pk['type'],
            'antall_personer' => $personer,
            'kake_produkt_id' => $kake['produkt'] ?? null,
            'kake_variant_id' => $kake['variant'] ?? null,
            'kake_navn'       => $kake['navn'] ?? '',
            'dag'             => $dag,
            'hyppighet'       => $hyppighet,
            'nettsiden'       => [
                'navn'                  => amendo_bp_tekst($o['navn'] ?? ''),
                'kake_navn'             => amendo_bp_tekst($o['kake_navn'] ?? ''),
                'pris_eks_per_levering' => amendo_bp_belop($o['pris_eks_per_levering'] ?? null),
                'anslag_eks_per_maned'  => amendo_bp_belop($o['anslag_eks_per_maned'] ?? null),
                'fra_eks_per_person'    => amendo_bp_belop($o['fra_eks_per_person'] ?? null),
            ],
        ];
    }
    $ut['bp_onske'] = $onsker;
    return $ut;
}, 10, 2);

add_action('amendo_b2b_soknad_lagret', function($bruker_id, $tillegg) {
    if (!is_array($tillegg) || (!array_key_exists('bp_onske', $tillegg) && !array_key_exists('bp_ref', $tillegg))) return;
    if (array_key_exists('bp_onske', $tillegg)) update_user_meta($bruker_id, '_amendo_bp_onske', $tillegg['bp_onske']);
    if (array_key_exists('bp_ref', $tillegg)) update_user_meta($bruker_id, '_amendo_bp_ref', $tillegg['bp_ref']);
    update_user_meta($bruker_id, '_amendo_bp_tid', gmdate('c'));
}, 10, 2);

// Svaret får det som står på brukeren, så frontenden ser at det kom fram.
add_filter('amendo_b2b_soknad_svar', function($svar, $bruker_id) {
    wp_cache_delete($bruker_id, 'user_meta');
    $onsker = get_user_meta($bruker_id, '_amendo_bp_onske', true);
    $svar['pakker'] = is_array($onsker) ? array_values($onsker) : [];
    $svar['ref'] = (string) get_user_meta($bruker_id, '_amendo_bp_ref', true);
    return $svar;
}, 10, 2);

/** «Fredagskos · 25 personer · fredag · annenhver uke · anslag fra nettsiden ca. 2 400 kr/mnd eks. mva». */
function amendo_bp_onske_tekst(array $o) {
    $deler = [(string) ($o['navn'] ?? $o['pakke_id'] ?? '')];
    if (!empty($o['antall_personer'])) $deler[] = (int) $o['antall_personer'] . ' personer';
    if (!empty($o['kake_navn'])) $deler[] = (string) $o['kake_navn'];
    if (!empty($o['dag'])) $deler[] = (string) $o['dag'];
    if (!empty($o['hyppighet'])) $deler[] = mb_strtolower(AMENDO_BP_HYPPIGHETER[$o['hyppighet']][0] ?? $o['hyppighet']);
    $n = is_array($o['nettsiden'] ?? null) ? $o['nettsiden'] : [];
    $kr = function($v) { return number_format((float) $v, 0, ',', ' ') . ' kr'; };
    if (isset($n['anslag_eks_per_maned'])) $deler[] = 'anslag fra nettsiden ca. ' . $kr($n['anslag_eks_per_maned']) . '/mnd eks. mva';
    elseif (isset($n['pris_eks_per_levering'])) $deler[] = 'anslag fra nettsiden ca. ' . $kr($n['pris_eks_per_levering']) . ' per levering eks. mva';
    elseif (isset($n['fra_eks_per_person'])) $deler[] = 'fra ' . $kr($n['fra_eks_per_person']) . ' per person eks. mva';
    return implode(' · ', $deler);
}

/** Pakkene og selgerkoden på brukeren, som HTML-linjer. Tom streng når ingenting er lagret. */
function amendo_bp_bruker_html($bruker_id) {
    $onsker = get_user_meta($bruker_id, '_amendo_bp_onske', true);
    $ref = (string) get_user_meta($bruker_id, '_amendo_bp_ref', true);
    $linjer = [];
    foreach (is_array($onsker) ? $onsker : [] as $o) {
        if (is_array($o)) $linjer[] = '<span class="bp-onske">' . esc_html(amendo_bp_onske_tekst($o)) . '</span>';
    }
    if ($ref !== '') $linjer[] = '<span class="bp-ref">Selgerkode: ' . esc_html($ref) . '</span>';
    return implode('<br>', $linjer);
}

// Kolonnen Pakke i Søknader (includes/moduler/b2b.php).
add_action('amendo_b2b_soknad_celle', function($u) {
    echo amendo_bp_bruker_html($u->ID) ?: '—'; // escapet i amendo_bp_bruker_html
});

// Brukerprofilen. Bare for dem som kan bruke Amendo-admin; ingenting å redigere.
add_action('show_user_profile', 'amendo_bp_profil');
add_action('edit_user_profile', 'amendo_bp_profil');
function amendo_bp_profil($u) {
    if (!current_user_can(amendo_settings_kapabilitet())) return;
    $html = amendo_bp_bruker_html($u->ID);
    if ($html === '') return;
    $tid = (string) get_user_meta($u->ID, '_amendo_bp_tid', true);
    ?>
    <h2>Bedriftspakke fra søknaden</h2>
    <table class="form-table" role="presentation" id="amendo-bp-profil">
        <tr>
            <th scope="row">Ønsket</th>
            <td><?php echo $html; // escapet i amendo_bp_bruker_html ?>
                <p class="description">Anslagene er regnet på nettsiden og er ikke avtalte priser.<?php if ($tid && ($t = strtotime($tid))): ?> Sendt <?php echo esc_html(wp_date('d.m.Y H:i', $t)); ?>.<?php endif; ?></p></td>
        </tr>
    </table>
    <?php
}

// ── Admin ───────────────────────────────────────────────────────────────────

add_filter('amendo_lagring_deler', function($deler) { return $deler + ['bedriftspakker' => 'amendo_bedriftspakker']; });
add_filter('amendo_lagring_navn', function($navn) { return $navn + ['bedriftspakker' => 'Bedriftspakker']; });

/** Fra $_POST. Krever markøren `bedriftspakker[finnes]` og at delen er endret (includes/lagring.php). */
add_action('amendo_lagre_innstillinger', function(array $post) {
    $inn = $post['bedriftspakker'] ?? null;
    if (!is_array($inn) || empty($inn['finnes']) || !amendo_lagring_skal_lagre($post, 'bedriftspakker')) return;
    $ut = amendo_bp_rens_liste(is_array($inn['pakker'] ?? null) ? $inn['pakker'] : [], true);
    update_option('amendo_bedriftspakker', wp_json_encode($ut, JSON_UNESCAPED_UNICODE));
});

// Produktsøket til WooCommerce (det samme som i kuponger og koblede produkter).
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook !== 'toplevel_page_amendo-settings') return;
    wp_enqueue_script('wc-enhanced-select');
    wp_enqueue_style('woocommerce_admin_styles');
}, 20);

add_action('amendo_admin_faner', function() {
    echo '<button type="button" class="amendo-tab" data-tab="bedriftspakker">📦 Bedriftspakker</button>';
});

/** Produktvelgeren: WooCommerce sitt søk i produkter og varianter. */
function amendo_bp_produktvalg($name, $valgt, $etikett) {
    printf(
        '<select class="wc-product-search bp-produkt" name="%s" data-action="woocommerce_json_search_products_and_variations" data-placeholder="Søk etter produkt eller variant …" data-allow_clear="true" aria-label="%s"><option value=""></option>',
        esc_attr($name), esc_attr($etikett)
    );
    if ($valgt) {
        $p = wc_get_product($valgt);
        printf('<option value="%d" selected>%s</option>', (int) $valgt, esc_html($p ? wp_strip_all_tags($p->get_formatted_name()) : amendo_bp_visningsnavn($valgt)));
    }
    echo '</select>';
}

/** Én linje i oppskriften. `$p` og `$l` er tall, eller «__p__»/«__l__» i malen. */
function amendo_bp_linje($p, $l, array $linje) {
    $n = 'bedriftspakker[pakker][' . $p . '][linjer][' . $l . ']';
    ?>
    <div class="bp-linje">
        <div class="bp-linje-produkt"><?php amendo_bp_produktvalg($n . '[produkt]', (int) ($linje['produkt'] ?? 0), 'Produkt'); ?></div>
        <input type="number" name="<?php echo esc_attr($n); ?>[antall]" value="<?php echo esc_attr((string) ($linje['antall'] ?? 1)); ?>" min="0.01" max="10000" step="0.01" aria-label="Antall">
        <span>stk</span>
        <button type="button" class="amendo-btn-danger bp-slett-linje">Slett</button>
    </div>
    <?php
}

/** Én kake. `$p` og `$k` er tall, eller «__p__»/«__k__» i malen. */
function amendo_bp_kake($p, $k, array $kake) {
    $n = 'bedriftspakker[pakker][' . $p . '][kaker][' . $k . ']';
    ?>
    <div class="bp-linje bp-kake">
        <div class="bp-linje-produkt"><?php amendo_bp_produktvalg($n . '[produkt]', (int) ($kake['produkt'] ?? 0), 'Kake eller størrelse'); ?></div>
        <span>til</span>
        <input type="number" name="<?php echo esc_attr($n); ?>[personer]" value="<?php echo esc_attr((string) ($kake['personer'] ?? '')); ?>" min="1" max="1000" placeholder="fra navnet" aria-label="Antall personer kaken rekker til">
        <span>personer</span>
        <button type="button" class="amendo-btn-danger bp-slett-kake">Slett</button>
    </div>
    <?php
}

/** Ett pakkekort. `$i` er et tall, eller «__p__» i malen. */
function amendo_bp_pakke_kort($i, array $pk) {
    $n = 'bedriftspakker[pakker][' . $i . ']';
    $type = $pk['type'] ?? 'engang';
    ?>
    <div class="avdeling-kort bp-pakke" data-p="<?php echo esc_attr((string) $i); ?>">
        <div class="avdeling-header">
            <span class="bp-tittel"><?php echo esc_html(($pk['navn'] ?? '') ?: 'Ny pakke'); ?></span>
            <button type="button" class="amendo-btn-danger bp-slett-pakke">Slett pakke</button>
        </div>
        <div class="avdeling-body">
            <input type="hidden" name="<?php echo esc_attr($n); ?>[id]" value="<?php echo esc_attr($pk['id'] ?? ''); ?>">
            <div class="amendo-grid-2">
                <div class="amendo-field"><label>Navn</label><input type="text" name="<?php echo esc_attr($n); ?>[navn]" value="<?php echo esc_attr($pk['navn'] ?? ''); ?>" placeholder="Fredagskos"></div>
                <div class="amendo-field"><label>Type</label>
                    <select name="<?php echo esc_attr($n); ?>[type]" class="bp-type">
                        <?php foreach (AMENDO_BP_TYPER as $k => $t): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($type, $k); ?>><?php echo esc_html($t); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="amendo-field"><label>Kort tekst</label><textarea name="<?php echo esc_attr($n); ?>[tekst]" rows="2"><?php echo esc_textarea($pk['tekst'] ?? ''); ?></textarea></div>
            <?php amendo_forside_media($n . '[bilde]', 'Bilde', (string) ($pk['bilde'] ?? ''), 'image', '', (int) ($pk['bilde_id'] ?? 0)); ?>
            <div class="amendo-grid-2">
                <div class="amendo-field"><label>Sortering</label><input type="number" name="<?php echo esc_attr($n); ?>[sortering]" value="<?php echo esc_attr((string) ($pk['sortering'] ?? '')); ?>" style="width:90px"><p class="field-help">Lavest først på nettsiden.</p></div>
                <div class="amendo-field"><label>Vises på nettsiden</label><label class="toggle"><input type="checkbox" name="<?php echo esc_attr($n); ?>[aktiv]" value="1" <?php checked(!array_key_exists('aktiv', $pk) || !empty($pk['aktiv'])); // ny pakke: på ?>><span class="toggle-slider"></span></label></div>
            </div>

            <div class="amendo-field">
                <label>Oppskrift</label>
                <p class="field-help">Hva pakken består av for <input type="number" name="<?php echo esc_attr($n); ?>[per_personer]" value="<?php echo (int) ($pk['per_personer'] ?? 10); ?>" min="1" max="1000" style="width:70px" aria-label="Oppskriften gjelder for antall personer"> personer. Nettsiden skalerer til antallet kunden oppgir (rundet opp per linje) og priser med dagens priser i WooCommerce.</p>
                <div class="bp-linjer">
                    <?php foreach (array_values($pk['linjer'] ?? []) as $l => $linje) amendo_bp_linje($i, $l, $linje); ?>
                </div>
                <button type="button" class="amendo-btn-secondary bp-legg-til-linje">+ Legg til linje</button>
            </div>

            <div class="amendo-field bp-bare-fast" <?php echo $type === 'fast' ? '' : 'hidden'; ?>>
                <label>Dager kunden kan velge</label>
                <div class="bp-valg">
                    <?php foreach (AMENDO_BP_DAGER as $d): ?><label><input type="checkbox" name="<?php echo esc_attr($n); ?>[dager][]" value="<?php echo esc_attr($d); ?>" <?php checked(in_array($d, $pk['dager'] ?? [], true)); ?>> <?php echo esc_html(mb_convert_case($d, MB_CASE_TITLE)); ?></label><?php endforeach; ?>
                </div>
                <label>Hyppigheter kunden kan velge</label>
                <div class="bp-valg">
                    <?php foreach (AMENDO_BP_HYPPIGHETER as $k => [$t]): ?><label><input type="checkbox" name="<?php echo esc_attr($n); ?>[hyppigheter][]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k, $pk['hyppigheter'] ?? [], true)); ?>> <?php echo esc_html($t); ?></label><?php endforeach; ?>
                </div>
            </div>

            <div class="amendo-field bp-bare-bursdag" <?php echo $type === 'bursdag' ? '' : 'hidden'; ?>>
                <label>Kaker og størrelser kunden kan velge</label>
                <p class="field-help">Velg variantene (størrelsene), ikke bare kaken. Tomt antall personer: tallet i størrelsen («12 personer») brukes.</p>
                <div class="bp-kaker">
                    <?php foreach (array_values($pk['kaker'] ?? []) as $k => $kake) amendo_bp_kake($i, $k, $kake); ?>
                </div>
                <button type="button" class="amendo-btn-secondary bp-legg-til-kake">+ Legg til kake</button>
            </div>
        </div>
    </div>
    <?php
}

add_action('amendo_admin_paneler', function() {
    $pakker = amendo_bp_alle();
    $advarsler = amendo_bp_advarsler($pakker);
    ?>
    <div class="amendo-panel" id="tab-bedriftspakker">
        <div class="amendo-card" id="bedriftspakker" data-amendo-del="bedriftspakker">
            <?php amendo_lagring_felt('bedriftspakker'); ?>
            <input type="hidden" name="bedriftspakker[finnes]" value="1">
            <h2>Bedriftspakker</h2>
            <p class="amendo-desc">Pakkene på landingssiden for bedrifter: kakeabonnement, faste leveranser og møtemat. Nettsiden viser pakkene som er på, med anslag fra dagens priser. Kunden kan velge pakker i søknaden om bedriftsavtale; valget og selgerkoden står i Søknader og på brukeren.</p>
            <?php if ($advarsler): ?>
                <div class="amendo-advarsel" id="bp-advarsler">
                    <strong>⚠ Produkter i pakkene som må sjekkes:</strong>
                    <ul>
                        <?php foreach ($advarsler as $a): ?><li data-produkt="<?php echo (int) $a['produkt']; ?>"><?php echo esc_html($a['pakke'] . ': ' . $a['tekst']); ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <div id="bp-liste">
                <?php foreach ($pakker as $i => $pk) amendo_bp_pakke_kort($i, $pk); ?>
            </div>
            <button type="button" class="amendo-btn-secondary" id="bp-legg-til">+ Legg til pakke</button>
            <template id="bp-pakke-mal"><?php amendo_bp_pakke_kort('__p__', []); ?></template>
            <template id="bp-linje-mal"><?php amendo_bp_linje('__p__', '__l__', []); ?></template>
            <template id="bp-kake-mal"><?php amendo_bp_kake('__p__', '__l__', []); ?></template>
        </div>
    </div>
    <?php
});
