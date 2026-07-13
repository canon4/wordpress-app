let oauth = () => {
	let currentLocation = window.location.origin + window.location.pathname?.split('/wp-admin')?.[0];
	let appId = 'NrUAVHsfjTJUE0NacgA7mVSogkfTuWTW';
	let urlQuery = window.location.pathname?.split('/wp-admin')?.[0] + '/wp-admin/admin-ajax.php';
	let failRedirect= window.location.pathname?.split('/wp-admin')?.[0] + '/wp-admin/plugins.php';
	let oauthHostname = 'https://oauth.ecartapi.com';
	let thispluginUrl = window.location.pathname?.split('/wp-admin')?.[0] + '/wp-admin/admin.php?page=wc-settings&tab=shipping&section=envia_shipping';
	let windowFeatures = 'width=800, height=900, popup';
	let win = window.open( `${oauthHostname}/oauth/woocommerce/${appId}?url=${currentLocation}&state=fromPlugin&origin=wc_envia`, '', windowFeatures);
	let close = setInterval(function () {
		if(win.closed){
			ajaxCheckRequest( urlQuery, 'check', thispluginUrl, thispluginUrl );
			clearInterval(close);
		}
	},1000)
	let modal = document.getElementById('modal-envia-oauth');
	let classes = Array.from( modal.classList );
	modal.classList.remove('hidden');
	document.getElementById("saving-oauth").style.display="block"
}

let ajaxCheckRequest = ( url, status, doneRedirect, failRedirect ) => {
	let spinGif = document.getElementById("saving-oauth");
	let successGif = document.getElementById('check-oauth');
	jQuery.ajax({
		type: 'GET',
		url:url,
		data: { 'action': 'envia_check', 'status': status },
		dataType: 'json',
		beforeSend: function() {
			spinGif.children[1].innerText = 'Check in process';
		}
	})
	.done( function(res) {
		if( res.status == 'success' ) {
			if( doneRedirect ) {
				spinGif.children[0].classList.remove('lds-dual-ring');
				successGif.style.display = 'block';
				spinGif.children[1].innerText = res.message + ': We are ready to start shipping!'
				setTimeout(function(){
					window.onbeforeunload = null;
					successGif.style.display = 'none';
					spinGif.children[0].classList.add('lds-dual-ring');
					spinGif.children[1].innerText = 'Reloading'
					window.location.href = doneRedirect;
				},1500);
			}
		}
	})
	.fail( function( error ) {
		if( failRedirect ) {
			spinGif.children[1].innerText = error.responseJSON?.message ? 'Error:' + error.responseJSON.message : 'Not connected';
			setTimeout(function(){
					window.onbeforeunload = null;
					spinGif.children[1].innerText = 'Reloading'
					window.location.href = failRedirect;
			},1000);
		}
	})
}

let copyAbout = async () => {
	try {
	    let values = document.getElementsByClassName('data-value') ? Array.from(document.getElementsByClassName('data-value')).map(item=> item.innerText.replace(/\n/g, "")).join(' ') : ''
	    await navigator.clipboard.writeText(values);
	}
	catch(err) {
		console.error(err.message);
	}
}


jQuery(function() {
	let checkUrl = new URL ( window.location );
	if( oauthData.status == 'false' ) {
		location = window.location.pathname?.split('/wp-admin')?.[0] + '/wp-admin/admin.php?page=wc-settings&tab=shipping&section=envia_shipping'; 
		window.location.href = window.location.pathname?.split('/wp-admin')?.[0] + '/wp-admin/admin.php?page=wc-settings&tab=shipping&section=envia_shipping';
	}
	if( checkUrl.searchParams.get( 'section' ) == 'envia_shipping' || checkUrl.searchParams.get( 'page' ) == 'envia-order-manager' ) { 
		let button = document.getElementById('envia_oauth');
		let labelStatus = document.getElementById('status-msj');
		let iconStatus = document.getElementById('status-icon');
		if( labelStatus ) {
			labelStatus.innerText = oauthData.status == 'wait' ? oauthData.i18n.notConnected : oauthData.status == 'true' ? oauthData.i18n.connected : null;
		}
		if( iconStatus ) {
			iconStatus.className = oauthData.status == 'wait' ? 'disconnected' : oauthData.status == 'true' ? 'connected' : null;
		}
		if( oauthData.status == 'wait' ) { 
			console.info("The Envia plugin is active but Oauth is pending.");
		}
		if( oauthData.status == 'true' ) { 
			console.info("The Envia plugin is active.");
		}
		if( button ) {
			button.value = oauthData.status == 'wait' ? oauthData.i18n.connectBtn : oauthData.status == 'true' ? oauthData.i18n.refreshBtn : null;
			button.addEventListener( 'click', function(){
				oauth()	
			})
		}
	}
	document.getElementById('about').addEventListener('click', function() {
		copyAbout();	
	})
})

