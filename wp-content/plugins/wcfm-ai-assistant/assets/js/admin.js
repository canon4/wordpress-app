/* WCFM AI Assistant — Admin settings JS */
(function ($) {
    'use strict';

    var defaultModels = {
        deepseek: 'deepseek-chat',
        openai:   'gpt-4o',
        claude:   'claude-sonnet-4-6',
        gemini:   'gemini-2.0-flash',
        groq:     'llama-3.3-70b-versatile',
        mistral:  'mistral-large-latest',
    };

    var keyHints = {
        deepseek: 'Obtén tu clave en <a href="https://platform.deepseek.com/api_keys" target="_blank">platform.deepseek.com</a>',
        openai:   'Obtén tu clave en <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>',
        claude:   'Obtén tu clave en <a href="https://console.anthropic.com/settings/keys" target="_blank">console.anthropic.com</a>',
        gemini:   'Obtén tu clave en <a href="https://aistudio.google.com/app/apikey" target="_blank">aistudio.google.com</a> — tier gratuito disponible',
        groq:     'Obtén tu clave en <a href="https://console.groq.com/keys" target="_blank">console.groq.com</a>',
        mistral:  'Obtén tu clave en <a href="https://console.mistral.ai/api-keys" target="_blank">console.mistral.ai</a>',
    };

    $(function () {
        var $providerSelect = $('#wcfm_ai_provider_select');
        var $modelSelect    = $('#wcfm_ai_model_select');
        var $modelInput     = $('#wcfm_ai_model_input');
        var $modelStatus    = $('#wcfm_ai_model_status');
        var $refreshBtn     = $('#wcfm_ai_models_refresh');
        var $manualToggle   = $('#wcfm_ai_model_manual_toggle');
        var $keyHint        = $('#wcfm_ai_key_hint');
        var $testBtn        = $('#wcfm_ai_test_btn');
        var $testResult     = $('#wcfm_ai_test_result');

        // El <select> no tiene "name": su valor se copia al <input> oculto,
        // que es el que realmente viaja en el submit del formulario.
        $modelSelect.on('change', function () {
            $modelInput.val($(this).val());
        });

        $manualToggle.on('change', function () {
            var manual = $(this).is(':checked');
            $modelInput.toggle(manual);
            $modelSelect.toggle(!manual);
            if (!manual) {
                // Al volver al modo lista, que el select mande de nuevo su valor.
                $modelInput.val($modelSelect.val());
            }
        });

        function fetchModels(provider, preserveValue) {
            var keep = preserveValue || $modelInput.val();

            $modelStatus.removeClass('notice-error').text('Cargando modelos…');
            $refreshBtn.prop('disabled', true);

            $.ajax({
                url:        wcfmAIAdmin.restUrl + 'models',
                method:     'GET',
                data:       { provider: provider },
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcfmAIAdmin.nonce);
                },
                success: function (res) {
                    var models = (res && res.models) || [];
                    $modelSelect.empty();

                    if (!models.length) {
                        $modelStatus.text('El proveedor no devolvió modelos disponibles.');
                    } else {
                        $modelStatus.text('');
                    }

                    var found = false;
                    models.forEach(function (m) {
                        var $opt = $('<option></option>').val(m.id).text(m.label || m.id);
                        if (m.id === keep) {
                            $opt.prop('selected', true);
                            found = true;
                        }
                        $modelSelect.append($opt);
                    });

                    // El modelo guardado puede ya no estar en el catalogo vigente
                    // (como paso con llama-3.3-70b-versatile): se conserva como
                    // opcion extra para no perder la configuracion actual.
                    if (!found && keep) {
                        $modelSelect.prepend(
                            $('<option></option>').val(keep).text(keep + ' (guardado, ya no está en la lista)').prop('selected', true)
                        );
                    }

                    $modelInput.val($modelSelect.val());
                },
                error: function (xhr) {
                    var msg = 'No se pudo obtener la lista de modelos.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    $modelStatus.addClass('notice-error').text('✗ ' + msg + ' Usa "Escribir manualmente".');
                },
                complete: function () {
                    $refreshBtn.prop('disabled', false);
                }
            });
        }

        // Update model & hint when provider changes
        $providerSelect.on('change', function () {
            var provider = $(this).val();
            if (keyHints[provider]) {
                $keyHint.html(keyHints[provider]);
            }
            fetchModels(provider, defaultModels[provider] || '');
        });

        $refreshBtn.on('click', function () {
            fetchModels($providerSelect.val());
        });

        // Show hint for current provider on load
        var currentProvider = $providerSelect.val();
        if (keyHints[currentProvider]) {
            $keyHint.html(keyHints[currentProvider]);
        }
        fetchModels(currentProvider);

        // Test connection
        $testBtn.on('click', function () {
            $testBtn.prop('disabled', true).text('Probando…');
            $testResult.hide();

            $.ajax({
                url:        wcfmAIAdmin.restUrl + 'test',
                method:     'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', wcfmAIAdmin.nonce);
                },
                success: function (res) {
                    $testResult
                        .removeClass('notice-error notice-success')
                        .addClass('notice notice-success')
                        .html('<strong>✓ ' + res.message + '</strong>' +
                              (res.sample ? '<br><em>' + res.sample + '</em>' : '') +
                              (res.tokens ? '<br>Tokens usados: ' + res.tokens : ''))
                        .show();
                },
                error: function (xhr) {
                    var msg = 'Error de conexión';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    $testResult
                        .removeClass('notice-error notice-success')
                        .addClass('notice notice-error')
                        .html('<strong>✗ ' + msg + '</strong>')
                        .show();
                },
                complete: function () {
                    $testBtn.prop('disabled', false).text('Probar conexión');
                }
            });
        });
    });

}(jQuery));
