<?php

/**
 * WCFM plugin controllers
 *
 * Plugin WCfM Marketplace Withdrawal Request Approve Controller
 *
 * @author 		WC Lovers
 * @package 	wcfm/controllers/withdrawal/wcfm
 * @version   5.0.0
 */

class WCFM_Withdrawal_Requests_Manually_Approve_Controller {

	protected $default_payment_method = '';

	public function __construct() {
		$this->default_payment_method = apply_filters( 'wcfm_withdrawal_requests_manually_approve_default_payment_method', 'bank_transfer' );

		$this->processing();
	}

	public function processing() {
		global $wpdb, $_POST, $WCFMmp;

		$wcfm_withdrawal_manage_form_data = array();
		parse_str($_POST['wcfm_withdrawal_manage_form'], $wcfm_withdrawal_manage_form_data);

		if (isset($wcfm_withdrawal_manage_form_data['withdrawals']) && !empty($wcfm_withdrawal_manage_form_data['withdrawals'])) {
			$withdrawals   = $wcfm_withdrawal_manage_form_data['withdrawals'];
			$withdraw_note = wcfm_stripe_newline(wp_filter_post_kses($wcfm_withdrawal_manage_form_data['withdraw_note']));
			$withdraw_note = esc_sql($withdraw_note);

			// WCFM form custom validation filter
			$custom_validation_results = apply_filters('wcfm_form_custom_validation', $wcfm_withdrawal_manage_form_data, 'withdrawal_requests_manage_manually');
			if (isset($custom_validation_results['has_error']) && !empty($custom_validation_results['has_error'])) {
				$custom_validation_error = __('There has some error in submitted data.', 'wc-frontend-manager');
				if (isset($custom_validation_results['message']) && !empty($custom_validation_results['message'])) {
					$custom_validation_error = $custom_validation_results['message'];
				}
				wp_send_json_error([
					'message'	=> esc_html($custom_validation_error)
				]);
			}

			$add_payment_method = false;
			if (!array_key_exists($this->default_payment_method, $WCFMmp->wcfmmp_gateways->payment_gateways)) {
				$WCFMmp->wcfmmp_gateways->payment_gateways[$this->default_payment_method] = __('Manual Payment', 'wc-frontend-manager');
				$add_payment_method = true;

				$gateway = 'WCFMmp_Gateway_' . ucfirst($this->default_payment_method);
				if (!class_exists($gateway)) {
					$WCFMmp->wcfmmp_gateways->load_gateway($this->default_payment_method);
				}
				$WCFMmp->wcfmmp_gateways->payment_gateways[$this->default_payment_method] = new $gateway();
			}

			$withdrawal_update_status = true;
			foreach ($withdrawals as $withdrawal_id) {
				$sql = 'SELECT vendor_id, payment_method, withdraw_amount, withdraw_charges FROM ' . $wpdb->prefix . 'wcfm_marketplace_withdraw_request';
				$sql .= ' WHERE 1=1';
				$sql .= " AND ID = %d";
				$withdrawal_infos = $wpdb->get_results($wpdb->prepare($sql, $withdrawal_id));
				if (!empty($withdrawal_infos)) {
					foreach ($withdrawal_infos as $withdrawal_info) {
						$vendor_id = $withdrawal_info->vendor_id;
						$payment_method = $this->default_payment_method;
						$withdraw_amount = $withdrawal_info->withdraw_amount;
						$withdraw_charges = $withdrawal_info->withdraw_charges;

						$payment_processesing_status = $WCFMmp->wcfmmp_withdraw->wcfmmp_withdrawal_payment_processesing($withdrawal_id, $vendor_id, $payment_method, $withdraw_amount, $withdraw_charges, $withdraw_note);

						if (!$payment_processesing_status) {
							$withdrawal_update_status = false;
						} else {
							$WCFMmp->wcfmmp_withdraw->wcfmmp_update_withdrawal_meta( $withdrawal_id, 'is_manually_approved', 'yes' );
							$WCFMmp->wcfmmp_withdraw->wcfmmp_update_withdrawal_meta( $withdrawal_id, 'manually_approved_by_user', get_current_user_id() );
							$WCFMmp->wcfmmp_withdraw->wcfmmp_update_withdrawal_meta( $withdrawal_id, 'used_manual_payment_method', $payment_method );

							if( apply_filters( 'wcfm_should_update_original_payment_method_for_withdrawal_requests_manually_approve', false ) ) {
								$wpdb->update(
									"{$wpdb->prefix}wcfm_marketplace_withdraw_request", 
									['payment_method' => $payment_method], 
									['ID' => $withdrawal_id], 
									['%s'], 
									['%d']
								);
							}

							do_action( 'wcfm_withdrawal_requests_manually_approval_complete', $withdrawal_id );
						}
					}
				}
			}

			if( $add_payment_method ) {
				unset( $WCFMmp->wcfmmp_gateways->payment_gateways[$this->default_payment_method] );
			}

			if( $withdrawal_update_status ) {
				wp_send_json_success([
					'message' => esc_html( __('Withdrawal Requests successfully processed.', 'wc-frontend-manager') )
				]);
			} else {
				wp_send_json_error([
					'message' => esc_html( __('Withdrawal Requests partially processed, check log for more details.', 'wc-frontend-manager') )
				]);
			}
		} else {
			wp_send_json_error([
				'message' => esc_html( __('No withdrawals selected for approval.', 'wc-frontend-manager') )
			]);
		}
	}
}

class WCFM_Withdrawal_Requests_Approve_Controller {

	public function __construct() {
		global $WCFM;

		$this->processing();
	}

	public function processing() {
		global $WCFM, $wpdb, $_POST, $WCFMmp;

		$wcfm_withdrawal_manage_form_data = array();
		parse_str($_POST['wcfm_withdrawal_manage_form'], $wcfm_withdrawal_manage_form_data);

		$commissions = array();
		if (isset($wcfm_withdrawal_manage_form_data['withdrawals']) && !empty($wcfm_withdrawal_manage_form_data['withdrawals'])) {
			$withdrawals   = $wcfm_withdrawal_manage_form_data['withdrawals'];
			$withdraw_note = wcfm_stripe_newline(wp_filter_post_kses($wcfm_withdrawal_manage_form_data['withdraw_note']));
			$withdraw_note = esc_sql($withdraw_note);

			// WCFM form custom validation filter
			$custom_validation_results = apply_filters('wcfm_form_custom_validation', $wcfm_withdrawal_manage_form_data, 'withdrawal_requests_manage');
			if (isset($custom_validation_results['has_error']) && !empty($custom_validation_results['has_error'])) {
				$custom_validation_error = __('There has some error in submitted data.', 'wc-frontend-manager');
				if (isset($custom_validation_results['message']) && !empty($custom_validation_results['message'])) {
					$custom_validation_error = $custom_validation_results['message'];
				}
				echo '{"status": false, "message": "' . esc_html($custom_validation_error) . '"}';
				die;
			}

			$withdrawal_update_status = true;
			foreach ($withdrawals as $withdrawal_id) {
				$sql = 'SELECT vendor_id, payment_method, withdraw_amount, withdraw_charges FROM ' . $wpdb->prefix . 'wcfm_marketplace_withdraw_request';
				$sql .= ' WHERE 1=1';
				$sql .= " AND ID = %d";
				$withdrawal_infos = $wpdb->get_results($wpdb->prepare($sql, $withdrawal_id));
				if (!empty($withdrawal_infos)) {
					foreach ($withdrawal_infos as $withdrawal_info) {
						$vendor_id = $withdrawal_info->vendor_id;
						$payment_method = $withdrawal_info->payment_method;
						$withdraw_amount = $withdrawal_info->withdraw_amount;
						$withdraw_charges = $withdrawal_info->withdraw_charges;
						$payment_processesing_status = $WCFMmp->wcfmmp_withdraw->wcfmmp_withdrawal_payment_processesing($withdrawal_id, $vendor_id, $payment_method, $withdraw_amount, $withdraw_charges, $withdraw_note);
						if (!$payment_processesing_status)
							$withdrawal_update_status = false;
					}
				}
			}
			if ($withdrawal_update_status) {
				echo '{"status": true, "message": "' . esc_html(__('Withdrawal Requests successfully processed.', 'wc-frontend-manager')) . '"}';
			} else {
				echo '{"status": false, "message": "' . esc_html(__('Withdrawal Requests partially processed, check log for more details.', 'wc-frontend-manager')) . '"}';
			}
		} else {
			echo '{"status": false, "message": "' . esc_html(__('No withdrawals selected for approval.', 'wc-frontend-manager')) . '"}';
		}

		die;
	}
}

class WCFM_Withdrawal_Requests_Cancel_Controller {

	public function __construct() {
		global $WCFM;

		$this->processing();
	}

	public function processing() {
		global $WCFM, $wpdb, $_POST, $WCFMmp;

		$wcfm_withdrawal_manage_form_data = array();
		parse_str($_POST['wcfm_withdrawal_manage_form'], $wcfm_withdrawal_manage_form_data);

		$commissions = array();
		if (isset($wcfm_withdrawal_manage_form_data['withdrawals']) && !empty($wcfm_withdrawal_manage_form_data['withdrawals'])) {
			$withdrawals   = $wcfm_withdrawal_manage_form_data['withdrawals'];
			$withdraw_note = wcfm_stripe_newline(wp_filter_post_kses($wcfm_withdrawal_manage_form_data['withdraw_note']));
			$withdraw_note = esc_sql($withdraw_note);

			// WCFM form custom validation filter
			$custom_validation_results = apply_filters('wcfm_form_custom_validation', $wcfm_withdrawal_manage_form_data, 'withdrawal_manage');
			if (isset($custom_validation_results['has_error']) && !empty($custom_validation_results['has_error'])) {
				$custom_validation_error = __('There has some error in submitted data.', 'wc-frontend-manager');
				if (isset($custom_validation_results['message']) && !empty($custom_validation_results['message'])) {
					$custom_validation_error = $custom_validation_results['message'];
				}
				echo '{"status": false, "message": "' . $custom_validation_error . '"}';
				die;
			}

			$order_ids = '';
			$commission_ids = '';
			$total_commission = 0;

			foreach ($withdrawals as $withdrawal_id) {
				// Update withdrawal status
				$WCFMmp->wcfmmp_withdraw->wcfmmp_withdraw_status_update_by_withdrawal($withdrawal_id, 'cancelled', $withdraw_note);

				do_action('wcfmmp_withdrawal_request_cancelled', $withdrawal_id);
			}
			echo '{"status": true, "message": "' . esc_html(__('Withdrawal Requests successfully cancelled.', 'wc-frontend-manager')) . '"}';
		} else {
			echo '{"status": false, "message": "' . esc_html(__('No withdrawals selected for cancel.', 'wc-frontend-manager')) . '"}';
		}

		die;
	}
}
