<?php

use Stripe\Stripe;
use \Stripe\StripeClient;

class WCFM_Stripe_Connect_Client {

    protected $client_id;
    protected $secret_key;
    protected $stripe;
    protected $user_id;

    public function __construct($client_id = "", $secret_key = "") {
        $this->client_id = $client_id;
        $this->secret_key = $secret_key;
        // $this->stripe = new StripeClient($secret_key);
        $this->stripe = new StripeClient([
            'client_id' => $client_id,
            'api_key'   => $secret_key,
        ]);
    }

    public function set_user_id( $user_id ) {
        $this->user_id = $user_id;
    }

    public function is_connected_to_stripe() {
        $is_connected = false;

        $admin_client_id = get_user_meta($this->user_id, 'admin_client_id', true);

        // Check if current_client_id matches the client id in DB
        $this->verify_stripe_credentials();
        
        $stripe_user_id = get_user_meta($this->user_id, 'stripe_user_id', true);

        // Check if stripe_user_id exists
        if ($stripe_user_id && ($admin_client_id == $this->client_id)) {
            // Check if details_sumitted == true
            $is_connected = $this->verify_details_submitted($stripe_user_id);
        }
        
        return $is_connected;
    }

    public function verify_stripe_credentials() {
        $admin_client_id = get_user_meta($this->user_id, 'admin_client_id', true);
        
        if ($admin_client_id != $this->client_id) {
            $this->delete_stripe_data();
        }
    }

    /**
     *  Prepare data for Stripe account creation
     *  
     *  @return array $stripe_connect_args 
     * 
     *  @link https://docs.stripe.com/api/accounts/create
     */
    public function get_stripe_accounts_args() {
        $user       = get_user_by('id', $this->user_id);
        $store_name = wcfm_get_vendor_store_name($this->user_id);
        $store_name = empty($store_name) ? $user->display_name : $store_name;

        // Address
        $country    = isset($_GET['vendor_country']) ? wc_clean($_GET['vendor_country']) : '';
        $countries = WC()->countries->get_countries();

        $stripe_connect_args = [];
        if (apply_filters('wcfm_is_allow_stripe_express_api', true)) {
            // Before you begin: https://stripe.com/docs/connect/express-accounts#prerequisites-for-using-express
            // Express account prefill information

            /**
             *  @link https://docs.stripe.com/api/accounts/create#create_account-type
             */
            $stripe_connect_args['type'] = \Stripe\Account::TYPE_EXPRESS;
        } else {
            // Standard account prefill information

            /**
             *  @link https://docs.stripe.com/api/accounts/create#create_account-type
             */
            $stripe_connect_args['type'] = \Stripe\Account::TYPE_STANDARD;
        }

        /**
         *  @link https://docs.stripe.com/api/accounts/create#create_account-country
         */
        if (
            ! empty($country) &&
            in_array($country, $this->get_supported_transfer_countries())
        ) {
            $stripe_connect_args['country'] = $country;
        } else {
            wcfm_stripe_log(sprintf("Vendor[id:%s] country %s doesn't support transfer. Fallback to platform country %s", $this->user_id, $countries[$country], $countries[$this->get_platform_country()]), 'info');
        }

        $stripe_connect_args['capabilities'] = [
            'card_payments'       => [
                'requested' => true,
            ],
            'transfers'           => [
                'requested' => true,
            ],
        ];

        if (
            !empty($stripe_connect_args['country']) && 
            !$this->support_stripe_card_payments($stripe_connect_args['country'])
        ) {
            if (!$this->cross_border_payment_supported($stripe_connect_args['country'])) {
                wcfm_stripe_log(sprintf('Cross-border payment required, but country %s doesn\'t support transfers.', $countries[$stripe_connect_args['country']]), 'info');
            } 

            // Unset all payments capabilities.
            unset(
                $stripe_connect_args['capabilities']['card_payments']
            );
            // Set the `service_agreement` to `recipient` for cross border payment.
            $stripe_connect_args['tos_acceptance'] = [
                'service_agreement' => 'recipient',
            ];
        }

        return apply_filters('wcfm_stripe_accounts_args', $stripe_connect_args);
    }

    public function get_stripe_account_links_args($stripe_user_id) {
        return apply_filters('wcfm_stripe_account_links_args', [
            'account' => $stripe_user_id,
            'refresh_url' => add_query_arg('stripe_action', 'refresh', get_wcfm_settings_url()),
            'return_url' => get_wcfm_settings_url(),
            'type' => 'account_onboarding',
        ]);
    }

    /**
     *  @return boolean $deleted
     */
    public function delete_account($stripe_user_id, $params = null, $opts = null) {
        $account = null;

        try {
            $account = $this->stripe->accounts->delete($stripe_user_id, $params, $opts);
        } catch (\Stripe\Exception\ApiErrorException $api_error) {
            wcfm_stripe_log('Can not delete account. Reason: ' . $api_error->getMessage(), 'error');
            wcfm_stripe_log('Error Details: ' . $api_error->getHttpBody());
        }

        if (isset($account->deleted) && $account->deleted) {
            $this->delete_stripe_data();

            $vendor_data = get_user_meta($this->user_id, 'wcfmmp_profile_settings', true);
            $vendor_data['payment']['method'] = '';
            update_user_meta($this->user_id, 'wcfmmp_profile_settings', $vendor_data);

            return true;
        }

        return false;
    }

    /**
     *  @return null|/Stripe/Account
     */
    public function create_account($params = null, $opts = null) {
        $account = null;

        try {
            $account = $this->stripe->accounts->create($params, $opts);
        } catch (\Stripe\Exception\ApiErrorException $api_error) {
            wcfm_stripe_log('Can not create account. Reason: ' . $api_error->getMessage(), 'error');
            wcfm_stripe_log('Error Details: ' . $api_error->getHttpBody());
        }

        if ($account && isset($account->id)) {
            $stripe_account_id = $account->id;
            $vendor_data = get_user_meta($this->user_id, 'wcfmmp_profile_settings', true);
            $vendor_data['payment']['method'] = 'stripe';
            
            update_user_meta($this->user_id, 'wcfmmp_profile_settings', $vendor_data);
            update_user_meta($this->user_id, 'admin_client_id', $this->client_id);
            update_user_meta($this->user_id, 'stripe_user_id', $stripe_account_id);
            update_user_meta($this->user_id, 'stripe_connect_type', $params['type']);
        }

        return $account;
    }

    /**
     *  @return boolean|string
     */
    public function create_account_link($params = null, $opts = null) {
        $account_link = null;

        // if stripe_connect_type changed
        $saved_connect_type = get_user_meta($this->user_id, 'stripe_connect_type', true);
        
        if ( $saved_connect_type ) {
            $current_connect_type = apply_filters('wcfm_is_allow_stripe_express_api', true) ? 'express' : 'standard';

            if ( $saved_connect_type !== $current_connect_type ) {
                // delete account
                if ( is_array($params) && $params['account'] ) {
                    $this->delete_account($params['account']);
                }

                // create new account
                $account = $this->create_account($this->get_stripe_accounts_args());
                $params['account'] = $account->id;
            }
        }

        try {
            $account_link = $this->stripe->accountLinks->create($params, $opts);
        } catch (\Stripe\Exception\ApiErrorException $api_error) {
            wcfm_stripe_log('Can not create account link. Reason: ' . $api_error->getMessage(), 'error');
            wcfm_stripe_log('Error Details: ' . $api_error->getHttpBody());
        }

        // get the stripe connect url (expires in 5 minutes)
        if ($account_link && isset($account_link->url)) {
            return $account_link->url;
        }

        return false;
    }

    /**
     *  @return boolean $details_submitted
     */
    public function verify_details_submitted($stripe_user_id, $params = null, $opts = null) {
        $account = null;

        if (get_user_meta($this->user_id, 'stripe_details_submitted', true)) {
            return true;
        }

        try {
            $account = $this->stripe->accounts->retrieve($stripe_user_id, $params, $opts);
        } catch (\Stripe\Exception\ApiErrorException $api_error) {
            wcfm_stripe_log('Can not verify details submitted. Reason: ' . $api_error->getMessage(), 'error');
            wcfm_stripe_log('Error Details: ' . $api_error->getHttpBody());

            if ('account_invalid' == $api_error->getStripeCode()) {
                $this->delete_stripe_data();
            }
        }

        if (isset($account->details_submitted) && $account->details_submitted) {
            update_user_meta($this->user_id, 'vendor_connected', true);
            update_user_meta($this->user_id, 'admin_client_id', $this->client_id);
            update_user_meta($this->user_id, 'stripe_details_submitted', $account->details_submitted);

            if (isset($account->capabilities) && isset($account->capabilities->card_payments) && $account->capabilities->card_payments === 'active') {
                update_user_meta($this->user_id, 'stripe_card_payments_enabled', true);
            }

            return true;
        }

        return false;
    }

    /**
     * Retrives account data of the platform.
     *
     * @return \Stripe\Account|false
     */
    public function get_platform_data() {
        try {
            $cache_key   = "stripe_express_get_platform_data";
            $platform    = get_transient( $cache_key );

            if ( false === $platform || apply_filters('wcfm_stripe_force_reload_transients', false) ) {
                $platform = $this->stripe->accounts->retrieve();
                set_transient( $cache_key, $platform, WEEK_IN_SECONDS );
            }
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            wcfm_stripe_log(sprintf( __( 'Could not retrieve platform data: %s', 'wc-frontend-manager' ), $e->getMessage() ), 'error');
            wcfm_stripe_log('Error Details: ' . $e->getHttpBody());
            return false;
        }

        return $platform;
    }

    /**
     * Retrives the country of the platform.
     *
     * @return string|false The two-letter ISO code of the country or `false` if no data found.
     */
    public function get_platform_country() {
        $platform = $this->get_platform_data();

        if ( ! $platform ) {
            return false;
        }

        return $platform->country;
    }

    /**
     * Retrieves supported transfer countries based on the marketplace country.
     * Currently only the EU countries are supported for each other.
     *
     * @param string $country_code (Optional) The two-letter ISO code of the country of the marketplace.
     *
     * @return string[] List of two-letter ISO codes of the supported transfer countries.
     */
    public function get_supported_transfer_countries( $country_code = null ) {
        try {
            if ( empty( $country_code ) ) {
                $country_code = $this->get_platform_country();
            }

            // Get the list of EU countries.
            $eu_countries = $this->get_european_countries();

            /**
             *  Apply the feature for EU countries and the US only.
             *  For other platform countries, there will be no list as they only support transfers from the platform country.
             */
            if ( ! ( 'US' === $country_code || in_array( $country_code, $eu_countries, true )) ) {
                return [];
            }

            $cache_key     = "stripe_express_get_specs_for_$country_code";
            $country_specs = get_transient( $cache_key );

            if ( false === $country_specs || apply_filters('wcfm_stripe_force_reload_transients', false) ) {
                $country_specs = $this->stripe->countrySpecs->retrieve($country_code);
                set_transient( $cache_key, $country_specs );
            }

            if ( ! isset( $country_specs->supported_transfer_countries ) ) {
                return [];
            }

            return $country_specs->supported_transfer_countries;
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            wcfm_stripe_log(sprintf( __( 'Could not retrieve countryspec: %s', 'wc-frontend-manager' ), $e->getMessage() ), 'error');
            wcfm_stripe_log('Error Details: ' . $e->getHttpBody());
            return [];
        }
    }

    /**
     * Retrieves the supported European countries.
     *
     * @return array
     */
    public function get_european_countries() {
        $eu_countries = \WC()->countries->get_european_union_countries();
        $non_eu_sepa_countries = [ 'AD', 'CH', 'GB', 'MC', 'SM', 'VA' ];
        return array_merge( $eu_countries, $non_eu_sepa_countries );
    }

    /**
     *  @return \Stripe\Account
     */
    public function retrieve_account($params = null, $opts = null) {
        $account = null;

        $stripe_user_id = get_user_meta($this->user_id, 'stripe_user_id', true);

        if ($stripe_user_id) {
            try {
                $account = $this->stripe->accounts->retrieve($stripe_user_id, $params, $opts);
            } catch (\Stripe\Exception\ApiErrorException $api_error) {
                wcfm_stripe_log('Can not fetch stripe account. Reason: ' . $api_error->getMessage(), 'error');
                wcfm_stripe_log('Error Details: ' . $api_error->getHttpBody());
            }
        }

        return $account;
    }

    /**
     *  deletes all stripe related info from DB
     */
    public function delete_stripe_data() {
        delete_user_meta($this->user_id, 'vendor_connected');
        delete_user_meta($this->user_id, 'admin_client_id');
        delete_user_meta($this->user_id, 'stripe_user_id');
        delete_user_meta($this->user_id, 'stripe_connect_type');
        delete_user_meta($this->user_id, 'stripe_card_payments_enabled');
        delete_user_meta($this->user_id, 'stripe_details_submitted');
        delete_user_meta($this->user_id, 'stripe_account_capabilities');
    }

    public function cross_border_payment_supported($country) {
        $platform_country = $this->get_platform_country();
        $cross_border_supported_countries = array_keys($this->get_cross_border_supported_countries());
        $allow_cross_border_payment = ($platform_country === 'US') && in_array($country, $cross_border_supported_countries);

        return apply_filters('wcfmmp_stripe_is_allow_cross_border_payment', $allow_cross_border_payment, $country, $platform_country);
    }

    public function support_stripe_card_payments($country) {
        return in_array($country, array_keys($this->get_stripe_supported_direct_charge_countries()));
    }

    /**
     *  @link https://docs.stripe.com/connect/cross-border-payouts#supported-countries
     */
    public function get_cross_border_supported_countries() {
        return apply_filters('wcfm_stripe_cross_border_supported_countries', [
            'AL' => __( 'Albania', 'woocommerce' ),
            'DZ' => __( 'Algeria', 'woocommerce' ),
            'AO' => __( 'Angola', 'woocommerce' ),
            'AG' => __( 'Antigua and Barbuda', 'woocommerce' ),
            'AR' => __( 'Argentina', 'woocommerce' ),
            'AM' => __( 'Armenia', 'woocommerce' ),
            'AU' => __( 'Australia', 'woocommerce' ),
            'AT' => __( 'Austria', 'woocommerce' ),
            'AZ' => __( 'Azerbaijan', 'woocommerce' ),
            'BS' => __( 'Bahamas', 'woocommerce' ),
            'BH' => __( 'Bahrain', 'woocommerce' ),
            'BD' => __( 'Bangladesh', 'woocommerce' ),
            'BE' => __( 'Belgium', 'woocommerce' ),
            'BJ' => __( 'Benin', 'woocommerce' ),
            'BT' => __( 'Bhutan', 'woocommerce' ),
            'BO' => __( 'Bolivia', 'woocommerce' ),
            'BA' => __( 'Bosnia and Herzegovina', 'woocommerce' ),
            'BW' => __( 'Botswana', 'woocommerce' ),
            'BN' => __( 'Brunei', 'woocommerce' ),
            'BG' => __( 'Bulgaria', 'woocommerce' ),
            'KH' => __( 'Cambodia', 'woocommerce' ),
            'CA' => __( 'Canada', 'woocommerce' ),
            'CL' => __( 'Chile', 'woocommerce' ),
            'CO' => __( 'Colombia', 'woocommerce' ),
            'CR' => __( 'Costa Rica', 'woocommerce' ),
            'CI' => __( 'Ivory Coast', 'woocommerce' ),
            'HR' => __( 'Croatia', 'woocommerce' ),
            'CY' => __( 'Cyprus', 'woocommerce' ),
            'CZ' => __( 'Czech Republic', 'woocommerce' ),
            'DK' => __( 'Denmark', 'woocommerce' ),
            'DO' => __( 'Dominican Republic', 'woocommerce' ),
            'EC' => __( 'Ecuador', 'woocommerce' ),
            'EG' => __( 'Egypt', 'woocommerce' ),
            'SV' => __( 'El Salvador', 'woocommerce' ),
            'EE' => __( 'Estonia', 'woocommerce' ),
            'ET' => __( 'Ethiopia', 'woocommerce' ),
            'FI' => __( 'Finland', 'woocommerce' ),
            'FR' => __( 'France', 'woocommerce' ),
            'GA' => __( 'Gabon', 'woocommerce' ),
            'GM' => __( 'Gambia', 'woocommerce' ),
            'DE' => __( 'Germany', 'woocommerce' ),
            'GH' => __( 'Ghana', 'woocommerce' ),
            'GR' => __( 'Greece', 'woocommerce' ),
            'GT' => __( 'Guatemala', 'woocommerce' ),
            'GY' => __( 'Guyana', 'woocommerce' ),
            'HK' => __( 'Hong Kong', 'woocommerce' ),
            'HU' => __( 'Hungary', 'woocommerce' ),
            'IS' => __( 'Iceland', 'woocommerce' ),
            'IN' => __( 'India', 'woocommerce' ),
            'ID' => __( 'Indonesia', 'woocommerce' ),
            'IE' => __( 'Ireland', 'woocommerce' ),
            'IL' => __( 'Israel', 'woocommerce' ),
            'IT' => __( 'Italy', 'woocommerce' ),
            'JM' => __( 'Jamaica', 'woocommerce' ),
            'JP' => __( 'Japan', 'woocommerce' ),
            'JO' => __( 'Jordan', 'woocommerce' ),
            'KZ' => __( 'Kazakhstan', 'woocommerce' ),
            'KE' => __( 'Kenya', 'woocommerce' ),
            'KW' => __( 'Kuwait', 'woocommerce' ),
            'LA' => __( 'Laos', 'woocommerce' ),
            'LV' => __( 'Latvia', 'woocommerce' ),
            'LI' => __( 'Liechtenstein', 'woocommerce' ),
            'LT' => __( 'Lithuania', 'woocommerce' ),
            'LU' => __( 'Luxembourg', 'woocommerce' ),
            'MO' => __( 'Macao', 'woocommerce' ),
            'MG' => __( 'Madagascar', 'woocommerce' ),
            'MY' => __( 'Malaysia', 'woocommerce' ),
            'MT' => __( 'Malta', 'woocommerce' ),
            'MU' => __( 'Mauritius', 'woocommerce' ),
            'MX' => __( 'Mexico', 'woocommerce' ),
            'MD' => __( 'Moldova', 'woocommerce' ),
            'MC' => __( 'Monaco', 'woocommerce' ),
            'MN' => __( 'Mongolia', 'woocommerce' ),
            'MA' => __( 'Morocco', 'woocommerce' ),
            'MZ' => __( 'Mozambique', 'woocommerce' ),
            'NA' => __( 'Namibia', 'woocommerce' ),
            'NL' => __( 'Netherlands', 'woocommerce' ),
            'NZ' => __( 'New Zealand', 'woocommerce' ),
            'NE' => __( 'Niger', 'woocommerce' ),
            'NG' => __( 'Nigeria', 'woocommerce' ),
            'MK' => __( 'North Macedonia', 'woocommerce' ),
            'NO' => __( 'Norway', 'woocommerce' ),
            'OM' => __( 'Oman', 'woocommerce' ),
            'PK' => __( 'Pakistan', 'woocommerce' ),
            'PA' => __( 'Panama', 'woocommerce' ),
            'PY' => __( 'Paraguay', 'woocommerce' ),
            'PE' => __( 'Peru', 'woocommerce' ),
            'PH' => __( 'Philippines', 'woocommerce' ),
            'PL' => __( 'Poland', 'woocommerce' ),
            'PT' => __( 'Portugal', 'woocommerce' ),
            'QA' => __( 'Qatar', 'woocommerce' ),
            'RO' => __( 'Romania', 'woocommerce' ),
            'RW' => __( 'Rwanda', 'woocommerce' ),
            'SM' => __( 'San Marino', 'woocommerce' ),
            'SA' => __( 'Saudi Arabia', 'woocommerce' ),
            'SN' => __( 'Senegal', 'woocommerce' ),
            'RS' => __( 'Serbia', 'woocommerce' ),
            'SG' => __( 'Singapore', 'woocommerce' ),
            'SK' => __( 'Slovakia', 'woocommerce' ),
            'SI' => __( 'Slovenia', 'woocommerce' ),
            'ZA' => __( 'South Africa', 'woocommerce' ),
            'KR' => __( 'South Korea', 'woocommerce' ),
            'ES' => __( 'Spain', 'woocommerce' ),
            'LK' => __( 'Sri Lanka', 'woocommerce' ),
            'LC' => __( 'Saint Lucia', 'woocommerce' ),
            'SE' => __( 'Sweden', 'woocommerce' ),
            'CH' => __( 'Switzerland', 'woocommerce' ),
            'TW' => __( 'Taiwan', 'woocommerce' ),
            'TZ' => __( 'Tanzania', 'woocommerce' ),
            'TH' => __( 'Thailand', 'woocommerce' ),
            'TT' => __( 'Trinidad and Tobago', 'woocommerce' ),
            'TN' => __( 'Tunisia', 'woocommerce' ),
            'TR' => __( 'Turkey', 'woocommerce' ),
            'AE' => __( 'United Arab Emirates', 'woocommerce' ),
            'GB' => __( 'United Kingdom', 'woocommerce' ),
            'UY' => __( 'Uruguay', 'woocommerce' ),
            'UZ' => __( 'Uzbekistan', 'woocommerce' ),
            'VN' => __( 'Vietnam', 'woocommerce' ),
        ]);
    }

    /**
     *  List of countries, does not support stripe card_payments 
     * 
     *  i.e. accounts can receive funds from admin business [Transfer Charge]
     *  but accounts can not accept payments from their own customers [Direct Charge, Destination Charge]
     * 
     *  Didn't find any API endpoint to get this data, so this list may not be accurate or may change later
     *  Stripe support suggest to use only standard accounts for direct_charge API, but we also need express account
     * 
     *  @link https://dashboard.stripe.com/test/settings/connect/onboarding-options/countries > Add the products they need > Payments - Let accounts accept payments from their own customers
     *  @return array $countries
     */
    public function get_stripe_supported_direct_charge_countries() {
        return apply_filters('wcfm_stripe_supported_direct_charge_countries', [
            'AU' => __( 'Australia', 'woocommerce' ),
            'AT' => __( 'Austria', 'woocommerce' ),
            'BE' => __( 'Belgium', 'woocommerce' ),
            'BG' => __( 'Bulgaria', 'woocommerce' ),
            'CA' => __( 'Canada', 'woocommerce' ),
            'HR' => __( 'Croatia', 'woocommerce' ),
            'CY' => __( 'Cyprus', 'woocommerce' ),
            'CZ' => __( 'Czech Republic', 'woocommerce' ),
            'DK' => __( 'Denmark', 'woocommerce' ),
            'EE' => __( 'Estonia', 'woocommerce' ),
            'FI' => __( 'Finland', 'woocommerce' ),
            'FR' => __( 'France', 'woocommerce' ),
            'DE' => __( 'Germany', 'woocommerce' ),
            'GI' => __( 'Gibraltar', 'woocommerce' ),
            'GR' => __( 'Greece', 'woocommerce' ),
            'HK' => __( 'Hong Kong SAR China', 'woocommerce' ),
            'HU' => __( 'Hungary', 'woocommerce' ),
            'IE' => __( 'Ireland', 'woocommerce' ),
            'IT' => __( 'Italy', 'woocommerce' ),
            'JP' => __( 'Japan', 'woocommerce' ),
            'LV' => __( 'Latvia', 'woocommerce' ),
            'LI' => __( 'Liechtenstein', 'woocommerce' ),
            'LT' => __( 'Lithuania', 'woocommerce' ),
            'LU' => __( 'Luxembourg', 'woocommerce' ),
            'MT' => __( 'Malta', 'woocommerce' ),
            'MX' => __( 'Mexico', 'woocommerce' ),
            'NL' => __( 'Netherlands', 'woocommerce' ),
            'NZ' => __( 'New Zealand', 'woocommerce' ),
            'NO' => __( 'Norway', 'woocommerce' ),
            'PL' => __( 'Poland', 'woocommerce' ),
            'PT' => __( 'Portugal', 'woocommerce' ),
            'RO' => __( 'Romania', 'woocommerce' ),
            'SG' => __( 'Singapore', 'woocommerce' ),
            'SK' => __( 'Slovakia', 'woocommerce' ),
            'SI' => __( 'Slovenia', 'woocommerce' ),
            'ES' => __( 'Spain', 'woocommerce' ),
            'SE' => __( 'Sweden', 'woocommerce' ),
            'CH' => __( 'Switzerland', 'woocommerce' ),
            'TH' => __( 'Thailand', 'woocommerce' ),
            'AE' => __( 'United Arab Emirates', 'woocommerce' ),
            'GB' => __( 'United Kingdom', 'woocommerce' ),
            'US' => __( 'United States', 'woocommerce' )
        ]);
    }
}
