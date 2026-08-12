<?php
/**
 * Pantalla admin: métricas por vendedor + recomendaciones de IA.
 *
 * Variables disponibles (definidas en WCFM_AI_Insights::render_admin_page()):
 *   array $vendors         Lista de objetos { ID, display_name }.
 *   int   $default_period
 *   int   $monthly_limit
 *
 * @package WCFM_AI_Insights
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap wcfm-ai-insights-wrap">
	<h1>&#128202; IA Insights — Análisis de Métricas</h1>

	<?php if ( ! class_exists( 'WCFM_AI_API' ) ) : ?>
		<div class="notice notice-error"><p>El plugin <strong>WCFM AI Assistant</strong> debe estar activo para generar recomendaciones. Los números crudos se pueden ver igual.</p></div>
	<?php endif; ?>

	<?php if ( empty( $vendors ) ) : ?>
		<p>No hay vendedores registrados (rol <code>wcfm_vendor</code>) todavía.</p>
		<?php return; ?>
	<?php endif; ?>

	<div class="wcfm-ai-insights-controls">
		<label for="wcfm_ai_insights_vendor">Vendedor</label>
		<select id="wcfm_ai_insights_vendor">
			<?php foreach ( $vendors as $v ) : ?>
				<option value="<?php echo esc_attr( $v->ID ); ?>"><?php echo esc_html( $v->display_name ); ?></option>
			<?php endforeach; ?>
		</select>

		<label for="wcfm_ai_insights_period">Periodo</label>
		<select id="wcfm_ai_insights_period">
			<option value="7">Últimos 7 días</option>
			<option value="30" <?php selected( $default_period, 30 ); ?>>Últimos 30 días</option>
			<option value="90">Últimos 90 días</option>
		</select>

		<button id="wcfm_ai_insights_load_btn" class="button button-primary">Ver métricas</button>
		<button id="wcfm_ai_insights_recommend_btn" class="button button-secondary" disabled>Generar recomendación IA</button>
		<button id="wcfm_ai_insights_refresh_btn" class="button button-link" disabled>Forzar refresco</button>
	</div>

	<p class="description">Límite mensual de análisis de IA por vendedor: <?php echo esc_html( $monthly_limit ); ?> (los administradores no consumen cuota).</p>

	<div id="wcfm_ai_insights_loading" style="display:none;"><p>Cargando…</p></div>
	<div id="wcfm_ai_insights_error" class="notice notice-error" style="display:none;"><p></p></div>

	<h2>Métricas por producto</h2>
	<table class="widefat striped" id="wcfm_ai_insights_table">
		<thead>
			<tr>
				<th>Producto</th>
				<th>Unidades</th>
				<th>Ingresos</th>
				<th>Periodo anterior</th>
				<th>Variación</th>
				<th>Stock</th>
				<th>Días de stock</th>
			</tr>
		</thead>
		<tbody></tbody>
	</table>

	<div id="wcfm_ai_insights_risk" style="display:none;">
		<h2>Señales de stock</h2>
		<div id="wcfm_ai_insights_risk_content"></div>
	</div>

	<div id="wcfm_ai_insights_recommendations" style="display:none;">
		<h2>Recomendaciones de IA</h2>
		<p class="description" id="wcfm_ai_insights_rec_meta"></p>
		<div id="wcfm_ai_insights_rec_content"></div>
	</div>
</div>
