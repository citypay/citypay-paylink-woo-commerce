<?php

const CITYPAY_API_ROOT = 'https://api.citypay.com/v6';
const CITYPAY_API_TEST_ROOT = 'https://sandbox.citypay.com/v6';

trait WC_CP_API {

    /**
     * Returns the host to process renewals
     * @return string
     */
    public function get_api_host() {
        $test_mode = $this->get_option('testmode');

        if ($test_mode == 'yes') {
            return CITYPAY_API_TEST_ROOT;
        } else return CITYPAY_API_ROOT;
    }
    
    /**
     * Gets the Card Holder Account
     * @throws Exception should a non 200 be returned or invalid data be found
     */
    public function account_retrieval($accountNo)
    {
        $this->debugLog('WC_Gateway_CP_Subscriptions::accountRetrieval()');

        $url = $this->get_api_host() . '/account/' . $accountNo;

        $apiKey =  new ApiKey($this->client_id, $this->licence_key);

        $context = get_file_data(__DIR__ . '/wc-payment-gateway-citypay.php', ['version' => 'Version']);

        $user_agent = 'WooCommerce-' . wc()->version . '/CityPay-WC-' . $context['version'];

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'cp-api-key' => $apiKey->generate(),
                'User-Agent' => $user_agent
            ],
            'method' => 'GET',
        ];

        $response = wp_remote_get($url, $args);
        $responseCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->debugLog('ResponseCode: ' . $responseCode . ', Body: ' . $body);

        if (is_wp_error($response)) {
            throw new Exception("Unable to create a payment " . $response->get_error_message());
        }

        $packet = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Unable to obtain result from CityPay: " . json_last_error_msg());
        }

        return $packet;
    }

    /**
     * Charge the Card Holder Account
     * @throws Exception should a non 200 be returned or invalid data be found
     */
    public function account_charge($charge) {
        $this->debugLog('WC_Gateway_CP_Subscriptions::account_charge()');

        $url = $this->get_api_host() . '/charge';

        $apiKey =  new ApiKey($this->client_id, $this->licence_key);

        $context = get_file_data(__DIR__ . '/wc-payment-gateway-citypay.php', ['version' => 'Version']);

        $user_agent = 'WooCommerce-' . wc()->version . '/CityPay-WC-' . $context['version'];

        $body = [
            "amount" => $charge['amount'],
            "identifier" => $charge['identifier'],
            "subscription_id" => $charge['subscription_id'],
            "merchantid" => $charge['merchantid'],
            "token" => $charge['token'],
            "currency" => $charge['currency'],
            "initiation" => "M", // Merchant
            "cardholder_agreement" => "R", // Recurring
            "csc_policy" => "2" // to ignore. Transactions that are ignored will bypass the result and not send the CSC details for authorisation.
        ];

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'cp-api-key' => $apiKey->generate(),
                'User-Agent' => $user_agent
            ],
            'method' => 'POST',
            'body' => json_encode($body)
        ];

        $response = wp_remote_post($url, $args);
        $responseCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->debugLog('ResponseCode: ' . $responseCode . ', Body: ' . $body);

        if (is_wp_error($response)) {
            throw new Exception("Unable to create a payment " . $response->get_error_message());
        }

        $packet = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Unable to obtain result from CityPay: " . json_last_error_msg());
        }

        return $packet;
    }
}