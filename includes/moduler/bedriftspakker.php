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
 *         nivaer: [{ nokkel, navn, tekst, per_personer, linjer, pris_eks, pris_inkl }],
 *         ufullstendig }] }
 *
 * Nivåer (1.4.33, valgfrie): f.eks. Liten / Medium / Stor, hver med egen
 * kort tekst og egen oppskrift (linjer for per_personer personer, i samme
 * form som pakkens). `nivaer` er [] for en pakke uten nivåer, og da er
 * pakken som før. `nokkel` er stabil (lages fra navnet første gang).
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
 *     pakker: [{ pakke_id, navn, type, niva, antall_personer,
 *                kake_produkt_id, kake_variant_id, kake_navn, dag, hyppighet,
 *                pris_eks_per_levering, anslag_eks_per_maned, fra_eks_per_person }]
 *
 * Valget sjekkes mot pakken FØR noe lagres, og feil gir 400 uten at søknaden
 * lagres: ukjent `pakke_id`, `dag`/`hyppighet` som pakken ikke har (eller på
 * en pakke som ikke er fast), en kake som ikke er i pakkens liste (eller på en
 * pakke som ikke er bursdag), `antall_personer` utenfor 1–10 000, `niva` som
 * pakken ikke har (eller på en pakke uten nivåer). `niva` kan utelates. En
 * pakke som er slått av, godtas (kunden kan ha hatt siden åpen).
 *
 * ⚠ Navn, type og kakenavn lagres fra VÅRE pakker. Det frontenden sendte av
 * navn og priser (anslagene) lagres under `nettsiden`, bare til visning — det
 * er ikke en pris noen har avtalt. Lagret på brukeren:
 *
 *     _amendo_bp_onske   [{ pakke_id, navn, type, niva, niva_navn, antall_personer, kake_produkt_id,
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
 * ── E-post og oppfølging (1.4.37) ─────────────────────────────────────────
 * En søknad med pakker gir butikken en e-post (se amendo_bp_varsle()), og
 * hver bedrift har en oppfølgingsstatus (Ny, Kontaktet, Avtale startet, Ikke
 * aktuell) med notat og dato i Søknader, i kortet «Oppfølging av
 * bedriftspakker» (med filter på status og kolonnen Ref) og på
 * brukerprofilen. Ingenting av dette er i REST-svarene.
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
const AMENDO_BP_MAKS_NIVAER = 10;

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

    $id = amendo_bp_unik($p['id'] ?? '', $navn, 'pakke', $brukt, $reservert);

    $finnes = function($pid) use ($lagring) { return $pid > 0 && (!$lagring || wc_get_product($pid)); };
    $rens_linjer = function($liste) use ($finnes) {
        $ut = [];
        foreach (is_array($liste) ? $liste : [] as $l) {
            if (!is_array($l)) continue;
            $pid = (int) ($l['produkt'] ?? 0);
            $antall = round((float) str_replace(',', '.', (string) ($l['antall'] ?? '')), 2);
            if (!$finnes($pid) || $antall <= 0) continue;
            $ut[] = ['produkt' => $pid, 'antall' => min($antall, 10000.0)];
        }
        return $ut;
    };
    $linjer = $rens_linjer($p['linjer'] ?? []);

    // Nivåene (1.4.33). Nøklene som finnes, settes av først, som pakke-ID-ene.
    $inn = is_array($p['nivaer'] ?? null) ? array_values($p['nivaer']) : [];
    $n_reservert = [];
    foreach ($inn as $nv) {
        $k = is_array($nv) && is_scalar($nv['nokkel'] ?? null) ? strtolower((string) $nv['nokkel']) : '';
        if ($k !== '' && preg_match(AMENDO_BP_ID, $k) && sanitize_text_field((string) ($nv['navn'] ?? '')) !== '') $n_reservert[$k] = true;
    }
    $nivaer = [];
    $n_brukt = [];
    foreach ($inn as $nv) {
        if (!is_array($nv) || count($nivaer) >= AMENDO_BP_MAKS_NIVAER) continue;
        $nnavn = sanitize_text_field((string) ($nv['navn'] ?? ''));
        if ($nnavn === '') continue;
        $nivaer[] = [
            'nokkel'       => amendo_bp_unik($nv['nokkel'] ?? '', $nnavn, 'niva', $n_brukt, $n_reservert),
            'navn'         => $nnavn,
            'tekst'        => sanitize_textarea_field((string) ($nv['tekst'] ?? '')),
            'per_personer' => max(1, min(1000, (int) ($nv['per_personer'] ?? 1))),
            'linjer'       => $rens_linjer($nv['linjer'] ?? []),
        ];
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
        'nivaer'       => $nivaer,
    ];
}

/**
 * En gyldig, unik nøkkel: den gitte når den er gyldig og ledig, ellers laget
 * av navnet (med -2, -3 … når den er brukt eller satt av).
 */
function amendo_bp_unik($gitt, $navn, $standard, array &$brukt, array $reservert) {
    $k = is_scalar($gitt) ? strtolower((string) $gitt) : '';
    if (!preg_match(AMENDO_BP_ID, $k) || isset($brukt[$k])) {
        $k = substr(trim(preg_replace('/[^a-z0-9-]+/', '-', sanitize_title($navn)), '-'), 0, 40) ?: $standard;
        $base = substr($k, 0, 36);
        for ($n = 2; isset($brukt[$k]) || isset($reservert[$k]); $n++) $k = $base . '-' . $n;
    }
    $brukt[$k] = true;
    return $k;
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
        foreach ($pk['nivaer'] as $nv) $ider = array_merge($ider, array_column($nv['linjer'], 'produkt'));
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

/**
 * Linjene i kontraktens form, og summen. Produkter som ikke kan selges, er
 * utelatt og setter $ufullstendig.
 */
function amendo_bp_linjer_ut(array $linjer, &$ufullstendig) {
    $ut = [];
    $sum = ['eks' => 0.0, 'inkl' => 0.0];
    foreach ($linjer as $l) {
        $p = wc_get_product($l['produkt']);
        if (!amendo_bp_produkt_ok($p)) { $ufullstendig = true; continue; }
        [$hoved, $variant] = amendo_bp_ider($p);
        $eks  = round((float) wc_get_price_excluding_tax($p), 2);
        $inkl = round((float) wc_get_price_including_tax($p), 2);
        $sum['eks']  += $l['antall'] * $eks;
        $sum['inkl'] += $l['antall'] * $inkl;
        $ut[] = [
            'produkt_id' => $hoved,
            'variant_id' => $variant,
            'navn'       => amendo_bp_visningsnavn($p->get_id()),
            'antall'     => $l['antall'],
            'pris_eks'   => $eks,
            'pris_inkl'  => $inkl,
        ];
    }
    return ['linjer' => $ut, 'pris_eks' => round($sum['eks'], 2), 'pris_inkl' => round($sum['inkl'], 2)];
}

function amendo_bp_rest() {
    $pakker = array_values(array_filter(amendo_bp_alle(), function($pk) { return $pk['aktiv']; }));
    usort($pakker, function($a, $b) { return $a['sortering'] <=> $b['sortering']; });

    $ut = [];
    foreach ($pakker as $pk) {
        $ufullstendig = false;
        $egne = amendo_bp_linjer_ut($pk['linjer'], $ufullstendig);
        $nivaer = [];
        foreach ($pk['nivaer'] as $nv) {
            $nivaer[] = ['nokkel' => $nv['nokkel'], 'navn' => $nv['navn'], 'tekst' => $nv['tekst'], 'per_personer' => $nv['per_personer']]
                + amendo_bp_linjer_ut($nv['linjer'], $ufullstendig);
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
            'linjer'       => $egne['linjer'],
            'pris_eks'     => $egne['pris_eks'],
            'pris_inkl'    => $egne['pris_inkl'],
            'kaker'        => $kaker,
            'dager'        => $pk['dager'],
            'hyppigheter'  => array_map(function($h) {
                return ['nokkel' => $h, 'navn' => AMENDO_BP_HYPPIGHETER[$h][0], 'per_maned' => round(AMENDO_BP_HYPPIGHETER[$h][1], 2)];
            }, $pk['hyppigheter']),
            'nivaer'       => $nivaer,
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

        // Nivået (1.4.33): valgfritt, men må finnes i pakken.
        $niva = $o['niva'] ?? null;
        if ($niva !== null && !is_string($niva)) return amendo_bp_feil('niva må være tekst.');
        $niva = (string) $niva;
        $niva_navn = '';
        if ($niva !== '') {
            if (!$pk['nivaer']) return amendo_bp_feil($navn . ' har ingen nivåer.');
            foreach ($pk['nivaer'] as $nv) if ($nv['nokkel'] === $niva) $niva_navn = $nv['navn'];
            if ($niva_navn === '') return amendo_bp_feil('Nivået «' . $niva . '» finnes ikke i ' . $navn . '.');
        }

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
            'niva'            => $niva,
            'niva_navn'       => $niva_navn,
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
    $deler = [(string) ($o['navn'] ?? $o['pakke_id'] ?? '') . (!empty($o['niva_navn']) ? ' – ' . $o['niva_navn'] : '')];
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

/** Pakkene (og selgerkoden) på brukeren, som HTML-linjer. Tom streng når ingenting er lagret. */
function amendo_bp_bruker_html($bruker_id, $med_ref = true) {
    $onsker = get_user_meta($bruker_id, '_amendo_bp_onske', true);
    $ref = (string) get_user_meta($bruker_id, '_amendo_bp_ref', true);
    $linjer = [];
    foreach (is_array($onsker) ? $onsker : [] as $o) {
        if (is_array($o)) $linjer[] = '<span class="bp-onske">' . esc_html(amendo_bp_onske_tekst($o)) . '</span>';
    }
    if ($med_ref && $ref !== '') $linjer[] = '<span class="bp-ref">Selgerkode: ' . esc_html($ref) . '</span>';
    return implode('<br>', $linjer);
}

// ── E-post til butikken (1.4.37) ────────────────────────────────────────────
//
// Når en søknad har pakker, får butikken en e-post (ren tekst) med firma,
// org.nr., kontakt, telefon, e-post, pakkene (nivå, personer, kake, dag,
// hyppighet, anslag), selgerkoden og lenker til søknaden og brukeren i admin.
// Mottakere som kontaktskjemaet: «Kontaktskjema sendes til», ellers
// kontakt-e-posten, ellers admin-e-posten (amendo_kontakt_mottakere()).
// Reply-To er kunden.
//
// Samme innhold (pakker, selgerkode, org.nr., status) to ganger innen et døgn
// sendes ikke på nytt: frontenden kan prøve igjen. Feiler wp_mail, står
// søknaden som før, og neste innsending prøver igjen. Ingenting logges.

/** «911 222 333». */
function amendo_bp_orgnr_tekst($orgnr) {
    return strlen($orgnr) === 9 ? substr($orgnr, 0, 3) . ' ' . substr($orgnr, 3, 3) . ' ' . substr($orgnr, 6) : $orgnr;
}

/** Kontaktpersonen: navnet på kontoen, ellers fakturanavnet, ellers visningsnavnet. */
function amendo_bp_kontakt_navn(WP_User $u) {
    $navn = trim($u->first_name . ' ' . $u->last_name);
    if ($navn === '') $navn = trim(get_user_meta($u->ID, 'billing_first_name', true) . ' ' . get_user_meta($u->ID, 'billing_last_name', true));
    return $navn !== '' ? $navn : (string) $u->display_name;
}

function amendo_bp_varsle($bruker_id, array $onsker) {
    $u = get_userdata($bruker_id);
    if (!$u || !$onsker) return false;
    wp_cache_delete($bruker_id, 'user_meta');
    $status = (string) get_user_meta($bruker_id, '_b2b_status', true);
    $orgnr  = amendo_b2b_orgnr(get_user_meta($bruker_id, '_orgnr_soknad', true) ?: get_user_meta($bruker_id, 'billing_org_number', true));
    $ref    = (string) get_user_meta($bruker_id, '_amendo_bp_ref', true);

    $hash = md5(wp_json_encode([$onsker, $ref, $orgnr, $status]));
    $sist = get_user_meta($bruker_id, '_amendo_bp_varslet', true);
    if (is_array($sist) && ($sist['hash'] ?? '') === $hash && time() - (int) ($sist['tid'] ?? 0) < DAY_IN_SECONDS) return false;

    $til = amendo_kontakt_mottakere();
    if (!$til) return false;

    $firma = amendo_kontakt_linje(get_user_meta($bruker_id, 'billing_company', true), 150);
    $telefon = amendo_kontakt_linje(get_user_meta($bruker_id, 'billing_phone', true), 40);
    $epost = (string) $u->user_email;
    $kr = function($v) { return number_format((float) $v, 0, ',', ' ') . ' kr'; };

    $linjer = [
        'Firma: ' . ($firma !== '' ? $firma : '—'),
        'Org.nr.: ' . ($orgnr !== '' ? amendo_bp_orgnr_tekst($orgnr) : '—'),
        'Kontakt: ' . amendo_kontakt_linje(amendo_bp_kontakt_navn($u), 150),
        'Telefon: ' . ($telefon !== '' ? $telefon : '—'),
        'E-post: ' . $epost,
        'Selgerkode (ref): ' . ($ref !== '' ? $ref : '—'),
        'Søknaden: ' . ($status === 'godkjent' ? 'godkjent automatisk (bedriften finnes i Tellix)' : ($status === 'venter' ? 'venter på godkjenning' : ($status !== '' ? $status : '—'))),
        '',
        count($onsker) === 1 ? 'Pakke:' : 'Pakker:',
    ];
    foreach ($onsker as $o) {
        if (!is_array($o)) continue;
        $linjer[] = '';
        $linjer[] = '- ' . (string) ($o['navn'] ?? $o['pakke_id'] ?? '') . (!empty($o['niva_navn']) ? ' – ' . $o['niva_navn'] : '');
        if (!empty($o['niva_navn'])) $linjer[] = '  Nivå: ' . $o['niva_navn'];
        if (!empty($o['antall_personer'])) $linjer[] = '  Antall personer: ' . (int) $o['antall_personer'];
        if (!empty($o['kake_navn'])) $linjer[] = '  Kake: ' . $o['kake_navn'];
        if (!empty($o['dag'])) $linjer[] = '  Dag: ' . $o['dag'];
        if (!empty($o['hyppighet'])) $linjer[] = '  Hyppighet: ' . mb_strtolower(AMENDO_BP_HYPPIGHETER[$o['hyppighet']][0] ?? $o['hyppighet']);
        $n = is_array($o['nettsiden'] ?? null) ? $o['nettsiden'] : [];
        if (isset($n['anslag_eks_per_maned'])) $linjer[] = '  Anslag: ca. ' . $kr($n['anslag_eks_per_maned']) . '/mnd eks. mva';
        if (isset($n['pris_eks_per_levering'])) $linjer[] = '  Anslag per levering: ca. ' . $kr($n['pris_eks_per_levering']) . ' eks. mva';
        if (isset($n['fra_eks_per_person'])) $linjer[] = '  Fra ' . $kr($n['fra_eks_per_person']) . ' per person eks. mva';
    }
    $linjer[] = '';
    $linjer[] = 'Anslagene er regnet på nettsiden og er ikke avtalte priser.';
    $linjer[] = '';
    if ($status === 'venter') $linjer[] = 'Søknaden i admin: ' . admin_url('admin.php?page=amendo-settings#b2b');
    $linjer[] = 'Brukeren i admin: ' . admin_url('user-edit.php?user_id=' . (int) $bruker_id);

    $emne = 'Bedriftspakke: søknad fra ' . ($firma !== '' ? $firma : ($orgnr !== '' ? 'org.nr. ' . amendo_bp_orgnr_tekst($orgnr) : $epost));
    $hoder = ['Content-Type: text/plain; charset=UTF-8'];
    if (is_email($epost)) $hoder[] = 'Reply-To: ' . str_replace(['<', '>', '"', "\r", "\n"], '', amendo_bp_kontakt_navn($u)) . ' <' . $epost . '>';

    if (!wp_mail($til, $emne, implode("\n", $linjer) . "\n", $hoder)) return false;
    update_user_meta($bruker_id, '_amendo_bp_varslet', ['hash' => $hash, 'tid' => time()]);
    return true;
}

// Etter at søknaden er lagret (også når den ble godkjent automatisk). Bare
// når DETTE kallet hadde pakker — et kall med bare selgerkode sender ingenting.
add_action('amendo_b2b_soknad_lagret', function($bruker_id, $tillegg) {
    if (is_array($tillegg) && !empty($tillegg['bp_onske'])) amendo_bp_varsle((int) $bruker_id, $tillegg['bp_onske']);
}, 20, 2);

// ── Oppfølging (1.4.37) ─────────────────────────────────────────────────────
//
// Per bedrift (brukeren som søkte): status, notat og en dato butikken velger
// (f.eks. når de tok kontakt eller avtalen starter), lagret som
//
//     _amendo_bp_oppfolging  { status, notat, dato ('Y-m-d' eller ''), endret (ISO), endret_av }
//
// Uten lagret oppfølging er statusen «Ny». Endres i Søknader (kolonnen
// Oppfølging), i kortet «Oppfølging av bedriftspakker» under — alle som har
// søkt med pakke eller selgerkode, også de som er godkjent, filtrert på
// status — og på brukerprofilen. Vises aldri for kunden og aldri i REST.

const AMENDO_BP_OPPFOLGING = [
    'ny'           => 'Ny',
    'kontaktet'    => 'Kontaktet',
    'avtale'       => 'Avtale startet',
    'ikke_aktuell' => 'Ikke aktuell',
];
const AMENDO_BP_MAKS_NOTAT = 2000;
const AMENDO_BP_MAKS_OPPFOLGING = 300;

function amendo_bp_oppfolging($bruker_id) {
    $o = get_user_meta($bruker_id, '_amendo_bp_oppfolging', true);
    $o = is_array($o) ? $o : [];
    $status = (string) ($o['status'] ?? '');
    return [
        'status'    => isset(AMENDO_BP_OPPFOLGING[$status]) ? $status : 'ny',
        'notat'     => (string) ($o['notat'] ?? ''),
        'dato'      => (string) ($o['dato'] ?? ''),
        'endret'    => (string) ($o['endret'] ?? ''),
        'endret_av' => (int) ($o['endret_av'] ?? 0),
    ];
}

/** Har søkt med pakke eller selgerkode (eller har oppfølging fra før). */
function amendo_bp_har_soknad($bruker_id) {
    return (string) get_user_meta($bruker_id, '_amendo_bp_tid', true) !== '' || is_array(get_user_meta($bruker_id, '_amendo_bp_oppfolging', true));
}

/** Lagrer fra skjemaet. Returnerer 'lagret', 'uendret' eller 'ugyldig' (ingenting lagret). */
function amendo_bp_sett_oppfolging($bruker_id, $inn) {
    if (!is_array($inn)) return 'ugyldig';
    $status = is_scalar($inn['status'] ?? null) ? (string) $inn['status'] : '';
    if (!isset(AMENDO_BP_OPPFOLGING[$status])) return 'ugyldig';
    $dato = is_scalar($inn['dato'] ?? null) ? trim((string) $inn['dato']) : '';
    if ($dato !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $dato);
        if (!$d || $d->format('Y-m-d') !== $dato) return 'ugyldig';
    }
    $notat = is_scalar($inn['notat'] ?? null) ? mb_substr(trim(sanitize_textarea_field((string) $inn['notat'])), 0, AMENDO_BP_MAKS_NOTAT) : '';
    $gammel = amendo_bp_oppfolging($bruker_id);
    if (is_array(get_user_meta($bruker_id, '_amendo_bp_oppfolging', true)) && $gammel['status'] === $status && $gammel['dato'] === $dato && $gammel['notat'] === $notat) return 'uendret';
    // wp_slash: update_user_meta() fjerner én backslash, og notatet er fri tekst.
    update_user_meta($bruker_id, '_amendo_bp_oppfolging', wp_slash([
        'status' => $status, 'notat' => $notat, 'dato' => $dato, 'endret' => gmdate('c'), 'endret_av' => get_current_user_id(),
    ]));
    return 'lagret';
}

/** Feltene for én bedrift. `$navn` er prefikset, `$form` skjemaet feltene hører til ('' = det rundt). */
function amendo_bp_oppfolging_felt($bruker_id, $navn, $form = '') {
    $o = amendo_bp_oppfolging($bruker_id);
    $f = $form !== '' ? ' form="' . esc_attr($form) . '"' : '';
    ?>
    <span class="bp-oppfolging" data-status="<?php echo esc_attr($o['status']); ?>">
        <select name="<?php echo esc_attr($navn . '[status]'); ?>"<?php echo $f; ?> aria-label="Oppfølgingsstatus">
            <?php foreach (AMENDO_BP_OPPFOLGING as $k => $etikett): ?><option value="<?php echo esc_attr($k); ?>"<?php selected($o['status'], $k); ?>><?php echo esc_html($etikett); ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="<?php echo esc_attr($navn . '[dato]'); ?>"<?php echo $f; ?> value="<?php echo esc_attr($o['dato']); ?>" aria-label="Dato (f.eks. når dere tok kontakt eller avtalen starter)">
        <textarea name="<?php echo esc_attr($navn . '[notat]'); ?>"<?php echo $f; ?> rows="2" maxlength="<?php echo AMENDO_BP_MAKS_NOTAT; ?>" placeholder="Notat" aria-label="Notat"><?php echo esc_textarea($o['notat']); ?></textarea>
        <?php if ($o['endret'] && ($t = strtotime($o['endret']))): $av = $o['endret_av'] ? get_userdata($o['endret_av']) : null; ?>
            <span class="field-help bp-endret">Endret <?php echo esc_html(wp_date('d.m.Y H:i', $t) . ($av ? ' av ' . $av->display_name : '')); ?></span>
        <?php endif; ?>
    </span>
    <?php
}

function amendo_bp_status_tekst($b2b_status) {
    return ['venter' => 'Venter', 'godkjent' => 'Godkjent', 'avvist' => 'Avvist'][$b2b_status] ?? '—';
}

// Kolonnene i Søknader (includes/moduler/b2b.php).
add_filter('amendo_b2b_soknad_kolonner', function($k) {
    return $k + ['pakke' => 'Pakke', 'ref' => 'Ref', 'oppfolging' => 'Oppfølging'];
});
add_action('amendo_b2b_soknad_celle', function($u, $nokkel = 'pakke') {
    if ($nokkel === 'pakke') {
        echo amendo_bp_bruker_html($u->ID, false) ?: '—'; // escapet i amendo_bp_bruker_html
    } elseif ($nokkel === 'ref') {
        echo esc_html((string) get_user_meta($u->ID, '_amendo_bp_ref', true) ?: '—');
    } elseif ($nokkel === 'oppfolging') {
        if (!amendo_bp_har_soknad($u->ID)) { echo '—'; return; }
        amendo_bp_oppfolging_felt($u->ID, 'bp_oppf[s][' . (int) $u->ID . ']', 'amendo-bp-oppfolging');
        echo '<button type="submit" form="amendo-bp-oppfolging" name="lagre" value="s:' . (int) $u->ID . '" class="amendo-btn-secondary bp-lagre-oppfolging">Lagre</button>';
    }
}, 10, 2);

add_action('amendo_b2b_skjemaer', function() {
    ?>
    <form id="amendo-bp-oppfolging" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('amendo_bp_oppfolging'); ?>
        <input type="hidden" name="action" value="amendo_bp_oppfolging">
        <input type="hidden" name="bp_status" value="<?php echo esc_attr(amendo_bp_filter()); ?>">
    </form>
    <?php
});

/** Statusfilteret fra adressen ('' = alle). */
function amendo_bp_filter() {
    $f = is_scalar($_GET['bp_status'] ?? null) ? (string) $_GET['bp_status'] : '';
    return isset(AMENDO_BP_OPPFOLGING[$f]) ? $f : '';
}

// «Lagre» på én rad: `lagre` = «s:<id>» (Søknader) eller «o:<id>» (oppfølgingskortet).
// En søknad som venter, står i begge tabellene; feltene har derfor hvert sitt
// prefiks (bp_oppf[s] / bp_oppf[o]), så den ene ikke overskriver den andre.
add_action('admin_post_amendo_bp_oppfolging', function() {
    if (!current_user_can(amendo_settings_kapabilitet())) wp_die('Ingen tilgang', 403);
    check_admin_referer('amendo_bp_oppfolging');
    $v = is_scalar($_POST['lagre'] ?? null) ? (string) $_POST['lagre'] : '';
    $svar = 'ukjent';
    if (preg_match('/^([so]):(\d+)$/', $v, $m) && get_userdata((int) $m[2]) && amendo_bp_har_soknad((int) $m[2])) {
        $inn = wp_unslash($_POST['bp_oppf'][$m[1]][$m[2]] ?? null);
        $svar = amendo_bp_sett_oppfolging((int) $m[2], $inn);
    }
    $filter = is_scalar($_POST['bp_status'] ?? null) && isset(AMENDO_BP_OPPFOLGING[(string) $_POST['bp_status']]) ? '&bp_status=' . $_POST['bp_status'] : '';
    wp_redirect(admin_url('admin.php?page=amendo-settings&bp_oppf=' . $svar . $filter . '#b2b'));
    exit;
});

/** Alle som har søkt med pakke eller selgerkode, nyeste først. */
function amendo_bp_soknader() {
    $q = new WP_User_Query([
        'meta_query' => ['relation' => 'OR', ['key' => '_amendo_bp_tid', 'compare' => 'EXISTS'], ['key' => '_amendo_bp_oppfolging', 'compare' => 'EXISTS']],
        'orderby'    => 'registered',
        'order'      => 'DESC',
        'number'     => AMENDO_BP_MAKS_OPPFOLGING,
        'count_total' => true,
    ]);
    $brukere = $q->get_results();
    // Siste innsending først (brukere uten tid sist, etter registrering).
    usort($brukere, function($a, $b) {
        return strcmp((string) get_user_meta($b->ID, '_amendo_bp_tid', true), (string) get_user_meta($a->ID, '_amendo_bp_tid', true));
    });
    return ['brukere' => $brukere, 'totalt' => (int) $q->get_total()];
}

add_action('amendo_b2b_etter_soknader', function() {
    $alle = amendo_bp_soknader();
    $filter = amendo_bp_filter();
    $antall = array_fill_keys(array_keys(AMENDO_BP_OPPFOLGING), 0);
    $rader = [];
    foreach ($alle['brukere'] as $u) {
        $st = amendo_bp_oppfolging($u->ID)['status'];
        $antall[$st]++;
        if ($filter === '' || $filter === $st) $rader[] = $u;
    }
    $melding = [
        'lagret'  => ['ok', 'Oppfølgingen er lagret.'],
        'uendret' => ['ok', 'Ingen endring i oppfølgingen.'],
        'ugyldig' => ['feil', 'Oppfølgingen ble ikke lagret: ugyldig status eller dato.'],
        'ukjent'  => ['feil', 'Fant ikke bedriften.'],
    ][(string) ($_GET['bp_oppf'] ?? '')] ?? null;
    $lenke = function($f) { return add_query_arg(array_filter(['page' => 'amendo-settings', 'bp_status' => $f]), admin_url('admin.php')) . '#b2b'; };
    ?>
    <div class="amendo-card" id="bp-oppfolging">
        <h2>Oppfølging av bedriftspakker (<?php echo (int) $alle['totalt']; ?>)</h2>
        <p class="amendo-desc">Alle som har søkt med en pakke eller selgerkode, også de som er godkjent. Ref er selgerkoden fra lenken callsenteret sendte. Butikken får en e-post når en søknad med pakke kommer inn.</p>
        <?php if ($melding): ?>
            <div class="amendo-b2b-melding amendo-b2b-melding-<?php echo esc_attr($melding[0]); ?>" id="bp-oppfolging-melding"><?php echo esc_html($melding[1]); ?></div>
        <?php endif; ?>
        <p class="bp-filter">Status:
            <a href="<?php echo esc_url($lenke('')); ?>" class="bp-filter-valg<?php echo $filter === '' ? ' current' : ''; ?>" data-status="">Alle (<?php echo array_sum($antall); ?>)</a>
            <?php foreach (AMENDO_BP_OPPFOLGING as $k => $etikett): ?>
                · <a href="<?php echo esc_url($lenke($k)); ?>" class="bp-filter-valg<?php echo $filter === $k ? ' current' : ''; ?>" data-status="<?php echo esc_attr($k); ?>"><?php echo esc_html($etikett); ?> (<?php echo (int) $antall[$k]; ?>)</a>
            <?php endforeach; ?>
        </p>
        <?php if ($alle['totalt'] > count($alle['brukere'])): ?><p class="field-help">Viser de <?php echo count($alle['brukere']); ?> nyeste.</p><?php endif; ?>
        <?php if (!$rader): ?>
            <p class="field-help"><?php echo $filter !== '' ? 'Ingen med denne statusen.' : 'Ingen har søkt med pakke ennå.'; ?></p>
        <?php else: ?>
        <table class="amendo-tabell">
            <thead><tr><th>Sendt</th><th>Firma</th><th>Org.nr</th><th>Kontaktperson</th><th>Pakke</th><th>Ref</th><th>Søknad</th><th>Oppfølging</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rader as $u):
                $tid = strtotime((string) get_user_meta($u->ID, '_amendo_bp_tid', true));
                $orgnr = amendo_b2b_orgnr(get_user_meta($u->ID, 'billing_org_number', true) ?: get_user_meta($u->ID, '_orgnr_soknad', true));
                $telefon = (string) get_user_meta($u->ID, 'billing_phone', true);
            ?>
            <tr class="bp-oppfolging-rad" data-bruker="<?php echo (int) $u->ID; ?>">
                <td><?php echo esc_html($tid ? wp_date('d.m.Y H:i', $tid) : '—'); ?></td>
                <td><?php echo esc_html(get_user_meta($u->ID, 'billing_company', true) ?: '—'); ?></td>
                <td><?php echo esc_html($orgnr !== '' ? amendo_bp_orgnr_tekst($orgnr) : '—'); ?></td>
                <td><a href="<?php echo esc_url(get_edit_user_link($u->ID)); ?>"><?php echo esc_html(amendo_bp_kontakt_navn($u)); ?></a><br><span class="field-help"><?php echo esc_html($u->user_email . ($telefon !== '' ? ' · ' . $telefon : '')); ?></span></td>
                <td class="b2b-pakke"><?php echo amendo_bp_bruker_html($u->ID, false) ?: '—'; // escapet ?></td>
                <td class="b2b-ref"><?php echo esc_html((string) get_user_meta($u->ID, '_amendo_bp_ref', true) ?: '—'); ?></td>
                <td class="bp-b2b-status"><?php echo esc_html(amendo_bp_status_tekst((string) get_user_meta($u->ID, '_b2b_status', true))); ?></td>
                <td><?php amendo_bp_oppfolging_felt($u->ID, 'bp_oppf[o][' . (int) $u->ID . ']', 'amendo-bp-oppfolging'); ?></td>
                <td><button type="submit" form="amendo-bp-oppfolging" name="lagre" value="o:<?php echo (int) $u->ID; ?>" class="amendo-btn-secondary bp-lagre-oppfolging">Lagre</button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
});

// Brukerprofilen. Bare for dem som kan bruke Amendo-admin, aldri for kunden selv.
add_action('show_user_profile', 'amendo_bp_profil');
add_action('edit_user_profile', 'amendo_bp_profil');
function amendo_bp_profil($u) {
    if (!current_user_can(amendo_settings_kapabilitet()) || !amendo_bp_har_soknad($u->ID)) return;
    $html = amendo_bp_bruker_html($u->ID);
    $tid = (string) get_user_meta($u->ID, '_amendo_bp_tid', true);
    ?>
    <h2>Bedriftspakke fra søknaden</h2>
    <table class="form-table" role="presentation" id="amendo-bp-profil">
        <tr>
            <th scope="row">Ønsket</th>
            <td><?php echo $html ?: '—'; // escapet i amendo_bp_bruker_html ?>
                <p class="description">Anslagene er regnet på nettsiden og er ikke avtalte priser.<?php if ($tid && ($t = strtotime($tid))): ?> Sendt <?php echo esc_html(wp_date('d.m.Y H:i', $t)); ?>.<?php endif; ?></p></td>
        </tr>
        <tr>
            <th scope="row">Oppfølging</th>
            <td><input type="hidden" name="amendo_bp_oppf_finnes" value="1"><?php amendo_bp_oppfolging_felt($u->ID, 'amendo_bp_oppf'); ?></td>
        </tr>
    </table>
    <?php
}

// Lagring fra profilen (WordPress har sjekket nonce-en). Krever markøren, så
// et skjema uten feltene ikke nullstiller noe.
add_action('personal_options_update', 'amendo_bp_profil_lagre');
add_action('edit_user_profile_update', 'amendo_bp_profil_lagre');
function amendo_bp_profil_lagre($bruker_id) {
    if (empty($_POST['amendo_bp_oppf_finnes']) || !current_user_can(amendo_settings_kapabilitet()) || !current_user_can('edit_user', $bruker_id)) return;
    if (!amendo_bp_har_soknad($bruker_id)) return;
    amendo_bp_sett_oppfolging((int) $bruker_id, wp_unslash($_POST['amendo_bp_oppf'] ?? null));
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

/**
 * Én linje i en oppskrift. `$prefiks` er navnet på lista
 * («bedriftspakker[pakker][0][linjer]» eller «…[nivaer][1][linjer]»), og `$l`
 * et tall; i malen «__prefiks__» og «__l__».
 */
function amendo_bp_linje($prefiks, $l, array $linje) {
    $n = $prefiks . '[' . $l . ']';
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

/** En oppskrift: linjene og knappen. admin.js legger nye linjer i den nærmeste. */
function amendo_bp_oppskrift($prefiks, array $linjer) {
    ?>
    <div class="bp-oppskrift" data-prefiks="<?php echo esc_attr($prefiks); ?>">
        <div class="bp-linjer">
            <?php foreach (array_values($linjer) as $l => $linje) amendo_bp_linje($prefiks, $l, $linje); ?>
        </div>
        <button type="button" class="amendo-btn-secondary bp-legg-til-linje">+ Legg til linje</button>
    </div>
    <?php
}

/** Ett nivå. `$p` og `$i` er tall, eller «__p__»/«__n__» i malen. */
function amendo_bp_niva($p, $i, array $nv) {
    $n = 'bedriftspakker[pakker][' . $p . '][nivaer][' . $i . ']';
    ?>
    <div class="bp-niva">
        <input type="hidden" name="<?php echo esc_attr($n); ?>[nokkel]" value="<?php echo esc_attr($nv['nokkel'] ?? ''); ?>">
        <div class="amendo-grid-2">
            <div class="amendo-field"><label>Navn på nivået</label><input type="text" name="<?php echo esc_attr($n); ?>[navn]" value="<?php echo esc_attr($nv['navn'] ?? ''); ?>" placeholder="Medium"></div>
            <div class="amendo-field"><label>Oppskriften gjelder for</label><input type="number" name="<?php echo esc_attr($n); ?>[per_personer]" value="<?php echo (int) ($nv['per_personer'] ?? 10); ?>" min="1" max="1000" style="width:70px"> personer</div>
        </div>
        <div class="amendo-field"><label>Kort tekst</label><textarea name="<?php echo esc_attr($n); ?>[tekst]" rows="2"><?php echo esc_textarea($nv['tekst'] ?? ''); ?></textarea></div>
        <?php amendo_bp_oppskrift($n . '[linjer]', $nv['linjer'] ?? []); ?>
        <button type="button" class="amendo-btn-danger bp-slett-niva">Slett nivå</button>
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
                <?php amendo_bp_oppskrift($n . '[linjer]', $pk['linjer'] ?? []); ?>
            </div>

            <div class="amendo-field">
                <label>Nivåer (valgfritt)</label>
                <p class="field-help">F.eks. Liten, Medium og Stor, hver med egen tekst og oppskrift. Kunden velger ett. Uten nivåer gjelder oppskriften over.</p>
                <div class="bp-nivaer">
                    <?php foreach (array_values($pk['nivaer'] ?? []) as $k => $nv) amendo_bp_niva($i, $k, $nv); ?>
                </div>
                <button type="button" class="amendo-btn-secondary bp-legg-til-niva">+ Legg til nivå</button>
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
            <template id="bp-linje-mal"><?php amendo_bp_linje('__prefiks__', '__l__', []); ?></template>
            <template id="bp-niva-mal"><?php amendo_bp_niva('__p__', '__n__', []); ?></template>
            <template id="bp-kake-mal"><?php amendo_bp_kake('__p__', '__l__', []); ?></template>
        </div>
    </div>
    <?php
});
