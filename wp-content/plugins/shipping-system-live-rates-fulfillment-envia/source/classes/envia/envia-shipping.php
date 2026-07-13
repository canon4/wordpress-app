<?php
namespace Envia\Classes\Module;

defined( 'ABSPATH' ) || exit;
use WC_Shipping_Method;
if ( class_exists( '\WC_Shipping_Method' ) && ! class_exists( 'Envia_Shipping' ) ) {
	class Envia_Shipping extends \WC_Shipping_Method {
		use Envia_Oauth;
		use Envia_Orders;
		use Envia_Templates;
		private $domain_url; 
		private $active;
		private $useLabels;
		private $useTime; 
		private $usePickUp; 
		private $inputFormCity;
		private $typeDisplayPickUp; 
		private $origin;
		private $origin_options;
		private $enviaPickup;
		private $useNewBlocks;
		private $countriesToSkipCp;
		private $maxPickupRatesPerService;
		public function __construct( $instance_id = 0 ) {
			$this->id                 = 'envia_shipping';
			$this->instance_id        = absint( $instance_id );
			// Translations may not be loaded yet at this point (plugins_loaded runs before init).
			// These strings are intentionally set again in init() after the textdomain is available.
			$this->title              = 'Live shipping rates';
			$this->method_title       = 'Envia rates and shipping';
			$this->method_description = 'Send easily WooCommerce orders with Envia and Rate Shipment Cost.';
			$this->supports           = array( 'shipping-zones', 'settings', 'instance-settings', 'instance-settings-modal' );
			$this->enabled            = 'yes';
			$this->domain_url         = site_url();
			$this->origin_options     = get_option( 'woocommerce_envia_shipping_settings', array() );
			$this->active             = $this->get_option( 'active' );
			$this->useLabels          = $this->get_option( 'useLabels' );
			$this->useTime            = $this->get_option( 'useDeliveryTime' );
			$this->usePickUp          = $this->get_option( 'pickUpDestination' );
			$this->inputFormCity      = $this->get_option( 'cityType' );
			$this->typeDisplayPickUp  = $this->get_option( 'displayPickUp' );
			$max = absint( $this->get_option( 'maxPickupRatesPerService', 5 ) );
			$this->maxPickupRatesPerService = ( $max >= 1 && $max <= 10 ) ? $max : 5;
			$this->countriesToSkipCp = array( 'CL', 'GT', 'CO', 'NG', 'PA', 'UY', 'HN' );
			$this->useNewBlocks       = \Enviacom::uses_block_checkout();
			$this->origin  = 'Envia.com';
			$this->init();
		}

	public function init() {
		try {
			// Re-apply translatable strings now that the textdomain is loaded (init hook has fired).
			$this->title              = __( 'Live shipping rates', 'envia-shipping' );
			$this->method_title       = __( 'Envia rates and shipping', 'envia-shipping' );
			$this->method_description = __( 'Send easily WooCommerce orders with Envia and Rate Shipment Cost.', 'envia-shipping' );

			$this->init_form_fields();
			$this->init_settings();
				add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
				if ( is_admin() ) {
					$cssFiles = array( 
				'configPageAdmin' => array( 
						'file' => 'config-page-admin',
						'version' => '1.6',
					),
					'configOauthAdmin' => array( 
						'file' => 'config-oauth-admin',
						'version' => '1.6',
					),
						'configOriginModal' => array( 
							'file' => 'config-origin-modal',
							'version' => '1.5',
						),
					'ordersAdmin' => array( 
						'file' => 'orders-admin',
						'version' => '1.5',
					),
				);
				$this->envia_css_loader( $cssFiles );
				wp_enqueue_script(
					'envia-form-fields',
					plugins_url( 'admin/js/formFieldsLoad.js', \Enviacom::MAINFILE ),
					array( 'jquery' ),
					'1.3',
					true
				);
			}
		} catch ( \Exception $err ) {
				\Enviacom::print_exception( $err );
			}
		}

		public function init_form_fields() {
			$country                                = WC()->countries->get_base_country();
			$form                                   = array(
			'token'             => array(
				'title' => __( 'API Key', 'envia-shipping' ),
				'type'  => 'password',
				'class' => 'envia-cfg-disabled envia-option envia-last-option',
			),
			'active'            => array(
				'title' => __( 'Envia live shipping rate', 'envia-shipping' ),
				'type'  => 'checkbox',
				'label' => __( 'Display the shipping quote in your cart and checkout.', 'envia-shipping' ),
				'class' => 'envia-option envia-top-option'
			),
			'useLabels'         => array(
				'title' => __( 'Carrier brand image', 'envia-shipping' ),
				'type'  => 'checkbox',
				'label' => __( 'Display the rate options with carrier brand image.', 'envia-shipping' ),
				'class' => 'envia-option',
			),
			'useDeliveryTime'   => array(
				'title' => __( 'Delivery time', 'envia-shipping' ),
				'type'  => 'checkbox',
				'label' => __( 'Display the rate options with estimated delivery time.', 'envia-shipping' ),
				'class' => 'envia-option',
			),
			'pickUpDestination' => array(
				'title' => __( 'Pickup rates options', 'envia-shipping' ),
				'type'  => 'checkbox',
				'label' => __( 'Display pickup location options (available only in some countries).', 'envia-shipping' ),
				'class' => 'envia-option',
			),
			'displayPickUp' => array(
				'title'       => __( 'Pickup classic checkout format', 'envia-shipping' ),
				'type'        => 'select',
				'label'       => __( 'Select display mode for pickup options', 'envia-shipping' ),
				'description' => __( 'View mode for pickup locations options.', 'envia-shipping' ),
				'options'     => array(
					'standard' => __( 'WooCommerce standard', 'envia-shipping' ),
					'custom'   => __( 'Dropdown list', 'envia-shipping' ),
				),
				'default'     => 'standard',
				'class'       => 'envia-option envia-input-with-description',
			),
			'displayPickUpBlock' => array(
				'title'       => __( 'Pickup block checkout format', 'envia-shipping' ),
				'type'        => 'select',
				'label'       => __( 'How pickup locations are shown in block checkout.', 'envia-shipping' ),
				'options'     => array(
					'list' => __( 'List only (WooCommerce standard)', 'envia-shipping' ),
					'map'  => __( 'Map only', 'envia-shipping' ),
					'both' => __( 'Map and list', 'envia-shipping' ),
				),
				'default'     => 'list',
				'class'       => 'envia-option envia-input-with-description',
			),
			'maxPickupRatesPerService' => array(
				'title'       => __( 'Max pickup options to display', 'envia-shipping' ),
				'type'        => 'number',
				'description' => __( 'Maximum number of pickup locations to display per service.', 'envia-shipping' ),
				'default'     => 5,
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 10,
					'step' => 1,
				),
				'class'       => 'envia-option envia-last-option envia-input-with-description',
			),
			'cityType'          => array(
				'title'       => __( 'Input type for city', 'envia-shipping' ),
				'type'        => 'select',
				'label'       => __( 'Select type of input', 'envia-shipping' ),
				'description' => __( 'Type of input in checkout/cart/plugin page.', 'envia-shipping' ),
				'options'     => array(
					'text'   => __( 'Text input', 'envia-shipping' ),
					'select' => __( 'Dropdown list', 'envia-shipping' ),
				),
				'default'     => 'text',
				'class' => 'envia-cfg-disabled envia-cfg-display envia-option envia-input-with-description',
			),
			);
	$cartIsBlock       = \Enviacom::is_new_blocks( 'cart' );
	$checkoutIsBlock   = \Enviacom::is_new_blocks( 'checkout' );

	// UseLabels: carrier brand images are not rendered in block checkout
	// DisplayPickUp: classic template setting — only unavailable when both cart AND checkout use blocks
	if ( $this->useNewBlocks ) {
		$form['displayPickUp']['title'] .= ' <span class="not-msj">* Not available</span>';
		$form['displayPickUp']['class']  = 'envia-cfg-disabled ' . $form['displayPickUp']['class'];
	// UseLabels: carrier brand images are not rendered in block checkout
		$form['useLabels']['title'] .= ' <span class="not-msj"> *Not available</span>';
		$form['useLabels']['class']  = 'envia-cfg-disabled ' . $form['useLabels']['class'];
	}

	// DisplayPickUpBlock: block checkout setting — unavailable when no block checkout is active
	if ( ! $checkoutIsBlock ) {
		$form['displayPickUpBlock']['title'] .= ' <span class="not-msj"> *Not available</span>';
		$form['displayPickUpBlock']['class']  = 'envia-cfg-disabled ' . $form['displayPickUpBlock']['class'];
	}
			$form                                   = array_merge( $form, $this->origin_address_form_fields() );
			$this->form_fields                      = $form;
			$default                                = array( 'default' => 'Default origin address' );
			$formInstance                           = $this->origin_address_form_fields();
			$formInstance['enviaOrigin']['options'] = $formInstance['enviaOrigin']['options'] ? $default + $formInstance['enviaOrigin']['options'] : $default;
			$this->instance_form_fields             = $formInstance;
		}

		public function origin_address_form_fields() {
			try {
				$form                = array();
				$loadOptions = ( isset( $_GET['tab'] ) && 'shipping' == $_GET['tab'] || ( isset( $_GET['action'] ) && 'woocommerce_shipping_zone_add_method' == $_GET['action'] ) );
				$originOptions       = $loadOptions ? $this->get_envia_origin_adresses() : array();
				$form['enviaOrigin'] = array(
					'title'       => 'Select an origin address',
					'type'        => 'select',
					'label'       => 'Envia origin address',
					'description' => 'Default shipping origin address to calculate in shipping rates.',
					'options'     => $originOptions,
					'default'     => 'default',
					'class' => 'envia-option envia-top-option',
				);

				if ( $this->get_instance_id() > 0 ) {
					$form['enviaOrigin']['description'] = 'It will be your default shipping origin address to use in the shipping calculator.<br><b>Default origin address:</b> Origin address selected in <a href="admin.php?page=wc-settings&tab=shipping&section=envia_shipping" target="_blank">plugin section</a> ';
					return $form;
				}
				return $form;
			} catch ( \Exception $err ) {
				throw $err;
			}
		}

		public function envia_css_loader( $files = array() ) {
			if ( 0 == count( $files ) ) {
				return 0;
			}	
			foreach ( $files as $handle => $css ) {
				if ( file_exists( \Enviacom::MDABSPATH . '/admin/css/envia-' . $css['file'] . '.css' ) ) {
					$toLoad = plugins_url( '../../../admin/css/envia-' . $css['file'] . '.css', __FILE__ );
					wp_enqueue_style( $handle, $toLoad, array(), $css['version'], false );
				}
			}
		}
		/**
		 * Get origin addresses from an shopID 
		 */ 
		public function get_envia_origin_adresses() {
			try {
				if ( false == $this->get_option( 'token' ) && '' == $this->get_option( 'token' ) ) {
					return null;
				}
				$origins   = array( 'default' => 'Select a origin address' );
				$addresses = \Enviacom::requests_process( 'GET', \Enviacom::ENVIA_QUERIES_HOSTNAME . '/shop-default-address/' . $this->get_option( 'shop' ), $this->get_option( 'token' ), null );
				foreach ( $addresses['data'] as $address ) {
					$postalCode                        = $address['postal_code'] ? ', (' . esc_html( $address['postal_code'] ) . ')' : '';
					$addressText                       = '' != $address['number'] ? esc_html( $address['street'] ) . ', #' . esc_html( $address['number'] ) . $postalCode : esc_html( $address['street'] ) . $postalCode;
					$origins[ $address['address_id'] ] = $addressText . ', ' . esc_html( $address['state'] ) . '. ' . esc_html( $address['country'] ) . '.';
				}
				return $origins;

			} catch ( \Exception $e ) {
				$origins = array( '' => $e->getMessage() . ' Please refresh connection.' );
				return $origins;
			}
		}

		public function get_country_states_options( $country ) {
			$states = WC()->countries->get_states( $country ) ? WC()->countries->get_states( $country ) : \Enviacom::requests_process( 'GET', \Enviacom::ENVIA_QUERIES_HOSTNAME . '/state?country_code=' . $country, null );
			if ( isset( $states['data'] ) ) {
				$statesAux = array();
				foreach ( $states['data'] as $state ) {
					$statesAux[ $state['code_2_digits'] ] = $state['name'];
				}
				$states = $statesAux;
			}
			return $states;
		}

		public function admin_options() {
			$systemVars = \Enviacom::get_system_vars();
			$this->load_admin_settings($systemVars); //calling trait templates
		}

		public function calculate_shipping( $package = array() ) {
			$setCoupon = isset( WC()->session->set_coupon_access ) ? WC()->session->set_coupon_access : false;
			$updatedCart = isset( WC()->session->updated_cart ) ? WC()->session->updated_cart : false;
			/**
			 * A process to control the wc multiple trigger of quotes - For legacy mode   
			 */
			if ( ( ! ( is_cart() || is_checkout() || $setCoupon || $updatedCart ) ) && ! $this->useNewBlocks ) {
				return [];
			}
			WC()->session->__unset('set_coupon_access');
			WC()->session->__unset('updated_cart');
		/**
		 * The changedAddress variable indicates that a pickup address has been added but new request quote is not required.
		 * The refreshBlock allow know if must be blocked the try of request quote and continue with the previous.
		 */
			$changedAddress = isset( WC()->session->changed_address ) ? WC()->session->changed_address : false;
			$blockedRefresh = isset( WC()->session->blocked_refresh ) ? WC()->session->blocked_refresh : false;
			$prevRates = isset ( WC()->session->prev_envia_rates ) ? WC()->session->prev_envia_rates : null;
			if ( 'yes' == $this->active && get_option( 'envia_oauth_connection' ) ) {
				try {
					global $woocommerce;
					if ( ( $changedAddress || $blockedRefresh ) && ! is_null( $prevRates ) ) {
						$rates = WC()->session->get('prev_envia_rates');
						WC()->session->__unset('changed_address');
						WC()->session->__unset('blocked_refresh');
					} else {
						$rates = $this->get_shipping_rates( $package );
						if ( ! is_null( $rates ) && count( $rates ) > 0 ) {
							WC()->session->set('prev_envia_rates', $rates);
						}
						if ( isset( WC()->session->last_customer_shipping_address ) ) {
							WC()->session->__unset( 'last_customer_shipping_address' );
						}
					}
					$ratesPickup = [];
					foreach ( $rates as $key => $value ) {
						if ( ( 'no' == $this->usePickUp && count( $value['branches'] ) > 0 ) && 1 != $value['dropOff'] ) { // 1 can contain branches
							continue;
						}
						if ( 2 == $value['dropOff'] || 3 == $value['dropOff']) {
							if ( count( $value['branches'] ) == 0 ) { //validation to empty branch for dropoff 2 and 3
								continue;
							}
							$value = $this->limit_pickup_branches( $value );
					if ( $this->useNewBlocks ) {
						$ratesPickup[] = $value;
						continue;
					}
							if ( 'billing_only' === get_option( 'woocommerce_ship_to_destination' ) ) {
								continue;
							}
						if ( 'standard' == $this->typeDisplayPickUp || \Enviacom::is_new_blocks( 'cart' ) ) {
							$branchesKeys = array_keys( $value['branches'] );
							foreach ( $branchesKeys as $key ) {
								$rate = $this->generate_rates( $value, 2, $key );
								$this->add_rate( $rate );
							}
						}
						if ( 'custom' == $this->typeDisplayPickUp && ! \Enviacom::is_new_blocks( 'cart' ) ) {
							$rate = $this->generate_rates( $value, 2, null );
							$this->add_rate( $rate );
						}
						} else {
							//For dropOff 0 and 1
							$rate = $this->generate_rates( $value, 0, null );
							$this->add_rate( $rate );
						}
					}
			if ( count( $ratesPickup ) > 0 ) {
				WC()->session->set('envia_pickup', $ratesPickup);
			} else {
				WC()->session->__unset( 'envia_pickup' );
				WC()->session->__unset( 'envia_pickup_map_data' );
			}
				} catch ( \Exception $err ) {
					if ( is_cart() || is_checkout() ) {
						$message = $err->getCode() == 400 ? __( 'Enter a valid address to view shipping options', 'envia-shipping' ) : $err->getMessage();
						wc_add_notice( $message, 'notice' );	
					} else {
						$message = $err->getMessage();
						wc_add_notice( $message, 'notice' );	
					}	
				}
			}
		}

		/**
		 * Limits the branches array of a pickup rate to maxPickupRatesPerService.
		 * Each rate is limited independently (e.g. rate with 10 branches and max 4 shows 4).
		 *
		 * @param array $value Rate data with 'branches' key.
		 * @return array Rate with limited branches.
		 */
		private function limit_pickup_branches( $value ) {
			if ( ! isset( $value['branches'] ) || ! is_array( $value['branches'] ) ) {
				return $value;
			}
			$branches = $value['branches'];
			if ( count( $branches ) <= $this->maxPickupRatesPerService ) {
				return $value;
			}
			$value['branches'] = array_slice( $branches, 0, $this->maxPickupRatesPerService, true );
			return $value;
		}

		/**
		 * Creates an array with the especifications of an rate object, the type = 0 is for branches 0 y 1 and type 2 = is for branches 2 y 3
		 */ 
		public function generate_rates( $value, $type, $branch = null ) {
			$service_title = $this->get_service_title( $value );
			if ( ! is_null($branch) && 2 == $type ) { //When branch exists indicate the position on value['branches'] to create, .
				$addressFormat        = $value['branches'][$branch]['address']['address'] . ', #' . $value['branches'][$branch]['address']['number'] . ', (' . $value['branches'][$branch]['address']['zipcode'] . ')';
				$direction = esc_html( $service_title . ' ' . $value['branches'][$branch]['address']['country'] ) . '. ' . esc_html( ucwords( $addressFormat ) );
			} else {
				$direction = esc_html( $service_title );
			}
			// Branch null and type 2 is a custom option
			$label = 'yes' == $this->useTime ? $direction . ' ( ' . $value['deliveryEstimate'] . ' ) ' : $direction;
			if ( isset( $value['landedCostTotal'] ) ) {
				$currency = isset( $value['currency'] ) ? $value['currency'] : '';
				$shipping = isset( $value['totalShippingPrice'] ) ? number_format( (float) $value['totalShippingPrice'], 2 ) : '0.00';
				$duty_tax = isset( $value['landedCostTotal'] ) ? number_format( (float) $value['landedCostTotal'], 2 ) : '0.00';
				$label   .= ' - ' . esc_html( 'Shipping: ' . $shipping . ' ' . $currency . ', Duty and tax: ' . $duty_tax . ' ' . $currency );
			}
			return array(
				'id'        => ! is_null($branch) ? 'envia-' . $value['serviceId'] . '-' . $type . '-' . $value['branches'][$branch]['branch_code'] : 'envia-' . $value['serviceId'] . '-' . $type,
				'label'     => $label,
				'cost'      => strval( $value['totalPrice'] ),
				'meta_data' => array(
					'delivery'    => $value['deliveryEstimate'],
					'carrier'     => $value['carrier'],
					'dropoff'     => $value['dropOff'],
					'serviceId'     => $value['serviceId'],
					'service'     => $value['service'],
					'description' => $service_title,
					'branches' => is_null($branch) && 2 == $type ? $value['branches'] : null,
					'branchCode'    => ! is_null($branch) ? $value['branches'][$branch]['branch_code'] : null,
					'branchAddress'    => ! is_null($branch) ? $value['branches'][$branch]['address'] : null,
				),
			);
		}

		/**
		 * Returns the service title. Prioritizes aliasServiceDescription (customized) over serviceDescription.
		 *
		 * @param array $value Rate data from API.
		 * @return string
		 */
		private function get_service_title( $value ) {
			if ( ! empty( $value['aliasServiceDescription'] ) ) {
				return $value['aliasServiceDescription'];
			}
			return isset( $value['serviceDescription'] ) ? $value['serviceDescription'] : '';
		}

		/**
		 * Request to quote, postalCode and state are required
		*/ 
		public function get_shipping_rates( $package ) {
			try {
				global $woocommerce;
				$url         = \Enviacom::ENVIA_APP_HOSTNAME . '/v2/checkout/woocommerce/' . $this->get_option( 'shop' );
				$destination = $package['destination'];
				$cart        = $woocommerce->cart->get_cart();
				$origin      = $this->origin_options;
				$payload = $this->create_format_request( $origin, $destination, $cart );
				$postalCodeValidation = ( ! empty( $payload['destination']['postalCode'] ) ) || in_array( $payload['destination']['country'], $this->countriesToSkipCp );
				if ( $postalCodeValidation && ! empty( $payload['items'] ) && ! empty( $payload['packages'] ) ) {
					$requestRates = \Enviacom::requests_process( 'POST', $url, null, $payload );
					if ( isset( $requestRates['meta'] ) && 'error' == $requestRates['meta'] ) {
							throw new \Exception( 'Envia shipping: ' . $requestRates['error']['message'], $requestRates['error']['code'] );
					}
					for ( $i = 0; $i < count( $requestRates ); $i++ ) {
						for ( $j = 0; $j < count( $requestRates ) - 1; $j++ ) {
							if ( $requestRates[ $j + 1 ]['totalPrice'] < $requestRates[ $j ]['totalPrice'] ) {
								$rateAux                = $requestRates[ $j ];
								$requestRates[ $j ]     = $requestRates[ $j + 1 ];
								$requestRates[ $j + 1 ] = $rateAux;
							}
						}
					}
					if ( ! is_null( $requestRates ) ) {
						return $requestRates;
					} else {
						return array();
					}
				} else {
					return array();
				}
			} catch ( \Exception $err ) {
				throw $err;
			}
		}

		/**
		 * Request format for live rates 
		 */ 
		public function create_format_request( $origin, $destination, $cart ) {
			try {
				$calculationsOf = $this->package_dimensions( $cart );
				$district = 'CO' == $destination['country'] ? $destination['city'] : $destination['address_2'];  // COl. Carriers validation
				$stateValueParts = explode( '-', $destination['state'] );	
				$state = count( $stateValueParts ) > 1 ? $stateValueParts[1] : $stateValueParts[0] ;
				$payload      = array(
					'origin'      => array(
						'address_id' => $this->get_instance_option( 'enviaOrigin' ) && $this->get_instance_option( 'enviaOrigin' ) != 'default' ? $this->get_instance_option( 'enviaOrigin' ) : $origin['enviaOrigin'],
					),
					'destination' => array(
						'name'       => 'envia',
						'company'    => 'envia',
						'email'      => 'clients@envia.com',
						'phone'      => '1234567890',
						'street'     => $destination['address'],
						'number'     => $destination['address_2'],
						'district'   => ! is_null( $district ) ? $district : $destination['address_1'],
						'city'       => $destination['city'],
						'state'      => $state,
						'country'    => $destination['country'],
						'postalCode' => '' == $destination['postcode'] ? null : $destination['postcode'],
					),
					'currency'    => get_woocommerce_currency(),
					'items'       => count($calculationsOf['items']) > 0 ? $calculationsOf['items'] : null,
					'packages'    => count($calculationsOf['packages']) > 0 ? $calculationsOf['packages'] : null,
					'discountSummary' => $calculationsOf['discounts'] ? $calculationsOf['discounts'] : null,
					'locale' => determine_locale()
				);
				if ( in_array( $destination['country'], $this->countriesToSkipCp ) && '' == $destination['postcode'] ) {
					$payload['destination']['postalCode'] = '';
				}
				return $payload;
			} catch ( \Exception $err ) {
				throw $err;
			}
		}

		/**
		 * Package dimensions - packages key in request payload.
		 */ 
		public function package_dimensions( $cart ) {
			try {
				$itemsPriceDiscount = 0; //Discount amount for coupon per each item x quantity
				$itemsPriceTotal    = 0; //Total products before of discount coupons;
				$totalDiscount      = 0; //Total of discount of coupons
				$totalWeight        = 0; //Total weight of package
				$totalHeight        = 0; //Total height of package
				$maxDimensionValue  = 0; //Save the max value found in the dimensions products to use as package base
				$allDimensions      = array();
				$items              = array();
				$packages           = array();
				$couponDiscounts    = self::calculate_discount_coupons( $cart ); // Retrieve the amount and type of coupon applied and the product IDs exceptions 
				$lengthUnit         = get_option( 'woocommerce_dimension_unit' ) ? get_option( 'woocommerce_dimension_unit' ) : 'cm';
				$weightUnit         = get_option( 'woocommerce_weight_unit' ) ? get_option( 'woocommerce_weight_unit' ) : 'kg';
				foreach ( $cart as $element ) {
					$productId       = $element['product_id'];
					$variationId     = $element['variation_id'];
					$productInCart   = $element['variation_id'] > 0 ? $element['variation_id'] : $element['product_id'];
					$product         = wc_get_product( $productInCart );
					if ( $product->is_virtual() || $product->is_downloadable() ) {
						continue;
					}
					$productPrice    = $product->get_price();
					$weight          = ! empty( $product->get_weight() ) ? floatval( $product->get_weight() ) : 1;
					$dimensions = array(
						'length'          => floatval( $product->get_length() ),
						'height'          => floatval( $product->get_height() ),
						'width'           => floatval( $product->get_width() ),
					);
					if ( $couponDiscounts && array_key_exists( 'coupons', $couponDiscounts['fixedProducts'] ) ) {
						foreach ( $couponDiscounts['fixedProducts']['coupons'] as $coupon ) { //To evaluate the accumulation of discounts price applied to the products
							if ( count( $coupon['excludeIds'] ) > 0 ) {
								if ( in_array( $productId, $coupon['excludeIds'] ) || in_array( $variationId, $coupon['excludeIds'] ) ) {
									continue;
								}
							}
							$productPrice       -= $coupon['discount']; //Get the price of the product with each discount coupon applied
							$itemsPriceDiscount += $coupon['discount'] * $element['quantity']; //Get the total of discount per coupon multiplied to quantity of the product
						}
					}
					$items[] = array(
						'productId'          => $productId,
						'variantId'          => $variationId,
						'name'               => $product->get_name(),
						'sku'                => $product->get_sku(),
						'quantity'           => $element['quantity'],
						'price'              => $productPrice,
						'properties'         => $dimensions['height'] . ' * ' . $dimensions['width'] . ' * ' . $dimensions['length'],
						'width'              => number_format( $dimensions['width'], 2, '.', '' ),
						'length'             => number_format( $dimensions['length'], 2, '.', '' ),
						'height'             => number_format( $dimensions['height'], 2, '.', '' ),
						'weight'             => number_format( $weight, 2, '.', '' ),
						'fulfillmentService' => 'manual',
						'requiresShipping'   => true,
						'taxable'            => true,
						'vendor'             => '',
					);
					$itemsPriceTotal  += $product->get_price() * $element['quantity'];
					$pmdv              = max($dimensions); //Max dimension value of current product;
					$pmindv            = min($dimensions); //Min dimension value of current product;
					$maxDimensionValue = $maxDimensionValue < $pmdv ? $pmdv : $maxDimensionValue; //Get the most dimension value in all products
					$allDimensions     = array_merge($allDimensions,array_values($dimensions)); //Save al dimensions values
					for ( $i = 0; $i < $element['quantity']; $i++ ) { //Iteration per quantity of product
						$totalWeight += $weight; //Summation of weight
						$totalHeight += $pmindv; //Summation of pmindv
					}
				}
				$filtered_array = array_filter( $allDimensions, function( $value ) use ( $maxDimensionValue ) {
					return $value < $maxDimensionValue;
				} );
				$packageLength = ! empty( $filtered_array ) ? max( $filtered_array ) : $maxDimensionValue;
				$packages[]           = array(
					'content'       => 'Package',
					'type'          => 'box',
					'amount'        => 1,
					'length'        => number_format( $packageLength, 2, '.', '' ),
					'width'         => number_format( $maxDimensionValue, 2, '.', '' ),
					'height'        => number_format( $totalHeight, 2, '.', '' ),
					'weight'        => number_format( $totalWeight, 2, '.', '' ),
					'lengthUnit'    => $lengthUnit,
					'weightUnit'    => $weightUnit,
					'insurance'     => 0,
					'declaredValue' => 0,
				);
				if ( ! is_null( $couponDiscounts ) ) {
					if ( $couponDiscounts['percentTotal'] > 0 ) { //Percent discount must be calculated with product price before of coupons
						$totalDiscount   = $itemsPriceTotal * ( $couponDiscounts['percentTotal']['totalAmount'] / 100 ); 
						$itemsPriceTotal = $itemsPriceTotal - $totalDiscount;
					}
					if ( $couponDiscounts['fixedTotal'] > 0 ) { //Fixed discount must be calculated after percent discount
						$totalDiscount  += $couponDiscounts['fixedTotal']['totalAmount'];
						$itemsPriceTotal = $itemsPriceTotal - $couponDiscounts['fixedTotal']['totalAmount'];
					}
				}
				$discounts = array(
					'amount'=> $totalDiscount > 0 ? $totalDiscount : 0,
					'total' => $itemsPriceTotal - $itemsPriceDiscount, //Total coupon discount for items must be subtracted
				);
				return array(
					'items'    => $items,
					'packages' => $packages,
					'discounts'=> $discounts
				);
			} catch ( \Exception $err ) {
					throw $err;
			}
		}

		/**
		 * Get the products discounts when coupons is applied
		 */ 
		public static function calculate_discount_coupons( $cart ) {
			$coupons = WC()->cart->get_coupons();
			$calculate = array(
				'fixedProducts' => array(),
				'fixedTotal' => array(),
				'percentTotal' => array()
			);
			$discountFixedProduct = 0;
			$discountFixedTotal = 0;
			$discountPercentTotal = 0;
			if ( count( $coupons ) > 0  ) {
				foreach ( $coupons as $coupon ) { 
					$couponName = $coupon->get_code();
					$discount = $coupon->get_amount( $couponName );
					switch ( $coupon->get_discount_type() ) {
						case 'fixed_product':
							$calculate['fixedProducts']['coupons'][] = array( 'name' => $couponName, 'discount' => $discount, 'excludeIds' => $coupon->get_product_ids() );
							$discountFixedProduct += $discount;
							break;
						case 'fixed_cart':
							$calculate['fixedTotal']['coupons'][] = array( 'name' => $couponName, 'discount' => $discount );
							$discountFixedTotal += $discount;   
							break;
						case 'percent':
							$calculate['percentTotal']['coupons'][] = array( 'name' => $couponName, 'discount' => $discount );
							$discountPercentTotal += $discount;
							break;
						default:
							break;
					}
				}
				$calculate['fixedProducts']['totalAmount'] = $discountFixedProduct;
				$calculate['fixedTotal']['totalAmount'] = $discountFixedTotal;
				$calculate['percentTotal']['totalAmount'] = $discountPercentTotal;
				return $calculate;
			}

			return null;
		}


		public function custom_override_default_address_fields( $fields ) {
			$statePriority = $fields['shipping']['shipping_state']['priority'];
			$city_args     = wp_parse_args(
				array(
					'type'        => 'select',
					'options'     => array(
						'default' => __( 'Select city', 'envia-shipping' ),
					),
					'input_class' => array(
						'country_select',
					),
					'priority'    => $statePriority + 1,
				),
				$fields['billing']['billing_city']
			);

			$fields['billing']['billing_city'] = $city_args;

			$city_args2 = wp_parse_args(
				array(
					'type'        => 'select',
					'options'     => array(
						'default' => __( 'Select city', 'envia-shipping' ),
					),
					'input_class' => array(
						'country_select',
					),
					'priority'    => $statePriority + 1,
				),
				$fields['shipping']['shipping_city']
			);

			$fields['shipping']['shipping_city'] = $city_args2;

			return $fields;
		}
		public function custom_priority_address_fields( $address_fields ) {
			$statePriority                      = $address_fields['state']['priority'];
			$address_fields['city']['priority'] = $statePriority + 1;
			return $address_fields;
		}

	}
}
