jQuery(function($) {

    // ── Tabs ──
    $('.amendo-tab').on('click', function() {
        $('.amendo-tab').removeClass('active');
        $('.amendo-panel').removeClass('active');
        $(this).addClass('active');
        $('#tab-' + $(this).data('tab')).addClass('active');
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
        return '<div class="avdeling-kort" data-index="' + i + '"><div class="avdeling-header"><span class="avdeling-navn-tittel">Ny avdeling</span><button type="button" class="slett-avdeling amendo-btn-danger">Slett</button></div><div class="avdeling-body"><div class="amendo-grid-2"><div class="amendo-field"><label>Navn på avdeling</label><input type="text" name="avdelinger[' + i + '][navn]" placeholder="Sentrum" class="avdeling-navn-input"></div><div class="amendo-field"><label>Telefon</label><input type="tel" name="avdelinger[' + i + '][telefon]" placeholder="+47 123 45 678"></div></div><div class="amendo-field"><label>Adresse</label><input type="text" name="avdelinger[' + i + '][adresse]" placeholder="Storgata 1, 0123 Oslo"></div><div class="amendo-field"><label>Bilde</label><div class="amendo-logo-upload"><div class="avd-bilde-preview logo-placeholder">Ingen bilde</div><input type="hidden" name="avdelinger[' + i + '][bilde]" class="avd-bilde-input" value=""><button type="button" class="amendo-btn-secondary upload-avd-bilde">Last opp bilde</button></div></div><div class="amendo-field"><label>Åpningstider</label><div class="åpningstider-grid"><div class="åpningstider-header"><span>Dag</span><span>Åpen</span><span>Fra</span><span>Til</span></div>' + rader + '</div></div>' +'<div class="amendo-field" style="margin-top:20px;padding-top:20px;border-top:1px solid #e5e7eb;">' +'<label style="font-size:13px;color:#374151;margin-bottom:12px;display:block;">⚙️ Henting-innstillinger for denne avdelingen</label>' +'<div class="amendo-grid-2">' +'<div class="amendo-field"><label>Prosesseringstid (virkedager)</label><input type="number" name="avdelinger[' + i + '][prosesseringstid]" value="2" min="0" max="30"><p class="field-help">0 = samme dag mulig</p></div>' +'<div class="amendo-field"><label>Cut-off tid</label><input type="time" name="avdelinger[' + i + '][cutoff]" value="10:00"><p class="field-help">Bestillinger etter dette teller fra neste dag</p></div>' +'</div><div class="amendo-grid-2">' +'<div class="amendo-field"><label>Tidsluke-lengde (min)</label><select name="avdelinger[' + i + '][tidsluke]" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;"><option value="15">15 min</option><option value="30">30 min</option><option value="60" selected>60 min</option><option value="120">120 min</option></select></div>' +'<div class="amendo-field"><label>Maks per tidsluke</label><input type="number" name="avdelinger[' + i + '][maks_per_tidsluke]" value="10" min="1" max="999"></div>' +'</div>' +'<div class="amendo-field"><label>Tillat henting her</label><label class="toggle"><input type="checkbox" name="avdelinger[' + i + '][henting_aktiv]" checked><span class="toggle-slider"></span></label></div>' +'</div>' + '</div></div>';
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
});

// ── Stengte perioder ──
var periodeTeller = $('#stengte-perioder-liste .steng-rad').length;
$('#legg-til-periode').on('click', function() {
    var i = periodeTeller++;
    $('#stengte-perioder-liste').append(
        '<div class="steng-rad">' +
        '<input type="text" name="stengte_perioder[' + i + '][label]" placeholder="Beskrivelse">' +
        '<input type="date" name="stengte_perioder[' + i + '][fra]">' +
        '<span>→</span>' +
        '<input type="date" name="stengte_perioder[' + i + '][til]">' +
        '<button type="button" class="slett-periode amendo-btn-danger">Slett</button>' +
        '</div>'
    );
});
$(document).on('click', '.slett-periode', function() {
    $(this).closest('.steng-rad').remove();
});

// ── Kategori-regler ──
var kategoriRegelTeller = $('#kategori-regler-liste .kategori-regel-rad').length;
$('#legg-til-kategori-regel').on('click', function() {
    // Hent kategori-select HTML fra første eksisterende rad eller lag tom
    var i = kategoriRegelTeller++;
    $('#kategori-regler-liste').append(
        '<div class="kategori-regel-rad" style="border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-bottom:12px;">' +
        '<div class="amendo-grid-2">' +
        '<div class="amendo-field"><label>Kategori-ID</label>' +
        '<input type="number" name="kategori_regler[' + i + '][kategori_id]" placeholder="WC kategori-ID"></div>' +
        '<div class="amendo-field"><label>Prosesseringstid (virkedager)</label>' +
        '<input type="number" name="kategori_regler[' + i + '][prosesseringstid]" min="0" max="30" placeholder="Standard"></div>' +
        '</div><div class="amendo-grid-2">' +
        '<div class="amendo-field"><label>Steng fra</label>' +
        '<input type="date" name="kategori_regler[' + i + '][steng_fra]"></div>' +
        '<div class="amendo-field"><label>Steng til</label>' +
        '<input type="date" name="kategori_regler[' + i + '][steng_til]"></div>' +
        '</div>' +
        '<button type="button" class="slett-kategori-regel amendo-btn-danger">Slett regel</button>' +
        '</div>'
    );
});
$(document).on('click', '.slett-kategori-regel', function() {
    $(this).closest('.kategori-regel-rad').remove();
});
