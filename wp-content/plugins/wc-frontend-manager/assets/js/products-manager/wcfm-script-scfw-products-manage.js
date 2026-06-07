(function($) {
    let wcfm_scfw_app = {
        run: function() {
            this.init();
            this.make_select2();
        },
        init: function() {
            this.$wcfm_prod_chart = $('#wcfm_prod_chart');
        },
        make_select2: function() {
            var terms = [];
            this.$wcfm_prod_chart.select2({
                containerCssClass: wcfm_scfw_js_object.select2Args.container_css_class,
                allowClear: wcfm_scfw_js_object.select2Args.allow_clear,
                placeholder: wcfm_scfw_js_object.l10n.placeholder,
                minimumInputLength: wcfm_scfw_js_object.select2Args.minimum_input_length,
                escapeMarkup: function ( m ) {
                    return m;
                },
                ajax: {
                    url: wcfm_scfw_js_object.select2Args.ajax.url,
                    dataType: wcfm_scfw_js_object.select2Args.ajax.dataType,
                    delay: wcfm_scfw_js_object.select2Args.ajax.delay,
                    data: function ( params ) {
                        return {
                            'searchQueryParameter': params.term,
                            action: wcfm_scfw_js_object.select2Args.ajax.action,
                            security: wcfm_scfw_js_object.select2Args.ajax.security,
                            exclude: wcfm_scfw_js_object.select2Args.ajax.exclude
                        };
                    },
                    processResults: function ( data ) {
                        terms = [];
                        if ( data ) {
                            $.each( data, function ( id, text ) {
                                terms.push( {
                                    id: id,
                                    text: text,
                                } );
                            } );
                        }
                        return {
                            results: terms,
                        };
                    },
                    cache: true,
                },
            });
        }
    };
    $(document).ready(wcfm_scfw_app.run.bind(wcfm_scfw_app));
})(jQuery);