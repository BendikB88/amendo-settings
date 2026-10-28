jQuery(function($) {

    // ── Tabs ──
    $('.amendo-tab').on('click', function() {
        $('.amendo-tab').removeClass('active');
        $('.amendo-panel').removeClass('active');
        $(this).addClass('active');
        $('#tab-' + $(this).data('tab')).addClass('active');
    });

    // ── Underfaner (B2B: Bedrifter, Kundegrupper, Helsebygget) ──
    // Alt står i hovedskjemaet og sendes som før; bare visningen byttes.
    $(document).on('click', '.amendo-undertab', function() {
        var $panel = $(this).closest('.amendo-panel'), under = $(this).data('under');
        $panel.find('.amendo-undertab').removeClass('active');
        $(this).addClass('active');
        $panel.find('.amendo-underpanel').removeClass('active').filter(function() { return $(this).data('under-panel') === under; }).addClass('active');
    });

    // #b2b, #avdelinger osv. i adressen åpner den fanen — B2B-handlingene
    // sender tilbake hit, og varselet om avdelinger lenker til #avdelinger.
    // #b2b-helsebygget åpner underfanen Helsebygget i B2B.
    var fraAdresse = (window.location.hash || '').slice(1).split('-');
    if (fraAdresse[0]) {
        $('.amendo-tab').filter(function() { return $(this).data('tab') === fraAdresse[0]; }).first().trigger('click');
        if (fraAdresse[1]) $('#tab-' + fraAdresse[0] + ' .amendo-undertab').filter(function() { return $(this).data('under') === fraAdresse[1]; }).first().trigger('click');
    }

    // ── Hvilke faner er endret? (includes/lagring.php) ──
    // Hele skjemaet sendes ved hver lagring. En del som lagres som én option
    // (forsiden, menyen …) lagres bare når noe i den er endret HER: første
    // skriving, valg, klikk på en knapp eller dra-og-slipp setter flagget.
    $(document).on('input change sortupdate', '[data-amendo-del]', function(e) {
        // Søkefeltet i kategorivelgeren endrer ingenting som lagres.
        if ($(e.target).is('.amendo-endret, .amendo-kategori-sok')) return;
        $(this).find('> .amendo-endret').val('1');
    });
    $(document).on('click', '[data-amendo-del] button', function() {
        // Knapper som bare viser noe («Vis grupper uten kunder»), endrer ingenting.
        if ($(this).is('.amendo-bare-visning')) return;
        $(this).closest('[data-amendo-del]').find('> .amendo-endret').val('1');
    });

    // ── Secrets (Kasse, Revalidering) ──
    // Knappen er type="button" og ikke submit: ellers ville Enter i et
    // hvilket som helst felt (den står før «Lagre») generert en ny secret.
    $('.amendo-generer-secret').on('click', function() {
        if (!confirm($(this).data('bekreft'))) return;
        $(this).closest('.amendo-hemmelighet').find('.amendo-secret-flagg').val('1');
        $(this).closest('form').trigger('submit');
    });

    $('.amendo-kopier-secret').on('click', function() {
        var $knapp = $(this), felt = $knapp.siblings('.amendo-ny-secret')[0];
        var ferdig = function() { $knapp.text('Kopiert ✓'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(felt.value).then(ferdig, function() { felt.select(); document.execCommand('copy'); ferdig(); });
        } else {
            felt.select(); document.execCommand('copy'); ferdig();
        }
    });

    // ── Fargepicker ──
    $('input[type="color"]').on('input', function() {
        $(this).siblings('.color-value').text($(this).val());
    });

    // ── Logo opplasting ──
    var logoUploader;
    $('#upload-logo').on('click', function(e) {
        e.preventDefault();
        if (logoUploader) { logoUploader.open(); return; }
        logoUploader = wp.media({ title: 'Velg logo', button: { text: 'Bruk som logo' }, multiple: false, library: { type: 'image' } });
        logoUploader.on('select', function() {
            var att = logoUploader.state().get('selection').first().toJSON();
            $('#butikk_logo').val(att.url);
            if ($('#logo-preview').is('img')) {
                $('#logo-preview').attr('src', att.url);
            } else {
                $('#logo-preview').replaceWith('<img id="logo-preview" src="' + att.url + '" style="max-height:60px;max-width:200px;border:1px solid #e5e7eb;border-radius:6px;padding:4px;">');
            }
            if (!$('#remove-logo').length) {
                $('#upload-logo').after('<button type="button" class="amendo-btn-danger" id="remove-logo">Fjern</button>');
                bindRemoveLogo();
            }
        });
        logoUploader.open();
    });

    function bindRemoveLogo() {
        $('#remove-logo').on('click', function() {
            $('#butikk_logo').val('');
            $('#logo-preview').replaceWith('<div id="logo-preview" class="logo-placeholder">Ingen logo valgt</div>');
            $(this).remove();
        });
    }
    bindRemoveLogo();

    // ── Avdeling bilde opplasting ──
    $(document).on('click', '.upload-avd-bilde', function() {
        var $btn = $(this);
        var $wrap = $btn.closest('.amendo-logo-upload');
        var uploader = wp.media({ title: 'Velg bilde', button: { text: 'Bruk bilde' }, multiple: false, library: { type: 'image' } });
        uploader.on('select', function() {
            var att = uploader.state().get('selection').first().toJSON();
            $wrap.find('.avd-bilde-input').val(att.url);
            var $preview = $wrap.find('.avd-bilde-preview');
            if ($preview.is('img')) {
                $preview.attr('src', att.url);
            } else {
                $preview.replaceWith('<img class="avd-bilde-preview" src="' + att.url + '" style="max-height:80px;max-width:160px;border-radius:6px;object-fit:cover;">');
            }
            if (!$wrap.find('.fjern-avd-bilde').length) {
                $btn.after('<button type="button" class="amendo-btn-danger fjern-avd-bilde">Fjern</button>');
            }
        });
        uploader.open();
    });

    $(document).on('click', '.fjern-avd-bilde', function() {
        var $wrap = $(this).closest('.amendo-logo-upload');
        $wrap.find('.avd-bilde-input').val('');
        $wrap.find('.avd-bilde-preview').replaceWith('<div class="avd-bilde-preview logo-placeholder">Ingen bilde</div>');
        $(this).remove();
    });

    // ── Avdeling navn oppdatering ──
    $(document).on('input', '.avdeling-navn-input', function() {
        $(this).closest('.avdeling-kort').find('.avdeling-navn-tittel').text($(this).val() || 'Avdeling');
    });

    // ── Slett avdeling ──
    $(document).on('click', '.slett-avdeling', function() {
        if (confirm('Slette denne avdelingen?')) {
            $(this).closest('.avdeling-kort').remove();
            oppdaterAvdelingIndekser();
        }
    });

    // ── Legg til avdeling ──
    var avdelingTeller = $('#avdelinger-liste .avdeling-kort').length;
    $('#legg-til-avdeling').on('click', function() {
        $('#avdelinger-liste').append(lagAvdelingHTML(avdelingTeller++));
    });

    function lagAvdelingHTML(i) {
        var dager = ['mandag','tirsdag','onsdag','torsdag','fredag','lørdag','søndag'];
        var dagLabels = ['Mandag','Tirsdag','Onsdag','Torsdag','Fredag','Lørdag','Søndag'];
        var defaultÅpen = ['mandag','tirsdag','onsdag','torsdag','fredag'];
        var rader = dager.map(function(dag, idx) {
            return '<div class="åpningstider-rad"><span class="dag-navn">' + dagLabels[idx] + '</span><label class="toggle"><input type="checkbox" name="avdelinger[' + i + '][åpningstider][' + dag + '][åpen]" ' + (defaultÅpen.includes(dag) ? 'checked' : '') + '><span class="toggle-slider"></span></label><input type="time" name="avdelinger[' + i + '][åpningstider][' + dag + '][fra]" value="09:00"><input type="time" name="avdelinger[' + i + '][åpningstider][' + dag + '][til]" value="17:00"></div>';
        }).join('');
        return '<div class="avdeling-kort" data-index="' + i + '"><div class="avdeling-header"><span class="avdeling-navn-tittel">Ny avdeling</span><button type="button" class="slett-avdeling amendo-btn-danger">Slett</button></div><div class="avdeling-body"><div class="amendo-grid-2"><div class="amendo-field"><label>Navn på avdeling</label><input type="text" name="avdelinger[' + i + '][navn]" placeholder="Sentrum" class="avdeling-navn-input"></div><div class="amendo-field"><label>Telefon</label><input type="tel" name="avdelinger[' + i + '][telefon]" placeholder="+47 123 45 678"></div></div><div class="amendo-field"><label>Adresse</label><input type="text" name="avdelinger[' + i + '][adresse]" placeholder="Storgata 1, 0123 Oslo"></div><div class="amendo-field"><label>Bilde</label><div class="amendo-logo-upload"><div class="avd-bilde-preview logo-placeholder">Ingen bilde</div><input type="hidden" name="avdelinger[' + i + '][bilde]" class="avd-bilde-input" value=""><button type="button" class="amendo-btn-secondary upload-avd-bilde">Last opp bilde</button></div></div><div class="amendo-field"><label>Åpningstider</label><div class="åpningstider-grid"><div class="åpningstider-header"><span>Dag</span><span>Åpen</span><span>Fra</span><span>Til</span></div>' + rader + '</div></div>' +'<div class="amendo-field" style="margin-top:20px;padding-top:20px;border-top:1px solid #e5e7eb;">' +'<label style="font-size:13px;color:#374151;margin-bottom:12px;display:block;">⚙️ Henting-innstillinger for denne avdelingen</label>' +'<div class="amendo-grid-2">' +'<div class="amendo-field"><label>Prosesseringstid (virkedager)</label><input type="number" name="avdelinger[' + i + '][prosesseringstid]" value="2" min="0" max="30"><p class="field-help">0 = samme dag mulig</p></div>' +'<div class="amendo-field"><label>Cut-off tid</label><input type="time" name="avdelinger[' + i + '][cutoff]" value="10:00"><p class="field-help">Bestillinger etter dette teller fra neste dag</p></div>' +'</div><div class="amendo-grid-2">' +'<div class="amendo-field"><label>Tidsluke-lengde (min)</label><select name="avdelinger[' + i + '][tidsluke]" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;"><option value="15">15 min</option><option value="30">30 min</option><option value="60" selected>60 min</option><option value="120">120 min</option></select></div>' +'<div class="amendo-field"><label>Maks per tidsluke</label><input type="number" name="avdelinger[' + i + '][maks_per_tidsluke]" value="10" min="1" max="999"></div>' +'</div>' +'<div class="amendo-field"><label>Kan bestilles inntil</label><input type="number" name="avdelinger[' + i + '][bestillingsvindu_dager]" value="60" min="1" max="365" style="width:90px"> dager fram<p class="field-help">Hvor langt fram kunden kan velge dato. Standard 60.</p></div>' +'<div class="amendo-field"><label>Klargjøringstid (minutter)</label><input type="number" name="avdelinger[' + i + '][klargjoring_minutter]" value="15" min="0" max="1440" style="width:90px"> min<p class="field-help">Første hentetid samme dag er tidligst nå + klargjøringstiden. Standard 15.</p></div>' +'<div class="amendo-field"><label>Tillat henting her</label><label class="toggle"><input type="checkbox" name="avdelinger[' + i + '][henting_aktiv]" checked><span class="toggle-slider"></span></label></div>' +
            // Inngangen (1.4.23) fra malen: PHP vet om modulen Helsebygget er på.
            ($('#avdeling-inngang-mal').html() || '').split('__i__').join(String(i)) +
            '</div>' + '</div></div>';
    }

    function oppdaterAvdelingIndekser() {
        $('#avdelinger-liste .avdeling-kort').each(function(i) {
            $(this).attr('data-index', i);
            $(this).find('input, select, textarea').each(function() {
                var name = $(this).attr('name');
                if (name) $(this).attr('name', name.replace(/avdelinger\[\d+\]/, 'avdelinger[' + i + ']'));
            });
        });
    }

    // ── Meny ──
    var menyTeller = $('#meny-liste .meny-rad').length;

    $('#legg-til-meny').on('click', function() {
        var i = menyTeller++;
        $('#meny-liste').append(
            '<div class="meny-rad" data-index="' + i + '">' +
            '<div class="meny-drag">⠿</div>' +
            '<input type="text" name="meny_elementer[' + i + '][label]" placeholder="Menylenke">' +
            '<input type="text" name="meny_elementer[' + i + '][href]" placeholder="/side">' +
            '<button type="button" class="slett-meny amendo-btn-danger">Slett</button>' +
            '</div>'
        );
    });

    $(document).on('click', '.slett-meny', function() {
        $(this).closest('.meny-rad').remove();
        oppdaterMenyIndekser();
    });

    function oppdaterMenyIndekser() {
        $('#meny-liste .meny-rad').each(function(i) {
            $(this).attr('data-index', i);
            $(this).find('input').each(function() {
                var name = $(this).attr('name');
                if (name) $(this).attr('name', name.replace(/meny_elementer\[\d+\]/, 'meny_elementer[' + i + ']'));
            });
        });
    }

// ── Stengte perioder ──
// ⚠ Denne og kategori-reglene under lå tidligere UTENFOR jQuery-wrapperen. I
// wp-admin kjører jQuery i noConflict-modus, så `$` var udefinert der og
// «+ Legg til»-knappene gjorde ingenting.
var periodeTeller = $('#stengte-perioder-liste .steng-rad').length;
$('#legg-til-periode').on('click', function() {
    var i = periodeTeller++;
    $('#stengte-perioder-liste').append(
        '<div class="steng-rad">' +
        '<input type="text" name="stengte_perioder[' + i + '][label]" placeholder="Beskrivelse">' +
        '<input type="date" name="stengte_perioder[' + i + '][fra]">' +
        '<span>→</span>' +
        '<input type="date" name="stengte_perioder[' + i + '][til]">' +
        // Avdelingsvelgeren fra malen (PHP har listen over avdelinger).
        ($('#steng-avdeling-mal').html() || '').split('__i__').join(String(i)) +
        '<button type="button" class="slett-periode amendo-btn-danger">Slett</button>' +
        '</div>'
    );
});
$(document).on('click', '.slett-periode', function() {
    $(this).closest('.steng-rad').remove();
});

// ── Kategori-regler ──
// Raden lages fra malen PHP har rendret (kategorivelgeren med navn og antall),
// ikke et tallfelt for kategori-ID-en som før.
var kategoriRegelTeller = $('#kategori-regler-liste .kategori-regel-rad').length;
$('#legg-til-kategori-regel').on('click', function() {
    var mal = $('#kategori-regel-mal').html();
    if (!mal) return;
    var $rad = $(mal.split('__i__').join(String(kategoriRegelTeller++)));
    $('#kategori-regler-liste').append($rad);
    kategoriVelgere($rad);
});
$(document).on('click', '.slett-kategori-regel', function() {
    $(this).closest('.kategori-regel-rad').remove();
});

// Antallsregler (includes/leveringsregler.php): nye rader fra malen.
var antallRegelTeller = $('#antall-regler-liste .antall-regel-rad').length;
$('#legg-til-antall-regel').on('click', function() {
    var mal = $('#antall-regel-mal').html();
    if (!mal) return;
    var $rad = $(mal.split('__i__').join(String(antallRegelTeller++)));
    $('#antall-regler-liste').append($rad);
    kategoriVelgere($rad);
});
$(document).on('click', '.slett-antall-regel', function() {
    $(this).closest('.antall-regel-rad').remove();
});

    // ── Kategorivelgeren (includes/kategorivelger.php) ──
    // Et søkefelt foran hver liste. Søket tar bort valgene som ikke passer
    // (skjulte <option> virker ikke i Safari) og legger dem tilbake etterpå;
    // det valgte står alltid. Enter velger det første treffet.
    function kategoriVelgere(rot) {
        $(rot || document).find('select.amendo-kategori-velger').each(function() {
            var $select = $(this);
            if ($select.data('alleValg')) return;
            var $alle = $select.children('option');
            $select.data('alleValg', $alle);
            var $sok = $('<input type="search" class="amendo-kategori-sok" placeholder="Søk etter kategori …" aria-label="Søk etter kategori">');
            $select.before($sok);
            $sok.on('input', function() {
                var q = String($sok.val()).trim().toLowerCase(), valgt = $select.val();
                $alle.detach().filter(function() {
                    return !this.value || this.value === valgt || !q || $(this).text().toLowerCase().indexOf(q) !== -1;
                }).appendTo($select);
                $select.val(valgt);
            });
            $sok.on('keydown', function(e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                var q = String($sok.val()).trim().toLowerCase();
                var treff = $alle.filter(function() { return this.value && !this.disabled && $(this).text().toLowerCase().indexOf(q) !== -1; }).first();
                if (treff.length) $select.val(treff.val()).trigger('change');
            });
        });
    }
    kategoriVelgere();

    // ── Kategoriraden ──
    // Navnene nummereres etter rekkefølgen i DOM-en etter hver endring, så
    // PHP får lista i den rekkefølgen kunden ser den.
    function kategoriradNummerer($wrap) {
        var liste = $wrap.data('liste');
        var mønster = new RegExp('^kategorirad\\[' + liste + '\\]\\[[^\\]]+\\]');
        $wrap.find('.kategorirad-liste > .kategorirad-rad').each(function(i) {
            $(this).find('[name]').each(function() {
                $(this).attr('name', $(this).attr('name').replace(mønster, 'kategorirad[' + liste + '][' + i + ']'));
            });
        });
        kategoriradOppdaterValg();
    }

    // En kategori kan bare være med én gang, i én av listene.
    function kategoriradOppdaterValg() {
        var brukt = {};
        $('.kategorirad-liste .kategorirad-term').each(function() { if (this.value) brukt[this.value] = true; });
        // Alle valgene, også de søket har tatt bort for øyeblikket.
        $('.kategorirad-velg').each(function() {
            ($(this).data('alleValg') || $(this).find('option')).each(function() {
                if (this.value) $(this).prop('disabled', !!brukt[this.value]);
            });
        });
    }

    $('.kategorirad-legg-til-knapp').on('click', function() {
        var $wrap = $(this).closest('.kategorirad-liste-wrap');
        var $valgt = $wrap.find('.kategorirad-velg option:selected');
        if (!$valgt.val() || $valgt.prop('disabled')) return;
        var $liste = $wrap.find('.kategorirad-liste');
        var mal = $wrap.find('template.kategorirad-mal').html();
        var $rad = $(mal.split('__i__').join($liste.children().length));
        $rad.find('.kategorirad-term').val($valgt.val());
        $rad.find('.kategorirad-navn').text($valgt.data('navn'));
        $rad.find('.kategorirad-antall').text('(' + $valgt.data('antall') + ')');
        $liste.append($rad);
        $wrap.find('.kategorirad-velg').val('');
        kategoriradNummerer($wrap);
    });

    $(document).on('click', '.kategorirad-fjern', function() {
        var $wrap = $(this).closest('.kategorirad-liste-wrap');
        $(this).closest('.kategorirad-rad').remove();
        kategoriradNummerer($wrap);
    });

    $(document).on('click', '.kategorirad-opp, .kategorirad-ned', function() {
        var $rad = $(this).closest('.kategorirad-rad');
        if ($(this).hasClass('kategorirad-opp')) $rad.prev('.kategorirad-rad').before($rad);
        else $rad.next('.kategorirad-rad').after($rad);
        kategoriradNummerer($rad.closest('.kategorirad-liste-wrap'));
        $(this).trigger('focus');
    });

    if ($.fn.sortable) {
        $('.kategorirad-liste').sortable({
            handle: '.meny-drag',
            axis: 'y',
            update: function() { kategoriradNummerer($(this).closest('.kategorirad-liste-wrap')); }
        });
    }
    kategoriradOppdaterValg();

    // ── Forside: repeterbare lister ──
    // Samme mønster som menyen: legg til en rad, slett en rad, og nummerer
    // `name`-attributtene på nytt så PHP får en sammenhengende liste. Raden
    // lages fra en <template> som PHP har rendret — da er markeringen og
    // escapingen ett sted, ikke kopiert inn i en JS-streng.
    $(document).on('click', '.amendo-legg-til-rad', function() {
        var $rep = $(this).closest('.amendo-repeter');
        var mal = $rep.find('template.amendo-repeter-mal').html();
        var i = $rep.find('.amendo-repeter-liste > .amendo-repeter-rad').length;
        var $rad = $(mal.split('__i__').join(i));
        $rep.find('.amendo-repeter-liste').append($rad);
        kategoriVelgere($rad);
    });

    $(document).on('click', '.amendo-slett-rad', function() {
        var $rep = $(this).closest('.amendo-repeter');
        $(this).closest('.amendo-repeter-rad').remove();
        oppdaterRepeterIndekser($rep);
    });

    function oppdaterRepeterIndekser($rep) {
        var felt = $rep.data('repeter');
        var mønster = new RegExp('^forside\\[' + felt + '\\]\\[\\d+\\]');
        $rep.find('.amendo-repeter-liste > .amendo-repeter-rad').each(function(i) {
            $(this).find('input, textarea, select').each(function() {
                var name = $(this).attr('name');
                if (name) $(this).attr('name', name.replace(mønster, 'forside[' + felt + '][' + i + ']'));
            });
        });
    }

    // ── Forside: rekkefølgen på seksjonene ──
    // Samme mønster som kategoriraden: dra, eller ↑/↓ (tastatur og mobil).
    // `name` nummereres på nytt så PHP får lista i den nye rekkefølgen.
    function seksjonerNummerer() {
        $('.forside-seksjon-liste > .forside-seksjon-rad').each(function(i) {
            $(this).find('input').each(function() {
                var name = $(this).attr('name');
                if (name) $(this).attr('name', name.replace(/^forside\[seksjoner\]\[\d+\]/, 'forside[seksjoner][' + i + ']'));
            });
        });
    }

    $(document).on('click', '.forside-seksjon-opp, .forside-seksjon-ned', function() {
        var $rad = $(this).closest('.forside-seksjon-rad');
        if ($(this).hasClass('forside-seksjon-opp')) $rad.prev('.forside-seksjon-rad').before($rad);
        else $rad.next('.forside-seksjon-rad').after($rad);
        seksjonerNummerer();
    });

    if ($.fn.sortable) {
        $('.forside-seksjon-liste').sortable({
            handle: '.meny-drag',
            axis: 'y',
            update: seksjonerNummerer
        });
    }

    // ── Forside: mediefelt (bilde og video) ──
    // URL-feltet er synlig og kan redigeres direkte (en video kan ligge på en
    // CDN); knappen fyller det fra mediebiblioteket.
    $(document).on('click', '.amendo-velg-media', function(e) {
        e.preventDefault();
        var $felt = $(this).closest('.amendo-media');
        var type = $felt.data('type') === 'video' ? 'video' : 'image';
        var velger = wp.media({
            title: type === 'video' ? 'Velg video' : 'Velg bilde',
            button: { text: 'Bruk' },
            multiple: false,
            library: { type: type }
        });
        velger.on('select', function() {
            var valgt = velger.state().get('selection').first().toJSON();
            $felt.find('.amendo-media-url').val(valgt.url).trigger('input');
            // Etter trigger: input-lytteren under tømmer ID-en.
            $felt.find('.amendo-media-id').val(valgt.id);
        });
        velger.open();
    });

    $(document).on('click', '.amendo-fjern-media', function() {
        $(this).closest('.amendo-media').find('.amendo-media-url').val('').trigger('input');
    });

    $(document).on('input', '.amendo-media-url', function() {
        var url = $(this).val();
        // Ny URL (skrevet inn, limt inn eller fjernet): ID-en hører til den
        // gamle. Serveren slår opp den nye ved lagring.
        $(this).closest('.amendo-media').find('.amendo-media-id').val('');
        var $bilde = $(this).closest('.amendo-media').find('.amendo-media-forhandsvisning');
        if (!$bilde.length) return;
        if (url) $bilde.attr('src', url).prop('hidden', false);
        else $bilde.attr('src', '').prop('hidden', true);
    });

    // ── B2B (bare når modulen er på — se includes/moduler/b2b.php) ──
    // Godkjenning gir innsyn i bedriftens bestillinger; spør først.
    $(document).on('click', '.b2b-bekreft', function(e) {
        if (!confirm($(this).data('bekreft'))) e.preventDefault();
    });

    // Kundegrupper: gruppene uten kunder er skjult til man ber om dem.
    $('#vis-tomme-kundegrupper').on('click', function() {
        $('.kundegruppe-tom').prop('hidden', false);
        $(this).remove();
    });
});
