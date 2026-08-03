<?php
/**
 * ACF feltgrupper for alle sider
 * REST API for sideinnhold er definert i amendo-settings.php
 */
if (!defined('ABSPATH') || !function_exists('acf_add_local_field_group')) return;

add_action('acf/init', function() {

    // FORSIDE
    acf_add_local_field_group([
        'key'      => 'group_forside',
        'title'    => '🏠 Forside',
        'position' => 'normal',
        'location' => [[['param'=>'page_type','operator'=>'==','value'=>'front_page']]],
        'fields'   => [
            ['key'=>'field_hero_tab','label'=>'🎯 Hero','name'=>'','type'=>'tab','placement'=>'top'],
            ['key'=>'field_hero_tittel','label'=>'Tittel linje 1','name'=>'hero_tittel','type'=>'text','default_value'=>'Bakeri, kafe'],
            ['key'=>'field_hero_tittel2','label'=>'Tittel linje 2','name'=>'hero_tittel2','type'=>'text','default_value'=>'& jobbmat'],
            ['key'=>'field_hero_undertekst','label'=>'Undertekst','name'=>'hero_undertekst','type'=>'textarea','rows'=>2],
            ['key'=>'field_hero_bilde','label'=>'Bakgrunnsbilde','name'=>'hero_bilde','type'=>'image','return_format'=>'url'],
            ['key'=>'field_hero_knapp1_tekst','label'=>'Knapp 1 tekst','name'=>'hero_knapp1_tekst','type'=>'text','default_value'=>'Se menyen'],
            ['key'=>'field_hero_knapp1_lenke','label'=>'Knapp 1 lenke','name'=>'hero_knapp1_lenke','type'=>'text','default_value'=>'/produkter'],
            ['key'=>'field_hero_knapp2_tekst','label'=>'Knapp 2 tekst','name'=>'hero_knapp2_tekst','type'=>'text','default_value'=>'Bedriftsavtale'],
            ['key'=>'field_hero_knapp2_lenke','label'=>'Knapp 2 lenke','name'=>'hero_knapp2_lenke','type'=>'text','default_value'=>'/bedrift'],
            ['key'=>'field_marquee_tab','label'=>'📢 Marquee','name'=>'','type'=>'tab','placement'=>'top'],
            ['key'=>'field_marquee_elementer','label'=>'Elementer','name'=>'marquee_elementer','type'=>'repeater','button_label'=>'Legg til','min'=>1,'max'=>12,
                'sub_fields'=>[['key'=>'field_marquee_tekst','label'=>'Tekst','name'=>'tekst','type'=>'text']]
            ],
            ['key'=>'field_features_tab','label'=>'⭐ Features','name'=>'','type'=>'tab','placement'=>'top'],
            ['key'=>'field_features_etikett','label'=>'Etikett','name'=>'features_etikett','type'=>'text','default_value'=>'Hva vi tilbyr'],
            ['key'=>'field_features_tittel','label'=>'Tittel','name'=>'features_tittel','type'=>'text','default_value'=>'Et bakeri for enhver anledning'],
            ['key'=>'field_features_kort','label'=>'Kort','name'=>'features_kort','type'=>'repeater','button_label'=>'Legg til kort','min'=>1,'max'=>6,
                'sub_fields'=>[
                    ['key'=>'field_fk_bilde','label'=>'Bilde','name'=>'bilde','type'=>'image','return_format'=>'url'],
                    ['key'=>'field_fk_ikon','label'=>'Ikon','name'=>'ikon','type'=>'text'],
                    ['key'=>'field_fk_tittel','label'=>'Tittel','name'=>'tittel','type'=>'text'],
                    ['key'=>'field_fk_tekst','label'=>'Tekst','name'=>'tekst','type'=>'textarea','rows'=>2],
                    ['key'=>'field_fk_lenke_tekst','label'=>'Lenke tekst','name'=>'lenke_tekst','type'=>'text'],
                    ['key'=>'field_fk_lenke','label'=>'Lenke URL','name'=>'lenke','type'=>'text'],
                ]
            ],
            ['key'=>'field_omoss_tab','label'=>'📖 Om oss','name'=>'','type'=>'tab','placement'=>'top'],
            ['key'=>'field_omoss_etikett','label'=>'Etikett','name'=>'omoss_etikett','type'=>'text','default_value'=>'Vår historie'],
            ['key'=>'field_omoss_tittel','label'=>'Tittel','name'=>'omoss_tittel','type'=>'text','default_value'=>'Laget for din hverdag'],
            ['key'=>'field_omoss_tekst','label'=>'Tekst','name'=>'omoss_tekst','type'=>'textarea','rows'=>4],
            ['key'=>'field_omoss_antall_lok','label'=>'Antall lokasjoner','name'=>'omoss_antall_lokasjoner','type'=>'number','default_value'=>1],
            ['key'=>'field_omoss_knapp1_tekst','label'=>'Knapp 1 tekst','name'=>'omoss_knapp1_tekst','type'=>'text','default_value'=>'Les mer om oss'],
            ['key'=>'field_omoss_knapp1_lenke','label'=>'Knapp 1 lenke','name'=>'omoss_knapp1_lenke','type'=>'text','default_value'=>'/om-oss'],
            ['key'=>'field_omoss_knapp2_tekst','label'=>'Knapp 2 tekst','name'=>'omoss_knapp2_tekst','type'=>'text','default_value'=>'Finn en kafe'],
            ['key'=>'field_omoss_knapp2_lenke','label'=>'Knapp 2 lenke','name'=>'omoss_knapp2_lenke','type'=>'text','default_value'=>'/kontakt'],
            ['key'=>'field_lok_tab','label'=>'📍 Lokasjoner','name'=>'','type'=>'tab','placement'=>'top'],
            ['key'=>'field_lok_etikett','label'=>'Etikett','name'=>'lok_etikett','type'=>'text','default_value'=>'Finn oss'],
            ['key'=>'field_lok_tittel','label'=>'Tittel','name'=>'lok_tittel','type'=>'text','default_value'=>'Alltid et bakeri i nærheten'],
            ['key'=>'field_lok_vis_alle','label'=>'Vis "Se alle"-lenke','name'=>'lok_vis_alle','type'=>'true_false','default_value'=>1,'ui'=>1],
            ['key'=>'field_b2b_tab','label'=>'🏢 B2B','name'=>'','type'=>'tab','placement'=>'top'],
            ['key'=>'field_b2b_etikett','label'=>'Etikett','name'=>'b2b_etikett','type'=>'text','default_value'=>'For bedrifter'],
            ['key'=>'field_b2b_tittel','label'=>'Tittel','name'=>'b2b_tittel','type'=>'text','default_value'=>'Vil du ha bakeri på jobben?'],
            ['key'=>'field_b2b_undertekst','label'=>'Undertekst','name'=>'b2b_undertekst','type'=>'textarea','rows'=>2],
            ['key'=>'field_b2b_knapp1_tekst','label'=>'Knapp 1 tekst','name'=>'b2b_knapp1_tekst','type'=>'text','default_value'=>'Registrer bedrift'],
            ['key'=>'field_b2b_knapp1_lenke','label'=>'Knapp 1 lenke','name'=>'b2b_knapp1_lenke','type'=>'text','default_value'=>'/bedrift'],
            ['key'=>'field_b2b_knapp2_tekst','label'=>'Knapp 2 tekst','name'=>'b2b_knapp2_tekst','type'=>'text','default_value'=>'Les mer'],
            ['key'=>'field_b2b_knapp2_lenke','label'=>'Knapp 2 lenke','name'=>'b2b_knapp2_lenke','type'=>'text','default_value'=>'/bedrift'],
            ['key'=>'field_ig_tab','label'=>'📸 Instagram','name'=>'','type'=>'tab','placement'=>'top'],
            ['key'=>'field_ig_handle','label'=>'Instagram-handle (uten @)','name'=>'ig_handle','type'=>'text'],
            ['key'=>'field_ig_tittel','label'=>'Seksjon-tittel','name'=>'ig_tittel','type'=>'text','default_value'=>'Følg oss på Instagram'],
        ],
    ]);

    // OM OSS
    acf_add_local_field_group([
        'key'      => 'group_om_oss',
        'title'    => '👥 Om oss',
        'position' => 'normal',
        'location' => [[['param'=>'page','operator'=>'==','value'=>'om-oss']]],
        'fields'   => [
            ['key'=>'field_oo_hero_bilde','label'=>'Hero-bilde','name'=>'hero_bilde','type'=>'image','return_format'=>'url'],
            ['key'=>'field_oo_hero_tittel','label'=>'Hero-tittel','name'=>'hero_tittel','type'=>'text','default_value'=>'Vår historie'],
            ['key'=>'field_oo_ingress','label'=>'Ingress','name'=>'ingress','type'=>'textarea','rows'=>3],
            ['key'=>'field_oo_bilde2','label'=>'Bilde ved tekst','name'=>'bilde2','type'=>'image','return_format'=>'url'],
            ['key'=>'field_oo_hovedtekst','label'=>'Hovedtekst','name'=>'hovedtekst','type'=>'wysiwyg','tabs'=>'all','toolbar'=>'basic'],
            ['key'=>'field_oo_verdier','label'=>'Verdier','name'=>'verdier','type'=>'repeater','button_label'=>'Legg til verdi','max'=>4,
                'sub_fields'=>[
                    ['key'=>'field_vv_ikon','label'=>'Ikon','name'=>'ikon','type'=>'text'],
                    ['key'=>'field_vv_tittel','label'=>'Tittel','name'=>'tittel','type'=>'text'],
                    ['key'=>'field_vv_tekst','label'=>'Tekst','name'=>'tekst','type'=>'textarea','rows'=>2],
                ]
            ],
        ],
    ]);

    // KONTAKT
    acf_add_local_field_group([
        'key'      => 'group_kontakt',
        'title'    => '📞 Kontakt',
        'position' => 'normal',
        'location' => [[['param'=>'page','operator'=>'==','value'=>'kontakt']]],
        'fields'   => [
            ['key'=>'field_ko_tittel','label'=>'Tittel','name'=>'tittel','type'=>'text','default_value'=>'Ta kontakt'],
            ['key'=>'field_ko_undertekst','label'=>'Undertekst','name'=>'undertekst','type'=>'textarea','rows'=>2],
            ['key'=>'field_ko_vis_avdelinger','label'=>'Vis avdelinger','name'=>'vis_avdelinger','type'=>'true_false','default_value'=>1,'ui'=>1],
            ['key'=>'field_ko_ekstra','label'=>'Ekstra informasjon','name'=>'ekstra_tekst','type'=>'wysiwyg','tabs'=>'text','toolbar'=>'basic'],
            ['key'=>'field_ko_kart','label'=>'Google Maps embed URL','name'=>'kart_url','type'=>'url'],
        ],
    ]);

    // BEDRIFT
    acf_add_local_field_group([
        'key'      => 'group_bedrift',
        'title'    => '🏢 Bedrift',
        'position' => 'normal',
        'location' => [[['param'=>'page','operator'=>'==','value'=>'bedrift']]],
        'fields'   => [
            ['key'=>'field_be_hero_tittel','label'=>'Hero-tittel','name'=>'hero_tittel','type'=>'text','default_value'=>'Bakeri på jobben'],
            ['key'=>'field_be_hero_undertekst','label'=>'Hero-undertekst','name'=>'hero_undertekst','type'=>'textarea','rows'=>2],
            ['key'=>'field_be_hero_bilde','label'=>'Hero-bilde','name'=>'hero_bilde','type'=>'image','return_format'=>'url'],
            ['key'=>'field_be_fordeler','label'=>'Fordeler','name'=>'fordeler','type'=>'repeater','button_label'=>'Legg til fordel','max'=>6,
                'sub_fields'=>[
                    ['key'=>'field_fo_ikon','label'=>'Ikon','name'=>'ikon','type'=>'text'],
                    ['key'=>'field_fo_tittel','label'=>'Tittel','name'=>'tittel','type'=>'text'],
                    ['key'=>'field_fo_tekst','label'=>'Tekst','name'=>'tekst','type'=>'textarea','rows'=>2],
                ]
            ],
            ['key'=>'field_be_cta_tittel','label'=>'CTA tittel','name'=>'cta_tittel','type'=>'text','default_value'=>'Kom i gang i dag'],
            ['key'=>'field_be_cta_tekst','label'=>'CTA tekst','name'=>'cta_tekst','type'=>'textarea','rows'=>2],
            ['key'=>'field_be_vis_skjema','label'=>'Vis kontaktskjema','name'=>'vis_kontaktskjema','type'=>'true_false','default_value'=>1,'ui'=>1],
        ],
    ]);

});
