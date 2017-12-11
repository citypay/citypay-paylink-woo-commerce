<?php

/**
 * Class CityPay_PayLink for interacting with the Paylink API
 */
class CityPay_PayLink
{

    /**
     * @var WC_Gateway_CityPay
     */
    private $pay_module;
    private $request_addr = array();
    private $request_cart = array();
    private $request_client = array();
    private $request_config = array();

    /**
     * CityPay_PayLink constructor.
     * @throws Exception should no payment module be provided
     */
    function __construct()
    {
        $args = func_get_args();
        if (count($args) == 1) {
            $this->pay_module = $args[0];
        } else {
            throw new Exception('Payment module must be provided to constructor.');
        }
    }

    private function debugLog($text)
    {
        if (method_exists($this->pay_module, 'debugLog')) {
            $this->pay_module->debugLog($text);
        }
    }

    public function matchCurrencyConfig($currencyCode, $conf_num)
    {
        $conf_cur = trim(strtoupper($this->pay_module->getCurrencyConfig($conf_num)));
        $conf_mid = trim($this->pay_module->getMerchantConfig($conf_num));
        $conf_key = trim($this->pay_module->getLicenceConfig($conf_num));
        // Currency code not configured
        if (empty($conf_cur)) {
            return null;
        }
        // Merchant ID not configured
        if (empty($conf_mid)) {
            return null;
        }
        // Licence key not configured
        if (empty($conf_key)) {
            return null;
        }
        // Merchant ID is not numeric
        if (!ctype_digit($conf_mid)) {
            return null;
        }
        // Does not match required currency
        if (strcasecmp($conf_cur, $currencyCode) != 0) {
            return null;
        }
        // Matched, return config details
        return array($conf_mid, $conf_key, $conf_cur);
    }

    public function getCurrencyConfig($currencyCode)
    {
        for ($conf_num = 1; $conf_num <= 5; $conf_num++) {
            $conf = $this->matchCurrencyConfig($currencyCode, $conf_num);
            if (is_array($conf) && !empty($conf)) {
                return $conf;
            }
        }
        return null;
    }

    public function canUseForCurrency($currencyCode)
    {
        $conf = $this->getCurrencyConfig($currencyCode);
        if (is_array($conf) && !empty($conf)) {
            return true;
        }
        // No configured currency matches the required currency
        return false;
    }

    public function setRequestAddress($fname, $lname, $addr1, $addr2, $addr3, $area, $zip, $country, $email)
    {
        $this->request_addr = array(
            'cardholder' => array(
                'firstName' => trim($fname),
                'lastName' => trim($lname),
                'email' => trim($email),
                'address' => array(
                    'address1' => trim($addr1),
                    'address2' => trim($addr2),
                    'address3' => trim($addr3),
                    'area' => trim($area),
                    'postcode' => trim($zip),
                    'country' => trim(strtoupper($country)))));
    }

    public function setRequestCart($mid, $key, $cart_id, $price, $cart_desc)
    {
        $this->request_cart = array(
            'merchantid' => (int)$mid,
            'licenceKey' => $key,
            'identifier' => trim($cart_id),
            'amount' => (int)$price,
            'cart' => array(
                'productInformation' => trim($cart_desc)));
    }

    public function setRequestClient($client_name, $client_version)
    {
        $this->request_client = array('clientVersion' => trim($client_name) . '/' . trim($client_version));
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
        if (empty($postback_url)) {
            $this->request_config['config']['redirect_params'] = true;
            $this->request_config['config']['postback_policy'] = 'none';
        } else {
            $this->request_config['config']['redirect_params'] = false;
            $this->request_config['config']['postback'] = $postback_url;
            $this->request_config['config']['postback_policy'] = 'sync';
        }
    }

    public function getJSON()
    {
        $params = array_merge($this->request_cart, $this->request_client, $this->request_addr, $this->request_config);
        return json_encode($params);
    }

    /**
     * Creates a token with the remote end point and returns a url if cleanly generated
     * @throws Exception should a non 200 be returned or invalid data be found
     */
    public function createPaylinkToken()
    {
        $json = $this->getJSON();
        $this->debugLog($json);
        $response = wp_remote_post('https://secure.citypay.com/paylink3/create', array(
            'method'    => 'POST',
            'timeout'   => 45,
            'headers'   => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json;charset=UTF-8',
                'Content-Length' => strlen($json)
            ),
            'body'      => $json
        ));

        $this->debugLog($response);
        if (is_wp_error($response)) {
            throw new Exception("Unable to create a payment " . $response->get_error_message());
        } else {
            $results = json_decode($response, true);
            if ($results['result'] != 1) {
                $this->debugLog(print_r($results, true));
                throw new Exception('Invalid response from PayLink');
            }
            $paylink_url = $results['url'];
            if (empty($paylink_url)) {
                $this->debugLog(print_r($results, true));
                throw new Exception('No URL obtained from PayLink');
            }
            return $paylink_url;
        }
    }

    public function getPostbackData()
    {
        // Check response data - need the raw post data, can't use the processed post value as data is
        // in json format and not name/value pairs
        $HTTP_RAW_POST_DATA = isset($HTTP_RAW_POST_DATA) ? $HTTP_RAW_POST_DATA : file_get_contents("php://input");
        if (empty($HTTP_RAW_POST_DATA)) {
            return null;
        }
        $postback_data = array_change_key_case(json_decode($HTTP_RAW_POST_DATA, true), CASE_LOWER);
        if (empty($postback_data)) {
            return null;
        }
        $this->debugLog(print_r($postback_data, true));
        return $postback_data;
    }

    public function isAuthorised($postback_data)
    {
        $result = $postback_data['authorised'];
        $this->debugLog('isAuthorised result is type ' . gettype($result) . ' value = ' . $result);
        if (is_string($result)) {
            return (strtolower($result) === 'true');
        }
        if (is_bool($result)) {
            return $result === true;
        }
        return false;
    }

    public function validatePostbackData($postback_data, $key)
    {
        $hash_src = $postback_data['authcode'] .
            $postback_data['amount'] . $postback_data['errorcode'] .
            $postback_data['merchantid'] . $postback_data['transno'] .
            $postback_data['identifier'] . $key;
        // Check both the sha1 and sha256 hash values to ensure that results have not
        // been tampered with
        $check = base64_encode(sha1($hash_src, true));
        if (strcmp($postback_data['sha1'], $check) != 0) {
            return false;
        }
        $check = base64_encode(hash('sha256', $hash_src, true));
        if (strcmp($postback_data['sha256'], $check) != 0) {
            return false;
        }
        return true;    // Hash values match expected value
    }
}

?>
