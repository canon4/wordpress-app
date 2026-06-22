<?php 
namespace Envia\Classes\Module;

use \WC_Shipping_Local_Pickup;
defined( 'ABSPATH' ) || exit;
class_exists( '\WC_Shipping_Local_Pickup' );
class Envia_Pickup extends \WC_Shipping_Local_Pickup {
	private $useTime; 
	public function __construct( $instance_id = 0, $useTime = 'yes' ) {
		$this->id                 = 'envia_pickup';
		$this->instance_id 		  = absint( $instance_id );
		$this->title              = 'Live pickup rates';
		$this->method_title       = 'Envia rates pickup';
		$this->method_description = 'Envia rates pickup';
		$this->supports           = array( 'local-pickup' );
		$this->useTime 			  = $useTime;
		$this->enabled            = 'yes';
		add_action( 'init', array( $this, 'apply_translations' ), 5 );
	}

	public function apply_translations() {
		$this->title              = __( 'Live pickup rates', 'envia-shipping' );
		$this->method_title       = __( 'Envia rates pickup', 'envia-shipping' );
		$this->method_description = __( 'Envia rates pickup', 'envia-shipping' );
	}

	public function calculate_shipping( $package = array() ) {
		if ( ! isset( WC()->session->envia_pickup ) ) {
			return;
		}
		$pickupRates = WC()->session->envia_pickup;

		// Persist a copy for the map AJAX endpoint before the working key is cleared.
		WC()->session->set( 'envia_pickup_map_data', $pickupRates );

		foreach ( $pickupRates as $key => $value ) {
			$branchesKeys = array_keys( $value['branches'] );
			foreach ( $branchesKeys as $key ) {
				$rate = $this->generate_rates( $value, 2, $key );
				$this->add_rate( $rate );
			}
		}
		WC()->session->__unset( 'envia_pickup' );
	}

	/**
	 * Creates an array with the wc local pikcup class format
	 */ 
	public function generate_rates( $value, $type, $branch = null ) {
		$service_title = $this->get_service_title( $value );
		$address = $value['branches'][$branch]['address'];
		$location = array( 
			'address_1' => $address['address'] /*. ', #' . $address['number']*/,
			'city' => $address['city'],
			'state' => $address['province'],
			'postcode' => $address['zipcode'],
			'country' => $address['country'],	
		);
		$addressFormat        = strtolower( $location['address_1'] );
		$direction = esc_html( $value['carrierDescription'] . ' - ' . ucwords( $addressFormat ) );
		return array(
			'id'        => ! is_null($branch) ? 'envia-' . $value['serviceId'] . '-' . $type . '-' . $value['branches'][$branch]['branch_code'] : 'envia-' . $value['serviceId'] . '-' . $type,
			'label'     => 'yes' == $this->useTime ? $direction . ' (' . $value['deliveryEstimate'] . ')' : $direction,
			'cost'      => strval( $value['totalPrice'] ),
			'calc_tax'  => 'per_item',
			'meta_data' => array(
				'delivery'    => $value['deliveryEstimate'],
				'carrier'     => $value['carrier'],
				'dropoff'     => $value['dropOff'],
				'serviceId'     => $value['serviceId'],
				'service'     => $value['service'],
				'description' => $service_title,
				'branchCode'    => ! is_null($branch) ? $value['branches'][$branch]['branch_code'] : null,
				'branchAddress'    => ! is_null($branch) ? $value['branches'][$branch]['address'] : null,
				'pickup_address' => wc()->countries->get_formatted_address( $location , ', ' ) ,
				'pickup_details' => $service_title,

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
}
