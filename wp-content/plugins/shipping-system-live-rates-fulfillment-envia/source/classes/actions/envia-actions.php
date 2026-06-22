<?php
namespace Envia\Classes\Actions;

defined( 'ABSPATH' ) || exit;
require_once 'envia-legacy/envia-legacy-actions.php';
trait Envia_Actions { 
	use Envia_Legacy_Actions;
	public static function catch_envia_status() {
		/*Status active, inactive, check, update*/
		if ( isset( $_GET['status'] ) ) {
				$hash      = isset( $_GET['hash'] ) ? sanitize_text_field( $_GET['hash'] ) : null;
				$shopId    = isset( $_GET['shop'] ) ? sanitize_text_field( $_GET['shop'] ) : null;
				$companyId = isset( $_GET['company'] ) ? sanitize_text_field( $_GET['company'] ) : null;
				$userId    = isset( $_GET['user'] ) ? sanitize_text_field( $_GET['user'] ) : null;
			if ( 'active' == $_GET['status'] && ! is_null( $hash ) ) {
				update_option( 'envia_oauth_connection', 'true' );
				$settings = get_option( 'woocommerce_envia_shipping_settings' );
				if ( ! isset( $settings ) ) {
					add_option(
						'woocommerce_envia_shipping_settings',
						array(
							'token'   => $hash,
							'shop'    => $shopId,
							'company' => $companyId,
							'user'    => $userId,
						)
					);
				} else {
					// if option exists and is empty
					if ( is_array( $settings ) ) {
						// if option exists with array keys but without value
						if ( isset( $settings['token'] ) ) {
							$settings['token'] = $hash;
						}
						 $settings['shop']    = $shopId;
						 $settings['company'] = $companyId;
						 $settings['user']    = $userId;
					} else {
						// if option exists totally empty
						 $settings = array(
							 'token'   => $hash,
							 'shop'    => $shopId,
							 'company' => $companyId,
							 'user'    => $userId,
						 );
					}
					update_option( 'woocommerce_envia_shipping_settings', $settings );
				}
				header( 'HTTP/1.1 201 OK' );
			} elseif ( 'inactive' == $_GET['status'] ) {
				update_option( 'envia_oauth_connection', 'false' );
				deactivate_plugins( \Enviacom::MAINFILE );
				header( 'HTTP/1.1 200 OK' );
			} elseif ( 'check' == $_GET['status'] ) {
				$is_active = get_option( 'envia_oauth_connection' );
				$settings  = get_option( 'woocommerce_envia_shipping_settings' );
				if ( 'true' == $is_active && '' != $settings['token'] ) {
					echo json_encode(
						array(
							'status'  => 'success',
							'message' => 'Envia is connected',
						)
					);
					header( 'HTTP/1.1 201 OK' );
				} else {
					if ( 'false' == $is_active ) {
						update_option( 'envia_oauth_connection', 'wait' );
					}
					echo json_encode(
						array(
							'status'  => 'fail',
							'message' => 'Validation failed',
						)
					);
					header( 'HTTP/1.1 400' );
				}
			} elseif ( 'update' == $_GET['status'] ) {
				update_option( 'envia_oauth_connection', 'wait' );
				header( 'HTTP/1.1 200 OK' );
			} else {
				header( 'HTTP/1.1 400' );
			}
		}
		die();
	}

	public static function use_prev_rates() {
		WC()->session->set( 'blocked_refresh', true );
	}

	/**
	 * Actions for ajaxFunctions. 
	 */ 

	public static function getCities() {
		try {
			$country = isset( $_GET['country'] ) ? sanitize_text_field( $_GET['country'] ) : null;
			$state   = isset( $_GET['state'] ) ? sanitize_text_field( $_GET['state'] ) : null;
			if ( ! is_null( $country ) && ! is_null( $state ) ) {
				$url    = "https://geocodes.envia.com/list/localities/{$country}/{$state}";
				$cities = self::requests_process( 'GET', $url, null, null );
				header( 'HTTP/1.1 200 OK' );
				echo json_encode( $cities, JSON_PRETTY_PRINT );
			}
		} catch ( Exception $err ) {
			throw $err;
		}
		die();
	}


	public static function getStates( $country ) {
		try {
			$country = isset( $_GET['country'] ) ? sanitize_text_field( $_GET['country'] ) : $country;
			if ( ! is_null( $country ) ) {
				$url    = "https://queries.envia.com/state?country_code={$country}";
				$states = self::requests_process( 'GET', $url, null, null );
				if ( isset( $states['data'] ) ) {
					$statesAux = array();
					foreach ( $states['data'] as $state ) {
						$statesAux[ $state['code_2_digits'] ] = $state['name'];
					}
					$states = $statesAux;
				}
				return $states;
			}
		} catch ( Exception $err ) {
			throw $err;
		}
	}

	public static function getForm() {
		try {
			$country = isset( $_GET['country'] ) ? sanitize_text_field( $_GET['country'] ) : null;
			if ( ! is_null( $country ) ) {
				$url         = \Enviacom::ENVIA_QUERIES_HOSTNAME . "/generic-form?country_code={$country}&form=address_info";
				$countryForm = self::requests_process( 'GET', $url, null, null );
				$states      = \Enviacom::getStates( $country );
				header( 'HTTP/1.1 200 OK' );
				$response = array(
					'form'   => $countryForm,
					'states' => $states,
				);
				echo json_encode( $response, JSON_PRETTY_PRINT );
			}
		} catch ( Exception $err ) {
			echo json_encode(
				array(
					'status'  => 'error',
					'message' => $err->getMessage(),
					'code'    => $err->getCode(),
				)
			);
		}
		die();
	}

	public static function saveOriginAddress() {
		try {
			if ( isset( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( $_REQUEST['_wpnonce'] ), 'my-nonce' ) ) {
				$payload = isset( $_POST['form'] ) ? json_decode( str_replace( '\\', '', sanitize_text_field( $_POST['form'] ) ), true ) : null;
				$payload = self::recursive_sanitize_text_field( $payload );
			}
			if ( ! is_null( $payload ) ) {
				$payload['type']        = 1;
				$payload['category_id'] = 1;
				$token                  = get_option( 'woocommerce_envia_shipping_settings' )['token'];
				if ( ! $token ) {
					throw new \Exception( 'You need an API Key for start to save addresses, connect your store with Envia.com', 401 );
				}
				$url     = \Enviacom::ENVIA_QUERIES_HOSTNAME . '/user-address';
				$address = self::requests_process( 'POST', $url, $token, $payload );
				if ( isset( $address['id'] ) ) {
					$shopId          = get_option( 'woocommerce_envia_shipping_settings' )['shop'];
					$url             = \Enviacom::ENVIA_QUERIES_HOSTNAME . '/shop-default-address/' . $shopId;
					$payload         = array( 'address_id' => strval( $address['id'] ) );
					$setAddressStore = self::requests_process( 'POST', $url, $token, $payload );
					if ( $setAddressStore['shop_id'] ) {
						echo json_encode(
							array(
								'status' => 'success',
								'code'   => '200',
							),
							JSON_PRETTY_PRINT
						);
						header( 'HTTP/1.1 200 Success' );
					} else {
						throw new \Exception( 'Something went wrong, try again', 400 );
					}
				} else {
					throw new \Exception( 'Something went wrong, try again', 400 );
				}
			} else {
				throw new \Exception( 'Something went wrong, try again', 401 );
			}
		} catch ( \Exception $err ) {
			header( "HTTP/1.1 {$err->getCode()} Error" );
			echo json_encode(
				array(
					'status'  => 'error',
					'message' => $err->getMessage(),
					'code'    => $err->getCode(),
				)
			);
		}
		die();
	}

	public static function requests_process( $method, $url, $auth = null, $payload = null ) {
		if ( isset( $payload ) ) {
			$payload = json_encode( $payload );
		}
		try {
			$response = wp_remote_request(
				$url,
				array(
					'headers' => array(
						'Content-Type'  => 'application/json; charset=utf-8',
						'Authorization' => $auth,
						'Enviacom-v' => \Enviacom::get_current_version(),
					),
					'user-agent' => 'wc-envia-plugin',
					'body'    => $payload,
					'method'  => $method,
				)
			);
			if ( is_wp_error( $response ) ) {
				if ( array_key_exists( 'http_request_failed', $response->errors ) ) {
					throw new \Exception( $response->errors['http_request_failed'][0], 408 );
				}
				$message = is_string( $response->get_error_code() ) ? $response->get_error_code() : $response->get_error_message();
				$code    = is_string( $response->get_error_code() ) ? 500 : $response->get_error_code();
				throw new \Exception( $message, $code );
			} elseif ( $response['response']['code'] >= 400 ) {
					$response = json_decode( $response['body'], true );
					throw new \Exception( $response['message'], $response['statusCode'] );
			} else {
				$data = json_decode( $response['body'], true );
				if ( array_key_exists( 'code', $data ) ) {
					$code = intval( $data['code'] );
					if ( $code >= 400 ) {
						throw new \Exception( $response['message'], $response['statusCode'] );
					}
				}
				if ( array_key_exists( 'error', $data ) ) {
					$code = intval( $data['error']['code'] );
					if ( $code >= 400 ) {
						throw new \Exception( $data['error']['message'], $data['error']['code'] );
					}
				}
				return json_decode( $response['body'], true );
			}
		} catch ( \Exception $err ) {
			throw $err;
		}
	}

	/**
	 * Enqueues Leaflet and the pickup map script/style when all conditions are met:
	 * block checkout active + pickup enabled + displayPickUp = map.
	 */
	public static function enqueue_pickup_map_assets() {
		if ( ! ( is_checkout() || is_cart() ) ) {
			return;
		}
		if ( ! \Enviacom::uses_block_checkout() ) {
			return;
		}
		$settings     = get_option( 'woocommerce_envia_shipping_settings', array() );
		$pickup_on    = isset( $settings['pickUpDestination'] ) && 'yes' === $settings['pickUpDestination'];
		$display_mode = isset( $settings['displayPickUpBlock'] ) ? $settings['displayPickUpBlock'] : 'list';
		if ( ! $pickup_on || 'list' === $display_mode ) {
			return;
		}
		wp_enqueue_style(
			'leaflet-css',
			'https://cdn.jsdelivr.net/npm/leaflet@1.9/dist/leaflet.min.css',
			array(),
			'1.9'
		);
		wp_enqueue_script(
			'leaflet-js',
			'https://cdn.jsdelivr.net/npm/leaflet@1.9/dist/leaflet.min.js',
			array(),
			'1.9',
			true
		);
		wp_enqueue_style(
			'envia-pickup-map',
			plugins_url( 'public/css/envia-pickup-map.css', \Enviacom::MAINFILE ),
			array( 'leaflet-css' ),
		'1.6'
		);
		wp_enqueue_script(
			'envia-pickup-map',
			plugins_url( 'public/js/pickup-map.js', \Enviacom::MAINFILE ),
			array( 'leaflet-js', 'wp-element', 'wp-plugins', 'wp-data' ),
			'1.7',
				true
		);
	$pickup_rates = isset( WC()->session ) ? WC()->session->get( 'envia_pickup_map_data' ) : null;
		wp_localize_script(
			'envia-pickup-map',
			'enviaPickupMap',
			array(
			'branches'    => is_array( $pickup_rates ) ? $pickup_rates : array(),
			'displayMode' => $display_mode,
			'nonce'       => wp_create_nonce( 'envia_pickup_map' ),
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * Returns pickup branch data from the session for the map SlotFill.
	 * Accepts both logged-in (wp_ajax_) and guest (wp_ajax_nopriv_) requests.
	 */
	public static function get_pickup_map_data() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( $_POST['nonce'] ), 'envia_pickup_map' ) ) {
			wp_send_json( array(), 403 );
		}
		$pickup_rates = isset( WC()->session ) ? WC()->session->get( 'envia_pickup_map_data' ) : null;
		wp_send_json( is_array( $pickup_rates ) ? $pickup_rates : array() );
	}

	public static function recursive_sanitize_text_field( $array ) {
		foreach ( $array as $key => &$value ) {
			if ( is_array( $value ) ) {
				$value = self::recursive_sanitize_text_field( $value );
			} else {
				$value = sanitize_text_field( $value );
			}
		}
		return $array;
	}
}
