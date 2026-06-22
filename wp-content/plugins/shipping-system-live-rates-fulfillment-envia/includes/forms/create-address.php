<div class='modal-address'>
	<div style = 'margin:12px'>
		<img style='padding-left:4px;' height="30" src=<?php echo '../wp-content/plugins/' . esc_html( basename( \Enviacom::MDABSPATH ) ) . '/admin/images/envia-logo-dark.svg'; ?> >
	</div>
	<hr>
	<h2 style="text-align:left; font-size: 1.1em;"> Create a new origin address </h2>
	<table class= "form-table" id="form-add-origin" value=<?php echo esc_html( $nonce ); ?>>
		<tr>
			<td class="envia-label" scope="row">
				<label for='envia-name'> Name <span>*</span> </label>
			</td>
			<td class="envia-input">
				<input type="text" name='name' placeholder= 'Name*' id='envia-address-name'>
			</td>
		</tr>
		<tr>
			<td class="envia-label">
				<label for='envia-company'> Company </label>
			</td>
			<td class="envia-input">
				<input type="text" name='company' placeholder= 'Company' id='envia-address-company' >
			</td>
		</tr>
		<tr>
			<td scope="row" class="envia-label">
				<label for='envia-email'> Email </label>
			</td>
			<td class="envia-input">
				<input type="text" name='email' placeholder= 'Email' id='envia-address-email' >
			</td>
		</tr>
		<tr>
			<td scope="row" class="envia-label">
				<label for='envia-phone'> Phone <span>*</span></label>
			</td>
			<td class="envia-input">
				<input type="text" name='phone' placeholder= 'Phone*' id='envia-address-telefono' >
			</td>
		</tr>
		<tr>
			<td scope="row" class="envia-label" >
				<label for='envia-email'> Country </label>
			</td>
			<td class="envia-input e-i-2">
				<select type="select" name='country' id='envia-address-country' >
					<option value="default"> Select country </option>
					<?php
					$countries = WC()->countries->get_countries();
					foreach ( $countries as $key => $country ) {
						echo "<option value ='" . esc_html( $key ) . "'>" . esc_html( $country ) . '</option>';
					}
					wp_enqueue_script( 'formAddOrigin', plugins_url( '../../admin/js/formAddOrigin.js', __FILE__ ), array(), '1.6.2', false );
					wp_localize_script(
						'formAddOrigin',
						'formConfig',
						array(
							'cityType' => $this->get_option( 'cityType' ),
						)
					);
					?>
				</select>
			</td>
		</tr>
	</table>
	<hr>
	<span id='envia-show-error'></span>
	<div class= 'area-buttons'>
		<button type='button' name= '' class= 'custom-option-btn cus-m-b close-option envia-open-origin' >Cancel</button>
		<button type='button' id= 'envia-save-address' class= 'custom-option-btn cus-m-b create-option' id='' >Save</button>
	</div>
</div>
