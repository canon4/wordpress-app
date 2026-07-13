jQuery(function() {
	sessionStorage.setItem('nonce', data.nonce );
		if( data.viewMode == 'standard' ) {
			getStandardOptionRate();
		}
		if( data.viewMode == 'custom' ) {
			getStandardOptionRate();
			getCustomOptionRate();
		}
});

function additionalInfo() {
	let checkout = Array.from(jQuery(".checkout :input"))
	let fields = ["first_name", "last_name", "company", "phone", "email"];
	let customer = {};
	checkout.forEach( input => {
	    if( fields.some( field => input.name.search( new RegExp( field,'i') ) !== -1  ) ) {
	        customer[`${input.name}`] = input.value   
	    }
	})
	return customer;
}

function setShippingAddress( data ){
	let nonce = sessionStorage.getItem('nonce')
	if( nonce == data.nonce) {
		customer = JSON.stringify( additionalInfo() );
		const ajaxUrl = data.ajaxurl;
		const payload = JSON.stringify( data );
		jQuery.ajax({
			type:'POST',
			url: ajaxUrl,
			data: { 'action': 'set_pick', 'data': payload, 'customer': customer, '_wpnonce': nonce },
			dataType: 'json'
		})
		.done( function( response ) {
			if(response.status == 'success') {
				data = JSON.parse(payload);
				if(response.whereIs == 'cart') {
					(function( $ ) {
					    'use strict';
					    jQuery( document ).ready(function($) {
					        function refresh_fragments() {
					            // console.log('fragments refreshed!');
					            jQuery( "[name='update_cart']" ).removeAttr( 'disabled' );
					            jQuery( "[name='update_cart']" ).trigger( 'click' );
					        }
					        refresh_fragments();
					    });
					})(jQuery);
				}
				else if(response.whereIs == 'checkout'){
					// jQuery('body').trigger('update_checkout');
					window.location.reload()
				}
			}
		})
		.fail( function( fail ) {
			console.error( fail );
		})
	}
}

function setValue( rateId ) {
	let values = rateId.split('-');
	data.method = values[0] || null;
	data.serviceId = values[1] || null;
	data.type = values[2] || null
	data.branchCode = values[3] || null;
	return data;
}

function getCustomOptionRate(element) {
	if( element ){
		if( element.selectedIndex == 0 ) {
			jQuery('#place_order').prop('disabled', true);
			jQuery('.checkout-button').prop('link', 'role');
			jQuery('.checkout-button').removeAttr('href');
		}
		let selectedPickUp = element.options[element.selectedIndex].value;
		let radioToCheck =  Array.from(element.parentElement.childNodes).find( child => child.type == 'radio');
		if ( radioToCheck.checked && selectedPickUp ) {
			data = setValue( selectedPickUp );
			data.option = radioToCheck.value;
			if(data.method == 'envia') {
				setShippingAddress( data );
			}
		}
		else{
			element.options['selectedIndex'] = 0;
		}
	}
}

function getStandardOptionRate() {
	jQuery( document ).on( 'change', '.shipping_method', ':input[name^=shipping_method]', async function() {
		data = setValue(this.value)
		if( data.method == 'envia' ) {
			data.option = this.value
			let animation = await standardOptionLauncher( data.ratesIn );
			if( animation == 'finished') {
				switch( data.viewMode ) {
					case 'standard' :
					setShippingAddress( data );
					break;
					case 'custom' :
					if ( data.type == '0' ) {
						setShippingAddress( data );
					}
					if( data.type == '2' ) {
						if ( jQuery('.pick-up-location')[0].options.selectedIndex == 0 ) {
							jQuery('#place_order').prop('disabled', true)
						}
					}
					break;
				}
			}
		}
	});
}

async function standardOptionLauncher( site ) {
	return new Promise( function (resolve, reject ) {
		if( site == 'checkout' ){
				let checkoutRateTable = document.getElementsByClassName('woocommerce-checkout-review-order-table')[0];
				if( checkoutRateTable ) {
					let animationStart = setInterval( () => {
						checkoutRateTable = document.getElementsByClassName('woocommerce-checkout-review-order-table')[0]
						let listNodes = Array.from(checkoutRateTable.children)
						if( ! listNodes.some(el => { return el.className.includes('block') } ) ) {
							resolve('finished');
							clearInterval(animationStart);
						} 
					},500);
				}
			}
		if( site == 'cart' ) {
			let cartRateTable = document.getElementsByClassName('cart_totals')[0];
			if( cartRateTable ) {
					let animationStart = setInterval( function(){
						cartRateTable = document.getElementsByClassName('cart_totals')[0]
						let classList = Array.from( cartRateTable.classList );
						if( ! classList.includes('processing') && !document.getElementsByClassName('processing')[0] ) {
							resolve('finished');
							clearInterval(animationStart);
						}
					},500);
			}
		}
	})
}