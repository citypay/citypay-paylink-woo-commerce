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
    public function setCardHolder($firstName, $lastName, $address1, $address2, $address3, $area, $postcode, $country, $email)
    {
        $this->request_addr = array(
            'cardholder' => array(
                'firstName' => trim($firstName),
                'lastName' => trim($lastName),
                'email' => trim($email),
                'address' => array(
                    'address1' => trim($address1),
                    'address2' => trim($address2),
                    'address3' => trim($address3),
                    'area' => trim($area),
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
            'licenceKey' => $licenceKey,
            'identifier' => trim($identifier),
            'amount' => (int)$amount,
            'currency' => $currency,
            'cart' => array(
                'productInformation' => trim($productInformation)));
    }

    public function setRequestClient($client_version)
    {
        $this->request_client = array('clientVersion' => 'WooCommerce-' . wc()->version . '/CityPay-WC-' . $client_version);
    }

    /**
     * Sets option CREATE_CAC_ACCOUNT_ON_AUTHORISATION and accountNo for the token creation
     * @param $accountNo
     * @return void
     */
    public function setOptionsAndAccountNo($accountNo) {
        $this->request_config['config']['options'] = ['CREATE_CAC_ACCOUNT_ON_AUTHORISATION'];
        $this->base_call['accountNo'] = $accountNo;
    }

    /**
     * Adds subscriptionId
     * @param $subscription_id
     * @return void
     */
    public function addSubscriptionId($subscription_id) {
        $this->base_call['subscriptionId'] = $subscription_id;
    }

    /**
     * Adds recurring field
     * @param $value
     * @return void
     */
    public function setRecurring($value) {
        $this->base_call['recurring'] = $value;
    }

    public function setRequestConfig($testmode, $postback_url, $return_success_url, $return_failure_url)
    {
        $this->request_config = array(
            //'test'		=> 'simulator',
            'test' => $testmode ? 'true' : 'false',
            'config' => array(
                'lockParams' => array('cardholder'),
                'redirect_success' => $return_success_url,
                'redirect_failure' => $return_failure_url)
        );
        $this->request_config['config']['redirect_params'] = false;
        $this->request_config['config']['postback'] = $postback_url;
        $this->request_config['config']['postback_policy'] = 'sync';
        if (empty($postback_url)) {
            $this->request_config['config']['redirect_params'] = true;
            $this->request_config['config']['postback_policy'] = 'none';
        }
    }

    public function getJSON()
    {
        $params = array_merge($this->base_call, $this->request_client, $this->request_addr, $this->request_config);
        return json_encode($params);
    }

    /**
     * Creates a token with the remote end point and returns an url if cleanly generated
     * @throws Exception should a non 200 be returned or invalid data be found
     */
    public function createPaylinkToken()
    {
        $this->debugLog('CityPay_PayLink::createPaylinkToken()');
        $json = $this->getJSON();

        $url = CITYPAY_PAYLINK_API_ROOT . '/create';
        $this->debugLog('POST data to ' . $url . ' with data /\n' . $json);

        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'timeout' => 45,
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json;charset=UTF-8',
                'Content-Length' => strlen($json)
            ),
            'body' => $json
        ));

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
