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
        var $modelInput     = $('#wcfm_ai_model_input');
        var $keyHint        = $('#wcfm_ai_key_hint');
        var $testBtn        = $('#wcfm_ai_test_btn');
        var $testResult     = $('#wcfm_ai_test_result');

        // Update model & hint when provider changes
        $providerSelect.on('change', function () {
            var provider = $(this).val();
            if (defaultModels[provider]) {
                $modelInput.val(defaultModels[provider]);
            }
            if (keyHints[provider]) {
                $keyHint.html(keyHints[provider]);
            }
        });

        // Show hint for current provider on load
        var currentProvider = $providerSelect.val();
        if (keyHints[currentProvider]) {
            $keyHint.html(keyHints[currentProvider]);
        }

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
