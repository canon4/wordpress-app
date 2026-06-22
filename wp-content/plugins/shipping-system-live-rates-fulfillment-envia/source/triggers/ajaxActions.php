<?php
/**
 * Envia connection status - Oauth callback check if the instance variables exists 
 */
add_action( 'wp_ajax_envia_check', array( 'Enviacom', 'catch_envia_status' ) );

/**
 * Envia connection status - Oauth callback create instance wc-api= 
 */ 
add_action( 'woocommerce_api_envia_shipping', array( 'Enviacom', 'catch_envia_status' ) );

/**
 * Get cities from geocodes
 */ 
add_action( 'wp_ajax_envia_cities', array( 'Enviacom', 'getCities' ) );

/**
 * Get forms from Envia queries
 */ 
add_action( 'wp_ajax_envia_forms', array( 'Enviacom', 'getForm' ) );

/**
 * Save new origin address 
 */
add_action( 'wp_ajax_envia_save', array('Enviacom', 'saveOriginAddress') );

/**
 * Save the branchCode and customer info for use in the forms and order finished - legacy actions
 */ 
add_action( 'wp_ajax_nopriv_set_pick', array( 'Enviacom', 'pickDestination' ) );
add_action( 'wp_ajax_set_pick', array( 'Enviacom', 'pickDestination' ) );

/**
 * Return pickup branch data from session for the block checkout map SlotFill.
 */
add_action( 'wp_ajax_envia_get_pickup_map_data', array( 'Enviacom', 'get_pickup_map_data' ) );
add_action( 'wp_ajax_nopriv_envia_get_pickup_map_data', array( 'Enviacom', 'get_pickup_map_data' ) );

/**
 * Open shipping address form - legacy actions?  - pending to check
 */
if ( get_option( 'envia_oauth_connection' ) && 'true' == get_option( 'envia_oauth_connection' ) && array_key_exists( 'pickUpDestination', get_option( 'woocommerce_envia_shipping_settings' )  ) ) {
	if ( 'billing' == get_option( 'woocommerce_ship_to_destination' ) && 'yes' == get_option( 'woocommerce_envia_shipping_settings' )['pickUpDestination'] ) {
		add_filter( 'woocommerce_ship_to_different_address_checked', '__return_true' );
	}
}
