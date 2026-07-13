<?php
/**
 * Funciones puras (sin dependencias de WordPress).
 *
 * Se aíslan aquí para poder probarlas con el intérprete de PHP directamente,
 * sin cargar WordPress ni instalar PHPUnit:
 *   C:\xampp\php\php.exe tests\test02-split.php
 *
 * @package Amazonia_Vendor_Shipping
 */

defined( 'ABSPATH' ) || defined( 'AVS_TEST' ) || exit;

if ( ! function_exists( 'avs_calc_split' ) ) {
	/**
	 * Reparte el costo de envío cotizado entre cliente y vendor según el modo de cobro.
	 *
	 * @param string $mode   'customer_pays' | 'vendor_absorbs' | 'shared_fixed'.
	 * @param float  $quoted Costo cotizado por Envia.
	 * @param float  $fixed  Tarifa fija que paga el cliente (solo 'shared_fixed').
	 * @return array{paid: float, absorbed: float} Lo que paga el cliente y lo que absorbe el vendor.
	 */
	function avs_calc_split( $mode, $quoted, $fixed = 0.0 ) {
		$quoted = max( 0.0, (float) $quoted );
		$fixed  = max( 0.0, (float) $fixed );

		switch ( $mode ) {
			case 'vendor_absorbs':
				return array(
					'paid'     => 0.0,
					'absorbed' => $quoted,
				);

			case 'shared_fixed':
				$paid = min( $fixed, $quoted ); // El cliente nunca paga más que el costo real.
				return array(
					'paid'     => $paid,
					'absorbed' => $quoted - $paid,
				);

			case 'customer_pays':
			default:
				return array(
					'paid'     => $quoted,
					'absorbed' => 0.0,
				);
		}
	}
}

if ( ! function_exists( 'avs_build_envia_hash' ) ) {
	/**
	 * Construye el hash del panel de Envia: base64( site_url:companyId:storeId ).
	 *
	 * Réplica exacta de la lógica del plugin de Envia
	 * (source/classes/envia-templates.php:19) para abrir el panel filtrado a un pedido.
	 *
	 * @param string $site_url   URL del sitio (site_url()).
	 * @param string $company_id EnviaID de la cuenta del marketplace.
	 * @param string $store_id   StoreID de la cuenta del marketplace.
	 * @return string Hash en base64.
	 */
	function avs_build_envia_hash( $site_url, $company_id, $store_id ) {
		return base64_encode( $site_url . ':' . $company_id . ':' . $store_id );
	}
}

if ( ! function_exists( 'avs_valid_modes' ) ) {
	/**
	 * Modos de cobro soportados.
	 *
	 * @return string[]
	 */
	function avs_valid_modes() {
		return array( 'customer_pays', 'vendor_absorbs', 'shared_fixed' );
	}
}

if ( ! function_exists( 'avs_postcode_required' ) ) {
	/**
	 * Indica si Envia exige código postal para cotizar en el país dado.
	 *
	 * Réplica de la lista `countriesToSkipCp` del plugin de Envia
	 * (source/classes/envia/envia-shipping.php): esos países NO requieren CP.
	 *
	 * @param string $country Código ISO-2 del país.
	 * @return bool True si el CP es obligatorio.
	 */
	function avs_postcode_required( $country ) {
		$exempt = array( 'CL', 'GT', 'CO', 'NG', 'PA', 'UY', 'HN' );
		return ! in_array( strtoupper( (string) $country ), $exempt, true );
	}
}

if ( ! function_exists( 'avs_normalize_state' ) ) {
	/**
	 * Normaliza el código de estado/provincia al formato que espera Envia.
	 *
	 * WooCommerce/WCFM guardan estados como "CO-CAQ"; Envia usa solo el sufijo
	 * ("CAQ"). Réplica de la lógica del plugin de Envia
	 * (envia-shipping.php:477-478: explode('-') y toma la última parte).
	 *
	 * @param string $state Estado en formato WooCommerce ("CO-CAQ") o simple ("CAQ").
	 * @return string Código de estado para Envia.
	 */
	function avs_normalize_state( $state ) {
		$state = (string) $state;
		$parts = explode( '-', $state );
		return count( $parts ) > 1 ? trim( $parts[1] ) : trim( $parts[0] );
	}
}

if ( ! function_exists( 'avs_vendor_address_complete' ) ) {
	/**
	 * Determina si la dirección de tienda de un vendedor sirve como origen de Envia.
	 *
	 * Exige calle, ciudad, estado y país; y código postal solo si el país lo requiere.
	 *
	 * @param array $addr Dirección normalizada (claves: street, city, state, country, postcode).
	 * @return bool
	 */
	function avs_vendor_address_complete( $addr ) {
		$addr = (array) $addr;
		foreach ( array( 'street', 'city', 'state', 'country' ) as $req ) {
			if ( '' === trim( (string) ( $addr[ $req ] ?? '' ) ) ) {
				return false;
			}
		}
		if ( avs_postcode_required( $addr['country'] ?? '' ) && '' === trim( (string) ( $addr['postcode'] ?? '' ) ) ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'avs_vendor_origin_signature' ) ) {
	/**
	 * Firma estable de una dirección de origen, para detectar cambios y re-sincronizar con Envia.
	 *
	 * @param array $addr Dirección normalizada.
	 * @return string Hash md5.
	 */
	function avs_vendor_origin_signature( $addr ) {
		$addr = (array) $addr;
		$keys = array( 'street', 'number', 'city', 'state', 'country', 'postcode' );
		$flat = array();
		foreach ( $keys as $k ) {
			$flat[] = strtolower( trim( (string) ( $addr[ $k ] ?? '' ) ) );
		}
		return md5( implode( '|', $flat ) );
	}
}

if ( ! function_exists( 'avs_label_address' ) ) {
	/**
	 * Normaliza una dirección al formato que espera Envia en ship/generate
	 * (estado como sufijo, país en mayúsculas).
	 *
	 * @param array $a Dirección (name,email,phone,street,number,city,state,country,postcode/postalCode,company).
	 * @return array
	 */
	function avs_label_address( $a ) {
		$a = (array) $a;
		return array(
			'name'       => (string) ( $a['name'] ?? '' ),
			'company'    => (string) ( $a['company'] ?? ( $a['name'] ?? '' ) ),
			'email'      => (string) ( $a['email'] ?? '' ),
			'phone'      => (string) ( $a['phone'] ?? '' ),
			'street'     => (string) ( $a['street'] ?? '' ),
			'number'     => (string) ( $a['number'] ?? '' ),
			'district'   => (string) ( $a['district'] ?? ( $a['city'] ?? '' ) ),
			'city'       => (string) ( $a['city'] ?? '' ),
			'state'      => avs_normalize_state( $a['state'] ?? '' ),
			'country'    => strtoupper( (string) ( $a['country'] ?? '' ) ),
			'postalCode' => (string) ( $a['postalCode'] ?? ( $a['postcode'] ?? '' ) ),
		);
	}
}

if ( ! function_exists( 'avs_build_label_payload' ) ) {
	/**
	 * Construye el payload de generación de guía para Envia (POST api.envia.com/ship/generate/).
	 *
	 * @param array $origin      Dirección de origen (la del vendedor).
	 * @param array $destination Dirección de destino (la del pedido).
	 * @param array $packages    Lista de paquetes (content, amount, type, weight, dimensions...).
	 * @param array $shipment    Datos del envío: carrier, service (type se fuerza a 1 = etiqueta).
	 * @return array
	 */
	function avs_build_label_payload( $origin, $destination, $packages, $shipment ) {
		return array(
			'origin'      => avs_label_address( $origin ),
			'destination' => avs_label_address( $destination ),
			'packages'    => array_values( (array) $packages ),
			'shipment'    => array_merge( array( 'type' => 1 ), (array) $shipment ),
			'settings'    => array(
				'printFormat' => 'PDF',
				'printSize'   => 'STOCK_4X6',
				'comments'    => '',
			),
		);
	}
}

if ( ! function_exists( 'avs_build_label_package' ) ) {
	/**
	 * Construye un paquete para el payload de guía a partir de peso/dimensiones (con defaults seguros).
	 *
	 * @param float  $weight  kg (default 1 si falta).
	 * @param float  $length  cm.
	 * @param float  $width   cm.
	 * @param float  $height  cm.
	 * @param float  $value   Valor declarado.
	 * @param string $content Descripción del contenido.
	 * @return array
	 */
	function avs_build_label_package( $weight, $length, $width, $height, $value = 0, $content = 'Productos' ) {
		return array(
			'content'      => (string) $content,
			'amount'       => 1,
			'type'         => 'box',
			'weightUnit'   => 'KG',
			'lengthUnit'   => 'CM',
			'weight'       => (float) $weight > 0 ? (float) $weight : 1.0,
			'insurance'    => 0,
			'declaredValue' => max( 0.0, (float) $value ),
			'dimensions'   => array(
				'length' => (float) $length > 0 ? (float) $length : 10.0,
				'width'  => (float) $width > 0 ? (float) $width : 10.0,
				'height' => (float) $height > 0 ? (float) $height : 10.0,
			),
		);
	}
}

if ( ! function_exists( 'avs_marketplace_carriers' ) ) {
	/**
	 * Transportadoras seleccionables del marketplace (código normalizado → etiqueta).
	 *
	 * Los códigos coinciden con `strtolower()` del carrier que devuelve Envia en cada tarifa
	 * (p. ej. Envia manda "serviEntrega" → "servientrega").
	 *
	 * @return array<string,string>
	 */
	function avs_marketplace_carriers() {
		return array(
			'coordinadora'    => 'Coordinadora',
			'servientrega'    => 'Servientrega',
			'interrapidisimo' => 'InterRapidísimo',
			'deprisa'         => 'Deprisa',
			'tcc'             => 'TCC',
		);
	}
}

if ( ! function_exists( 'avs_carrier_key' ) ) {
	/**
	 * Normaliza un código/nombre de transportadora para comparaciones (minúsculas, sin espacios).
	 *
	 * @param string $carrier
	 * @return string
	 */
	function avs_carrier_key( $carrier ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $carrier ) );
	}
}

if ( ! function_exists( 'avs_carrier_allowed' ) ) {
	/**
	 * ¿La transportadora está permitida según la lista de permitidas del vendedor/marketplace?
	 *
	 * Lista vacía = sin restricción (se permiten todas). Comparación insensible a mayúsculas.
	 *
	 * @param string   $carrier Código/nombre de la tarifa (meta 'carrier' de Envia).
	 * @param string[] $allowed Códigos permitidos (ya normalizados o no).
	 * @return bool
	 */
	function avs_carrier_allowed( $carrier, $allowed ) {
		$allowed = array_filter( array_map( 'avs_carrier_key', (array) $allowed ) );
		if ( empty( $allowed ) ) {
			return true; // Sin restricción configurada.
		}
		return in_array( avs_carrier_key( $carrier ), $allowed, true );
	}
}

if ( ! function_exists( 'avs_map_origin_form_payload' ) ) {
	/**
	 * Construye el payload para registrar una dirección de origen en Envia (POST /user-address)
	 * mapeando la dirección del vendedor contra el esquema de campos que Envia define por país
	 * (GET /generic-form?country_code=..&form=address_info).
	 *
	 * Envia valida estrictamente: solo acepta los `fieldName` de su esquema (+ los campos base
	 * name/company/email/phone/country del formulario). Enviar campos extra da error 422.
	 *
	 * @param array $schema Lista de campos del esquema de Envia (cada uno con 'fieldName' y 'rules').
	 * @param array $addr   Dirección normalizada del vendedor.
	 * @return array Payload con solo los campos permitidos por el país.
	 */
	function avs_map_origin_form_payload( $schema, $addr ) {
		$addr = (array) $addr;

		// Campos base del formulario de origen (siempre aceptados por Envia).
		$payload = array(
			'name'    => (string) ( $addr['name'] ?? '' ),
			'company' => (string) ( $addr['name'] ?? '' ),
			'email'   => (string) ( $addr['email'] ?? '' ),
			'phone'   => (string) ( $addr['phone'] ?? '' ),
			'country' => strtoupper( (string) ( $addr['country'] ?? '' ) ),
		);

		$state = avs_normalize_state( $addr['state'] ?? '' );
		$city  = (string) ( $addr['city'] ?? '' );

		// Envia sí exige que la ciudad viaje como 'city' (el 'city_select' es solo de la UI).
		$payload['city'] = $city;

		foreach ( (array) $schema as $field ) {
			$fn = isset( $field['fieldName'] ) ? $field['fieldName'] : '';
			if ( '' === $fn || isset( $payload[ $fn ] ) ) {
				continue;
			}
			// Campos solo de interfaz (selects que la API no acepta): city_select, state_select, etc.
			if ( '_select' === substr( $fn, -7 ) ) {
				continue;
			}
			$required = ! empty( $field['rules']['required'] );

			switch ( $fn ) {
				case 'street':
				case 'address1':
					$payload[ $fn ] = (string) ( $addr['street'] ?? '' );
					break;
				case 'number':
					$payload[ $fn ] = (string) ( $addr['number'] ?? '' );
					break;
				case 'state':
					$payload[ $fn ] = $state;
					break;
				case 'city':
					$payload[ $fn ] = $city;
					break;
				case 'postal_code':
					$payload[ $fn ] = (string) ( $addr['postcode'] ?? '' );
					break;
				case 'district':
				case 'neighborhood':
				case 'colonia':
					// Opcionales sin dato propio: usar la ciudad como aproximación solo si son obligatorios.
					$payload[ $fn ] = $required ? $city : '';
					break;
				default:
					// Resto (reference, alias, identification_number, etc.): solo si es obligatorio.
					if ( $required ) {
						$payload[ $fn ] = '';
					}
					break;
			}
		}

		return $payload;
	}
}
