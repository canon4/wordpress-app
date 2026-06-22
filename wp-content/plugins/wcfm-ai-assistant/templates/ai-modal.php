<?php
/**
 * AI Assistant modal template.
 * Injected via wp_footer — renders outside WCFM's form structure.
 *
 * Available: $vendor_data (array)
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>

<!-- =====================================================
     WCFM AI Assistant — Modal
     ===================================================== -->
<div id="wcfm-ai-overlay" class="wcfm-ai-overlay" role="dialog" aria-modal="true" aria-labelledby="wcfm-ai-modal-title" style="display:none;">
	<div class="wcfm-ai-modal">

		<!-- Modal header -->
		<div class="wcfm-ai-modal-header">
			<div class="wcfm-ai-modal-title-wrap">
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
				</svg>
				<h2 id="wcfm-ai-modal-title">Generador de Descripción Cultural con IA</h2>
			</div>
			<button type="button" id="wcfm-ai-close-btn" class="wcfm-ai-close-btn" aria-label="Cerrar">
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
					<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
				</svg>
			</button>
		</div>

		<!-- Modal body: two columns -->
		<div class="wcfm-ai-modal-body">

			<!-- LEFT: Inputs -->
			<div class="wcfm-ai-inputs-col">
				<p class="wcfm-ai-col-label">Información del producto</p>

				<div class="wcfm-ai-field">
					<label for="wai_product_name">Nombre del producto <span class="wcfm-ai-auto-badge">auto</span></label>
					<input type="text" id="wai_product_name" class="wcfm-ai-input" placeholder="Se leerá del formulario…"/>
				</div>

				<div class="wcfm-ai-field">
					<label for="wai_category">Categoría <span class="wcfm-ai-auto-badge">auto</span></label>
					<input type="text" id="wai_category" class="wcfm-ai-input" placeholder="Se leerá del formulario…"/>
				</div>

				<div class="wcfm-ai-field">
					<label for="wai_materials">Materiales <span class="wcfm-ai-required">*</span></label>
					<textarea id="wai_materials" class="wcfm-ai-textarea" rows="2" placeholder="Ej: lana de alpaca, tintes naturales, madera de pino…"></textarea>
				</div>

				<div class="wcfm-ai-field">
					<label for="wai_process">Proceso de fabricación</label>
					<textarea id="wai_process" class="wcfm-ai-textarea" rows="2" placeholder="Ej: tejido a telar de cintura, 3 semanas de trabajo…"></textarea>
				</div>

				<div class="wcfm-ai-field">
					<label for="wai_benefits">Beneficios y usos</label>
					<textarea id="wai_benefits" class="wcfm-ai-textarea" rows="2" placeholder="Ej: decoración, regalo, uso ceremonial…"></textarea>
				</div>

				<?php if ( $vendor_data['store_name'] || $vendor_data['store_desc'] ) : ?>
				<div class="wcfm-ai-vendor-chip">
					<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
					Usando perfil:
					<strong><?php echo esc_html( $vendor_data['store_name'] ); ?></strong>
					<?php if ( $vendor_data['location'] ) : ?>
						· <?php echo esc_html( $vendor_data['location'] ); ?>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<button type="button" id="wcfm-ai-generate-btn" class="wcfm-ai-generate-btn">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
					</svg>
					<span id="wcfm-ai-btn-text">Generar descripción</span>
				</button>

				<div id="wcfm-ai-progress" class="wcfm-ai-progress" style="display:none;">
					<div class="wcfm-ai-progress-bar"><div class="wcfm-ai-progress-fill"></div></div>
					<span>Generando contenido…</span>
				</div>

				<div id="wcfm-ai-error-msg" class="wcfm-ai-error-box" style="display:none;"></div>

				<!-- Hidden vendor context -->
				<input type="hidden" id="wai_v_store"      value="<?php echo esc_attr( $vendor_data['store_name'] ); ?>">
				<input type="hidden" id="wai_v_desc"       value="<?php echo esc_attr( $vendor_data['store_desc'] ); ?>">
				<input type="hidden" id="wai_v_location"   value="<?php echo esc_attr( $vendor_data['location'] ); ?>">
				<input type="hidden" id="wai_v_community"  value="<?php echo esc_attr( $vendor_data['community'] ); ?>">
				<input type="hidden" id="wai_v_traditions" value="<?php echo esc_attr( $vendor_data['traditions'] ); ?>">
			</div>

			<!-- RIGHT: Results -->
			<div class="wcfm-ai-results-col" id="wcfm-ai-results-col">

				<!-- Empty state -->
				<div id="wcfm-ai-empty-state" class="wcfm-ai-empty-state">
					<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" opacity=".3">
						<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
					</svg>
					<p>Completa los campos y presiona<br/><strong>Generar descripción</strong></p>
				</div>

				<!-- Result tabs (hidden until generated) -->
				<div id="wcfm-ai-result-panel" style="display:none;">
					<div class="wcfm-ai-result-tabs" role="tablist">
						<button class="wcfm-ai-rtab active" data-tab="r_comercial">Descripción</button>
						<button class="wcfm-ai-rtab" data-tab="r_historia">Historia</button>
						<button class="wcfm-ai-rtab" data-tab="r_cultural">Cultural</button>
						<button class="wcfm-ai-rtab" data-tab="r_curioso">Dato</button>
						<button class="wcfm-ai-rtab" data-tab="r_impacto">Impacto</button>
						<button class="wcfm-ai-rtab" data-tab="r_seo">SEO</button>
					</div>

					<div class="wcfm-ai-rtab-content active" id="r_comercial">
						<label class="wcfm-ai-rlabel">Descripción comercial</label>
						<textarea id="wai_r_comercial" class="wcfm-ai-rtextarea" rows="8"></textarea>
					</div>
					<div class="wcfm-ai-rtab-content" id="r_historia">
						<label class="wcfm-ai-rlabel">Historia y origen</label>
						<textarea id="wai_r_historia" class="wcfm-ai-rtextarea" rows="8"></textarea>
					</div>
					<div class="wcfm-ai-rtab-content" id="r_cultural">
						<label class="wcfm-ai-rlabel">Valor cultural</label>
						<textarea id="wai_r_cultural" class="wcfm-ai-rtextarea" rows="8"></textarea>
					</div>
					<div class="wcfm-ai-rtab-content" id="r_curioso">
						<label class="wcfm-ai-rlabel">¿Sabías que…?</label>
						<textarea id="wai_r_curioso" class="wcfm-ai-rtextarea" rows="8"></textarea>
					</div>
					<div class="wcfm-ai-rtab-content" id="r_impacto">
						<label class="wcfm-ai-rlabel">Impacto social</label>
						<textarea id="wai_r_impacto" class="wcfm-ai-rtextarea" rows="8"></textarea>
					</div>
					<div class="wcfm-ai-rtab-content" id="r_seo">
						<label class="wcfm-ai-rlabel">Título SEO <span id="wai_seo_titulo_count" class="wcfm-ai-charcount">0/60</span></label>
						<input type="text" id="wai_r_seo_titulo" class="wcfm-ai-input" maxlength="60"/>
						<label class="wcfm-ai-rlabel" style="margin-top:10px">Meta descripción <span id="wai_seo_meta_count" class="wcfm-ai-charcount">0/155</span></label>
						<textarea id="wai_r_seo_meta" class="wcfm-ai-rtextarea" rows="3" maxlength="155"></textarea>
						<label class="wcfm-ai-rlabel" style="margin-top:10px">Palabras clave</label>
						<input type="text" id="wai_r_seo_kw" class="wcfm-ai-input"/>
					</div>

					<!-- Apply actions -->
					<div class="wcfm-ai-apply-row">
						<button type="button" id="wcfm-ai-apply-all-btn" class="wcfm-ai-apply-btn wcfm-ai-apply-btn--primary">
							✓ Aplicar descripción completa
						</button>
						<button type="button" id="wcfm-ai-apply-excerpt-btn" class="wcfm-ai-apply-btn">
							Aplicar como descripción corta
						</button>
						<button type="button" id="wcfm-ai-regenerate-btn" class="wcfm-ai-apply-btn wcfm-ai-apply-btn--ghost">
							↺ Regenerar
						</button>
					</div>
					<div id="wcfm-ai-success-msg" class="wcfm-ai-success-msg" style="display:none;"></div>
				</div>

			</div><!-- /.wcfm-ai-results-col -->
		</div><!-- /.wcfm-ai-modal-body -->
	</div><!-- /.wcfm-ai-modal -->
</div><!-- /#wcfm-ai-overlay -->
