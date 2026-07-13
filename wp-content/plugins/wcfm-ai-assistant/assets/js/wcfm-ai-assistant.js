/* WCFM AI Assistant — v1.1 */
(function ($) {
    'use strict';

    var $overlay, $modal, $generateBtn, $progress, $progressFill, $errorMsg,
        $emptyState, $resultPanel;

    /* ------------------------------------------------------------------ */
    /*  Init                                                               */
    /* ------------------------------------------------------------------ */
    $(function () {
        $overlay      = $('#wcfm-ai-overlay');
        $modal        = $('.wcfm-ai-modal');
        $generateBtn  = $('#wcfm-ai-generate-btn');
        $progress     = $('#wcfm-ai-progress');
        $progressFill = $progress.find('.wcfm-ai-progress-fill');
        $errorMsg     = $('#wcfm-ai-error-msg');
        $emptyState   = $('#wcfm-ai-empty-state');
        $resultPanel  = $('#wcfm-ai-result-panel');

        // Open
        $(document).on('click', '#wcfm-ai-open-btn', openModal);

        // Close
        $('#wcfm-ai-close-btn').on('click', closeModal);
        $overlay.on('click', function (e) {
            if ($(e.target).is('#wcfm-ai-overlay')) closeModal();
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $overlay.is(':visible')) closeModal();
        });

        // Tabs
        $(document).on('click', '.wcfm-ai-rtab', function () {
            var tab = $(this).data('tab');
            $('.wcfm-ai-rtab').removeClass('active');
            $(this).addClass('active');
            $('.wcfm-ai-tab-panel').removeClass('active');
            $('#' + tab).addClass('active');
        });

        // Generate / Regenerate
        $generateBtn.on('click', doGenerate);
        $('#wcfm-ai-regenerate-btn').on('click', doGenerate);

        // Apply
        $('#wcfm-ai-apply-all-btn').on('click', applyAll);
        $('#wcfm-ai-apply-excerpt-btn').on('click', applyExcerpt);

        // SEO char counters
        $('#wai_r_seo_titulo').on('input', function () {
            $('#cnt_seo_titulo').text($(this).val().length + '/60');
        });
        $('#wai_r_seo_meta').on('input', function () {
            $('#cnt_seo_meta').text($(this).val().length + '/155');
        });
    });

    /* ------------------------------------------------------------------ */
    /*  Modal open / close                                                 */
    /* ------------------------------------------------------------------ */
    function openModal() {
        $overlay.fadeIn(160);
        $('body').css('overflow', 'hidden');
        populateAutoFields();
    }

    function closeModal() {
        $overlay.fadeOut(140);
        $('body').css('overflow', '');
    }

    /* ------------------------------------------------------------------ */
    /*  Auto-populate from WCFM form                                       */
    /* ------------------------------------------------------------------ */
    function populateAutoFields() {
        // Product name
        var name = $('[name="pro_title"]').val() || '';
        $('#wai_product_name').val(name);

        // Categories — checked checkboxes
        var cats = [];
        $('[name="product_cat[]"]:checked').each(function () {
            var label = $('label[for="' + $(this).attr('id') + '"]').text().trim();
            if (label) cats.push(label);
        });
        // Fallback: select element
        if (!cats.length) {
            $('[name="product_cat[]"] option:selected').each(function () {
                cats.push($(this).text().trim());
            });
        }
        $('#wai_category').val(cats.join(', '));
    }

    /* ------------------------------------------------------------------ */
    /*  Collect data                                                       */
    /* ------------------------------------------------------------------ */
    function collect() {
        // Also read excerpt from WCFM form for context
        var shortDesc = $('[name="post_excerpt"]').val() || $('[name="excerpt"]').val() || '';

        return {
            product_name:       $('#wai_product_name').val().trim(),
            category:           $('#wai_category').val().trim(),
            short_desc:         shortDesc,
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

    /* ------------------------------------------------------------------ */
    /*  Generate                                                           */
    /* ------------------------------------------------------------------ */
    function doGenerate() {
        hideError();
        var data = collect();

        if (!data.product_name) {
            showError('El nombre del producto es requerido. Complétalo en el formulario o en el campo de arriba.');
            return;
        }
        if (!data.materials) {
            showError('Por favor indica los materiales del producto.');
            return;
        }

        startProgress();
        $generateBtn.prop('disabled', true);

        $.ajax({
            url:         wcfmAI.restUrl + 'generate',
            method:      'POST',
            contentType: 'application/json',
            data:        JSON.stringify(data),
            beforeSend:  function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcfmAI.nonce);
            },
            success: function (res) {
                stopProgress();
                $generateBtn.prop('disabled', false);
                renderResult(res);
            },
            error: function (xhr) {
                stopProgress();
                $generateBtn.prop('disabled', false);
                var msg = 'Error al conectar con la IA.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                showError(msg);
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Render result                                                      */
    /* ------------------------------------------------------------------ */
    function renderResult(d) {
        $('#wai_r_comercial').val(d.descripcion_comercial || '');
        $('#wai_r_historia').val(d.historia_origen || '');
        $('#wai_r_cultural').val(d.valor_cultural || '');
        $('#wai_r_curioso').val(d.dato_curioso || '');
        $('#wai_r_impacto').val(d.impacto_social || '');
        $('#wai_r_seo_titulo').val(d.seo_titulo || '').trigger('input');
        $('#wai_r_seo_meta').val(d.seo_meta || '').trigger('input');
        $('#wai_r_seo_kw').val(d.seo_palabras_clave || '');

        // Show first tab
        $('.wcfm-ai-rtab').first().trigger('click');

        $emptyState.hide();
        $resultPanel.css('display', 'flex').show();
    }

    /* ------------------------------------------------------------------ */
    /*  Apply to product form                                              */
    /* ------------------------------------------------------------------ */
    function applyAll() {
        var sections = [
            { title: 'Descripción',       id: '#wai_r_comercial' },
            { title: 'Historia y Origen', id: '#wai_r_historia'  },
            { title: 'Valor Cultural',    id: '#wai_r_cultural'  },
            { title: 'Dato Curioso',      id: '#wai_r_curioso'   },
            { title: 'Impacto Social',    id: '#wai_r_impacto'   },
        ];

        var html = '';
        sections.forEach(function (s) {
            var text = $(s.id).val().trim();
            if (text) {
                html += '<h3>' + s.title + '</h3><p>' + text.replace(/\n/g, '</p><p>') + '</p>\n';
            }
        });

        setField('description', html);
        applySEO();
        closeModal();
    }

    function applyExcerpt() {
        var text  = $('#wai_r_comercial').val().trim();
        // Take first 2 sentences
        var match = text.match(/[^.!?]*[.!?](\s[^.!?]*[.!?])?/);
        var short = match ? match[0].trim() : text.substring(0, 200);
        setField('excerpt', short);
        closeModal();
    }

    /* ------------------------------------------------------------------ */
    /*  Field writer                                                       */
    /* ------------------------------------------------------------------ */
    function setField(id, value) {
        var $field = $('#' + id);
        if ($field.length) {
            $field.val(value).trigger('change');
        }
        // Update TinyMCE if active
        if (typeof tinymce !== 'undefined') {
            var editor = tinymce.get(id);
            if (editor) {
                editor.setContent(value);
                editor.save();
            }
        }
    }

    function applySEO() {
        var titulo = $('#wai_r_seo_titulo').val();
        var meta   = $('#wai_r_seo_meta').val();
        var kw     = $('#wai_r_seo_kw').val();

        // Yoast SEO
        $('#yoast_wpseo_title').val(titulo).trigger('input');
        $('#yoast_wpseo_metadesc').val(meta).trigger('input');
        $('#yoast_wpseo_focuskw').val(kw).trigger('input');

        // RankMath
        $('#rank-math-title').val(titulo).trigger('input');
        $('#rank-math-description').val(meta).trigger('input');
    }

    /* ------------------------------------------------------------------ */
    /*  Progress bar                                                       */
    /* ------------------------------------------------------------------ */
    function startProgress() {
        $progress.show();
        // Restart animation by removing and re-adding class
        $progressFill.removeClass('animating');
        void $progressFill[0].offsetWidth; // reflow
        $progressFill.addClass('animating');
    }

    function stopProgress() {
        $progressFill.removeClass('animating');
        $progressFill.css('width', '100%');
        setTimeout(function () {
            $progress.hide();
            $progressFill.css('width', '');
        }, 300);
    }

    /* ------------------------------------------------------------------ */
    /*  Errors                                                             */
    /* ------------------------------------------------------------------ */
    function showError(msg) {
        $errorMsg.text(msg).show();
    }

    function hideError() {
        $errorMsg.hide().text('');
    }

}(jQuery));
