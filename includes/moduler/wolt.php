<?php
/**
 * Modulen Wolt-levering (1.4.23). Lastes BARE når modulen er på — se
 * includes/moduler.php.
 *
 * POST amendo-settings/v1/wolt-tilbud (X-Amendo-Secret) erstatter
 * `aanerud/v1/wolt-tilbud` fra mu-pluginen aanerud-wolt-frontend.php på
 * aanerudbakeri.no, med samme kontrakt, så malen ikke kaller et namespace med
 * et kundenavn. aanerud/v1 får stå til Aanerud er over på malen.
 *
 *   Inn: { adresse, postnummer, poststed, dato:'YYYY-MM-DD', tid:'HH:MM', telefon?, navn? }
 *   Ut:  { ok:true, leverer:true, utsalg, utsalg_navn,
 *          pris:{ amount (øre inkl. mva), currency }, promise_id, tidspunkt }
 *        { ok:true, leverer:false, feil:'for_tidlig', min_timer }
 *        { ok:true, leverer:false, utsalg, utsalg_navn, melding }   ← melding er Wolts rå svar
 *        400 { ok:false, feil:'ugyldig' }
 *
 * ── Hvor Wolt-oppsettet kommer fra ────────────────────────────────────────
 * Wolt-tillegget «Bursdagsabonnement – Wolt» (aanerud-b2b-birthday-wolt-addon,
 * abbdw_*) har utsalgene (slug, navn, venue_id, nøkkel) og miljøet. Det er
 * også DET som bestiller leveransen hos Wolt etter betaling. Vi leser samme
 * oppsett med abbdw_get_opts(), så tilbudet og bestillingen går mot samme
 * utsalg og samme konto. Uten tillegget svarer ruten 503.
 *
 * Kallet er Wolts shipment-promises, med samme felter som tillegget bruker i
 * abbdw_build_promise_for_order(). `tidspunkt` er midten av luken (tid + halve
 * `levering.luke_minutter`, standard 15), i UTC — samme regel som tillegget
 * bruker for _ab_delivery_window, så tilbudet og bestillingen gjelder samme
 * tid. ⚠ Vi logger ikke adressen eller svaret (tillegget gjør det for sine
 * egne kall).
 *
 * ── Hvilket utsalg ────────────────────────────────────────────────────────
 * Per avdeling, i fanen Wolt: hvilket Wolt-utsalg avdelingen er, og hvilke
 * postnumre og poststeder den leverer til. Postnummer foran poststed, og
 * første avdeling i lista vinner. Ingen treff = leverer ikke, med en melding
 * som inneholder DROPOFF_OUTSIDE_OF_DELIVERY_AREA (det frontenden leser som
 * «Wolt leverer ikke til denne adressen»).
 *
 * ── Etter betaling ────────────────────────────────────────────────────────
 * Ordren lages med _ab_delivery_method = 'wolt_venter', ikke 'wolt': tillegget
 * bestiller alle 'wolt'-ordrer (45 s etter at de er laget, og en skanner hvert
 * 5. minutt), og en ubetalt ordre skal ikke bestilles. Ved betaling byttes den
 * til 'wolt'. I dag gjør mu-pluginen det; vi gjør det SIST (prioritet 99) og
 * bare når den fortsatt står på 'wolt_venter', så de to aldri gjør det dobbelt.
 * Bestillingen gjør tillegget som før.
 */

if (!defined('ABSPATH')) exit;

const AMENDO_WOLT_MIN_TIMER = 4;   // når levering.min_timer ikke er satt (som AB_WOLT_MIN_LEAD_HOURS)
const AMENDO_WOLT_LUKE      = 15;  // minutter, når levering.luke_minutter ikke er satt
const AMENDO_WOLT_FORBEREDELSE = 30; // min_preparation_time_minutes, som ABBDW_MIN_PREP_MIN

// ── Oppsettet ───────────────────────────────────────────────────────────────

/** Wolt-tilleggets oppsett, eller null når tillegget ikke er aktivt. */
function amendo_wolt_oppsett() {
    if (!function_exists('abbdw_get_opts')) return null;
    $o = abbdw_get_opts();
    return is_array($o) ? $o : null;
}

/** Utsalgene i tillegget: [slug => rad]. */
function amendo_wolt_utsalg_alle() {
    $o = amendo_wolt_oppsett();
    $ut = [];
    foreach (is_array($o['outlets'] ?? null) ? $o['outlets'] : [] as $rad) {
        $slug = trim((string) ($rad['slug'] ?? ''));
        if ($slug !== '') $ut[$slug] = $rad;
    }
    return $ut;
}

/** Reglene per avdeling: [avdelingsnavn => {utsalg, postnumre, poststeder}]. */
function amendo_wolt_regler() {
    $raa = json_decode((string) get_option('amendo_wolt_utsalg', '{}'), true);
    $ut = [];
    foreach (is_array($raa) ? $raa : [] as $navn => $r) {
        if (!is_array($r)) continue;
        $ut[(string) $navn] = [
            'utsalg'     => sanitize_text_field((string) ($r['utsalg'] ?? '')),
            'postnumre'  => sanitize_text_field((string) ($r['postnumre'] ?? '')),
            'poststeder' => sanitize_text_field((string) ($r['poststeder'] ?? '')),
        ];
    }
    return $ut;
}

/** «0150-0199, 0250» inneholder postnummeret? */
function amendo_wolt_postnummer_treff($liste, $nr) {
    foreach (preg_split('/[\s,;]+/', (string) $liste, -1, PREG_SPLIT_NO_EMPTY) as $del) {
        if (preg_match('/^(\d{4})-(\d{4})$/', $del, $m)) {
            if ($nr >= $m[1] && $nr <= $m[2]) return true;
        } elseif ($del === $nr) {
            return true;
        }
    }
    return false;
}

function amendo_wolt_poststed_treff($liste, $sted) {
    $sted = mb_strtolower(trim($sted));
    foreach (preg_split('/[,;\n]+/', (string) $liste, -1, PREG_SPLIT_NO_EMPTY) as $del) {
        if (mb_strtolower(trim($del)) === $sted && $sted !== '') return true;
    }
    return false;
}

/** Utsalgs-sluggen for adressen, eller null. Postnummer foran poststed, avdelingene i rekkefølge. */
function amendo_wolt_velg_utsalg($postnummer, $poststed) {
    $navn = array_map(function($a) { return (string) ($a['navn'] ?? ''); }, json_decode((string) get_option('amendo_avdelinger', '[]'), true) ?: []);
    $regler = amendo_wolt_regler();
    foreach (['postnumre', 'poststeder'] as $felt) {
        foreach ($navn as $n) {
            $r = $regler[$n] ?? null;
            if (!$r || $r['utsalg'] === '') continue;
            $treff = $felt === 'postnumre' ? amendo_wolt_postnummer_treff($r['postnumre'], $postnummer) : amendo_wolt_poststed_treff($r['poststeder'], $poststed);
            if ($treff) return $r['utsalg'];
        }
    }
    return null;
}

// ── Ruten ───────────────────────────────────────────────────────────────────

add_action('rest_api_init', function() {
    register_rest_route('amendo-settings/v1', '/wolt-tilbud', [
        'methods'             => 'POST',
        'callback'            => 'amendo_wolt_tilbud',
        'permission_callback' => 'amendo_wolt_tilgang',
    ]);
});

/** Fail-closed: ingen secret satt → 503, feil eller ingen header → 401. */
function amendo_wolt_tilgang(WP_REST_Request $request) {
    $secret = (string) get_option('amendo_kasse_secret', '');
    if ($secret === '') return new WP_Error('amendo_kasse_secret_mangler', 'Kassens secret er ikke satt i Amendo-admin.', ['status' => 503]);
    if (!hash_equals($secret, (string) $request->get_header('X-Amendo-Secret'))) {
        return new WP_Error('amendo_ingen_tilgang', 'Ugyldig secret.', ['status' => 401]);
    }
    return true;
}

/** Gyldig 'YYYY-MM-DD' + 'HH:MM' i butikkens tidssone, ellers null. */
function amendo_wolt_tid($dato, $tid) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dato) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $tid)) return null;
    $t = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$dato $tid", wp_timezone());
    return ($t && $t->format('Y-m-d H:i') === "$dato $tid") ? $t : null;
}

function amendo_wolt_tilbud(WP_REST_Request $request) {
    $p = $request->get_json_params();
    $p = is_array($p) ? $p : [];
    $tekst = function($k) use ($p) { return is_scalar($p[$k] ?? null) ? trim((string) $p[$k]) : ''; };
    $adresse = $tekst('adresse');
    $postnummer = $tekst('postnummer');
    $poststed = $tekst('poststed');
    $start = amendo_wolt_tid($tekst('dato'), $tekst('tid'));
    if ($adresse === '' || !preg_match('/^\d{4}$/', $postnummer) || $poststed === '' || !$start) {
        return new WP_REST_Response(['ok' => false, 'feil' => 'ugyldig'], 400);
    }

    $oppsett = amendo_wolt_oppsett();
    if ($oppsett === null) {
        return new WP_REST_Response(['ok' => false, 'feil' => 'ikke_satt_opp', 'melding' => 'Wolt-tillegget er ikke aktivt.'], 503);
    }

    $regler = function_exists('amendo_mal_levering_regler') ? amendo_mal_levering_regler() : [];
    $min_timer = $regler['min_timer'] ?? null;
    if ($min_timer === null) $min_timer = defined('AB_WOLT_MIN_LEAD_HOURS') ? (int) AB_WOLT_MIN_LEAD_HOURS : AMENDO_WOLT_MIN_TIMER;
    if ($start->getTimestamp() < time() + $min_timer * HOUR_IN_SECONDS) {
        return ['ok' => true, 'leverer' => false, 'feil' => 'for_tidlig', 'min_timer' => $min_timer];
    }

    $slug   = amendo_wolt_velg_utsalg($postnummer, $poststed);
    $utsalg = $slug !== null ? (amendo_wolt_utsalg_alle()[$slug] ?? null) : null;
    if (!$utsalg || empty($utsalg['venue_id']) || empty($utsalg['active'])) {
        return [
            'ok' => true, 'leverer' => false,
            'utsalg' => $slug ?? '', 'utsalg_navn' => $utsalg ? (string) ($utsalg['label'] ?? '') : '',
            'melding' => 'DROPOFF_OUTSIDE_OF_DELIVERY_AREA: ingen avdeling leverer til ' . $postnummer . ' ' . $poststed . '.',
        ];
    }
    $navn = (string) ($utsalg['label'] ?? $slug);

    $luke = $regler['luke_minutter'] ?? null;
    if ($luke === null) $luke = AMENDO_WOLT_LUKE;
    $tidspunkt = $start->modify('+' . (int) round($luke * 30) . ' seconds')->setTimezone(new DateTimeZone('UTC'))->format('c');

    $svar = amendo_wolt_post($oppsett, $utsalg, '/v1/venues/' . rawurlencode((string) $utsalg['venue_id']) . '/shipment-promises', [
        'street'                       => $adresse,
        'city'                         => $poststed,
        'post_code'                    => $postnummer,
        'min_preparation_time_minutes' => defined('ABBDW_MIN_PREP_MIN') ? (int) ABBDW_MIN_PREP_MIN : AMENDO_WOLT_FORBEREDELSE,
        'scheduled_dropoff_time'       => $tidspunkt,
    ]);
    if (is_wp_error($svar)) {
        return new WP_REST_Response(['ok' => false, 'feil' => 'wolt', 'melding' => $svar->get_error_message()], 502);
    }

    $json = $svar['json'];
    $belop = $json['price']['amount'] ?? null;
    if ($svar['kode'] >= 200 && $svar['kode'] < 300 && is_numeric($belop) && !empty($json['id'])) {
        return [
            'ok' => true, 'leverer' => true,
            'utsalg' => $slug, 'utsalg_navn' => $navn,
            'pris' => ['amount' => (int) $belop, 'currency' => (string) ($json['price']['currency'] ?? 'NOK')],
            'promise_id' => (string) $json['id'],
            'tidspunkt' => $tidspunkt,
        ];
    }
    return [
        'ok' => true, 'leverer' => false,
        'utsalg' => $slug, 'utsalg_navn' => $navn,
        'melding' => substr((string) $svar['raa'], 0, 2000) ?: ('HTTP ' . $svar['kode']),
    ];
}

/**
 * POST mot Wolt med tilleggets miljø og nøkkel: utsalgets merchant_key, så
 * api_key på utsalget, så den globale — samme rekkefølge som
 * abbdw_wolt_post_for_outlet(). Logger ingenting.
 *
 * @return array{kode:int, raa:string, json:array}|WP_Error
 */
function amendo_wolt_post(array $oppsett, array $utsalg, $sti, array $data) {
    $base = ($oppsett['env'] ?? '') === 'production' ? 'https://daas-public-api.wolt.com' : 'https://daas-public-api.development.dev.woltapi.com';
    $nokkel = '';
    foreach ([$utsalg['merchant_key'] ?? '', $utsalg['api_key'] ?? '', $oppsett['api_key'] ?? ''] as $k) {
        if (trim((string) $k) !== '') { $nokkel = trim((string) $k); break; }
    }
    if ($nokkel === '') return new WP_Error('amendo_wolt_nokkel', 'Wolt-nøkkelen mangler i Wolt-tillegget.');
    $res = wp_remote_post($base . $sti, [
        'timeout' => 15,
        'headers' => ['Authorization' => 'Bearer ' . $nokkel, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'body'    => wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    if (is_wp_error($res)) return new WP_Error('amendo_wolt_kontakt', 'Fikk ikke kontakt med Wolt.');
    $raa = (string) wp_remote_retrieve_body($res);
    $json = json_decode($raa, true);
    return ['kode' => (int) wp_remote_retrieve_response_code($res), 'raa' => $raa, 'json' => is_array($json) ? $json : []];
}

// ── Etter betaling ──────────────────────────────────────────────────────────

/** 'wolt_venter' → 'wolt' når ordren er betalt. Se toppen. */
function amendo_wolt_betalt($ordre_id) {
    $o = wc_get_order($ordre_id);
    if (!$o || $o->get_meta('_ab_delivery_method') !== 'wolt_venter') return;
    if (!$o->is_paid()) return;
    $o->update_meta_data('_ab_delivery_method', 'wolt');
    $o->add_order_note('Wolt: ordren er betalt, levering bestilles hos Wolt.');
    $o->save();
    // Tillegget bestiller ved neste skanning (hvert 5. minutt). Kjør det med en gang i bakgrunnen.
    if (has_action('abbdw_eager_event') && !wp_next_scheduled('abbdw_eager_event', [(int) $ordre_id])) {
        wp_schedule_single_event(time() + 10, 'abbdw_eager_event', [(int) $ordre_id]);
    }
}
add_action('woocommerce_payment_complete', 'amendo_wolt_betalt', 99);
add_action('woocommerce_order_status_processing', 'amendo_wolt_betalt', 99);
add_action('woocommerce_order_status_completed', 'amendo_wolt_betalt', 99);

// ── Admin ───────────────────────────────────────────────────────────────────

add_filter('amendo_lagring_deler', function($deler) { return $deler + ['wolt' => 'amendo_wolt_utsalg']; });
add_filter('amendo_lagring_navn', function($navn) { return $navn + ['wolt' => 'Wolt-utsalg']; });

/** Fra $_POST. Krever markøren `wolt_utsalg[finnes]` og at delen er endret (includes/lagring.php). */
add_action('amendo_lagre_innstillinger', function(array $post) {
    $inn = $post['wolt_utsalg'] ?? null;
    if (!is_array($inn) || empty($inn['finnes']) || !amendo_lagring_skal_lagre($post, 'wolt')) return;
    $ut = amendo_wolt_regler(); // Avdelinger som ikke var i skjemaet, beholdes.
    foreach (is_array($inn['rader'] ?? null) ? $inn['rader'] : [] as $r) {
        $navn = sanitize_text_field((string) ($r['avdeling'] ?? ''));
        if ($navn === '') continue;
        $ut[$navn] = [
            'utsalg'     => sanitize_text_field((string) ($r['utsalg'] ?? '')),
            'postnumre'  => sanitize_text_field((string) ($r['postnumre'] ?? '')),
            'poststeder' => sanitize_text_field((string) ($r['poststeder'] ?? '')),
        ];
    }
    update_option('amendo_wolt_utsalg', wp_json_encode((object) $ut));
});

add_action('amendo_admin_faner', function() {
    echo '<button type="button" class="amendo-tab" data-tab="wolt">🛵 Wolt</button>';
});

add_action('amendo_admin_paneler', function() {
    $oppsett = amendo_wolt_oppsett();
    $utsalg  = amendo_wolt_utsalg_alle();
    $regler  = amendo_wolt_regler();
    $avdelinger = array_values(array_filter(array_map(function($a) { return (string) ($a['navn'] ?? ''); }, json_decode((string) get_option('amendo_avdelinger', '[]'), true) ?: [])));
    ?>
    <div class="amendo-panel" id="tab-wolt">
        <div class="amendo-card" id="wolt-utsalg" data-amendo-del="wolt">
            <?php amendo_lagring_felt('wolt'); ?>
            <input type="hidden" name="wolt_utsalg[finnes]" value="1">
            <h2>Wolt-utsalg per avdeling</h2>
            <p class="amendo-desc">Hvilket Wolt-utsalg hver avdeling er, og hvor den leverer. Nettsiden spør Wolt om pris og tid for utsalget adressen hører til. Postnummer går foran poststed, og første avdeling i lista vinner.</p>
            <?php if ($oppsett === null): ?>
                <p class="amendo-advarsel" id="wolt-mangler">⚠ Wolt-tillegget («Bursdagsabonnement – Wolt») er ikke aktivt. Uten det kan nettsiden ikke hente pris fra Wolt, og ingen leveranser bestilles.</p>
            <?php else: ?>
                <p class="field-help">Wolt-miljø: <strong><?php echo esc_html(($oppsett['env'] ?? '') === 'production' ? 'produksjon' : 'test'); ?></strong>. Utsalgene settes opp i WooCommerce → Bursdagsabonnement – Wolt.</p>
            <?php endif; ?>
            <?php if (!$avdelinger): ?>
                <p class="field-help">Ingen avdelinger ennå. Legg dem til under Avdelinger først.</p>
            <?php else: ?>
            <table class="amendo-tabell">
                <thead><tr><th>Avdeling</th><th>Wolt-utsalg</th><th>Postnumre</th><th>Poststeder</th></tr></thead>
                <tbody>
                <?php foreach ($avdelinger as $i => $navn):
                    $r = $regler[$navn] ?? ['utsalg' => '', 'postnumre' => '', 'poststeder' => ''];
                    $p = 'wolt_utsalg[rader][' . $i . ']';
                ?>
                <tr class="wolt-rad">
                    <td><?php echo esc_html($navn); ?><input type="hidden" name="<?php echo esc_attr($p); ?>[avdeling]" value="<?php echo esc_attr($navn); ?>"></td>
                    <td>
                        <select name="<?php echo esc_attr($p); ?>[utsalg]" aria-label="Wolt-utsalg for <?php echo esc_attr($navn); ?>">
                            <option value="">— Leverer ikke —</option>
                            <?php foreach ($utsalg as $slug => $u): ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected($r['utsalg'], $slug); ?>><?php echo esc_html(($u['label'] ?? $slug) . (empty($u['active']) ? ' (av)' : '')); ?></option>
                            <?php endforeach; ?>
                            <?php if ($r['utsalg'] !== '' && !isset($utsalg[$r['utsalg']])): ?>
                                <option value="<?php echo esc_attr($r['utsalg']); ?>" selected>(finnes ikke: <?php echo esc_html($r['utsalg']); ?>)</option>
                            <?php endif; ?>
                        </select>
                    </td>
                    <td><input type="text" name="<?php echo esc_attr($p); ?>[postnumre]" value="<?php echo esc_attr($r['postnumre']); ?>" placeholder="0150-0199, 0250"></td>
                    <td><input type="text" name="<?php echo esc_attr($p); ?>[poststeder]" value="<?php echo esc_attr($r['poststeder']); ?>" placeholder="Oslo, Nittedal"></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="field-help">Postnumre: enkeltnumre eller fra–til, skilt med komma. Poststeder: skilt med komma, store og små bokstaver spiller ingen rolle.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
});
