( function () {
	'use strict';

	// 5.1 — Guard: required WC/WP globals must exist
	if (
		! window.wc ||
		! window.wc.blocksCheckout ||
		! window.wc.blocksCheckout.ExperimentalOrderLocalPickupPackages ||
		! window.wp ||
		! window.wp.plugins ||
		! window.wp.element
	) {
		return;
	}

	// 5.2 — Guard: only run when map mode is active (map or both)
	var displayMode = window.enviaPickupMap && window.enviaPickupMap.displayMode;
	if ( ! displayMode || displayMode === 'list' ) {
		return;
	}

	// Hide the native WC pickup list when map-only mode is selected
	if ( displayMode === 'map' ) {
		var mapOnlyStyle = document.createElement( 'style' );
		mapOnlyStyle.textContent = '.wc-block-components-radio-control { display: none !important; }';
		document.head.appendChild( mapOnlyStyle );
	}

	const { registerPlugin }                          = window.wp.plugins;
	const { ExperimentalOrderLocalPickupPackages }    = window.wc.blocksCheckout;
	const { createElement, useEffect, useRef }        = window.wp.element;

	// Module-level map state
	let leafletMap     = null;
	let mapContainer   = null; // imperative DOM node — never a React-managed element
	let loadingOverlay = null;
	let markers        = []; // [{ marker: L.Marker, rateId: string }]

	// ─── Helpers ────────────────────────────────────────────────────────────────

	function showMapLoading() {
		if ( loadingOverlay ) {
			loadingOverlay.style.display = 'flex';
		}
	}

	function hideMapLoading() {
		if ( loadingOverlay ) {
			loadingOverlay.style.display = 'none';
		}
	}

	function buildRateId( serviceId, branchCode ) {
		return 'envia-' + serviceId + '-2-' + branchCode;
	}

	function clearMarkers() {
		markers.forEach( function ( m ) {
			if ( leafletMap ) {
				leafletMap.removeLayer( m.marker );
			}
		} );
		markers = [];
	}

	// 7.3 — Highlight the selected marker and open its popup; reset others.
	// Passing null deselects all markers and closes any open popup.
	function setActiveMarker( selectedRateId ) {
		if ( ! selectedRateId && leafletMap ) {
			leafletMap.closePopup();
		}
		markers.forEach( function ( m ) {
			var el = m.marker.getElement();
			if ( ! el ) return;
			if ( selectedRateId && m.rateId === selectedRateId ) {
				el.classList.add( 'envia-marker-selected' );
				m.marker.openPopup();
			} else {
				el.classList.remove( 'envia-marker-selected' );
			}
		} );
	}

	// 6.5 — Place a Leaflet marker with popup and click handler
	function placeMarkerOnMap( branch, serviceId, carrierDescription, lat, lng ) {
		if ( ! leafletMap ) return;
		var address     = branch.address;
		var addressLine = [
			address.address,
			address.city,
			address.province,
			address.zipcode,
			address.country,
		].filter( Boolean ).join( ', ' );
		var popupContent = '<strong>' + ( carrierDescription || '' ) + '</strong><br>' + addressLine;
		var rateId       = buildRateId( serviceId, branch.branch_code );
		var marker       = window.L.marker( [ lat, lng ] )
			.addTo( leafletMap )
			.bindPopup( popupContent );

		// 7.1 & 7.2 — Rate selection on marker click
		marker.on( 'click', function () {
			try {
				showMapLoading();
				window.wp.data.dispatch( 'wc/store/cart' ).selectShippingRate( rateId, 0 );
				setActiveMarker( rateId );
			} catch ( e ) {
				hideMapLoading();
				console.warn( 'Envia pickup map: could not select shipping rate', e );
			}
		} );

		markers.push( { marker: marker, rateId: rateId } );
	}

	// 6.3 — Place branch only when coordinates are available; skip otherwise
	function placeBranch( branch, serviceId, carrierDescription ) {
		var addr = branch.address;
		if ( addr && addr.latitude && addr.longitude ) {
			placeMarkerOnMap( branch, serviceId, carrierDescription, parseFloat( addr.latitude ), parseFloat( addr.longitude ) );
		}
	}

	// 6.6 — Fit map viewport to all placed markers
	function fitBounds() {
		if ( ! leafletMap || markers.length === 0 ) return;
		var group = window.L.featureGroup( markers.map( function ( m ) { return m.marker; } ) );
		leafletMap.fitBounds( group.getBounds().pad( 0.15 ) );
	}

	// ─── Branch rendering ────────────────────────────────────────────────────────

	function hideMap() {
		if ( mapContainer ) {
			mapContainer.style.display = 'none';
		}
		if ( leafletMap ) {
			leafletMap.remove();
			leafletMap = null;
		}
	}

	function renderBranches( branches ) {
		if ( ! leafletMap ) return;
		clearMarkers();
		setActiveMarker( null ); // reset highlight after address change
		branches.forEach( function ( rate ) {
			if ( ! Array.isArray( rate.branches ) ) return;
			rate.branches.forEach( function ( branch ) {
				placeBranch( branch, rate.serviceId, rate.carrierDescription );
			} );
		} );
		if ( markers.length === 0 ) {
			// Branches exist in the response but none had usable coordinates.
			hideMap();
			return;
		}
		fitBounds();
	}

	// ─── AJAX refresh (8.1–8.4) ──────────────────────────────────────────────────

	/**
	 * Fetches current branch data from the PHP session and updates the map.
	 * Called on component mount AND on every wc-blocks_shipping_rates_updated event.
	 * Also handles first-time map initialisation when branches arrive after page load.
	 */
	function fetchAndRefreshBranches() {
		// WC calculation is done at this point — hide the overlay immediately.
		// The AJAX below refreshes map branch data silently in the background.
		hideMapLoading();

		var formData = new FormData();
		formData.append( 'action', 'envia_get_pickup_map_data' );
		formData.append( 'nonce', window.enviaPickupMap.nonce );
		fetch( window.enviaPickupMap.ajaxUrl, {
			method: 'POST',
			body: formData,
			referrer: window.location.href,
		} )
			.then( function ( res ) { return res.json(); } )
		.then( function ( data ) {
			if ( ! mapContainer ) return;
			if ( Array.isArray( data ) && data.length > 0 ) {
				mapContainer.style.display = '';
				// Initialise the Leaflet map on first arrival of branch data
				if ( ! leafletMap ) {
					initMap( mapContainer );
				}
				renderBranches( data );
			} else {
				// 8.4 — Empty or missing branches: hide map and destroy instance
				hideMap();
			}
		} )
		.catch( function ( err ) {
			// Network error or invalid response — hide the map so stale markers
			// are not left visible after an address change with no pickup options.
			console.error( 'Envia pickup map: AJAX request to fetch branch data failed', err );
			hideMap();
		} );
	}

	// ─── Map initialisation (6.1 & 6.2) ─────────────────────────────────────────

	function initMap( container ) {
		if ( leafletMap ) return; // already initialised

		// 6.2 — Fallback when Leaflet CDN failed to load
		if ( ! window.L ) {
			container.innerHTML =
				'<p class="envia-map-fallback">Map unavailable &mdash; please use the pickup list above.</p>';
			return;
		}

		leafletMap = window.L.map( container );
		window.L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution:
				'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			maxZoom: 19,
		} ).addTo( leafletMap );

		// 7.4 — Subscribe to WC store for two purposes:
		//   a) Sync rate selection from the list to the map marker highlight.
		//   b) Hide the loading overlay when wc/store/checkout isCalculating() transitions
		//      true → false. This is the same signal that drives the WC checkout spinner,
		//      covering selectShippingRate API calls and totals recalculation.
		if ( window.wp && window.wp.data ) {
			var lastSyncedRateId = null;
			var wasCalculating   = false;
			window.wp.data.subscribe( function () {
				try {
					var cartStore     = window.wp.data.select( 'wc/store/cart' );
					if ( ! cartStore ) return;

					// a) Marker sync
					var shippingRates = typeof cartStore.getShippingRates === 'function'
						? cartStore.getShippingRates()
						: [];
					var selectedRateId = null;
					shippingRates.forEach( function ( pkg ) {
						if ( ! Array.isArray( pkg.shipping_rates ) ) return;
						pkg.shipping_rates.forEach( function ( rate ) {
							if ( rate.selected && rate.rate_id && rate.rate_id.startsWith( 'envia-' ) ) {
								selectedRateId = rate.rate_id;
							}
						} );
					} );
					if ( selectedRateId !== lastSyncedRateId ) {
						lastSyncedRateId = selectedRateId;
						setActiveMarker( selectedRateId );
					}

					// b) Hide overlay when checkout finishes calculating totals.
					//    wc/store/checkout.isCalculating() returns true while the WC server
					//    is recalculating after a rate selection or address change.
					var checkoutStore  = window.wp.data.select( 'wc/store/checkout' );
					var isCalculating  = checkoutStore && typeof checkoutStore.isCalculating === 'function'
						? checkoutStore.isCalculating()
						: false;
					if ( wasCalculating && ! isCalculating ) {
						hideMapLoading();
					}
					wasCalculating = isCalculating;
				} catch ( e ) {
					console.warn( 'Envia pickup map: store subscription error', e );
				}
			} );
		}
		// Note: wc-blocks_shipping_rates_updated listener is registered in
		// EnviaMapAnchor's useEffect, not here, to avoid double-registration.
	}

	// ─── SlotFill components (5.3 & 5.4) ────────────────────────────────────────

	/**
	 * EnviaMapAnchor renders a hidden <span> as a DOM anchor point inside the
	 * ExperimentalOrderLocalPickupPackages slot (pickup tab). The actual map div
	 * is created imperatively with document.createElement so that WC's
	 * React.cloneElement(slotProps) cannot attach slot attributes (cart, extensions,
	 * components, renderPickupLocation, context…) to our map container.
	 *
	 * Any slot props forwarded to this component land on _slotProps and are
	 * intentionally discarded; none reach the span or the map div.
	 */
	function EnviaMapAnchor( _slotProps ) {
		var anchorRef = useRef( null );

		useEffect( function () {
			if ( ! anchorRef.current ) return;

			// Create the map container imperatively — outside React's control.
			// Starting hidden; fetchAndRefreshBranches will show it once data arrives.
			mapContainer = document.createElement( 'div' );
			mapContainer.id = 'envia-pickup-map-container';
			mapContainer.style.display = 'none';

			// Loading overlay — shown during totals recalculation, hidden on AJAX completion
			loadingOverlay = document.createElement( 'div' );
			loadingOverlay.className = 'envia-map-loading-overlay';
			loadingOverlay.style.display = 'none';
			var spinner = document.createElement( 'div' );
			spinner.className = 'envia-map-loading-spinner';
			loadingOverlay.appendChild( spinner );
			mapContainer.appendChild( loadingOverlay );

			// Insert it right after our hidden anchor in the DOM
			anchorRef.current.parentNode.insertBefore(
				mapContainer,
				anchorRef.current.nextSibling
			);

			// Show overlay immediately when the user selects a pickup option from the list.
			// Radio inputs for envia pickup rates have values starting with 'envia-'.
			function onPickupRadioChange( e ) {
				if (
					e.target &&
					e.target.type === 'radio' &&
					typeof e.target.value === 'string' &&
					e.target.value.startsWith( 'envia-' )
				) {
					showMapLoading();
				}
			}
			document.addEventListener( 'change', onPickupRadioChange );

			// 8.1 — Register the rate-recalculation listener BEFORE the first fetch
			// so no update event is missed between mount and the AJAX response.
			document.addEventListener( 'wc-blocks_shipping_rates_updated', fetchAndRefreshBranches );

			// Always fetch from the PHP session on mount.
			// wp_localize_script data (enviaPickupMap.branches) is baked in at PHP
			// render time, before calculate_shipping() runs, so it is always empty.
			// The session key is populated during the Store API shipping calculation
			// which completes before or shortly after our component mounts.
			fetchAndRefreshBranches();

			return function () {
				document.removeEventListener( 'change', onPickupRadioChange );
				document.removeEventListener( 'wc-blocks_shipping_rates_updated', fetchAndRefreshBranches );
				if ( leafletMap ) {
					leafletMap.remove();
					leafletMap = null;
				}
				if ( mapContainer && mapContainer.parentNode ) {
					mapContainer.parentNode.removeChild( mapContainer );
				}
				mapContainer   = null;
				loadingOverlay = null;
			};
		}, [] );

		// Hidden anchor: aria-hidden so screen readers skip it.
		// Even if slot props are cloneElement'd onto this span, it is invisible.
		return createElement( 'span', {
			ref: anchorRef,
			'aria-hidden': 'true',
			style: { display: 'none' },
		} );
	}

	// 5.4 — Plugin render: ExperimentalOrderLocalPickupPackages places content
	//        inside the pickup tab of block checkout.
	function EnviaPickupMapFill() {
		return createElement(
			ExperimentalOrderLocalPickupPackages,
			null,
			createElement( EnviaMapAnchor, null )
		);
	}

	// 5.3 — Register as a WooCommerce block plugin
	registerPlugin( 'envia-pickup-map', {
		scope: 'woocommerce-checkout',
		render: EnviaPickupMapFill,
	} );
} )();
