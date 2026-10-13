<?php
/**
 * Moduler: funksjonalitet bare noen av butikkene skal ha, slått av og på under
 * Amendo → ⚙️ Moduler.
 *
 * ── ⚠⚠ EN AVSLÅTT MODUL FINNES IKKE ───────────────────────────────────────
 * Fila til en modul (`includes/moduler/<nøkkel>.php`) lastes BARE når modulen
 * er på. Det er hele mekanismen: ingen `if (modul_aktiv())` spredt rundt i
 * koden, og dermed ingen sjekk som kan glemmes. Er modulen av, er ingen av
 * krokene, fanene, feltene eller REST-rutene dens registrert i det hele tatt.
 * Lagrede data blir liggende, så en modul som slås på igjen er som før.
 *
 * I /amendo-settings/v1/settings som `moduler`: lista med nøklene som er på,
 * f.eks. `["b2b"]`, og `[]` når ingen er det. Feltet er alltid med, slik at
 * frontenden kan skille «ingen moduler på» fra en eldre plugin uten moduler.
 *
 * ── Krokene en modul kan bruke (alle i amendo-settings.php) ────────────────
 *     amendo_admin_faner            fanen i admin (<button class="amendo-tab">)
 *     amendo_admin_paneler          panelet, INNE i hovedskjemaet
 *     amendo_admin_etter_skjema     egne <form>-er, UTENFOR hovedskjemaet
 *                                   (HTML tillater ikke nøstede skjemaer)
 *     amendo_lagre_innstillinger    ($post) ved lagring, allerede wp_unslash-et
 *     amendo_settings_svar          (filter) svaret fra /settings
 *
 * ── Auto-aktivering ───────────────────────────────────────────────────────
 * En modul kan ha en `auto`-funksjon som sier om butikken åpenbart bruker den
 * (B2B: noen kunde har `billing_org_number`). Den sjekkes høyst to ganger i
 * døgnet, og kan bare slå modulen PÅ — aldri av.
 *
 * ⚠ Har noen skrudd på bryteren selv, gjelder det for godt: modulen havner i
 * `amendo_moduler_manuelt`, og auto-sjekken rører den aldri igjen. Ellers
 * ville en butikk som slo av B2B fått den på igjen ved neste sjekk.
 *
 * ⚠ «Skrudd på selv» betyr at bryteren ble ENDRET, ikke at skjemaet ble
 * lagret. Hele admin-skjemaet sendes ved hver lagring, så å regne en lagring
 * fra fanen Butikk som et valg ville låst B2B av hos hver butikk som lagret
 * noe før auto-sjekken rakk å kjøre.
 */

if (!defined('ABSPATH')) exit;

/** Hvor ofte auto-sjekken kjører. */
const AMENDO_MODULER_AUTO_HVER = 12 * HOUR_IN_SECONDS;

/**
 * Alle modulene som finnes, på eller av.
 *
 *     nøkkel => [navn, ikon, beskrivelse, fil (relativ til pluginen), auto (callable|null)]
 *
 * Nøkkelen er det frontenden ser i `moduler`. Den kan aldri endres når en
 * butikk har tatt modulen i bruk.
 */
function amendo_moduler_alle() {
    return [
        'b2b' => [
            'navn'        => 'B2B',
            'ikon'        => '🏢',
            'beskrivelse' => 'Bedriftskunder: søknader til godkjenning, oversikt over aktive bedriftskunder, og faktura og andre rettigheter per kundegruppe.',
            'fil'         => 'includes/moduler/b2b.php',
            'auto'        => 'amendo_moduler_auto_b2b',
        ],
    ];
}

/** Nøklene i en option lagret som JSON-liste, bare kjente moduler. */
function amendo_moduler_les($option) {
    $liste = json_decode((string) get_option($option, '[]'), true);
    if (!is_array($liste)) return [];
    return array_values(array_intersect(array_keys(amendo_moduler_alle()), $liste));
}

/** Nøklene som er på, i samme rekkefølge som i amendo_moduler_alle(). */
function amendo_moduler_aktive() {
    return amendo_moduler_les('amendo_moduler');
}

function amendo_modul_aktiv($nokkel) {
    return in_array($nokkel, amendo_moduler_aktive(), true);
}

function amendo_moduler_sett(array $aktive) {
    $aktive = array_values(array_intersect(array_keys(amendo_moduler_alle()), $aktive));
    update_option('amendo_moduler', wp_json_encode($aktive));
}

/** Last fila til en modul som er på. Trygg å kalle flere ganger. */
function amendo_modul_last($nokkel) {
    $modul = amendo_moduler_alle()[$nokkel] ?? null;
    if ($modul) require_once AMENDO_SETTINGS_PATH . $modul['fil'];
}

foreach (amendo_moduler_aktive() as $nokkel) {
    amendo_modul_last($nokkel);
}

// ── Auto-aktivering ─────────────────────────────────────────────────────────

/** Har noen bruker et org.nummer i `billing_org_number`? Én spørring, ett treff. */
function amendo_moduler_auto_b2b() {
    $q = new WP_User_Query([
        'meta_query'  => [['key' => 'billing_org_number', 'value' => '', 'compare' => '!=']],
        'number'      => 1,
        'fields'      => 'ID',
        'count_total' => false,
    ]);
    return !empty($q->get_results());
}

/**
 * Slår på moduler der `auto` svarer ja, og som ingen har tatt stilling til.
 * Returnerer nøklene som ble slått på.
 */
function amendo_moduler_auto_sjekk() {
    $aktive   = amendo_moduler_aktive();
    $manuelt  = amendo_moduler_les('amendo_moduler_manuelt');
    $slatt_paa = [];

    foreach (amendo_moduler_alle() as $nokkel => $modul) {
        if (in_array($nokkel, $aktive, true) || in_array($nokkel, $manuelt, true)) continue;
        if (empty($modul['auto']) || !is_callable($modul['auto'])) continue;
        if (call_user_func($modul['auto'])) $slatt_paa[] = $nokkel;
    }

    if ($slatt_paa) {
        amendo_moduler_sett(array_merge($aktive, $slatt_paa));
        foreach ($slatt_paa as $nokkel) amendo_modul_last($nokkel);
        // /settings har fått `moduler` — frontenden må hente den på nytt.
        if (function_exists('amendo_revalider')) amendo_revalider(['innstillinger']);
    }
    return $slatt_paa;
}

// `init`, ikke `admin_init`: en butikk der ingen logger inn i wp-admin skal
// også få modulen. Transienten gjør det til én option-lesing per forespørsel.
add_action('init', function() {
    if (get_transient('amendo_moduler_auto_sjekket')) return;
    set_transient('amendo_moduler_auto_sjekket', 1, AMENDO_MODULER_AUTO_HVER);
    amendo_moduler_auto_sjekk();
});

// ── Lagring ─────────────────────────────────────────────────────────────────

/**
 * Fra $_POST (allerede wp_unslash-et). Gjør ingenting uten markøren
 * `moduler[finnes]`. `moduler[var][<nøkkel>]` er tilstanden bryteren VISTE;
 * bare en bryter som er endret siden da, endrer modulen — se toppen av fila.
 */
function amendo_moduler_lagre(array $post) {
    $inn = $post['moduler'] ?? null;
    if (!is_array($inn) || empty($inn['finnes'])) return;

    $aktive  = amendo_moduler_aktive();
    $manuelt = amendo_moduler_les('amendo_moduler_manuelt');

    foreach (array_keys(amendo_moduler_alle()) as $nokkel) {
        if (!isset($inn['var'][$nokkel])) continue;
        $var = $inn['var'][$nokkel] === '1';
        $paa = !empty($inn['paa'][$nokkel]);
        if ($paa === $var) continue;

        $aktive = $paa ? array_merge($aktive, [$nokkel]) : array_diff($aktive, [$nokkel]);
        $manuelt[] = $nokkel;
    }

    amendo_moduler_sett($aktive);
    update_option('amendo_moduler_manuelt', wp_json_encode(array_values(array_unique($manuelt))));
}

// ── Admin ───────────────────────────────────────────────────────────────────

/** Fanen ⚙️ Moduler. Kalles inne i <form>. */
function amendo_moduler_fane() {
    $aktive  = amendo_moduler_aktive();
    $manuelt = amendo_moduler_les('amendo_moduler_manuelt');
    ?>
    <div class="amendo-panel" id="tab-moduler">
        <div class="amendo-card">
            <h2>Moduler</h2>
            <p class="amendo-desc">Funksjoner ikke alle butikker trenger. En modul som er av, er helt borte: ingen fane, ingen felter og ingenting i det nettsiden leser. Det som er lagret i en modul, blir liggende til den slås på igjen.</p>
            <input type="hidden" name="moduler[finnes]" value="1">
            <?php foreach (amendo_moduler_alle() as $nokkel => $modul):
                $paa = in_array($nokkel, $aktive, true);
                $id  = 'modul_' . $nokkel;
            ?>
            <div class="amendo-field amendo-modul">
                <input type="hidden" name="moduler[var][<?php echo esc_attr($nokkel); ?>]" value="<?php echo $paa ? '1' : '0'; ?>">
                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($modul['ikon'] . ' ' . $modul['navn']); ?></label>
                <label class="toggle">
                    <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="moduler[paa][<?php echo esc_attr($nokkel); ?>]" value="1" <?php checked($paa); ?>>
                    <span class="toggle-slider"></span>
                </label>
                <p class="field-help"><?php echo esc_html($modul['beskrivelse']); ?></p>
                <?php if (!empty($modul['auto']) && !in_array($nokkel, $manuelt, true)): ?>
                    <p class="field-help">Slås på automatisk når butikken ser ut til å bruke den. Slår du den av her, blir den av.</p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
