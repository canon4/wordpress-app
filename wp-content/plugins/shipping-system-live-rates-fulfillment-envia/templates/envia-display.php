<div class='envia-display'>
	<?php
	$statusOauth = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : null;
	if ( 'true' == $statusOauth ) :
		$enviaUrl = $args['enviaUrl'] . '?';
		$params = array(
			'hash' => isset( $args['hash'] ) ? sanitize_text_field( $args['hash'] ) : null,
			'ids' => isset( $_GET['ids'] ) ? sanitize_text_field( $_GET['ids'] ) : null,
			'id' => isset( $_GET['id'] ) ? sanitize_text_field( $_GET['id'] ) : null,
		);
		foreach ( $params as $key => $param ) {
			$enviaUrl .= ! is_null( $param ) ? '&' . $key . '=' . $param : '';
		}
		?>
		<iframe class= 'envia-frame' allow="fullscreen" src = <?php echo esc_url( $enviaUrl ); ?> ></iframe>
	<?php
		elseif ( 'false' == $statusOauth ) :
			$message = isset( $args['message'] ) ? sanitize_text_field( $args['message'] ) : null;
			?>
			<h2> <?php echo esc_html( $message ); ?> </h2>
			<div class='envia-connection'  >
				<div id='div-envia-oauth' class = 'connection-block'>
					<p>Connect your store</p>
					<input type='button' id ='envia_oauth' class='oauth-btn'>
				</div>
				<div class='connection-block'>
					<p>Connection status</p>
					<div id='status-label'><div id='status-icon'></div><label id='status-msj'></label><a href=''>Something wrong?</a></div>
				</div>
			</div>
			<div class = 'modal-envia hidden' id= 'modal-envia-oauth'>
				<div class ='saving-block' id = 'saving-oauth'>
					<div class="lds-dual-ring">
						<svg id = 'check-oauth' class="animated-check" viewBox="0 0 24 24"><path d="M4.1 12.7L9 17.6 20.3 6.3" fill="none"></path> </svg>
					</div>
					<p>Waiting to Envia.com</p>
				</div>
			</div>
	<?php 
		elseif ( 'unauthorized' == $statusOauth ) :
			$message = isset( $args['message'] ) ? sanitize_text_field( $args['message'] ) : null;
			?>
			<h2> <?php echo esc_html( $message ); ?> </h2>
	<?php endif; ?>
</div>
<style type="text/css">
	#wpcontent {
		padding-left: 0;
	}
</style>
