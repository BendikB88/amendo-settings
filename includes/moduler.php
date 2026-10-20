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
 * ── ⚠⚠ ALT ER AV TIL NOEN SLÅR DET PÅ ─────────────────────────────────────
 * Ingen modul slås på av seg selv. Pluginen oppdateres hos alle butikkene
 * (emmk, Garçon, Aanerud, Dorothea …), og en oppdatering skal aldri gi en
 * butikk en fane eller et REST-felt den ikke har bedt om.
 *
 * Under utviklingen av 1.4.12 fantes en auto-aktivering (B2B på når en kunde
 * hadde `billing_org_number`). Den ble fjernet før release: feltet kan
 * skrives av andre plugins (kasser med org.nr-felt, B2B-plugins), så den kunne
 * slått på B2B hos en butikk som aldri skulle ha det. Ikke legg den tilbake.
 *
 * ── Krokene en modul kan bruke (alle i amendo-settings.php) ────────────────
 *     amendo_admin_faner            fanen i admin (<button class="amendo-tab">)
 *     amendo_admin_paneler          panelet, INNE i hovedskjemaet
 *     amendo_admin_etter_skjema     egne <form>-er, UTENFOR hovedskjemaet
 *                                   (HTML tillater ikke nøstede skjemaer)
 *     amendo_lagre_innstillinger    ($post) ved lagring, allerede wp_unslash-et
 *     amendo_settings_svar          (filter) svaret fra /settings
 */

if (!defined('ABSPATH')) exit;

/**
 * Alle modulene som finnes, på eller av.
 *
 *     nøkkel => [navn, ikon, beskrivelse, fil (relativ til pluginen)]
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
        ],
        'henting' => [
            'navn'        => 'Hentedato på ordrer',
            'ikon'        => '🧺',
            'beskrivelse' => 'Én hentelinje («Henting: Stasjonen, onsdag 21.10 kl. 11:15») på ordresiden, i ordrelisten, i e-postene og i WP Overnight Order List; filter på hentedato og hentested; og hentedatoen til Tellix. Gjelder bare ordrer med _hentedato fra den nye kassen.',
            'fil'         => 'includes/moduler/henting.php',
        ],
    ];
}

/** Nøklene som er på, i samme rekkefølge som i amendo_moduler_alle(). */
function amendo_moduler_aktive() {
    $liste = json_decode((string) get_option('amendo_moduler', '[]'), true);
    if (!is_array($liste)) return [];
    return array_values(array_intersect(array_keys(amendo_moduler_alle()), $liste));
}

function amendo_modul_aktiv($nokkel) {
    return in_array($nokkel, amendo_moduler_aktive(), true);
}

foreach (amendo_moduler_aktive() as $nokkel) {
    require_once AMENDO_SETTINGS_PATH . amendo_moduler_alle()[$nokkel]['fil'];
}

// ── Lagring ─────────────────────────────────────────────────────────────────

/**
 * Fra $_POST (allerede wp_unslash-et). Gjør ingenting uten markøren
 * `moduler[finnes]`, så andre skjemaer aldri slår av modulene. Bryterne er
 * tilstanden: avkrysset er på, ellers av.
 */
function amendo_moduler_lagre(array $post) {
    $inn = $post['moduler'] ?? null;
    if (!is_array($inn) || empty($inn['finnes'])) return;

    $paa = is_array($inn['paa'] ?? null) ? array_keys(array_filter($inn['paa'])) : [];
    $aktive = array_values(array_intersect(array_keys(amendo_moduler_alle()), $paa));
    update_option('amendo_moduler', wp_json_encode($aktive));
}

// ── Admin ───────────────────────────────────────────────────────────────────

/** Fanen ⚙️ Moduler. Kalles inne i <form>. */
function amendo_moduler_fane() {
    $aktive = amendo_moduler_aktive();
    ?>
    <div class="amendo-panel" id="tab-moduler">
        <div class="amendo-card">
            <h2>Moduler</h2>
            <p class="amendo-desc">Funksjoner ikke alle butikker trenger. En modul som er av, er helt borte: ingen fane, ingen felter og ingenting i det nettsiden leser. Det som er lagret i en modul, blir liggende til den slås på igjen.</p>
            <input type="hidden" name="moduler[finnes]" value="1">
            <?php foreach (amendo_moduler_alle() as $nokkel => $modul):
                $id = 'modul_' . $nokkel;
            ?>
            <div class="amendo-field amendo-modul">
                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($modul['ikon'] . ' ' . $modul['navn']); ?></label>
                <label class="toggle">
                    <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="moduler[paa][<?php echo esc_attr($nokkel); ?>]" value="1" <?php checked(in_array($nokkel, $aktive, true)); ?>>
                    <span class="toggle-slider"></span>
                </label>
                <p class="field-help"><?php echo esc_html($modul['beskrivelse']); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
