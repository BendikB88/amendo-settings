<?php
/**
 * Tilvalg fra Acowebs WCPA (Woocommerce Custom Product Addons) på ordrer laget
 * via REST (1.4.36): POST amendo-settings/v1/kakebilder { order_id }.
 *
 *   Ut:  200 { ok: true, kopiert, mangler: [<url>], tilvalg }
 *        401 uten riktig X-Amendo-Secret, også når secreten er tom
 *        404 ordren finnes ikke
 *        409 { ok: false, feil: 'opptatt' } — et kall på samme ordre kjører
 *
 * Frontenden (malen, lib/kakebilde.ts) kaller ruten rett etter at ordren
 * er opprettet. Spesifikasjonen ligger i malens
 * docs/kakebilder-spesifikasjon.md. Den er utvidet til alle tilvalg, ikke
 * bare bildet. Ruten gjør to ting:
 *
 * ── 1. Opplastede filer ut av wcpa_temp ─────────────────────────────────────
 * WCPA legger opplastingen i uploads/wcpa_uploads/wcpa_temp/<md5>/, og cronen
 * deres SLETTER alt der etter ett døgn (WCPA 5.5.0, includes/cron.php). Den
 * gamle kassen fikk filen kopiert ut ved «legg i kurv»; det skjer ikke for
 * ordrer laget via REST, så en kake bestilt flere dager fram mistet bildet.
 *
 * Varelinjenes meta (ikke `_`) som er `<filnavn> | <url>` eller bare `<url>`
 * under <uploads>/wcpa_uploads/ KOPIERES til uploads/kakebilder/<år>/
 * <ordre-id>-<filnavn>, og verdien skrives om til `<filnavn> | <ny url>` på
 * samme meta_id.
 *   ⚠ Kopiere, ikke flytte: går kunden tilbake i kassen, lager frontenden en
 *     ny ordre med SAMME temp-URL fra kurven. Derfor heller ikke WCPAs egen
 *     File::move_file(), som sletter temp-filen etterpå. Temp ryddes av WCPA.
 *   ⚠ URL-en kommer fra klienten: den gjøres om til en sti under basedir og
 *     sjekkes med realpath() mot wcpa_uploads/. Andre verter røres ikke, og
 *     ingenting hentes over HTTP.
 *   ⚠⚠ GRUPPEN. Etter per-site-isoleringen på ecom4 må filene ha samme gruppe
 *     som resten av uploads (olsread), ellers svarer webserveren 403. Kopien
 *     og mappene får derfor gruppen til uploads-mappen (filteret
 *     `amendo_tilvalg_filgruppe` kan overstyre), og rettighetene WordPress
 *     selv gir opplastinger (mappens, uten kjøring).
 *   Filtypene er det wp_check_filetype_and_ext() godtar, og WCPA legger sine
 *   egne på det filteret (WCPA-innstillingen for ekstra filtyper, f.eks. SVG
 *   til logo): det WCPA tok imot, kopieres. Alle typer, ikke bare bilder —
 *   kunder sender logo som PDF eller SVG. PHP o.l. kopieres aldri.
 *   Allerede under uploads/kakebilder/ («bestill på nytt», eller kall nr. 2):
 *   røres ikke. Finnes ikke filen (kurven lå over et døgn): URL-en i
 *   `mangler`, linjen står, og ordren får ÉTT notat om det.
 *
 * ── 2. _WCPA_order_meta_data ───────────────────────────────────────────────
 * Ikonene/bildene for valg som «Velg kakefyll» og «Velg farge» tegner WCPA selv
 * fra `_WCPA_order_meta_data` på varelinjen (wp-admin, e-postene og
 * PDF-pakkseddelen, via woocommerce_order_item_display_meta_value). Den
 * gamle kassen fikk den fra kurven; REST-ordrer har den ikke. Den bygges her
 * med WCPAs EGEN kode: forespørselen produktsiden ville sendt lages av
 * linjens meta (etikett → feltet, alternativets etikett → verdien), og
 * filteret `wcpa_add_cart_item_data` (Process::add_cart_item_data) leser
 * skjemaet, betingelsene (også variantens attributter) og prisene slik det
 * gjorde ved «legg i kurv». Hvert felt får `meta_id` til raden som allerede
 * står på linjen, og WCPAs Order::update_order_item() kjøres som ved kassen.
 *   ⚠⚠ UTEN meta_id LEGGER WCPA TIL NYE RADER. WCPA kjører
 *     update_order_item() igjen når ordren blir processing («safety net» for
 *     eksterne ordrer); et felt uten meta_id får da en ny rad ved siden av den
 *     frontenden skrev. Felter uten en rad på linjen tas derfor ut.
 *   ⚠ Radene skrives om til WCPAs format (innstillingen «item_meta_format»,
 *     standard «{label} | {value}», bildevalg med « | {image}»), slik den
 *     gamle kassen gjorde. Det skjer uansett ved processing når strukturen
 *     finnes.
 *   Linjer som har strukturen fra før, røres ikke. Uten WCPA eller uten skjema
 *   på produktet: bare filene.
 *
 * ⚠ Ingenting fra ordren logges.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_TILVALG_MAPPE = 'kakebilder';
const AMENDO_TILVALG_WCPA_MAPPE = 'wcpa_uploads';

add_action('rest_api_init', function() {
    register_rest_route('amendo-settings/v1', '/kakebilder', [
        'methods'             => 'POST',
        'callback'            => 'amendo_tilvalg_rest',
        'permission_callback' => 'amendo_bedrift_sok_tilgang', // fail-closed X-Amendo-Secret, se includes/bedrift.php
    ]);
});

function amendo_tilvalg_rest(WP_REST_Request $req) {
    $p = $req->get_json_params();
    $id = (int) ((is_array($p) ? ($p['order_id'] ?? 0) : 0) ?: $req->get_param('order_id'));
    $ordre = $id > 0 ? wc_get_order($id) : false;
    if (!$ordre instanceof WC_Order || $ordre instanceof WC_Order_Refund) {
        return new WP_Error('amendo_ordre_finnes_ikke', 'Ordren finnes ikke.', ['status' => 404]);
    }

    // Ett kall om gangen per ordre (frontenden kan prøve igjen mens det første kjører).
    $las = 'amendo_tilvalg_las_' . $id;
    if (!add_option($las, time(), '', 'no')) {
        if (time() - (int) get_option($las) < 60) {
            return new WP_REST_Response(['ok' => false, 'feil' => 'opptatt'], 409);
        }
        update_option($las, time(), false);
    }
    try {
        $filer = amendo_tilvalg_filer($ordre);
        $tilvalg = 0;
        foreach ($ordre->get_items('line_item') as $linje) {
            if (amendo_tilvalg_struktur($linje) === 'bygget') $tilvalg++;
        }
    } finally {
        delete_option($las);
    }
    return ['ok' => true, 'kopiert' => $filer['kopiert'], 'mangler' => $filer['mangler'], 'tilvalg' => $tilvalg];
}

// ── 1. Filene ───────────────────────────────────────────────────────────────

/** Filene i en meta-verdi: [{ navn, url }], én per linje. Tom liste når verdien ikke er filer. */
function amendo_tilvalg_filverdier($verdi) {
    if (!is_string($verdi) || $verdi === '') return [];
    $ut = [];
    foreach (preg_split('/\r\n|\r|\n/', $verdi) as $linje) {
        $linje = trim($linje);
        if ($linje === '') continue;
        if (preg_match('#^(.*?)\s*\|\s*(https?://\S+)$#i', $linje, $m)) {
            $ut[] = ['navn' => trim($m[1]), 'url' => $m[2]];
        } elseif (preg_match('#^https?://\S+$#i', $linje)) {
            $ut[] = ['navn' => '', 'url' => $linje];
        } else {
            return []; // en linje som ikke er en fil: verdien er noe annet
        }
    }
    return $ut;
}

/**
 * Stien bak en URL under <uploads>/<mappe>/ (uten skjema, så http/https og
 * frontendens WC_BASE_URL ikke spiller noen rolle). false når URL-en ikke er
 * der; null når den er der, men ikke peker på en fil inne i mappen (finnes
 * ikke, ../, symlenke ut).
 */
function amendo_tilvalg_sti($url, $mappe) {
    $up = wp_upload_dir(null, false);
    $base = preg_replace('#^https?://#i', '', untrailingslashit($up['baseurl'])) . '/' . $mappe . '/';
    $uten = preg_replace('#^https?://#i', '', (string) $url);
    if (strpos($uten, $base) !== 0) return false;
    $rel = rawurldecode((string) strtok(substr($uten, strlen($base)), '?#'));
    if ($rel === '' || strpos($rel, "\0") !== false) return null;
    $rot = realpath($up['basedir'] . '/' . $mappe);
    $sti = realpath($up['basedir'] . '/' . $mappe . '/' . $rel);
    if (!$rot || !$sti || strpos($sti, $rot . DIRECTORY_SEPARATOR) !== 0 || !is_file($sti)) return null;
    return $sti;
}

/**
 * Gruppen og rettighetene en ny fil eller mappe under uploads skal ha. Se
 * toppen (403 etter per-site-isoleringen).
 */
function amendo_tilvalg_rettigheter($sti) {
    $up = wp_upload_dir(null, false);
    $gruppe = apply_filters('amendo_tilvalg_filgruppe', @filegroup($up['basedir']), $sti);
    // Mapper som mappen over, filer som wp_handle_upload() (uten kjøring).
    $stat = @stat(dirname($sti));
    $modus = $stat ? (is_dir($sti) ? $stat['mode'] & 07777 : $stat['mode'] & 0000666) : null;
    if ($modus !== null) @chmod($sti, $modus);
    $satt = false;
    if ($gruppe !== false && $gruppe !== null && $gruppe !== '' && @filegroup($sti) !== $gruppe) {
        $satt = @chgrp($sti, $gruppe);
    }
    do_action('amendo_tilvalg_rettigheter', $sti, $gruppe, $satt, $modus);
}

/** Mappen kopiene legges i, med gruppe og rettigheter på hvert nivå som lages. */
function amendo_tilvalg_mappe($aar) {
    $up = wp_upload_dir(null, false);
    $sti = $up['basedir'];
    foreach ([AMENDO_TILVALG_MAPPE, $aar] as $del) {
        $sti .= '/' . $del;
        if (!is_dir($sti)) {
            if (!wp_mkdir_p($sti)) return false;
            amendo_tilvalg_rettigheter($sti);
        }
    }
    return $sti;
}

/**
 * Kopierer filene på ordrens linjer ut av wcpa_uploads. Se toppen.
 *
 * @return array{kopiert:int, mangler:string[]}
 */
function amendo_tilvalg_filer(WC_Order $ordre) {
    $kopiert = 0;
    $mangler = [];
    $nye_notater = [];
    $notert = $ordre->get_meta('_amendo_tilvalg_mangler');
    $notert = is_array($notert) ? $notert : [];
    $aar = $ordre->get_date_created() ? $ordre->get_date_created()->date('Y') : gmdate('Y');

    foreach ($ordre->get_items('line_item') as $linje) {
        $endret = false;
        foreach ($linje->get_meta_data() as $meta) {
            $d = $meta->get_data();
            if (!is_string($d['key']) || $d['key'] === '' || $d['key'][0] === '_') continue;
            $filer = amendo_tilvalg_filverdier($d['value']);
            if (!$filer) continue;

            $ny = [];
            $denne = false;
            foreach ($filer as $f) {
                $navn = $f['navn'] !== '' ? $f['navn'] : wp_basename((string) wp_parse_url($f['url'], PHP_URL_PATH));
                $sti = amendo_tilvalg_sti($f['url'], AMENDO_TILVALG_WCPA_MAPPE);
                if ($sti === false) { $ny[] = $navn . ' | ' . $f['url']; continue; } // ikke vår (eller allerede i kakebilder/)

                $kopi = $sti ? amendo_tilvalg_kopier($sti, $ordre->get_id(), $aar) : null;
                if (!$kopi) {
                    $mangler[] = $f['url'];
                    if (!in_array(md5($f['url']), $notert, true)) {
                        $notert[] = md5($f['url']);
                        $nye_notater[] = sprintf('Opplastet fil på %s («%s») fantes ikke lenger — kontakt kunden.', $linje->get_name(), $d['key']);
                    }
                    $ny[] = $navn . ' | ' . $f['url'];
                    continue;
                }
                $kopiert++;
                $denne = true;
                $ny[] = $navn . ' | ' . $kopi;
            }
            if ($denne) {
                $linje->update_meta_data($d['key'], implode("\r\n", $ny), $d['id']);
                $endret = true;
            }
        }
        if ($endret) $linje->save_meta_data();
    }

    if ($nye_notater) {
        foreach ($nye_notater as $n) $ordre->add_order_note($n);
        $ordre->update_meta_data('_amendo_tilvalg_mangler', $notert);
        $ordre->save();
    }
    return ['kopiert' => $kopiert, 'mangler' => array_values(array_unique($mangler))];
}

/** Kopien av én fil, eller null (ikke en tillatt filtype, kopieringen feilet). Gir URL-en. */
function amendo_tilvalg_kopier($sti, $ordre_id, $aar) {
    $fil = wp_basename($sti);
    $type = wp_check_filetype_and_ext($sti, $fil);
    if (empty($type['ext']) || empty($type['type'])) return null;
    if (!empty($type['proper_filename'])) $fil = $type['proper_filename'];

    $mappe = amendo_tilvalg_mappe($aar);
    if (!$mappe) return null;
    $navn = wp_unique_filename($mappe, sanitize_file_name($ordre_id . '-' . $fil));
    $ny = $mappe . '/' . $navn;
    if (!@copy($sti, $ny)) return null;
    amendo_tilvalg_rettigheter($ny);

    $up = wp_upload_dir(null, false);
    return $up['baseurl'] . '/' . AMENDO_TILVALG_MAPPE . '/' . $aar . '/' . rawurlencode($navn);
}

// ── 2. _WCPA_order_meta_data ────────────────────────────────────────────────

/** Etikett slik frontenden skriver den: dekodet og trimmet. */
function amendo_tilvalg_tekst($s) {
    return trim(html_entity_decode(wp_strip_all_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** Alternativene i et valgfelt: etikett → verdi (også gruppert select). */
function amendo_tilvalg_alternativer($felt) {
    $ut = [];
    foreach ((array) ($felt->values ?? []) as $v) {
        $liste = (isset($v->options) && is_array($v->options)) ? $v->options : [$v];
        foreach ($liste as $a) {
            if (!is_object($a) || !isset($a->value)) continue;
            $etikett = amendo_tilvalg_tekst($a->label ?? $a->value);
            if ($etikett !== '' && !isset($ut[$etikett])) $ut[$etikett] = (string) $a->value;
        }
    }
    return $ut;
}

/**
 * Frontenden skriver flere valg som «A, B». En etikett kan selv ha «, » i seg
 * («Lys, 10 stk»), så delene settes sammen igjen mot etikettene som finnes,
 * lengste først. Verdiene, eller null når noe ikke er et alternativ.
 */
function amendo_tilvalg_del_opp(array $deler, array $alt) {
    if (!$deler) return [];
    for ($n = count($deler); $n >= 1; $n--) {
        $etikett = trim(implode(', ', array_slice($deler, 0, $n)));
        if (!isset($alt[$etikett])) continue;
        $resten = amendo_tilvalg_del_opp(array_slice($deler, $n), $alt);
        if ($resten !== null) return array_merge([$alt[$etikett]], $resten);
    }
    return null;
}

/**
 * Det produktsiden ville sendt for feltet, ut fra radens verdi:
 * ['verdi' => …, 'antall' => int|null], eller null når verdien ikke passer feltet.
 */
function amendo_tilvalg_postverdi($felt, $verdi) {
    $verdi = is_scalar($verdi) ? (string) $verdi : '';
    if (trim($verdi) === '') return null;

    switch ($felt->type) {
        case 'file':
            $filer = amendo_tilvalg_filverdier($verdi);
            if (!$filer) return null;
            $ut = [];
            foreach ($filer as $f) {
                $navn = $f['navn'] !== '' ? $f['navn'] : wp_basename((string) wp_parse_url($f['url'], PHP_URL_PATH));
                $type = wp_check_filetype($navn);
                // Med `url` gir WCPAs File::move_file() filen tilbake uten å flytte noe.
                $ut[] = ['url' => $f['url'], 'type' => (string) $type['type'], 'file_name' => $navn];
            }
            return ['verdi' => $ut, 'antall' => null, 'fil' => true];

        case 'select':
        case 'radio-group':
        case 'checkbox-group':
        case 'color-group':
        case 'image-group':
            $tekst = trim($verdi);
            $antall = null;
            if (!empty($felt->enable_quantity) && preg_match('/^(.*\S)\s+x\s+(\d+)$/u', $tekst, $m)) {
                $tekst = $m[1];
                $antall = (int) $m[2];
            }
            $alt = amendo_tilvalg_alternativer($felt);
            $tekst = amendo_tilvalg_tekst($tekst);
            $valgt = amendo_tilvalg_del_opp(explode(', ', $tekst), $alt);
            if (!$valgt) return null;
            return ['verdi' => count($valgt) > 1 || $felt->type === 'checkbox-group' ? $valgt : $valgt[0], 'antall' => $antall];

        case 'productGroup':
        case 'placeselector':
            return null; // ikke i bruk hos butikkene, og frontenden skriver dem ikke

        default: // tekst, tekstområde, tall, dato …
            return ['verdi' => $verdi, 'antall' => null];
    }
}

/** WCPAs egen Order-instans (fra krokene), eller null. Aldri `new Order()` — da registreres visningen to ganger. */
function amendo_tilvalg_wcpa_order() {
    global $wp_filter;
    $krok = $wp_filter['woocommerce_order_item_display_meta_value'] ?? null;
    if (!$krok) return null;
    foreach ($krok->callbacks as $cbs) {
        foreach ($cbs as $cb) {
            $f = $cb['function'];
            if (is_array($f) && is_object($f[0]) && is_a($f[0], 'Acowebs\WCPA\Order')) return $f[0];
        }
    }
    return null;
}

/**
 * Bygger _WCPA_order_meta_data på én linje. Se toppen.
 *
 * @return string 'bygget' | 'finnes' | 'ingen'
 */
function amendo_tilvalg_struktur(WC_Order_Item_Product $linje) {
    if (!empty($linje->get_meta('_WCPA_order_meta_data'))) return 'finnes';
    if (!class_exists('Acowebs\WCPA\Product') || !has_filter('wcpa_add_cart_item_data')) return 'ingen';

    $pid = (int) $linje->get_product_id();
    $vid = (int) $linje->get_variation_id();
    $skjema = (new \Acowebs\WCPA\Product())->get_fields($pid);
    if (empty($skjema['fields'])) return 'ingen';

    // Radene frontenden skrev (ikke `_`, ikke variantens attributter).
    $rader = [];
    foreach ($linje->get_meta_data() as $meta) {
        $d = $meta->get_data();
        if (!is_string($d['key']) || $d['key'] === '' || $d['key'][0] === '_') continue;
        if (taxonomy_is_product_attribute($d['key']) || meta_is_product_attribute($d['key'], $d['value'], $pid)) continue;
        $rader[] = ['id' => (int) $d['id'], 'key' => amendo_tilvalg_tekst($d['key']), 'value' => $d['value']];
    }
    if (!$rader) return 'ingen';

    $post = [];
    $filfelt = [];
    $kandidater = []; // elementId → meta_id-er som passer feltet
    foreach ($skjema['fields'] as $seksjon) {
        foreach ((array) ($seksjon->fields ?? []) as $rad) {
            foreach ((array) $rad as $felt) {
                if (!is_object($felt) || empty($felt->type) || empty($felt->elementId)) continue;
                if (in_array($felt->type, ['separator', 'groupValidation', 'header', 'content', 'formselector'], true)) continue;
                if (isset($felt->active) && $felt->active === false) continue;
                $navn = $felt->name ?? $felt->elementId;
                if (!is_string($navn) || $navn === '') continue; // repeterte felter

                $etiketter = array_filter([amendo_tilvalg_tekst($felt->label ?? ''), amendo_tilvalg_tekst($felt->cartLabel ?? '')]);
                foreach ($rader as $r) {
                    if (!in_array($r['key'], $etiketter, true)) continue;
                    $v = amendo_tilvalg_postverdi($felt, $r['value']);
                    if ($v === null) continue;
                    $kandidater[$felt->elementId][] = $r['id'];
                    if (array_key_exists($navn, $post)) continue;
                    if (!empty($v['fil'])) {
                        $post[$navn] = $v['verdi'];
                        $filfelt[$navn] = true;
                    } else {
                        $post[$navn] = wp_slash($v['verdi']); // som en ekte POST
                        if ($v['antall'] !== null) $post[$navn . '_quantity'] = (string) $v['antall'];
                    }
                }
            }
        }
    }
    if (!$post) return 'ingen';

    // Variantens attributter, til betingelser på varianten («Velg fyll» per størrelse).
    $attributter = [];
    if ($vid && ($variant = wc_get_product($vid))) {
        foreach ($variant->get_variation_attributes() as $k => $v) {
            if ($v === '') $v = (string) $linje->get_meta(substr($k, strlen('attribute_')));
            $attributter[$k] = $v;
        }
    }

    $lagret = [$_POST, $_GET, $_REQUEST];
    $_POST = $post;
    $_GET = [];
    $_REQUEST = $post + $attributter;
    $tving = function($felt) {
        // Vanlig opplasting leser $_FILES; vi har filen allerede.
        if (is_object($felt) && ($felt->type ?? '') === 'file' && ($felt->upload_type ?? '') === 'basic') {
            $felt = clone $felt;
            $felt->upload_type = 'ajax';
        }
        return $felt;
    };
    add_filter('wcpa_form_field', $tving, 1);
    try {
        $kurv = apply_filters('wcpa_add_cart_item_data', ['variation' => $attributter], $pid, $vid ?: false, max(1, (int) $linje->get_quantity()), true);
    } catch (\Throwable $e) {
        $kurv = null;
    } finally {
        remove_filter('wcpa_form_field', $tving, 1);
        [$_POST, $_GET, $_REQUEST] = $lagret;
    }
    $seksjoner = is_array($kurv) ? ($kurv['wcpa_data'] ?? null) : null;
    if (!is_array($seksjoner) || !$seksjoner) return 'ingen';

    // meta_id på hvert felt; felter uten en rad på linjen tas ut (se toppen).
    $brukt = [];
    $antall = 0;
    foreach ($seksjoner as $nokkel => $seksjon) {
        $nye = [];
        foreach ((array) ($seksjon['fields'] ?? []) as $rad) {
            $ny = [];
            foreach ((array) $rad as $f) {
                if (empty($f['type']) || empty($f['elementId'])) continue;
                $mid = null;
                foreach ($kandidater[$f['elementId']] ?? [] as $k) {
                    if (!in_array($k, $brukt, true)) { $mid = $k; break; }
                }
                if ($mid === null || $f['value'] === false || $f['value'] === '' || $f['value'] === []) continue;
                if ($f['type'] === 'file' && is_array($f['value'])) {
                    foreach ($f['value'] as $i => $fil) {
                        // WCPA setter `file` til URL-en; e-postvedlegg vil ha stien.
                        $sti = amendo_tilvalg_sti($fil['url'] ?? '', AMENDO_TILVALG_MAPPE);
                        if (!$sti) $sti = amendo_tilvalg_sti($fil['url'] ?? '', AMENDO_TILVALG_WCPA_MAPPE);
                        $f['value'][$i]['file'] = $sti ? wp_normalize_path($sti) : '';
                    }
                }
                $brukt[] = $mid;
                $f['meta_id'] = $mid;
                $ny[] = $f;
                $antall++;
            }
            if ($ny) $nye[] = $ny;
        }
        if ($nye) {
            $seksjoner[$nokkel]['fields'] = $nye;
        } else {
            unset($seksjoner[$nokkel]);
        }
    }
    if (!$antall) return 'ingen';

    $linje->update_meta_data('_WCPA_order_meta_data', $seksjoner);
    $bilde = $kurv['wcpa_cart_rules']['thumb_image'] ?? false;
    if (is_numeric($bilde) && (int) $bilde > 0) $linje->update_meta_data('_wcpa_thumb_image_id', (int) $bilde);
    $linje->save_meta_data();

    // Som ved kassen (Order::checkout_order_processed): radene i WCPAs format.
    $wcpa = amendo_tilvalg_wcpa_order();
    if ($wcpa && method_exists($wcpa, 'update_order_item')) {
        try {
            $wcpa->update_order_item($linje, false);
        } catch (\Throwable $e) {
            // Strukturen står; WCPA gjør det samme ved processing.
        }
    }
    return 'bygget';
}
