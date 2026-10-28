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
 * ⚠ Ett unntak, og det gir ingen butikk noe nytt: 1.4.23 delte B2B i flere
 * nøkler (helsebygget, levering, faktura), som garcon-frontend (malen for alle
 * butikkene) leser hver for seg. En butikk som hadde B2B på, hadde alt dette
 * fra før, så engangsmigreringen amendo_moduler_migrer_1423() slår dem på
 * ÉN gang, bare der B2B var på. Butikker uten B2B får ingenting. Se under.
 *
 * `moduler_kjente` i /settings (1.4.23): alle nøklene denne versjonen
 * kjenner, på eller av. Frontenden skiller «av» fra «finnes ikke i denne
 * versjonen» med den.
 *
 * En modul uten PHP-kode (`fil` = null) er bare en bryter: den finnes i
 * `moduler` når den er på, og det er frontenden som gjør noe med den.
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
 *     nøkkel => [navn, ikon, beskrivelse, fil (relativ til pluginen, eller null)]
 *
 * Nøkkelen er det frontenden ser i `moduler`. Den kan aldri endres når en
 * butikk har tatt modulen i bruk. Rekkefølgen er også rekkefølgen i
 * `moduler_kjente`, og modulene lastes i den (helsebygget etter b2b).
 */
function amendo_moduler_alle() {
    return [
        'b2b' => [
            'navn'        => 'B2B',
            'ikon'        => '🏢',
            'beskrivelse' => 'Bedriftskunder: søknader til godkjenning, oversikt over aktive bedriftskunder, og faktura og andre rettigheter per kundegruppe.',
            'fil'         => 'includes/moduler/b2b.php',
        ],
        'helsebygget' => [
            'navn'        => 'Helsebygget',
            'ikon'        => '🏥',
            'beskrivelse' => 'Helsebygget-inngangen på nettsiden: eget sortiment, innlogging med engangskode, Helsebygget-admin med PIN for de ansatte og egen rabattsats per kundegruppe. Avdelinger kan settes til inngangen «Helsebygget».',
            'fil'         => 'includes/moduler/helsebygget.php',
        ],
        'henting' => [
            'navn'        => 'Hentedato på ordrer',
            'ikon'        => '🧺',
            'beskrivelse' => 'Én hentelinje («Henting: Stasjonen, onsdag 21.10 kl. 11:15») på ordresiden, i ordrelisten, i e-postene og i WP Overnight Order List; filter på hentedato og hentested; og hentedatoen til Tellix. Gjelder bare ordrer med _hentedato fra den nye kassen.',
            'fil'         => 'includes/moduler/henting.php',
        ],
        'levering' => [
            'navn'        => 'Levering (fast pris)',
            'ikon'        => '🚚',
            'beskrivelse' => 'Levering med fast pris i kassen, som en vare (leveringsproduktet under Levering & Henting). Bare en bryter for nettsiden.',
            'fil'         => null,
        ],
        'faktura' => [
            'navn'        => 'Faktura',
            'ikon'        => '🧾',
            'beskrivelse' => 'Faktura i kassen via Amendo Invoice til Tellix, for kundegruppene som har faktura. Bare en bryter for nettsiden.',
            'fil'         => null,
        ],
        'wolt' => [
            'navn'        => 'Wolt-levering',
            'ikon'        => '🛵',
            'beskrivelse' => 'Levering med Wolt Drive: pris og tid fra Wolt i kassen, og bestilling hos Wolt når ordren er betalt. Krever Wolt-oppsettet i «Bursdagsabonnement – Wolt».',
            'fil'         => 'includes/moduler/wolt.php',
        ],
        'gavekort' => [
            'navn'        => 'Gavekort',
            'ikon'        => '🎁',
            'beskrivelse' => 'Kjøp og innløsning av gavekort fra WooCommerce Gift Cards i kassen på nettsiden.',
            'fil'         => 'includes/moduler/gavekort.php',
        ],
        'sporing' => [
            'navn'        => 'Sporing',
            'ikon'        => '📈',
            'beskrivelse' => 'Google Tag Manager, Klaviyo og Facebook-piksel på nettsiden, med samtykke. ID-ene står under Butikk. Bare en bryter for nettsiden.',
            'fil'         => null,
        ],
    ];
}

/** Alle nøklene pluginen kjenner, på eller av. `moduler_kjente` i /settings. */
function amendo_moduler_kjente() {
    return array_keys(amendo_moduler_alle());
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

/**
 * 1.4.23, én gang per butikk (flagget `amendo_migrert_1423`):
 *
 * 1. Var B2B på, slås helsebygget, levering og faktura på. Før 1.4.23 hørte
 *    alt det til B2B, så garcon.no skal ikke miste noe. wolt, gavekort og
 *    sporing starter av. Var B2B av, endres ingenting.
 * 2. Avdelingene får `inngang`: 'helsebygget' på avdelingen med
 *    «helsebygget» i navnet (samme treff som frontenden gjorde før,
 *    `navn.includes('helsebygget')`), 'nettbutikk' på de andre.
 *
 * Kjøres før modulene lastes, så de nye modulene finnes alt i samme
 * forespørsel.
 *
 * ⚠ Flagget settes bare når skrivingen gikk gjennom. Feiler en skriving (en
 * låst database), prøves migreringen på nytt i neste forespørsel. Den gjør
 * ingenting to ganger: modulene er en mengde, og `inngang` røres ikke der den
 * finnes.
 */
function amendo_moduler_migrer_1423() {
    if (get_option('amendo_migrert_1423')) return;
    $ok = true;
    $skriv = function ($option, $verdi) use (&$ok) {
        $ny = wp_json_encode($verdi);
        if ($ny !== get_option($option) && !update_option($option, $ny)) $ok = false;
    };
    $lagret = json_decode((string) get_option('amendo_moduler', '[]'), true);
    if (is_array($lagret) && in_array('b2b', $lagret, true)) {
        $ny = array_values(array_unique(array_merge($lagret, ['helsebygget', 'levering', 'faktura'])));
        $skriv('amendo_moduler', array_values(array_intersect(array_keys(amendo_moduler_alle()), $ny)));
    }
    $avdelinger = json_decode((string) get_option('amendo_avdelinger', ''), true);
    if (is_array($avdelinger) && $avdelinger) {
        foreach ($avdelinger as &$avd) {
            if (is_array($avd) && !isset($avd['inngang'])) {
                $avd['inngang'] = stripos((string) ($avd['navn'] ?? ''), 'helsebygget') !== false ? 'helsebygget' : 'nettbutikk';
            }
        }
        unset($avd);
        $skriv('amendo_avdelinger', $avdelinger);
    }
    if ($ok) update_option('amendo_migrert_1423', '1');
}
amendo_moduler_migrer_1423();

foreach (amendo_moduler_aktive() as $nokkel) {
    $fil = amendo_moduler_alle()[$nokkel]['fil'];
    if ($fil) require_once AMENDO_SETTINGS_PATH . $fil;
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
