<?php
/**
 * Saneamiento y validación de la entrada que llega a la IA.
 *
 * Funciones PURAS (sin dependencias de WordPress) para poder probarlas con el
 * intérprete de PHP directamente:
 *   C:\xampp\php\php.exe tests\test01-input.php
 *
 * Corrige tres fallos detectados en la auditoría:
 *  - A-1: la entrada del cliente no tenía tope de longitud (abuso de costo:
 *         200 KB en un campo generaban un prompt de ~50.000 tokens).
 *  - A-3: el texto del cliente llegaba verbatim al modelo (inyección de prompt).
 *  - A-6/A-7: los ajustes se guardaban sin validar (proveedor/modelo libres).
 *
 * @package WCFM_AI_Assistant
 */

defined( 'ABSPATH' ) || defined( 'WCFM_AI_TEST' ) || exit;

class WCFM_AI_Security {

	/**
	 * Campos aceptados del cliente y su longitud máxima (en caracteres).
	 *
	 * Cualquier campo fuera de esta lista se descarta: el endpoint ya no reenvía
	 * el array crudo de la petición a la IA.
	 *
	 * Los campos `vendor_*` NO están aquí a propósito: el servidor los deriva de
	 * la sesión del vendedor (ver A-4), nunca los acepta del cliente.
	 */
	const FIELD_LIMITS = array(
		'product_name' => 200,
		'category'     => 200,
		'short_desc'   => 1000,
		'materials'    => 500,
		'process'      => 1000,
		'benefits'     => 500,
	);

	/** Campos de contexto que SOLO puede rellenar el servidor. */
	const VENDOR_FIELDS = array(
		'vendor_store'      => 200,
		'vendor_desc'       => 1000,
		'vendor_location'   => 200,
		'vendor_community'  => 2000,
		'vendor_traditions' => 2000,
	);

	/** Proveedores soportados. */
	public static function allowed_providers() {
		return array( 'deepseek', 'groq', 'gemini', 'openai', 'claude', 'mistral' );
	}

	/**
	 * Recorta un texto a un máximo de caracteres, sin cortar a mitad de un carácter
	 * multibyte y colapsando espacios en blanco excesivos.
	 *
	 * @param mixed $value
	 * @param int   $max
	 * @return string
	 */
	public static function clamp( $value, $max ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		$s = trim( (string) $value );
		// Colapsa rachas largas de espacios/saltos (evita inflar el prompt con relleno).
		$s = preg_replace( '/\s{3,}/u', ' ', $s );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, 0, $max, 'UTF-8' );
		}
		return substr( $s, 0, $max );
	}

	/**
	 * Neutraliza texto del usuario antes de incrustarlo en el prompt.
	 *
	 * No elimina la inyección de prompt (es imposible al 100% con modelos de
	 * lenguaje), pero quita las secuencias con las que se rompe el bloque de datos
	 * delimitado y se simula el cierre de las instrucciones del sistema.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function neutralize( $text ) {
		$s = (string) $text;
		// Delimitadores del bloque de datos y vallas de código markdown.
		$s = str_replace( array( '<<<', '>>>', '```' ), array( '', '', '' ), $s );
		// Quita caracteres de control (salvo tabulador y salto de línea).
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s );
		return $s;
	}

	/**
	 * Sanea los campos del PRODUCTO que envía el cliente.
	 *
	 * Lista blanca + tope de longitud + neutralización. Descarta cualquier clave
	 * no reconocida (incluidos los vendor_*, que ahora los pone el servidor).
	 *
	 * @param array $raw Parámetros crudos de la petición.
	 * @return array Solo campos permitidos, acotados y neutralizados.
	 */
	public static function sanitize_product_fields( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( self::FIELD_LIMITS as $field => $max ) {
			$out[ $field ] = self::neutralize( self::clamp( isset( $raw[ $field ] ) ? $raw[ $field ] : '', $max ) );
		}
		return $out;
	}

	/**
	 * Sanea el contexto del vendedor que arma el SERVIDOR.
	 *
	 * @param array $ctx
	 * @return array
	 */
	public static function sanitize_vendor_fields( $ctx ) {
		$ctx = is_array( $ctx ) ? $ctx : array();
		$out = array();
		foreach ( self::VENDOR_FIELDS as $field => $max ) {
			$out[ $field ] = self::neutralize( self::clamp( isset( $ctx[ $field ] ) ? $ctx[ $field ] : '', $max ) );
		}
		return $out;
	}

	/**
	 * Tamaño máximo teórico del prompt de datos, para documentar el tope de costo.
	 *
	 * @return int caracteres
	 */
	public static function max_payload_chars() {
		return array_sum( self::FIELD_LIMITS ) + array_sum( self::VENDOR_FIELDS );
	}

	/* ------------------------------------------------------------------ */
	/*  Validación de ajustes (admin)                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * @param mixed $value
	 * @return string Proveedor válido; 'deepseek' si no lo es.
	 */
	public static function sanitize_provider( $value ) {
		$v = strtolower( trim( (string) $value ) );
		return in_array( $v, self::allowed_providers(), true ) ? $v : 'deepseek';
	}

	/**
	 * Valida el nombre del modelo.
	 *
	 * Importante: el modelo se interpola en la URL de Gemini
	 * (`/models/{model}:generateContent`). Sin validar, un valor con `/` o `..`
	 * podría alterar la ruta de la petición. Se acepta solo un patrón conservador.
	 *
	 * @param mixed $value
	 * @return string Modelo válido, o '' si no lo es.
	 */
	public static function sanitize_model( $value ) {
		$v = trim( (string) $value );
		if ( '' === $v ) {
			return '';
		}
		// Letras, números, punto, guion y guion bajo. Sin barras, espacios ni '..'.
		return preg_match( '/^[A-Za-z0-9._-]{1,64}$/', $v ) ? $v : '';
	}

	/**
	 * @param mixed $value
	 * @return int Límite mensual entre 0 y 10000.
	 */
	public static function sanitize_limit( $value ) {
		$n = (int) $value;
		if ( $n < 0 ) {
			$n = 0;
		}
		return min( $n, 10000 );
	}
}
