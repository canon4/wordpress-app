<?php
/**
 * Cuota mensual de generaciones por vendedor, con reserva atómica.
 *
 * Corrige A-2: la versión anterior hacía leer -> llamar a la API -> incrementar
 * con `get_transient`/`set_transient`. Esa secuencia NO es atómica: varias
 * peticiones simultáneas leían el mismo contador y todas pasaban el límite
 * (verificado: con límite 50 y contador en 49, cinco peticiones concurrentes
 * leían 49 y las cinco pasaban). Además un caché de objetos puede desalojar un
 * transient y reiniciar la cuota.
 *
 * Aquí el contador se incrementa PRIMERO, con una sentencia SQL atómica
 * (INSERT ... ON DUPLICATE KEY UPDATE), y se devuelve el saldo. Si la llamada a
 * la IA falla, se reembolsa. Bajo concurrencia extrema puede rechazar de más,
 * nunca de menos: el error cae del lado seguro.
 *
 * @package WCFM_AI_Assistant
 */

defined( 'ABSPATH' ) || exit;

class WCFM_AI_Quota {

	/** Prefijo de la opción contador. */
	const PREFIX = 'wcfm_ai_count_';

	/**
	 * Nombre de la opción contador de un vendedor para un mes dado.
	 *
	 * @param int    $vendor_id
	 * @param string $month Formato Y_m. Por defecto, el mes actual.
	 * @return string
	 */
	public static function key( $vendor_id, $month = '' ) {
		$month = $month ?: gmdate( 'Y_m' );
		return self::PREFIX . absint( $vendor_id ) . '_' . $month;
	}

	/**
	 * Consumo actual del vendedor en el mes.
	 *
	 * @param int $vendor_id
	 * @return int
	 */
	public static function usage( $vendor_id ) {
		global $wpdb;
		$key = self::key( $vendor_id );
		$val = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key )
		);
		return (int) $val;
	}

	/**
	 * Reserva una generación de forma atómica.
	 *
	 * Incrementa el contador ANTES de llamar a la IA y comprueba el resultado.
	 * Si se pasa del límite, se reembolsa en el acto y se deniega.
	 *
	 * @param int $vendor_id
	 * @param int $limit Máximo mensual. 0 = sin cuota (no reserva nada).
	 * @return bool True si la generación está autorizada.
	 */
	public static function reserve( $vendor_id, $limit ) {
		global $wpdb;

		$limit = (int) $limit;
		if ( $limit <= 0 ) {
			return true; // Sin límite configurado.
		}

		$key = self::key( $vendor_id );

		// Incremento atómico: una sola sentencia, sin lectura previa.
		// autoload 'no' para que estos contadores no se carguen en cada request.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				 VALUES (%s, '1', 'no')
				 ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$key
			)
		);

		// El caché de opciones de WordPress quedó obsoleto tras el SQL directo.
		wp_cache_delete( $key, 'options' );

		$count = self::usage( $vendor_id );

		if ( $count > $limit ) {
			self::refund( $vendor_id ); // Devuelve la reserva: no se llegó a generar.
			return false;
		}
		return true;
	}

	/**
	 * Devuelve una reserva (la generación no se completó).
	 *
	 * Nunca baja de cero.
	 *
	 * @param int $vendor_id
	 * @return void
	 */
	public static function refund( $vendor_id ) {
		global $wpdb;
		$key = self::key( $vendor_id );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				 SET option_value = GREATEST(CAST(option_value AS SIGNED) - 1, 0)
				 WHERE option_name = %s",
				$key
			)
		);
		wp_cache_delete( $key, 'options' );
	}

	/**
	 * Borra el contador de un vendedor (uso administrativo y de pruebas).
	 *
	 * @param int $vendor_id
	 * @return void
	 */
	public static function reset( $vendor_id ) {
		delete_option( self::key( $vendor_id ) );
		wp_cache_delete( self::key( $vendor_id ), 'options' );
	}
}
