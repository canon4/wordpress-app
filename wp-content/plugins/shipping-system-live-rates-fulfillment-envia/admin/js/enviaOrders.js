jQuery(function() {
	order_metabox ();
})

function order_metabox() {
	if( jQuery('#envia-order-manager').length > 0 ) {
	    while( jQuery('#postbox-container-2').children().first().children()[0].id !== 'envia-order-manager' ) {
	        if(jQuery('#postbox-container-1').children().first().children('#envia-order-manager').length > 0 ) {
	            jQuery('#envia-order-manager>.postbox-header>.handle-actions>.handle-order-lower').click();
	            continue;    
	        }
	        
	        if(jQuery('#postbox-container-2').children().first().children('#envia-order-manager').length > 0 ) {
	            jQuery('#envia-order-manager>.postbox-header>.handle-actions>.handle-order-higher').click();
	            continue;
	        }
	    }
	}
}