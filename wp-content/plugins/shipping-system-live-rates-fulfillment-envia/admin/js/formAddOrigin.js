jQuery(document).ready(function() {
	getCountryAddressForm()
	jQuery('.envia-open-origin').click(function(){
		let modal = document.getElementById('modal-envia')
		let classes = Array.from( modal.classList );
		let show = classes.some( cls => cls == 'hidden');
		if( show ) {
			modal.classList.remove('hidden');
		}else {
			modal.classList.add('hidden');
		}
	})
	jQuery('#envia-save-address').click( function() {
		let fields = Array.from( document.getElementsByClassName('envia-input') );
		validate = validateFields( fields );
		if( validate.length == 0 ){
			saveAddress( fields );
		}
		else{
			showFrontErrors( validate );
		}
	} )
})

function getCountryAddressForm() {
	let countrySelect = document.getElementById("envia-address-country");
	countrySelect.addEventListener("change", function(event) {
		let country = this.options[this.selectedIndex].value;
		jQuery.ajax({
			url : window.location.pathname?.split('/wp-admin')?.[0] + '/wp-admin/admin-ajax.php',
			method: 'GET',
			data: { 'action': 'envia_forms', 'country': country },
			dataType : 'json'
		})
		.done( function ( response ) {
			let enviaRows = document.getElementsByClassName('envia-row') || null;
			if( enviaRows ){
				Array.from( enviaRows ).forEach( row => row.remove() );
			}
			let formOrigin = document.getElementById('form-add-origin');
			response.form.forEach( element => {
				let fieldLabel;
				let fieldType = element.fieldType 
				let input = 'select' == fieldType ? document.createElement('select') : document.createElement('input');
				input.type = fieldType;
				let label = document.createElement('label');
				let span = document.createElement('span');
				span.innerText = '*';
				label.innerText = element.fieldLabel;
				//if( requiredMark.some( mark => mark == element.fieldLabel) ) {
				if ( element.rules?.required ) {
					label.innerHTML = element.fieldLabel + '<span class="envia-req">*</span>';
					fieldLabel = element.fieldLabel + ' *';
					input.required = true;
				}
				if( element.fieldLabel == 'City' ) {
					// if( formConfig.cityType == 'select' && element.fieldType == 'select')
					if(element.fieldType == 'text'){
						// input = document.createElement( 'input' );
						element.fieldId = 'city'
					}else {
						return;
					}
				}
				if( element.fieldLabel == 'Neighborhood' && element.fieldType == 'select') {
					return;
				}
				if( element.fieldLabel == 'State' ) {
					let option = document.createElement('option');
					option.text = input.required ? 'Select state *' : 'Select state';
					option.value = 'default';
					input.options.add( option, option[0]);
					Object.keys(response.states).forEach( ( state, index ) => {
						option = document.createElement('option')
						option.text = response.states[`${state}`];
						option.value = state
						input.options.add( option, option[index +1] );
						input.name = 'state';
					})
				}

				input.id = "envia-"+element.fieldId;
				input.name = input.name ? input.name : element.fieldName;
				input.placeholder = fieldLabel || element.fieldLabel;
				let newRow = formOrigin.insertRow(-1);
				let newCell1 = newRow.insertCell(0);
				let newCell2 = newRow.insertCell(1);
				newCell1.appendChild( label );
				newCell2.appendChild( input );
				newRow.className = 'envia-row';
				newCell1.className = 'envia-label';
				newCell2.className = 'envia-input';
			})
		})
		.fail( function (error) {
			let outputError = document.getElementById('envia-show-error');
			outputError.innerText = error.responseJSON ? error.responseJSON.message : error.statusText;
		})
	})
}

function validateFields(fields) {
	let requiredFields = [
		'envia-address-name',
		'envia-address-telefono',
		'envia-address-country',	
	];
	let spans = document.getElementsByClassName("envia-span-error");
	let errorClass = document.getElementsByClassName('envia-input-error');
	let fieldErrors = [];
	let reg = /^\d+$/;
	if(fields.length <= 5){
		fieldErrors.push( {'id' : 'envia-show-error', 'message' : 'Please, complete the form required fields'} )
	}
	fields.forEach(element => {
		let input =  element.children[0];
		let forceRequired = requiredFields.some( id => id == input.id ); 
		if ( input.required || forceRequired ) {
			if( input.id == 'envia-address-telefono' ) {
				if( input.value.length <= 7 ) {
					fieldErrors.push( { 'id' : input.id, 'message' : 'Phone must have at least 8 characters'} )
					return;
				}else if( !reg.test( input.value ) && input.value.length >= 10) {
					fieldErrors.push( { 'id' : input.id, 'message' : 'Phone must have only numbers'} )
					return;
				}else if( input.value.length > 12 ) {
					fieldErrors.push( { 'id' : input.id, 'message' : 'Phone must have at maximum 12 characters'} )
					return;
				}
			} 
			if( input.value.length == 0 || input.value == 'default' ) {
				fieldErrors.push( { 'id' : input.id, 'message' : 'Required'} )
			}
		}
	})
	if( spans.length > 0 ){
		Array.from(spans).forEach( span => span.remove())
	}
	if( errorClass.length > 0 ){
		Array.from(errorClass).forEach( input => input.removeAttribute('class'))
	}
	return fieldErrors
}

function showFrontErrors( errors ) {
	errors.forEach( error => {
		if( error.id == 'envia-show-error' ){
			let outputError = document.getElementById('envia-show-error');
			outputError.innerText = error.message;
		}
		else{		
			let span = document.createElement('span');
			span.innerText = error.message;
			span.className = 'envia-span-error'
			let input = document.getElementById(`${ error.id }`);
			input.parentElement.appendChild( span )
			input.className = "envia-input-error"
		}
	})
	return;
}

function saveAddress( fields ) {
	let data = {};
	fields.forEach( field => {
		data[`${field.children[0].name}`] = field.children[0].value
	});
	data = JSON.stringify( data );
	let nonce = document.getElementById('form-add-origin').attributes.value.value;
	jQuery.ajax({
		url : window.location.pathname?.split('/wp-admin')?.[0] + '/wp-admin/admin-ajax.php',
		method: 'POST',
		data: { 'action': 'envia_save', 'form': data, '_wpnonce': nonce },
		dataType : 'json',
		beforeSend: function() {
			document.getElementById("saving-origin").style.display="block"
			document.getElementsByClassName('modal-address')[0].style.display = 'none';
		}
	})
	.done( function ( response ) {
		if( response.status == 'success' ){
			let spinGif = document.getElementById("saving-origin");
			let successGif = document.getElementById('check-origin');
			spinGif.children[0].classList.remove('lds-dual-ring');
			successGif.style.display = 'block';
			spinGif.children[1].innerText = 'Saved'
			setTimeout(function(){
				window.onbeforeunload = null;
				window.location.reload();
				successGif.style.display = 'none';
				spinGif.children[0].classList.add('lds-dual-ring');
				spinGif.children[1].innerText = 'Reloading'
			},700);
		}
	})
	.fail( function ( error ) {
		document.getElementById("saving-origin").style.display = 'none';
		document.getElementsByClassName('modal-address')[0].style.display = 'block';
		let outputError = document.getElementById('envia-show-error');
		outputError.innerText = error.responseJSON.message || error.responseText || "unknow error";
	})
}

function clean_envia_form( $fields ){
	fields.forEach( field => {
		if(field.children[0].type == 'text' ){
			field.children[0].value = null;
		}else{
			field.children[0].value = 'default';
		}
	});
}