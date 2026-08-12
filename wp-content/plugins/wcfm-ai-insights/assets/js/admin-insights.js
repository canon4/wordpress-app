/* global jQuery, wcfmAIInsights */
( function ( $ ) {
	'use strict';

	function escapeHtml( s ) {
		return $( '<div>' ).text( s == null ? '' : s ).html();
	}

	function fmtMoney( n ) {
		if ( n === null || n === undefined ) return 'n/d';
		return Number( n ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	function fmtPct( n ) {
		if ( n === null || n === undefined ) return 'n/d';
		var sign = n > 0 ? '+' : '';
		return sign + n + '%';
	}

	function showError( msg ) {
		$( '#wcfm_ai_insights_error' ).show().find( 'p' ).text( msg );
	}

	function clearError() {
		$( '#wcfm_ai_insights_error' ).hide().find( 'p' ).text( '' );
	}

	function currentVendor() {
		return $( '#wcfm_ai_insights_vendor' ).val();
	}

	function currentPeriod() {
		return $( '#wcfm_ai_insights_period' ).val();
	}

	function renderTable( products ) {
		var $tbody = $( '#wcfm_ai_insights_table tbody' ).empty();
		if ( ! products || ! products.length ) {
			$tbody.append( '<tr><td colspan="7">Sin ventas registradas en este periodo.</td></tr>' );
			return;
		}
		products.forEach( function ( p ) {
			var pctClass = '';
			if ( p.pct_change_revenue > 0 ) pctClass = 'positive';
			else if ( p.pct_change_revenue < 0 ) pctClass = 'negative';

			var row = $( '<tr>' );
			row.append( $( '<td>' ).text( p.product_name ) );
			row.append( $( '<td>' ).text( p.units_sold ) );
			row.append( $( '<td>' ).text( fmtMoney( p.revenue ) ) );
			row.append( $( '<td>' ).text( p.prior_period_units + ' u. / ' + fmtMoney( p.prior_period_revenue ) ) );
			row.append( $( '<td class="' + pctClass + '">' ).text( fmtPct( p.pct_change_revenue ) ) );
			row.append( $( '<td>' ).text( p.current_stock === null ? 'n/d' : p.current_stock ) );
			row.append( $( '<td>' ).text( p.velocity_days_of_stock === null ? 'n/d' : p.velocity_days_of_stock ) );
			$tbody.append( row );
		} );
	}

	function renderRisk( risk ) {
		var $box = $( '#wcfm_ai_insights_risk_content' ).empty();
		var hasAny = ( risk.stockout_risk && risk.stockout_risk.length ) || ( risk.stale && risk.stale.length );
		$( '#wcfm_ai_insights_risk' ).toggle( hasAny );
		if ( ! hasAny ) return;

		if ( risk.stockout_risk && risk.stockout_risk.length ) {
			var $u = $( '<ul class="wcfm-ai-risk-list">' );
			$u.append( '<li><strong>Riesgo de quiebre de stock (&lt; 7 días):</strong></li>' );
			risk.stockout_risk.forEach( function ( p ) {
				$u.append( $( '<li>' ).text( p.product_name + ' — ' + p.velocity_days_of_stock + ' días restantes' ) );
			} );
			$box.append( $u );
		}
		if ( risk.stale && risk.stale.length ) {
			var $u2 = $( '<ul class="wcfm-ai-risk-list">' );
			$u2.append( '<li><strong>Estancados (con stock, sin ventas recientes):</strong></li>' );
			risk.stale.forEach( function ( p ) {
				$u2.append( $( '<li>' ).text( p.product_name + ' — stock: ' + p.current_stock ) );
			} );
			$box.append( $u2 );
		}
	}

	function renderRecommendations( payload ) {
		var $box = $( '#wcfm_ai_insights_rec_content' ).empty();
		var data = payload.data || {};
		var recs = data.recommendations || [];

		var meta = payload.from_cache ? 'Desde caché' : 'Recién generada';
		if ( payload.generated_at ) {
			meta += ' — ' + new Date( payload.generated_at * 1000 ).toLocaleString();
		}
		$( '#wcfm_ai_insights_rec_meta' ).text( meta );

		if ( data.summary_insight ) {
			$box.append( $( '<p>' ).append( $( '<strong>' ).text( data.summary_insight ) ) );
		}

		recs.forEach( function ( r ) {
			var confClass = 'confidence-' + ( r.confidence || 'media' );
			var $card = $( '<div class="wcfm-ai-rec-card ' + confClass + '">' );
			$card.append( $( '<h4>' ).text( '[' + ( r.action_type || '' ) + '] Producto #' + r.product_id ) );
			$card.append( $( '<p>' ).text( r.recommendation || '' ) );
			$card.append( $( '<p class="rationale">' ).text( ( r.rationale || '' ) + ' (confianza: ' + ( r.confidence || 'n/d' ) + ')' ) );
			$box.append( $card );
		} );

		$( '#wcfm_ai_insights_recommendations' ).show();
	}

	function loadSummary() {
		var vendor = currentVendor();
		if ( ! vendor ) return;
		clearError();
		$( '#wcfm_ai_insights_loading' ).show();

		$.ajax( {
			url: wcfmAIInsights.restUrl + 'summary',
			method: 'GET',
			data: { vendor_id: vendor, period: currentPeriod() },
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', wcfmAIInsights.nonce );
			}
		} ).done( function ( res ) {
			renderTable( res.summary.products );
			renderRisk( res.risk );
			$( '#wcfm_ai_insights_recommend_btn' ).prop( 'disabled', false );
		} ).fail( function ( xhr ) {
			showError( ( xhr.responseJSON && xhr.responseJSON.message ) || 'Error al cargar métricas.' );
		} ).always( function () {
			$( '#wcfm_ai_insights_loading' ).hide();
		} );
	}

	function generateRecommendation( forceRefresh ) {
		var vendor = currentVendor();
		if ( ! vendor ) return;
		clearError();
		$( '#wcfm_ai_insights_loading' ).show();

		$.ajax( {
			url: wcfmAIInsights.restUrl + 'recommendations',
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify( {
				vendor_id: parseInt( vendor, 10 ),
				period_days: parseInt( currentPeriod(), 10 ),
				force_refresh: !! forceRefresh
			} ),
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', wcfmAIInsights.nonce );
			}
		} ).done( function ( res ) {
			renderRecommendations( res );
			$( '#wcfm_ai_insights_refresh_btn' ).prop( 'disabled', false );
		} ).fail( function ( xhr ) {
			showError( ( xhr.responseJSON && xhr.responseJSON.message ) || 'Error al generar la recomendación.' );
		} ).always( function () {
			$( '#wcfm_ai_insights_loading' ).hide();
		} );
	}

	$( function () {
		$( '#wcfm_ai_insights_load_btn' ).on( 'click', loadSummary );
		$( '#wcfm_ai_insights_recommend_btn' ).on( 'click', function () {
			generateRecommendation( false );
		} );
		$( '#wcfm_ai_insights_refresh_btn' ).on( 'click', function () {
			generateRecommendation( true );
		} );
	} );
} )( jQuery );
