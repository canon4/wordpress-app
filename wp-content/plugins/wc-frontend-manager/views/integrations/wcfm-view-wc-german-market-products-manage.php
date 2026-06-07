<?php
/**
 * WCFM plugin view
 *
 * WCFM WC German Market Product Manage View
 *
 * @author 		WC Lovers
 * @package 	wcfm/views/integrations
 * @version   6.4.8
 */

global $wp, $WCFM, $WCFMu, $post, $woocommerce;

$product_id = 0;

$min_qty = 1;
$_unit_regular_price_per_unit = '';
$_auto_ppu_complete_product_quantity = '';
$_unit_regular_price_per_unit_mult = '';
$_age_rating_age = '';

$gpsr_labels = array(
	'ignore_defaults'                 => __( 'Never use default values in frontend if a field is empty', 'woocommerce-german-market' ),
	'manufacturer'                    => get_option('german_market_gpsr_label_manufacturer', __('Manufacturer', 'woocommerce-german-market')),
	'responsible_person'              => get_option('german_market_gpsr_label_responsible_person', __('Responsible person', 'woocommerce-german-market')),
	'warnings_and_safety_information' => get_option('german_market_gpsr_label_warnings_and_safety_information', __('Warnings and safety information', 'woocommerce-german-market')),
);
$gpsr_val = array(
    'ignore_defaults'                 => '',
    'manufacturer'                    => '',
    'responsible_person'              => '',
    'warnings_and_safety_information' => '',
);
if (isset($wp->query_vars['wcfm-products-manage']) && !empty($wp->query_vars['wcfm-products-manage'])) {
	$product_id = absint($wp->query_vars['wcfm-products-manage']);

	if ($product_id) {
		$gpsr_val['ignore_defaults'] = get_post_meta($product_id, '_german_market_gpsr_ignore_defaults', true);
		$gpsr_val['manufacturer'] = get_post_meta($product_id, '_german_market_gpsr_manufacturer', true);
		$gpsr_val['responsible_person'] = get_post_meta($product_id, '_german_market_gpsr_responsible_person', true);	
		$gpsr_val['warnings_and_safety_information'] = get_post_meta($product_id, '_german_market_gpsr_warnings_and_safety_information', true);

		$_unit_regular_price_per_unit = get_post_meta($product_id, '_unit_regular_price_per_unit', true);
		$_auto_ppu_complete_product_quantity = get_post_meta($product_id, '_auto_ppu_complete_product_quantity', true);
		$_unit_regular_price_per_unit_mult = get_post_meta($product_id, '_unit_regular_price_per_unit_mult', true);

		if (get_option('german_market_age_rating', 'off') == 'on') {
			$_age_rating_age = get_post_meta($product_id, '_age_rating_age', true);
		}
	}
}

if ( 
		( 'on' === get_option( 'german_market_gpsr_pre_fill_only_new_producs', 'on' ) && !$product_id ) ||
		( 'off' === get_option( 'german_market_gpsr_pre_fill_only_new_producs', 'on' ) )
) {
	$gpsr_val['manufacturer']                    = empty($gpsr_val['manufacturer']) ? get_option('german_market_gpsr_default_manufacturer', '') : $gpsr_val['manufacturer'];
	$gpsr_val['responsible_person']              = empty($gpsr_val['responsible_person']) ? get_option('german_market_gpsr_default_responsible_person', '') : $gpsr_val['responsible_person'];
	$gpsr_val['warnings_and_safety_information'] = empty($gpsr_val['warnings_and_safety_information']) ? get_option('german_market_gpsr_default_warnings_and_safety_information', '') : $gpsr_val['warnings_and_safety_information'];
}
$gpsr_val['ignore_defaults']    = apply_filters( 'german_market_get_post_meta_value_translatable', $gpsr_val['ignore_defaults'], $product_id, '_german_market_gpsr_ignore_defaults' ) ? 'yes' : 'no';
$gpsr_val['manufacturer']       = apply_filters( 'german_market_get_post_meta_value_translatable', $gpsr_val['manufacturer'], $product_id, '_german_market_gpsr_manufacturer' );
$gpsr_val['responsible_person'] = apply_filters( 'german_market_get_post_meta_value_translatable', $gpsr_val['responsible_person'], $product_id, '_german_market_gpsr_responsible_person' );

$regular_price_units = array();

$default_product_attributes = WGM_Defaults::get_default_product_attributes();
$attribute_taxonomy_name    = wc_attribute_taxonomy_name($default_product_attributes[0]['attribute_name']);
$terms                      = get_terms($attribute_taxonomy_name, 'orderby=name&hide_empty=0');

// fallback to depcracted bug
if (empty($terms) || is_wp_error($terms)) {
	$attribute_taxonomy_name    = 'pa_masseinheit';
	$terms                      = get_terms($attribute_taxonomy_name, 'orderby=name&hide_empty=0');
}
if (is_array($terms) && ! empty($terms)) {
	foreach ($terms as $value) {
		$regular_price_units[esc_attr($value->name)] = ! empty($value->description) ? esc_attr($value->description) : esc_attr(__('Fill in attribute description!', 'woocommerce-german-market'));
	}
}

?>
<div class="page_collapsible products_manage_wc_german_market simple variable" id="wcfm_products_manage_form_wc_german_market_gpsr_head"><label class="wcfmfa fa-user-shield"></label> &nbsp;<?php _e('GPSR'); ?><span></span></div>
<div class="wcfm-container simple variable">
	<div id="wcfm_products_manage_form_wc_german_market_gpsr_expander" class="wcfm-content">
		<?php
		$rich_editor = apply_filters( 'wcfm_is_allow_rich_editor', 'rich_editor' );
		$wpeditor = apply_filters( 'wcfm_is_allow_product_wpeditor', 'wpeditor' );
		if( $wpeditor && $rich_editor ) {
			$rich_editor = 'wcfm_wpeditor';
		} else {
			$wpeditor = 'textarea';
		}
		$wcfm_wc_german_market_gpsr_fields = apply_filters('wcfm_wc_german_market_gpsr_fields', array(
			"_german_market_gpsr_ignore_defaults" => array('label' => $gpsr_labels['ignore_defaults'], 'type' => 'checkbox', 'class' => 'wcfm-checkbox simple variable', 'label_class' => 'wcfm_title', 'value' => 'yes', 'dfvalue' => $gpsr_val['ignore_defaults']),
			"_german_market_gpsr_manufacturer" => array('label' => $gpsr_labels['manufacturer'], 'type' => 'textarea', 'class' => 'wcfm-textarea field_type_select_options', 'label_class' => 'wcfm_title', 'hint' => __( 'Name, trademark, address and electronic address (url of the website or e-mail address)', 'woocommerce-german-market' ), 'value' => $gpsr_val['manufacturer']),
			"_german_market_gpsr_responsible_person" => array('label' => $gpsr_labels['responsible_person'], 'type' => 'textarea', 'class' => 'wcfm-textarea field_type_select_options', 'label_class' => 'wcfm_title', 'hint' => __( 'If the manufacturer is not established in the EU: name, address and electronic address (url of the website or e-mail address) of an economic operator established in the EU who is responsible for the manufacturer\'s obligations.', 'woocommerce-german-market' ), 'value' => $gpsr_val['responsible_person']),
			"_german_market_gpsr_warnings_and_safety_information" => array('label' => $gpsr_labels['warnings_and_safety_information'], 'type' => $wpeditor, 'class' => 'wcfm-textarea wcfm_custom_field_editor ' . $rich_editor, 'label_class' => 'wcfm_title', 'value' => $gpsr_val['warnings_and_safety_information'] ),
		), $product_id);

		$WCFM->wcfm_fields->wcfm_generate_form_field($wcfm_wc_german_market_gpsr_fields);
		?>
		<div class="wcfm-clearfix"></div><br />
	</div>
</div>

<div class="page_collapsible products_manage_wc_german_market simple variable" id="wcfm_products_manage_form_wc_german_markethead"><label class="wcfmfa fa-dollar-sign"></label><?php _e('Price per Unit', 'woocommerce-german-market'); ?><span></span></div>
<div class="wcfm-container simple variable">
	<div id="wcfm_products_manage_form_wc_german_market_expander" class="wcfm-content">
		<?php
		$wcfm_wc_german_market_fields = apply_filters('wcfm_wc_german_market_fields', array(
			"_unit_regular_price_per_unit" => array('label' => __('Scale Unit', 'woocommerce-german-market'), 'type' => 'select', 'options' => $regular_price_units, 'class' => 'wcfm-select wcfm_ele simple variable', 'label_class' => 'wcfm_title simple variable', 'value' => $_unit_regular_price_per_unit),
			"_auto_ppu_complete_product_quantity" => array('label' => __('Complete product quantity', 'woocommerce-german-market'), 'type' => 'number', 'class' => 'wcfm-text wcfm_ele simple variable wcfm_non_negative_input', 'label_class' => 'wcfm_title simple variable', 'value' => $_auto_ppu_complete_product_quantity),
			"_unit_regular_price_per_unit_mult" => array('label' => __('Quantity to display', 'woocommerce-german-market'), 'type' => 'number', 'class' => 'wcfm-text wcfm_ele simple variable wcfm_non_negative_input', 'label_class' => 'wcfm_title simple variable', 'value' => $_unit_regular_price_per_unit_mult),
		), $product_id);

		$WCFM->wcfm_fields->wcfm_generate_form_field($wcfm_wc_german_market_fields);
		?>
		<div class="wcfm-clearfix"></div><br />
	</div>
</div>

<?php if (get_option('german_market_age_rating', 'off') == 'on') { ?>
	<div class="page_collapsible products_manage_wc_german_market simple variable" id="wcfm_products_manage_form_wc_german_market_age_restriction_head"><label class="wcfmfa fa-address-card"></label><?php _e('Age Rating', 'woocommerce-german-market'); ?><span></span></div>
	<div class="wcfm-container simple variable">
		<div id="wcfm_products_manage_form_wc_german_market_age_restriction_expander" class="wcfm-content">
			<?php
			$wcfm_wc_german_market_fields = apply_filters('wcfm_wc_german_market_age_restriction_fields', array(
				"_age_rating_age" => array('label' => __('Required age to buy this product', 'woocommerce-german-market') . ' (' . __('Years', 'woocommerce-german-market') . ')', 'type' => 'number', 'class' => 'wcfm-text wcfm_ele simple variable wcfm_non_negative_input', 'label_class' => 'wcfm_title simple variable', 'value' => $_age_rating_age),
			), $product_id);

			$WCFM->wcfm_fields->wcfm_generate_form_field($wcfm_wc_german_market_fields);
			?>
			<div class="wcfm-clearfix"></div><br />
		</div>
	</div>
<?php } ?>