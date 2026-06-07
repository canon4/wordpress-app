<?php

/**
 * WCFM plugin controllers
 *
 * Plugin Memberships Payment Controller
 *
 * @author 		WC Lovers
 * @package 	wcfmvm/controllers
 * @version   1.0.0
 */

class WCFMvm_Memberships_Payment_Controller {

	public function __construct() {
		global $WCFM, $WCFMu;

		$this->processing();
	}

	public function processing() {
		global $WCFM, $WCFMvm, $wpdb, $wcfm_membership_payment_form_data;

		$wcfm_membership_payment_form_data = array();
		parse_str($_POST['wcfm_membership_payment_form'], $wcfm_membership_payment_form_data);

		$wcfm_membership_payment_form_data = wc_clean($wcfm_membership_payment_form_data);

		$wcfm_membership_payment_messages = get_wcfmvm_membership_payment_messages();
		$has_error = false;

		if (isset($wcfm_membership_payment_form_data['member_id']) && !empty($wcfm_membership_payment_form_data['member_id'])) {
			$member_id 			= absint($wcfm_membership_payment_form_data['member_id']);
			
			if ($member_id && !$this->is_valid_member_id( $member_id )) {
				echo '{"status": false, "message": "' . esc_html__( 'Not a valid member', 'wc-multivendor-membership' ) . '"}';
				die;
			}

			$wcfm_membership	= get_user_meta($member_id, 'temp_wcfm_membership', true);
			$paymode 			= wc_clean($_POST['paymode']);

			$wcfm_membership_payment_methods = array_keys(get_wcfm_membership_payment_methods());

			if ($wcfm_membership) {
				$subscription 	= (array) get_post_meta($wcfm_membership, 'subscription', true);

				/**
				 * 	For Free membership, (paymode == free) is allowed
				 * 	For Paid membership, paymode should be in the list of get_wcfm_membership_payment_methods()
				 */
				if (!in_array($paymode, $wcfm_membership_payment_methods) && !isset( $subscription['is_free'] )) {
					echo '{"status": false, "message": "' . esc_html($wcfm_membership_payment_messages['invalid_payment_method']) . '"}';
					die;
				}

				update_user_meta($member_id, 'wcfm_membership_paymode', $paymode);
				$required_approval = get_post_meta($wcfm_membership, 'required_approval', true) ? get_post_meta($wcfm_membership, 'required_approval', true) : 'no';

				if ($required_approval != 'yes') {
					$has_error = $WCFMvm->register_vendor($member_id);
					$WCFMvm->store_subscription_data($member_id, $paymode, '', 'free_subscription', 'Completed', '');
				} else {
					$WCFMvm->send_approval_reminder_admin($member_id);
				}

				// Reset Membership Session
				if (WC()->session && WC()->session->get('wcfm_membership')) {
					WC()->session->__unset('wcfm_membership');
				}

				if (!$has_error) {
					echo '{"status": true, "message": "' . esc_html($wcfm_membership_payment_messages['subscription_success']) . '", "redirect": "' . esc_url(apply_filters('wcfm_registration_thankyou_url', add_query_arg('vmstep', 'thankyou', get_wcfm_membership_url()))) . '"}';
				} else {
					echo '{"status": false, "message": "' . esc_html($wcfm_membership_payment_messages['subscription_failed']) . '"}';
				}
			} else {
				echo '{"status": false, "message": "' . esc_html($wcfm_membership_payment_messages['no_memberid']) . '"}';
			}
		} else {
			echo '{"status": false, "message": "' . esc_html($wcfm_membership_payment_messages['no_memberid']) . '"}';
		}

		die;
	}

	protected function is_valid_member_id( $member_id ) {
		$is_valid_member = false;

		if ((get_current_user_id() == $member_id) && wcfm_is_allowed_membership()) {
			$is_valid_member = true;
		}

		return $is_valid_member;
	}
}
