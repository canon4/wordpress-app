function loadAddressInputData ( inputMain, param, output ) {
	    let country = document.getElementById( param ).value;
	    let currentState =  inputMain[0].options[inputMain[0].selectedIndex].value;
	inputMain.change( function() {
	    currentState =  this.options[this.selectedIndex].value;
	    ajaxGetCities( country, currentState, output );
	})
	    ajaxGetCities( country, currentState, output )
}

function ajaxGetCities ( country, state, output ) {
	let city = document.getElementById( output );
	state = country == 'CO' ? state.split('CO-')[1] : state;
	jQuery.ajax({
		url : window.location.pathname?.split('/wp-admin')?.[0] + '/wp-admin/admin-ajax.php',
		method: 'GET',
		data: { 
			'action': 'envia_cities', 
			'country': country, 
			'state': state 
		},
		'dataType' : 'json',
	})
	.done( function ( response ) {
		let lastValue = jQuery( '#'+output ).val();
		jQuery( '#'+output ).empty();
			let newOption = document.createElement("option");
			newOption.value = 'default';
			newOption.text = 'Select a city';
			city.options.add( newOption, 0 );
		response.forEach( (element, index) => {
			let newOption = document.createElement("option");
			newOption.value = element.name;
			newOption.text = element.name;
			city.options.add( newOption, index+1 );
		})
		Array.from( city.options ).forEach( option => {
			if( option.value == lastValue )
				city.value = lastValue
		})
	})
	.fail( function( error ){
		console.log(error)
	})
}


/**
 * 
 * init values, we have to check if the dom inputs exists
 * 
 **/
jQuery(function() {
	let possibleInputs = [ 
		// {
		// 	inputCountryId : 'calc_shipping_country',
		// 	inputStateId : 'calc_shipping_state',
		// 	inputCityId: 'calc_shipping_city'
		// },
		{
			inputCountryId : 'envia-shipping-country',
			inputStateId : 'woocommerce_envia_shipping_state',
			inputCityId: 'woocommerce_envia_shipping_city'
		},
		{
			inputCountryId : 'billing_country',
			inputStateId : 'billing_state',
			inputCityId: 'billing_city'
		},
		{
			inputCountryId : 'shipping_country',
			inputStateId : 'shipping_state',
			inputCityId: 'shipping_city'
		},
	]
	possibleInputs.forEach( obj => { 
		
		if( jQuery('#'+obj.inputStateId).length > 0 ) {
			let inputMain = jQuery('#'+obj.inputStateId);
			let output = obj.inputCityId;
			let param = obj.inputCountryId;
			//param puede ser objeto ej: param = { country : obj.inputCountryId } 
			loadAddressInputData( inputMain, param, output );
		}  
		//para input de colonias sera necesario crear select inputNeihborhoodId para output y hacer de inputCityId el inputMain
		// if( jQuery('#'+obj.inputStateId).length > 0 ) {
		// 	let inputMain = jQuery('#'+obj.inputStateId);
		// 	let output = obj.inputCityId;
		// 	let param = obj.inputCountryId;
		// 	loadAddressInput( inputMain, param, output );
		// }  
	} )

	// changeOriginDefault ()

	const pickupCheckbox   = jQuery( '#woocommerce_envia_shipping_pickUpDestination' );
	const pickupDependents = [
		'#woocommerce_envia_shipping_displayPickUp',
		'#woocommerce_envia_shipping_displayPickUpBlock',
		'#woocommerce_envia_shipping_maxPickupRatesPerService',
	];

	function togglePickupDependents() {
		const enabled = pickupCheckbox.is( ':checked' );
		pickupDependents.forEach( function( selector ) {
			jQuery( selector ).closest( 'tr' ).toggleClass( 'envia-cfg-disabled', ! enabled );
		} );
	}

	if ( pickupCheckbox.length ) {
		togglePickupDependents();
		pickupCheckbox.on( 'change', togglePickupDependents );
	}
})