<?php
/**
 * Agregación de métricas de venta por vendedor/producto.
 *
 * Todo el cálculo numérico vive AQUÍ, en SQL/PHP puro. La IA nunca ve filas
 * crudas ni se le pide que sume o calcule nada: solo interpreta lo que esta
 * clase ya calculó. Esto es deliberado — evita que una recomendación de
 * negocio se base en una cifra de venta alucinada por el modelo.
 *
 * Fuente de datos: wp_wcfm_marketplace_orders (wc-multivendor-marketplace),
 * que ya viene segmentada por vendor_id y es la fuente de verdad de
 * comisiones del vendedor (evita mantener una segunda ruta de consulta
 * contra wc_order_product_lookup de WooCommerce core).
 *
 * @package WCFM_AI_Insights
 */

defined( 'ABSPATH' ) || defined( 'WCFM_AI_INSIGHTS_TEST' ) || exit;

class WCFM_Metrics_Aggregator {

	/** Estados de pedido que cuentan como venta válida. */
	const VALID_ORDER_STATUSES = array( 'wc-completed', 'wc-processing' );

	/** Bajo este umbral de días de stock restante, se marca "riesgo de quiebre". */
	const STOCKOUT_RISK_DAYS = 7;

	/** Sin ventas en estos días con stock > 0, se marca "estancado". */
	const STALE_DAYS = 60;

	/**
	 * Resumen de ventas por producto de un vendedor, con comparación contra el
	 * periodo anterior de igual longitud.
	 *
	 * @param int $vendor_id
	 * @param int $period_days
	 * @return array {
	 *     @type int    $vendor_id
	 *     @type int    $period_days
	 *     @type string $period_start
	 *     @type string $period_end
	 *     @type array  $products Lista de filas por producto.
	 * }
	 */
	public static function get_vendor_summary( $vendor_id, $period_days = 30 ) {
		global $wpdb;

		$vendor_id   = absint( $vendor_id );
		$period_days = self::sanitize_period_days( $period_days );

		$period_end     = current_time( 'mysql' );
		$period_start   = gmdate( 'Y-m-d H:i:s', strtotime( $period_end ) - ( $period_days * DAY_IN_SECONDS ) );
		$prior_end      = $period_start;
		$prior_start    = gmdate( 'Y-m-d H:i:s', strtotime( $prior_end ) - ( $period_days * DAY_IN_SECONDS ) );

		$current = self::sales_by_product( $vendor_id, $period_start, $period_end );
		$prior   = self::sales_by_product( $vendor_id, $prior_start, $prior_end );

		$product_ids = array_unique( array_merge( array_keys( $current ), array_keys( $prior ) ) );

		$rows = array();
		foreach ( $product_ids as $product_id ) {
			$cur_units   = isset( $current[ $product_id ]['units'] ) ? (float) $current[ $product_id ]['units'] : 0.0;
			$cur_revenue = isset( $current[ $product_id ]['revenue'] ) ? round( (float) $current[ $product_id ]['revenue'], 2 ) : 0.0;
			$pri_units   = isset( $prior[ $product_id ]['units'] ) ? (float) $prior[ $product_id ]['units'] : 0.0;
			$pri_revenue = isset( $prior[ $product_id ]['revenue'] ) ? round( (float) $prior[ $product_id ]['revenue'], 2 ) : 0.0;

			$pct_change = null;
			if ( $pri_revenue > 0 ) {
				$pct_change = round( ( ( $cur_revenue - $pri_revenue ) / $pri_revenue ) * 100, 1 );
			} elseif ( $cur_revenue > 0 ) {
				$pct_change = 100.0; // De 0 a algo: crecimiento "nuevo", se reporta como +100%.
			}

			$stock    = self::get_stock( $product_id );
			$velocity = self::days_of_stock( $stock, $cur_units, $period_days );

			$rows[] = array(
				'product_id'              => (int) $product_id,
				'product_name'            => self::get_product_name( $product_id ),
				'units_sold'              => (int) $cur_units,
				'revenue'                 => $cur_revenue,
				'prior_period_units'      => (int) $pri_units,
				'prior_period_revenue'    => $pri_revenue,
				'pct_change_revenue'      => $pct_change,
				'current_stock'           => $stock,
				'velocity_days_of_stock'  => $velocity,
			);
		}

		// Ordena por ingresos del periodo actual, de mayor a menor.
		usort( $rows, function ( $a, $b ) {
			return $b['revenue'] <=> $a['revenue'];
		} );

		return array(
			'vendor_id'    => $vendor_id,
			'period_days'  => $period_days,
			'period_start' => $period_start,
			'period_end'   => $period_end,
			'products'     => $rows,
		);
	}

	/**
	 * Top/bottom N productos por ingresos, a partir de un resumen ya calculado.
	 *
	 * @param array $summary Salida de get_vendor_summary().
	 * @param int   $limit
	 * @return array { top: array, bottom: array }
	 */
	public static function top_bottom_from_summary( array $summary, $limit = 5 ) {
		$rows  = isset( $summary['products'] ) ? $summary['products'] : array();
		$limit = max( 1, (int) $limit );

		$sorted = $rows; // Ya viene ordenado por revenue desc en get_vendor_summary().
		$top    = array_slice( $sorted, 0, $limit );
		$bottom = array_slice( array_reverse( $sorted ), 0, $limit );

		return array(
			'top'    => $top,
			'bottom' => $bottom,
		);
	}

	/**
	 * Productos en riesgo de quiebre de stock o estancados (sin ventas recientes
	 * con stock disponible).
	 *
	 * @param int $vendor_id
	 * @return array { stockout_risk: array, stale: array }
	 */
	public static function get_stock_risk_products( $vendor_id ) {
		$vendor_id = absint( $vendor_id );
		$summary   = self::get_vendor_summary( $vendor_id, self::STALE_DAYS );

		$stockout_risk = array();
		$stale         = array();

		foreach ( $summary['products'] as $row ) {
			if ( null !== $row['velocity_days_of_stock'] && $row['velocity_days_of_stock'] < self::STOCKOUT_RISK_DAYS ) {
				$stockout_risk[] = $row;
			}
			if ( $row['current_stock'] > 0 && 0 === $row['units_sold'] ) {
				$stale[] = $row;
			}
		}

		return array(
			'stockout_risk' => $stockout_risk,
			'stale'         => $stale,
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Internos                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Ventas agrupadas por producto en una ventana de fechas, para un vendedor.
	 *
	 * item_total/quantity se guardan como varchar en wp_wcfm_marketplace_orders,
	 * de ahí el CAST explícito antes de sumar.
	 *
	 * @param int    $vendor_id
	 * @param string $start MySQL datetime
	 * @param string $end   MySQL datetime
	 * @return array product_id => array( units, revenue )
	 */
	private static function sales_by_product( $vendor_id, $start, $end ) {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( self::VALID_ORDER_STATUSES ), '%s' ) );

		$sql = "SELECT product_id,
					SUM(CAST(quantity AS SIGNED)) AS units,
					SUM(CAST(item_total AS DECIMAL(12,2))) AS revenue
				FROM {$wpdb->prefix}wcfm_marketplace_orders
				WHERE vendor_id = %d
				  AND is_trashed = 0
				  AND is_refunded = 0
				  AND order_status IN ($placeholders)
				  AND created BETWEEN %s AND %s
				GROUP BY product_id";

		$params = array_merge( array( $vendor_id ), self::VALID_ORDER_STATUSES, array( $start, $end ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[ (int) $r['product_id'] ] = array(
					'units'   => (float) $r['units'],
					'revenue' => (float) $r['revenue'],
				);
			}
		}
		return $out;
	}

	private static function get_stock( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}
		$stock = $product->get_stock_quantity();
		return null !== $stock ? (int) $stock : null;
	}

	private static function get_product_name( $product_id ) {
		$title = get_the_title( $product_id );
		return $title ? $title : ( 'Producto #' . (int) $product_id );
	}

	/**
	 * Días de stock restante a la velocidad de venta actual.
	 * null si no hay stock gestionado o no hay ventas suficientes para estimar.
	 *
	 * @param int|null $stock
	 * @param float    $units_sold_in_period
	 * @param int      $period_days
	 * @return float|null
	 */
	private static function days_of_stock( $stock, $units_sold_in_period, $period_days ) {
		if ( null === $stock ) {
			return null;
		}
		if ( $units_sold_in_period <= 0 ) {
			return $stock > 0 ? null : 0.0; // Sin ventas: no se puede estimar velocidad; si además no hay stock, 0.
		}
		$daily_velocity = $units_sold_in_period / max( 1, $period_days );
		if ( $daily_velocity <= 0 ) {
			return null;
		}
		return round( $stock / $daily_velocity, 1 );
	}

	/**
	 * @param mixed $value
	 * @return int Periodo acotado entre 7 y 365 días.
	 */
	public static function sanitize_period_days( $value ) {
		$n = (int) $value;
		if ( $n < 7 ) {
			$n = 30;
		}
		return min( $n, 365 );
	}
}
