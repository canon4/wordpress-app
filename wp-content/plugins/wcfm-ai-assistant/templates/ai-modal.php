<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// $vendor_ctx is passed from render_modal()
$store_name    = isset( $vendor_ctx['store_name'] ) ? $vendor_ctx['store_name'] : '';
$store_desc    = isset( $vendor_ctx['store_description'] ) ? $vendor_ctx['store_description'] : '';
$location      = isset( $vendor_ctx['location'] ) ? $vendor_ctx['location'] : '';
$community     = isset( $vendor_ctx['community_history'] ) ? $vendor_ctx['community_history'] : '';
$traditions    = isset( $vendor_ctx['traditions'] ) ? $vendor_ctx['traditions'] : '';
?>
<div id="wcfm-ai-overlay" class="wcfm-ai-overlay" role="dialog" aria-modal="true" aria-labelledby="wcfm-ai-modal-title" style="display:none;">
    <div class="wcfm-ai-modal">

        <!-- Header -->
        <div class="wcfm-ai-modal-header">
            <span id="wcfm-ai-modal-title">&#9733; Generador de Descripción Cultural con IA</span>
            <button id="wcfm-ai-close-btn" type="button" aria-label="Cerrar">&times;</button>
        </div>

        <!-- Body: two-column layout -->
        <div class="wcfm-ai-modal-body">

            <!-- LEFT: Inputs -->
            <div class="wcfm-ai-inputs-col">

                <div class="wcfm-ai-field-group">
                    <label for="wai_product_name">
                        Nombre del producto
                        <span class="wcfm-ai-badge">AUTO</span>
                    </label>
                    <input type="text" id="wai_product_name" placeholder="Se lee del formulario…" />
                </div>

                <div class="wcfm-ai-field-group">
                    <label for="wai_category">
                        Categoría
                        <span class="wcfm-ai-badge">AUTO</span>
                    </label>
                    <input type="text" id="wai_category" placeholder="Se lee del formulario…" />
                </div>

                <div class="wcfm-ai-field-group">
                    <label for="wai_materials">
                        Materiales <span class="wcfm-ai-required">*</span>
                    </label>
                    <textarea id="wai_materials" rows="2" placeholder="Ej: lana de oveja, tintes naturales…"></textarea>
                </div>

                <div class="wcfm-ai-field-group">
                    <label for="wai_process">Proceso de elaboración</label>
                    <textarea id="wai_process" rows="2" placeholder="Ej: tejido a mano en telar de madera…"></textarea>
                </div>

                <div class="wcfm-ai-field-group">
                    <label for="wai_benefits">Beneficios para el comprador</label>
                    <textarea id="wai_benefits" rows="2" placeholder="Ej: duradera, única, apoya comunidades…"></textarea>
                </div>

                <!-- Vendor chip -->
                <?php if ( ! empty( $store_name ) ) : ?>
                <div class="wcfm-ai-vendor-chip">
                    <span class="wcfm-ai-vendor-icon">&#127968;</span>
                    <div>
                        <strong><?php echo esc_html( $store_name ); ?></strong>
                        <?php if ( ! empty( $location ) ) : ?>
                            <br><small><?php echo esc_html( $location ); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Hidden vendor context -->
                <input type="hidden" id="wai_v_store"       value="<?php echo esc_attr( $store_name ); ?>" />
                <input type="hidden" id="wai_v_desc"        value="<?php echo esc_attr( $store_desc ); ?>" />
                <input type="hidden" id="wai_v_location"    value="<?php echo esc_attr( $location ); ?>" />
                <input type="hidden" id="wai_v_community"   value="<?php echo esc_attr( $community ); ?>" />
                <input type="hidden" id="wai_v_traditions"  value="<?php echo esc_attr( $traditions ); ?>" />

                <button id="wcfm-ai-generate-btn" type="button" class="wcfm-ai-generate-btn">
                    &#9733; Generar descripción
                </button>

                <!-- Progress bar -->
                <div id="wcfm-ai-progress" class="wcfm-ai-progress" style="display:none;">
                    <div class="wcfm-ai-progress-fill"></div>
                    <span class="wcfm-ai-progress-label">Generando con IA…</span>
                </div>

                <!-- Error -->
                <div id="wcfm-ai-error-msg" class="wcfm-ai-error" style="display:none;"></div>

            </div><!-- /inputs-col -->

            <!-- RIGHT: Results -->
            <div class="wcfm-ai-results-col">

                <!-- Empty state -->
                <div id="wcfm-ai-empty-state" class="wcfm-ai-empty-state">
                    <div class="wcfm-ai-empty-icon">&#9998;</div>
                    <p>Completa los materiales y haz clic en<br><strong>Generar descripción</strong></p>
                </div>

                <!-- Result panel -->
                <div id="wcfm-ai-result-panel" style="display:none;flex:1;display:none;flex-direction:column;">

                    <!-- Tabs -->
                    <div class="wcfm-ai-result-tabs" role="tablist">
                        <button class="wcfm-ai-rtab active" data-tab="r_comercial"  role="tab">Descripción</button>
                        <button class="wcfm-ai-rtab"        data-tab="r_historia"   role="tab">Historia</button>
                        <button class="wcfm-ai-rtab"        data-tab="r_cultural"   role="tab">Cultural</button>
                        <button class="wcfm-ai-rtab"        data-tab="r_curioso"    role="tab">Dato</button>
                        <button class="wcfm-ai-rtab"        data-tab="r_impacto"    role="tab">Impacto</button>
                        <button class="wcfm-ai-rtab"        data-tab="r_seo"        role="tab">SEO</button>
                    </div>

                    <!-- Tab panels -->
                    <div class="wcfm-ai-tab-panels">

                        <div id="r_comercial" class="wcfm-ai-tab-panel active">
                            <textarea id="wai_r_comercial" rows="8"></textarea>
                        </div>

                        <div id="r_historia" class="wcfm-ai-tab-panel">
                            <textarea id="wai_r_historia" rows="8"></textarea>
                        </div>

                        <div id="r_cultural" class="wcfm-ai-tab-panel">
                            <textarea id="wai_r_cultural" rows="8"></textarea>
                        </div>

                        <div id="r_curioso" class="wcfm-ai-tab-panel">
                            <textarea id="wai_r_curioso" rows="8"></textarea>
                        </div>

                        <div id="r_impacto" class="wcfm-ai-tab-panel">
                            <textarea id="wai_r_impacto" rows="8"></textarea>
                        </div>

                        <div id="r_seo" class="wcfm-ai-tab-panel">
                            <div class="wcfm-ai-seo-field">
                                <label>Título SEO <span class="wcfm-ai-char-count" id="cnt_seo_titulo">0/60</span></label>
                                <input type="text" id="wai_r_seo_titulo" maxlength="60" />
                            </div>
                            <div class="wcfm-ai-seo-field">
                                <label>Meta descripción <span class="wcfm-ai-char-count" id="cnt_seo_meta">0/155</span></label>
                                <textarea id="wai_r_seo_meta" rows="3" maxlength="155"></textarea>
                            </div>
                            <div class="wcfm-ai-seo-field">
                                <label>Palabras clave</label>
                                <input type="text" id="wai_r_seo_kw" placeholder="kw1, kw2, kw3…" />
                            </div>
                        </div>

                    </div><!-- /tab-panels -->

                    <!-- Apply row -->
                    <div class="wcfm-ai-apply-row">
                        <button id="wcfm-ai-apply-all-btn" type="button" class="button button-primary">
                            &#10003; Aplicar descripción completa
                        </button>
                        <button id="wcfm-ai-apply-excerpt-btn" type="button" class="button">
                            Aplicar como descripción corta
                        </button>
                        <button id="wcfm-ai-regenerate-btn" type="button" class="button">
                            &#8635; Regenerar
                        </button>
                    </div>

                </div><!-- /result-panel -->

            </div><!-- /results-col -->

        </div><!-- /modal-body -->
    </div><!-- /modal -->
</div><!-- /overlay -->
