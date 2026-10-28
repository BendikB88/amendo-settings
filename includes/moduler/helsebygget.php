<?php
/**
 * Modulen Helsebygget (1.4.23). Lastes BARE når modulen er på — se
 * includes/moduler.php.
 *
 * Før 1.4.23 var Helsebygget en del av B2B. garcon-frontend er nå malen for
 * alle butikkene og må kunne slå inngangen av og på for seg (`helsebygget` i
 * `moduler`), så den er løftet ut som egen nøkkel. Koden er den samme:
 *
 * - b2b-helsebygget.php: ansatte med PIN og loggen (lastes bare herfra).
 * - b2b-tellix.php og b2b-kundegrupper.php: rabatt/telefon og «Bare
 *   Helsebygget» per kundegruppe, som inngangen trenger for rabattsatsen.
 *   De lastes også av B2B; require_once gjør at det går bra med begge på.
 *
 * Er B2B av, får Helsebygget sin egen fane med det samme innholdet som
 * underfanen i B2B (pluss kundegruppene, der satsen settes).
 *
 * I /settings: `helsebygget: { navn }`, visningsnavnet på inngangen
 * («Helsebygget»). Tomt = frontenden bruker sitt eget.
 *
 * Avdelingsfeltet `inngang` ('nettbutikk' | 'helsebygget') ligger i kjernen
 * (includes/mal.php), fordi det er med i /leveringsregler for alle
 * avdelinger. Valget «Helsebygget» vises bare når denne modulen er på.
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/b2b-tellix.php';
require_once __DIR__ . '/b2b-kundegrupper.php';
require_once __DIR__ . '/b2b-helsebygget.php';

add_filter('amendo_settings_svar', function($svar) {
    $svar['helsebygget'] = ['navn' => (string) get_option('amendo_helsebygget_navn', '')];
    return $svar;
});

add_action('amendo_lagre_innstillinger', function(array $post) {
    // Bare med markøren: et skjema uten feltet skal ikke tømme det.
    if (!isset($post['helsebygget_navn_finnes'])) return;
    update_option('amendo_helsebygget_navn', sanitize_text_field((string) ($post['helsebygget_navn'] ?? '')));
});

/** Visningsnavnet. Inne i hovedskjemaet. */
function amendo_hb_navn_kort() {
    ?>
    <div class="amendo-card" id="helsebygget-navn">
        <h2>Inngangen</h2>
        <input type="hidden" name="helsebygget_navn_finnes" value="1">
        <div class="amendo-field">
            <label for="helsebygget_navn">Navn på nettsiden</label>
            <input type="text" id="helsebygget_navn" name="helsebygget_navn" value="<?php echo esc_attr(get_option('amendo_helsebygget_navn', '')); ?>" placeholder="Helsebygget" maxlength="60">
            <p class="field-help">Det kundene ser, f.eks. «Helsebygget». Tom = nettsidens eget navn. Hentestedet er avdelingen med inngangen «Helsebygget» (under Avdelinger).</p>
        </div>
    </div>
    <?php
}

// Egen fane bare når B2B er av. B2B lastes før denne (rekkefølgen i
// amendo_moduler_alle()), så funksjonen finnes alt når B2B er på.
if (!function_exists('amendo_b2b_soknader')) {
    add_action('amendo_admin_faner', function() {
        echo '<button type="button" class="amendo-tab" data-tab="helsebygget">🏥 Helsebygget</button>';
    });
    add_action('amendo_admin_paneler', function() {
        ?>
        <div class="amendo-panel" id="tab-helsebygget">
            <?php amendo_hb_navn_kort(); ?>
            <?php amendo_hb_kort(); ?>
            <?php amendo_kg_kort(); ?>
        </div>
        <?php
    });
}
