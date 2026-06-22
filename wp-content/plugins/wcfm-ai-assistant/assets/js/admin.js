/**
 * WCFM AI Assistant — Admin settings page script.
 */
(function ($) {
    'use strict';

    var defaultModels = {
        deepseek: 'deepseek-chat',
        claude:   'claude-sonnet-4-6',
        openai:   'gpt-4o',
        gemini:   'gemini-2.0-flash',
        groq:     'llama-3.3-70b-versatile',
    };

    var keyHints = {
        deepseek: 'Obtén tu key en platform.deepseek.com → API Keys (costo ~$0.30/100 productos)',
        claude:   'Obtén tu key en console.anthropic.com → API Keys (costo ~$8/100 productos)',
        openai:   'Obtén tu key en platform.openai.com → API Keys (costo ~$4/100 productos)',
        gemini:   'Obtén tu key en aistudio.google.com → Obtener clave API (tiene tier gratuito 15 req/min)',
        groq:     'Obtén tu key en console.groq.com → API Keys — Ultra rápido, ~280-1000 tokens/seg',
    };

    // Auto-fill model when provider changes
    $('#wcfm_ai_provider_select').on('change', function () {
        var provider = $(this).val();
        if (defaultModels[provider]) {
            $('#wcfm_ai_model_input').val(defaultModels[provider]);
        }
        if (keyHints[provider]) {
            $('#wcfm_ai_key_hint').text(keyHints[provider]);
        }
    });

    // Test connection
    $('#wcfm_ai_test_btn').on('click', function () {
        var $btn    = $(this);
        var $result = $('#wcfm_ai_test_result');

        $btn.prop('disabled', true).text('🔄 Probando…');
        $result.removeClass('success error').html('Conectando con la IA…').show();

        $.ajax({
            url:    wcfmAIAdmin.restUrl,
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', wcfmAIAdmin.nonce);
            },
            success: function (res) {
                if (res.success) {
                    $result.addClass('success').html(
                        '✅ <strong>' + res.message + '</strong>'
                        + (res.sample ? '<br/><em style="font-size:12px;color:#555">"' + res.sample + '"</em>' : '')
                        + (res.tokens ? '<br/><small>Tokens usados en prueba: ' + res.tokens + '</small>' : '')
                    );
                } else {
                    $result.addClass('error').html('❌ ' + (res.message || 'Error desconocido'));
                }
            },
            error: function (xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.message
                        ? xhr.responseJSON.message
                        : 'Error de conexión (HTTP ' + xhr.status + ')';
                $result.addClass('error').html('❌ ' + msg);
            },
            complete: function () {
                $btn.prop('disabled', false).text('🔌 Probar conexión');
            },
        });
    });

}(jQuery));
