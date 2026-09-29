<?php
/**
 * Delte hemmeligheter mellom WordPress og frontenden (fanen Butikk).
 *
 * Verdien vises aldri, bare «Satt, N tegn» / «Ikke satt». «Generer ny» lager
 * 64 hex-tegn (random_bytes) og viser verdien ÉN gang etter lagringen.
 *
 * ⚠ En secret er ikke et skjemafelt. Den endres BARE når det skjulte feltet
 * amendo_ny_{nøkkel}_secret er '1' (satt av «Generer ny» i admin.js), så
 * lagring fra andre faner aldri tømmer eller bytter den.
 */

if (!defined('ABSPATH')) exit;

function amendo_hemmeligheter() {
    return [
        'kasse' => [
            'option'      => 'amendo_kasse_secret',
            'tittel'      => 'Kasse-secret',
            'beskrivelse' => 'Delt hemmelighet mellom WordPress og kassen i frontenden. Brukes av fraktberegningen, Adyen-sesjonen og betalingsomdirigeringen.',
            'advarsel'    => '<strong>⚠ Samme verdi må inn som <code>KASSE_WEBHOOK_SECRET</code> i frontendens Vercel-prosjekt</strong>, og prosjektet må redeployes. Til det er gjort, feiler betaling i kassen, og fraktberegningen blir begrenset per IP.',
            'bekreft_ny'  => 'Generere kasse-secret? Den må legges inn som KASSE_WEBHOOK_SECRET i Vercel og prosjektet redeployes, ellers feiler betaling i kassen.',
            'bekreft_bytt'=> 'Erstatte kasse-secreten? Den gamle slutter å virke med en gang, og betaling i kassen feiler til den nye er lagt inn som KASSE_WEBHOOK_SECRET i Vercel og prosjektet er redeployet.',
        ],
        'revalidering' => [
            'option'      => 'amendo_revalidate_secret',
            'tittel'      => 'Revaliderings-secret',
            'beskrivelse' => 'Sendes som X-Revalidate-Secret når innstillingene lagres, slik at frontenden henter nytt innhold med én gang.',
            'advarsel'    => '<strong>⚠ Samme verdi må inn i frontendens Vercel-prosjekt</strong>, der <code>/api/revalidate</code> sjekker headeren <code>X-Revalidate-Secret</code>, og prosjektet må redeployes. Til det er gjort, oppdateres nettsiden først når hurtigbufferen går ut av seg selv.',
            'bekreft_ny'  => 'Generere revaliderings-secret? Den må legges inn i Vercel og prosjektet redeployes før nettsiden oppdateres ved lagring.',
            'bekreft_bytt'=> 'Erstatte revaliderings-secreten? Nettsiden oppdateres ikke ved lagring før den nye er lagt inn i Vercel og prosjektet er redeployet.',
        ],
    ];
}

/** Kalles fra lagringen. Lager ny secret der «Generer ny» er brukt. */
function amendo_hemmeligheter_lagre(array $post) {
    foreach (amendo_hemmeligheter() as $nokkel => $h) {
        if (($post["amendo_ny_{$nokkel}_secret"] ?? '') !== '1') continue;
        $ny = bin2hex(random_bytes(32));
        update_option($h['option'], $ny);
        set_transient("amendo_ny_{$nokkel}_secret_" . get_current_user_id(), $ny, 10 * MINUTE_IN_SECONDS);
    }
}

function amendo_hemmelighet_kort($nokkel) {
    $h = amendo_hemmeligheter()[$nokkel];
    $verdi = (string) get_option($h['option'], '');
    $vis_en_gang = "amendo_ny_{$nokkel}_secret_" . get_current_user_id();
    $ny = get_transient($vis_en_gang);
    if ($ny !== false) delete_transient($vis_en_gang);
    $felt = "amendo_ny_{$nokkel}_secret";
    ?>
    <div class="amendo-card amendo-hemmelighet" data-hemmelighet="<?php echo esc_attr($nokkel); ?>">
        <h2><?php echo esc_html($h['tittel']); ?></h2>
        <p class="amendo-desc"><?php echo esc_html($h['beskrivelse']); ?></p>
        <div class="amendo-field">
            <label>Status</label>
            <p class="amendo-secret-status">
                <?php if ($verdi !== ''): ?>
                    <strong>Satt</strong>, <?php echo esc_html(strlen($verdi)); ?> tegn
                <?php else: ?>
                    <strong>Ikke satt</strong>
                <?php endif; ?>
            </p>
        </div>
        <?php if (is_string($ny) && $ny !== ''): ?>
        <div class="amendo-field amendo-secret-ny">
            <label for="<?php echo esc_attr($felt); ?>_verdi">Ny secret — vises bare denne ene gangen</label>
            <div class="amendo-secret-kopier">
                <input type="text" id="<?php echo esc_attr($felt); ?>_verdi" class="amendo-ny-secret" value="<?php echo esc_attr($ny); ?>" readonly>
                <button type="button" class="amendo-btn-secondary amendo-kopier-secret">Kopier</button>
            </div>
            <p class="field-help">Kopier den nå. Etter at siden lastes på nytt, vises bare status.</p>
        </div>
        <?php endif; ?>
        <div class="amendo-advarsel"><?php echo wp_kses_post($h['advarsel']); ?></div>
        <input type="hidden" name="<?php echo esc_attr($felt); ?>" id="<?php echo esc_attr($felt); ?>" class="amendo-secret-flagg" value="0">
        <button type="button" class="amendo-btn-secondary amendo-generer-secret"
            data-bekreft="<?php echo esc_attr($verdi !== '' ? $h['bekreft_bytt'] : $h['bekreft_ny']); ?>">Generer ny</button>
        <p class="field-help">Lagrer også resten av skjemaet.</p>
    </div>
    <?php
}
