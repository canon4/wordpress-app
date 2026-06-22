/**
 * WCFM AI Assistant v1.1 — Modal script.
 */
(function ($) {
    'use strict';

    /* -------------------------------------------------------
       Modal open / close
    ------------------------------------------------------- */
    function openModal() {
        $('#wcfm-ai-overlay').fadeIn(160);
        $('body').css('overflow', 'hidden');
        populateAutoFields();
        $('#wai_materials').focus();
    }

    function closeModal() {
        $('#wcfm-ai-overlay').fadeOut(140);
        $('body').css('overflow', '');
    }

    // Open
    $(document).on('click', '#wcfm-ai-open-btn', openModal);

    // Close: X button
    $(document).on('click', '#wcfm-ai-close-btn', closeModal);

    // Close: click on overlay backdrop
    $(document).on('click', '#wcfm-ai-overlay', function (e) {
        if ($(e.target).is('#wcfm-ai-overlay')) closeModal();
    });

    // Close: Escape key
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#wcfm-ai-overlay').is(':visible')) closeModal();
    });

    /* -------------------------------------------------------
       Auto-populate product name and category from the WCFM form
    ------------------------------------------------------- */
    function populateAutoFields() {
        // Product name — WCFM uses name="pro_title"
        var name = $('[name="pro_title"]').val()
                || $('[name="post_title"]').val()
                || $('#pro_title').val()
                || '';
        if (name) $('#wai_product_name').val(name);

        // Categories — checked checkboxes
        var cats = [];
        $('[name="product_cat[]"]:checked, input[name^="product_cat"]:checked').each(function () {
            var label = $(this).closest('label').text().trim()
                     || $(this).siblings('span').text().trim()
                     || $(this).val();
            if (label) cats.push(label);
        });
        if (cats.length) $('#wai_category').val(cats.join(', '));
    }

    /* -------------------------------------------------------
       Tabs
    ------------------------------------------------------- */
    $(document).on('click', '.wcfm-ai-rtab', function () {
        var tab = $(this).data('tab');
        $('.wcfm-ai-rtab').removeClass('active');
        $('.wcfm-ai-rtab-content').removeClass('active');
        $(this).addClass('active');
        $('#' + tab).addClass('active');
    });

    /* -------------------------------------------------------
       Character counters for SEO fields
    ------------------------------------------------------- */
    $(document).on('input', '#wai_r_seo_titulo', function () {
        $('#wai_seo_titulo_count').text($(this).val().length + '/60');
    });
    $(document).on('input', '#wai_r_seo_meta', function () {
        $('#wai_seo_meta_count').text($(this).val().length + '/155');
    });

    /* -------------------------------------------------------
       Generate
    ------------------------------------------------------- */
    function collect() {
        return {
            product_name:       $('#wai_product_name').val().trim(),
            category:           $('#wai_category').val().trim(),
            short_desc:         $('[name="excerpt"]').val() || $('#excerpt').val() || '',
            materials:          $('#wai_materials').val().trim(),
            process:            $('#wai_process').val().trim(),
            benefits:           $('#wai_benefits').val().trim(),
            vendor_store:       $('#wai_v_store').val(),
            vendor_desc:        $('#wai_v_desc').val(),
            vendor_location:    $('#wai_v_location').val(),
            vendor_community:   $('#wai_v_community').val(),
            vendor_traditions:  $('#wai_v_traditions').val(),
        };
    }

    function setLoading(on) {
        $('#wcfm-ai-generate-btn').prop('disabled', on);
        $('#wcfm-ai-btn-text').text(on ? 'Generando…' : 'Generar descripción');
        $('#wcfm-ai-progress').toggle(on);
        if (on) {
            // restart CSS animation
            var fill = $('.wcfm-ai-progress-fill')[0];
            fill.style.animation = 'none';
            fill.offsetHeight; // reflow
            fill.style.animation = '';
        }
    }

    function showError(msg) {
        $('#wcfm-ai-error-msg').html('⚠️ ' + msg).slideDown(160);
    }
    function hideError() { $('#wcfm-ai-error-msg').hide(); }

    function showSuccess(msg) {
        $('#wcfm-ai-success-msg').text(msg).fadeIn(150);
        setTimeout(function () { $('#wcfm-ai-success-msg').fadeOut(300); }, 3000);
    }

    function doGenerate() {
        var data = collect();

        if (!data.product_name) {
            showError('Por favor ingresa el nombre del producto (campo "Nombre del producto" en el formulario).');
            return;
        }
        if (!data.materials && !data.process && !data.benefits) {
            showError('Completa al menos uno de los campos: Materiales, Proceso o Beneficios.');
            return;
        }

        hideError();
        setLoading(true);
        $('#wcfm-ai-result-panel').hide();
        $('#wcfm-ai-empty-state').show();

        $.ajax({
            url:         wcfmAI.restUrl,
            method:      'POST',
            contentType: 'application/json',
            data:        JSON.stringify(data),
            beforeSend:  function (xhr) { xhr.setRequestHeader('X-WP-Nonce', wcfmAI.nonce); },
            success:  function (res) {
                if (res && res.success && res.data) {
                    renderResult(res.data);
                } else {
                    showError('Respuesta inesperada. Inténtalo de nuevo.');
                }
            },
            error: function (xhr) {
                var msg = 'Error al conectar con la IA.';
                if (xhr.status === 429) {
                    msg = 'Has alcanzado el límite de generaciones de este mes.';
                } else if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg += ' ' + xhr.responseJSON.message;
                }
                showError(msg);
            },
            complete: function () { setLoading(false); },
        });
    }

    /* -------------------------------------------------------
       Render result into tabs
    ------------------------------------------------------- */
    function renderResult(d) {
        $('#wai_r_comercial').val(d.descripcion_comercial || '');
        $('#wai_r_historia').val(d.historia_origen        || '');
        $('#wai_r_cultural').val(d.valor_cultural         || '');
        $('#wai_r_curioso').val(d.dato_curioso            || '');
        $('#wai_r_impacto').val(d.impacto_social          || '');

        var titulo = d.seo_titulo || '';
        var meta   = d.seo_meta   || '';
        var kw     = Array.isArray(d.seo_palabras_clave)
                        ? d.seo_palabras_clave.join(', ')
                        : (d.seo_palabras_clave || '');

        $('#wai_r_seo_titulo').val(titulo);
        $('#wai_r_seo_meta').val(meta);
        $('#wai_r_seo_kw').val(kw);
        $('#wai_seo_titulo_count').text(titulo.length + '/60');
        $('#wai_seo_meta_count').text(meta.length + '/155');

        // Activate first tab
        $('.wcfm-ai-rtab').first().click();

        $('#wcfm-ai-empty-state').hide();
        $('#wcfm-ai-result-panel').show();
    }

    /* -------------------------------------------------------
       Apply to WooCommerce / WCFM product form fields
    ------------------------------------------------------- */
    function setField(id, value) {
        var $el = $('#' + id);
        if (!$el.length) return;
        $el.val(value).trigger('change');
        // Update TinyMCE if active
        if (window.tinymce && tinymce.get(id)) {
            tinymce.get(id).setContent(value.replace(/\n/g, '<br/>'));
        }
    }

    function applySEO() {
        var titulo = $('#wai_r_seo_titulo').val();
        var meta   = $('#wai_r_seo_meta').val();
        var kw     = $('#wai_r_seo_kw').val().split(',')[0].trim();
        // Yoast SEO
        if ($('#yoast_wpseo_title').length) {
            $('#yoast_wpseo_title').val(titulo).trigger('input');
            $('#yoast_wpseo_metadesc').val(meta).trigger('input');
            $('#yoast_wpseo_focuskw').val(kw).trigger('input');
        }
        // RankMath
        if ($('#rank-math-title').length) {
            $('#rank-math-title').val(titulo).trigger('input');
            $('#rank-math-description').val(meta).trigger('input');
        }
    }

    function applyAll() {
        var comercial = $('#wai_r_comercial').val();
        var historia  = $('#wai_r_historia').val();
        var cultural  = $('#wai_r_cultural').val();
        var curioso   = $('#wai_r_curioso').val();
        var impacto   = $('#wai_r_impacto').val();

        var parts = [comercial];
        if (historia) parts.push('<h3>Historia y Origen</h3>\n' + historia);
        if (cultural) parts.push('<h3>Valor Cultural</h3>\n' + cultural);
        if (curioso)  parts.push('<h3>¿Sabías que…?</h3>\n' + curioso);
        if (impacto)  parts.push('<h3>Impacto Social</h3>\n' + impacto);

        setField('description', parts.join('\n\n'));
        applySEO();
        showSuccess('✓ Descripción completa aplicada al producto');
    }

    function applyExcerpt() {
        var text = $('#wai_r_comercial').val();
        // First 2 sentences as excerpt
        var sentences = text.match(/[^.!?]+[.!?]+/g) || [];
        var excerpt = sentences.slice(0, 2).join(' ').trim() || text.substring(0, 200);
        setField('excerpt', excerpt);
        showSuccess('✓ Descripción corta aplicada');
    }

    /* -------------------------------------------------------
       Event bindings
    ------------------------------------------------------- */
    $(document).on('click', '#wcfm-ai-generate-btn',    doGenerate);
    $(document).on('click', '#wcfm-ai-regenerate-btn',  doGenerate);
    $(document).on('click', '#wcfm-ai-apply-all-btn',   applyAll);
    $(document).on('click', '#wcfm-ai-apply-excerpt-btn', applyExcerpt);

}(jQuery));
