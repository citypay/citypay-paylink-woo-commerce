<?php

/**
 * Class CityPay_PayLink for interacting with the CityPay Paylink API
 */
class CityPay_PayLink
{

    /**
     * The payment module which instantiated this class
     * @var WC_Gateway_CityPayPaylink
     */
    private $pay_module;
    private $request_addr = array();
    private $base_call = array();
    private $request_client = array();
    private $request_config = array();

    /**
     * CityPay_PayLink constructor.
     * @throws Exception should no payment module be provided
     */
    function __construct()
    {
        $args = func_get_args();
        if (count($args) == 0) {
            throw new Exception('Payment module must be provided to constructor.');
        }
        $this->pay_module = $args[0];
    }


    private function debugLog($text)
    {
        if (method_exists($this->pay_module, 'debugLog')) {
            $this->pay_module->debugLog($text);
        }
    }

    /**
     * @param $firstName string
     * @param $lastName string
     * @param $address1 string
     * @param $address2 string
     * @param $address3 string
     * @param $area string
     * @param $postcode string
     * @param $country string
     * @param $email string
     */
    public function setCardHolder($firstName, $lastName, $address1, $address2, $city, $state, $postcode, $country, $email)
    {
        $this->request_addr = array(
            'cardholder' => array(
                'firstname' => trim($firstName),
                'lastname' => trim($lastName),
                'email' => trim($email),
                'address' => array(
                    'address1' => trim($address1),
                    'address2' => trim($address2),
                    'address3' => trim($state),
                    'area' => trim($city),
                    'postcode' => trim($postcode),
                    'country' => trim(strtoupper($country)))));
    }

    /**
     * @param $merchantId int the merchant id to process with
     * @param $licenceKey string the licence key
     * @param $identifier  string the identifier
     * @param $amount integer the full amount to process for the order
     * @param $currency string the currency for the order
     * @param $productInformation string information describing the order
     */
    public function setBaseCall($merchantId, $licenceKey, $identifier, $amount, $currency, $productInformation)
    {
        $this->base_call = array(
            'merchantid' => $merchantId,
            'identifier' => trim($identifier),
            'amount' => (int)$amount,
            'currency' => $currency,
            'cart' => array(
                'product_information' => trim($productInformation)));
    }

    public function setRequestClient($client_version)
    {
        $this->request_client = array('client_version' => 'WooCommerce-' . wc()->version . '/CityPay-WC-' . $client_version);
    }

    /**
     * Sets option CREATE_CAC_ACCOUNT_ON_AUTHORISATION and accountNo for the token creation
     * @param $accountNo
     * @return void
     */
    public function setOptionsAndAccountNo($accountNo) {
        $this->request_config['config']['options'] = ['CREATE_CAC_ACCOUNT_ON_AUTHORISATION'];
        $this->base_call['accountno'] = $accountNo;
    }

    /**
     * Adds subscriptionId
     * @param $subscription_id
     * @return void
     */
    public function addSubscriptionId($subscription_id) {
        $this->base_call['subscription_id'] = $subscription_id;
    }

    /**
     * Adds recurring field
     * @param $value
     * @return void
     */
    public function setRecurring($value) {
        $this->base_call['recurring'] = $value;
    }

    /**
     * Adds transaction type for special setup flows.
     * @param $value
     * @return void
     */
    public function setTxType($value) {
        $this->base_call['tx_type'] = $value;
    }

    public function setRequestConfig($testmode, $postback_url, $return_success_url, $return_failure_url)
    {
        $this->request_config = array(
            'config' => array(
                'redirect_success' => $return_success_url,
                'redirect_failure' => $return_failure_url)
        );
        $this->request_config['config']['return_params'] = false;
        $this->request_config['config']['postback'] = $postback_url;
        $this->request_config['config']['postback_policy'] = 'sync';
        if (empty($postback_url)) {
            $this->request_config['config']['return_params'] = true;
            $this->request_config['config']['postback_policy'] = 'none';
        }
    }

    public function getPayload()
    {
        return array_merge($this->base_call, $this->request_client, $this->request_addr, $this->request_config);
    }

    private function useClientIdAuth()
    {
        return !empty(trim((string)$this->pay_module->client_id));
    }

    private function getLegacyPayload()
    {
        $payload = $this->getPayload();

        $legacy_payload = array(
            'merchantid' => $payload['merchantid'] ?? '',
            'licenceKey' => $this->pay_module->licence_key,
            'identifier' => $payload['identifier'] ?? '',
            'amount' => $payload['amount'] ?? 0,
            'currency' => $payload['currency'] ?? '',
            'test' => ($this->pay_module->testmode === 'yes') ? 'true' : 'false',
        );

        if (!empty($payload['cart']['product_information'])) {
            $legacy_payload['cart'] = array(
                'productInformation' => $payload['cart']['product_information'],
            );
        }

        if (!empty($payload['client_version'])) {
            $legacy_payload['clientVersion'] = $payload['client_version'];
        }

        if (!empty($payload['cardholder'])) {
            $cardholder = $payload['cardholder'];
            $legacy_payload['cardholder'] = array(
                'firstName' => $cardholder['firstname'] ?? '',
                'lastName' => $cardholder['lastname'] ?? '',
                'email' => $cardholder['email'] ?? '',
                'address' => array(
                    'address1' => $cardholder['address']['address1'] ?? '',
                    'address2' => $cardholder['address']['address2'] ?? '',
                    'address3' => $cardholder['address']['address3'] ?? '',
                    'area' => $cardholder['address']['area'] ?? '',
                    'postcode' => $cardholder['address']['postcode'] ?? '',
                    'country' => $cardholder['address']['country'] ?? '',
                ),
            );
        }

        if (!empty($payload['config'])) {
            $legacy_payload['config'] = $payload['config'];

            if (array_key_exists('return_params', $legacy_payload['config'])) {
                $legacy_payload['config']['redirect_params'] = $legacy_payload['config']['return_params'];
                unset($legacy_payload['config']['return_params']);
            }
        }

        if (!empty($payload['accountno'])) {
            $legacy_payload['accountNo'] = $payload['accountno'];
        }

        if (!empty($payload['subscription_id'])) {
            $legacy_payload['subscriptionId'] = $payload['subscription_id'];
        }

        if (!empty($payload['recurring'])) {
            $legacy_payload['recurring'] = $payload['recurring'];
        }

        if (!empty($payload['tx_type'])) {
            $legacy_payload['tx_type'] = $payload['tx_type'];
        }

        return $legacy_payload;
    }

    /**
     * Creates a token with the remote end point and returns an url if cleanly generated
     * @throws Exception should a non 200 be returned or invalid data be found
     */
    public function createPaylinkToken()
    {
        $this->debugLog('CityPay_PayLink::createPaylinkToken()');
        $use_client_id_auth = $this->useClientIdAuth();
        $payload = $use_client_id_auth ? $this->getPayload() : $this->getLegacyPayload();
        $json = wp_json_encode($payload);

        if ($use_client_id_auth) {
            $api_host = $this->pay_module->get_api_host();
            $paylink_host = preg_replace('#/v6/?$#', '', $api_host);
            $url = $paylink_host . '/paylink/create';
        } else {
            $url = 'https://payments.citypay.com/create';
        }

        $this->debugLog(
            'CityPay Paylink auth mode: ' .
            ($use_client_id_auth ? 'client_id' : 'legacy_fallback') .
            ', testmode=' . $this->pay_module->testmode
        );
        $this->debugLog('CityPay Paylink request URL: ' . $url);
        $this->debugLog('POST data to ' . $url . ' with data /\n' . $json);

        $context = get_file_data(__DIR__ . '/wc-payment-gateway-citypay.php', ['version' => 'Version']);
        $user_agent = 'WooCommerce-' . wc()->version . '/CityPay-WC-' . $context['version'];

        $headers = array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json;charset=UTF-8',
            'User-Agent' => $user_agent
        );

        if ($use_client_id_auth) {
            $apiKey =  new ApiKey($this->pay_module->client_id, $this->pay_module->licence_key);
            $headers['cp-api-key'] = $apiKey->generate();
        } else {
            $headers['Content-Length'] = strlen($json);
        }

        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'timeout' => 45,
            'headers' => $headers,
            'body' => $json
        ));

        $responseCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->debugLog('ResponseCode: ' . $responseCode . ', Body: ' . $body);

        if (is_wp_error($response)) {
            throw new Exception("Unable to create a payment " . $response->get_error_message());
        }

        if ($responseCode < 200 || $responseCode >= 300) {
            $this->debugLog('CityPay Paylink non-success response from URL: ' . $url);
            $packet = json_decode($body, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                throw new Exception('CityPay Paylink request failed (' . $responseCode . '): ' . wp_json_encode($packet));
            }

            throw new Exception('CityPay Paylink request failed (' . $responseCode . ') and returned a non-JSON response: ' . substr(trim(wp_strip_all_tags((string)$body)), 0, 300));
        }

        $packet = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Unable to obtain result from CityPay: " . json_last_error_msg() . '. Response: ' . substr(trim(wp_strip_all_tags((string)$body)), 0, 300));
        }
        $result = $packet['result'];
        if ($result != 1) {
            $errors = '';
            foreach ($packet['errors'] as $error) {
                $errors = $errors . '<li>' . $error['code'] . ': ' . $error['msg'] . '</li>';
            }
            throw new Exception('Unable to process to CityPay. <ul>' . $errors . '</ul>');
        }
        $paylink_url = $packet['url'];
        $this->debugLog($paylink_url);
        if (empty($paylink_url)) {
            throw new Exception('CityPay Paylink is currently unavailable');
        }

        return $packet;

    }

}

?>
