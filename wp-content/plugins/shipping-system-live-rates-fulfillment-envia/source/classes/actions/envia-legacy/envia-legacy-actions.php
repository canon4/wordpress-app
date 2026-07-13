<?php
namespace Envia\Classes\Actions;

defined( 'ABSPATH' ) || exit;
trait Envia_Legacy_Actions { 
	public static function assets_loader( $checkout = null ) {
		$ratesIn = is_null ( $checkout ) || '' == $checkout  ? 'cart' : 'checkout';
		$nonce  = ! isset( $nonce ) ? wp_create_nonce( 'my-nonce' ) : $nonce;
		wp_enqueue_script( 'pickUpDestination', plugins_url( 'public/js/pickUpDestination.js', \Enviacom::MAINFILE ), array( 'jquery' ), '2.7', false );
		wp_localize_script(
			'pickUpDestination',
			'data',
			array(
				'ajaxurl'   => admin_url( 'admin-ajax.php' ),
				'viewMode'  => \Enviacom::uses_block_checkout() ? 'standard' : ( get_option( 'woocommerce_envia_shipping_settings' )['displayPickUp'] ?? 'list' ),
				'nonce'     => $nonce,
				'ratesIn'   => $ratesIn,
			)
		);
		WC()->session->set( 'rates_in', $ratesIn );
	}

	public static function wp_enqueue_scripts_envia() {
		wp_enqueue_style( 'enviaPublicStylesheet', plugins_url( 'public/css/envia-shipping-public.css', \Enviacom::MAINFILE ), array(), '1.6', false );
	}

	public static function action_after_shipping_rate( $method, $index ) {
		$nonce  = ! isset( $nonce ) ? wp_create_nonce( 'my-nonce' ) : $nonce;
		$labels = get_option( 'woocommerce_envia_shipping_settings' )['useLabels'] ?? 'no';
		if ( 'envia_shipping' == $method->method_id || 'envia_pickup' == $method->method_id ) {
			if ( 'yes' == $labels ) {
				echo "<div class='rate-content'><div class= 'envia-shipping-complement-div'>" . PHP_EOL;
				echo "<img class= 'envia-shipping-carrier-img' src='https://s3.us-east-2.amazonaws.com/enviapaqueteria/uploads/logos/carriers/" . esc_html( $method->meta_data['carrier'] ) . ".svg' for= 'shipping_method_0_" . esc_html( $method->id ) . "'>" . PHP_EOL;
				echo '</div>' . PHP_EOL;
				echo '</div>' . PHP_EOL;
			}
			$chosenMethods     = WC()->session->get( 'chosen_shipping_methods' );
			$methodSelected    = ( is_array( $chosenMethods ) && isset( $chosenMethods[0] ) ) ? $chosenMethods[0] : '';
			$branchSelected = WC()->session->get( 'branch_selected' );
			$viewMode = get_option( 'woocommerce_envia_shipping_settings' )['displayPickUp'] ?? 'list';
			if ( 'envia_pickup' != $method->method_id ) {
				$branches = $method->meta_data['branches'] ?? null;
				if ( ! is_null( $branches ) && $methodSelected == $method->id && 'custom' == $viewMode ) {
					echo "<select class='pick-up-location' onChange='getCustomOptionRate(this)' value='" . esc_html( $nonce ) . "'>" . PHP_EOL;
					foreach ( $branches as $key => $value ) {
						if ( 0 == $key ) {
							echo "<option value='none'> Select a " . esc_html( ucfirst( $method->meta_data['carrier'] ) ) . ' pick-point </option>';
							$key = -1;
						}
						$addressFormat        = $value['address']['address'] . ', #' . $value['address']['number'] . ', (' . $value['address']['zipcode'] . ')';
						if ( $branchSelected == $value['branch_code'] ) {
							echo "<option selected='selected' value='" . esc_html( $method->id . '-' . $value['branch_code']) . "'>" . esc_html( $value['address']['country'] ) . '. ' . esc_html( ucwords( $addressFormat ) ) . '</option>';
						} else {
							echo "<option value='" . esc_html( $method->id . '-' . $value['branch_code'] ) . "'>" . esc_html( $value['address']['country'] ) . '. ' . esc_html( ucwords( $addressFormat ) ) . '</option>';
						}
					}
					echo '</select>' . PHP_EOL;
				}
			}
		}
	}

	public static function afterFinishOrder( $order_id ) {
		$lastBranch = isset( WC()->session->branch_selected ) ? WC()->session->branch_selected : null;
		if ( 'custom' == ( get_option( 'woocommerce_envia_shipping_settings' )['displayPickUp'] ?? 'list' ) ) {
			$order = wc_get_order( $order_id );
			$shippingItemId = array_keys( $order->get_items( 'shipping' ) )[0];
			if ( ! is_null( $shippingItemId ) ) {
				$objectShipping = $order->get_item( $shippingItemId );
				if ( ! is_null( $lastBranch ) ) {
					$objectShipping->update_meta_data( 'branchCode', $lastBranch );
				}
				$objectShipping->update_meta_data( 'branches', null );
				$objectShipping->save();
				WC()->session->__unset( 'prev_envia_rates' );
				WC()->session->__unset( 'branch_selected' );
				WC()->session->__unset( 'changed_address' );
				WC()->session->__unset( 'last_customer_shipping_address' );
			}
		}
	}

	public static function envia_coupon_action( $coupon_code ) {
		/**
		 * Origins that not must refresh shipping quote options
		 */	
		$ajaxOrigins = array(
			'apply_coupon',
			'remove_coupon'
		);
		if ( $coupon_code ) {
			if ( isset ( WC()->session->prev_envia_rates ) ) {
				foreach ( $ajaxOrigins as $origin ) {
					if ( isset( $_GET['wc-ajax'] ) && $origin == $_GET['wc-ajax'] ) {
						WC()->session->set( 'blocked_refresh', true );
						if ( 'apply_coupon' == $origin ) {
							WC()->session->set( 'set_coupon_access', true );
						}
					}
				}
			}
		}
	}

	public static function envia_update_cart_action( $cart_updated ) {
		if ( isset( $_REQUEST['update_cart'] ) && 'Update Cart' == $_REQUEST['update_cart'] ) {
			WC()->session->set( 'updated_cart', true );
		}
	}

	public static function save_customer_shipping_address( $customer = null ) {
		$beforePickSelected = array(
			'address' => '' != WC()->customer->get_shipping_address_1() ? WC()->customer->get_shipping_address_1() : WC()->customer->get_billing_address_1(),
			'number' => '' != WC()->customer->get_shipping_address_2() ? WC()->customer->get_shipping_address_2() : WC()->customer->get_billing_address_2(),
			'city' => '' != WC()->customer->get_shipping_city() ? WC()->customer->get_shipping_city() : WC()->customer->get_billing_city(),
			'zipcode' => '' != WC()->customer->get_shipping_postcode() ? WC()->customer->get_shipping_postcode() : WC()->customer->get_billing_postcode(),
			'country'  => '' != WC()->customer->get_shipping_country() ? WC()->customer->get_shipping_country() : WC()->customer->get_billing_country(),
		);
		if ( count( $customer ) > 0 ) {
			$beforePickSelected += array (
				'firstName' => '' != $customer['shipping_first_name'] ? $customer['shipping_first_name'] : $customer['shipping_last_name'],
				'lastName' => '' !=  $customer['shipping_last_name'] ? $customer['shipping_last_name'] : $customer['billing_last_name'],
				'company' => '' !=  $customer['shipping_company'] ? $customer['shipping_company'] : $customer['billing_company'],
			);
		}
		WC()->session->set( 'last_customer_shipping_address', $beforePickSelected );
	}

	/**
	 *Set shipping address receive $option when is a branch address, $address for restore the first quote address and $customer always contains saved information about the customer. 
	 */
	public static function set_shipping_address( $option = null, $address = null, $customer = null ) {
		$customerDetails = [];
		if ( ! is_null( $customer ) ) {
			if ( ! is_null( $address ) ) {
				$customerDetails = array(
					'shippingFirstName' => $address['firstName'], 
					'shippingLastName' => $address['lastName'], 
					'shippingCompany' => $address['company'], 
				);
			} elseif ( count( $customer ) > 0 ) {
				$customerDetails = array(
					'shippingFirstName' => '' != $customer['shipping_first_name'] ? $customer['shipping_first_name'] : $customer['billing_first_name'], 
					'shippingLastName' => '' !=  $customer['shipping_last_name'] ? $customer['shipping_last_name'] : $customer['billing_last_name'], 
					'shippingCompany' => '' !=  $customer['shipping_company'] ? $customer['shipping_company'] : $customer['billing_company'], 
				);
			}
		}
		if ( is_null ( $address ) ) {
			$session = WC()->session->get('shipping_for_package_0');
			$rates = $session['rates'];
			$chosenOption = $option['option'];
			$rateSelected = array_key_exists( $option['option'], $rates )  ? $rates[ $chosenOption ] : null;
			if ( ! is_null( $rateSelected ) && ! is_null( $option['branchCode'] ) ) {
				if ( 'custom' == $option['viewMode'] ) {
					foreach ( $rateSelected->meta_data['branches'] as $branch ) {
						if ( $option['branchCode'] == $branch['branch_code'] ) {
							$address = $branch['address'];
						}
					}
				}
				if ( 'standard' == $option['viewMode'] ) { 
					$address = $rateSelected->meta_data['branchAddress'];
				}
			}
		}
		if ( count( $customerDetails ) > 0 ) {
			WC()->customer->set_billing_first_name( wc_clean( $customer['billing_first_name'] ) );
			WC()->customer->set_billing_last_name( wc_clean( $customer['billing_last_name'] ) );
			WC()->customer->set_billing_company( wc_clean( $customer['billing_company'] ) );
			WC()->customer->set_billing_phone( wc_clean( $customer['billing_phone'] ) );
			WC()->customer->set_billing_email( wc_clean( $customer['billing_email'] ) );
			WC()->customer->set_shipping_first_name( wc_clean( $customerDetails['shippingFirstName'] ) );
			WC()->customer->set_shipping_last_name( wc_clean( $customerDetails['shippingLastName'] ) );
			WC()->customer->set_shipping_company( wc_clean( $customerDetails['shippingCompany'] ) );
		}
		WC()->customer->set_shipping_address_1( wc_clean( $address['address'] ) );
		WC()->customer->set_shipping_address_2( wc_clean( $address['number'] ) );
		WC()->customer->set_shipping_city( wc_clean( $address['city'] ) );
		WC()->customer->set_shipping_postcode( wc_clean( $address['zipcode'] ) );
		WC()->customer->set_shipping_country( wc_clean( $address['country'] ) );
		return array( 'status' => 'success' );
	}

	/**
	 * Actions for ajaxFunctions. pending to check
	 */ 

	public static function pickDestination() {
		try {
			if ( isset( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( $_REQUEST['_wpnonce'] ), 'my-nonce' ) ) {
				$optionData = isset( $_POST['data'] ) ? json_decode( str_replace( '\\', '', sanitize_text_field( $_POST['data'] ) ), true ) : null;
				$optionData = self::recursive_sanitize_text_field( $optionData );
				$customerInfo = isset( $_POST['customer'] ) ? json_decode( str_replace( '\\', '', sanitize_text_field( $_POST['customer'] ) ), true ) : null;
				$customerInfo = self::recursive_sanitize_text_field( $customerInfo );
				if ( 'envia' == $optionData['method'] ) {
					WC()->session->__unset( 'branch_selected' );
					if ( ! is_null( $optionData['branchCode'] ) && '' != $optionData['branchCode'] ) {
						if ( ! isset( WC()->session->branch_selected ) && ! isset( WC()->session->last_customer_shipping_address ) ) { 
							self::save_customer_shipping_address( $customerInfo );
						}
						WC()->session->set( 'branch_selected', $optionData['branchCode'] );
						$response = self::set_shipping_address( $optionData, null, $customerInfo );
						WC()->session->set( 'changed_address', true );
					} else if ( isset( WC()->session->last_customer_shipping_address ) ) {
							$address = WC()->session->last_customer_shipping_address;
							$response = self::set_shipping_address( null, $address, $customerInfo );
							WC()->session->__unset( 'last_customer_shipping_address' );
							WC()->session->__unset( 'branch_selected' );
							WC()->session->set( 'changed_address', true );
					} else {
						$response = array( 'status' => 'nothing changed' );
					}
				}
				echo json_encode(
					array(
						'status'  => $response['status'],
						'message' => 'changed shipping address',
						'code'    => 200,
						'whereIs' => WC()->session->get( 'rates_in' ),
						'address' => WC()->customer->get_shipping(),
					)
				);
				header( 'HTTP/1.1 200 Success' );
			}
		} catch ( Exception $err ) {
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
}
