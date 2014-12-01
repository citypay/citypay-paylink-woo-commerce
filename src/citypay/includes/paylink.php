<?php
/* Generic code for PayLink */

class CityPay_PayLink {

	private $pay_module;
	private	$request_addr = array();
	private $request_cart = array();
	private $request_client = array();
	private $request_config = array();

	// Default CURL options
	public $curl_opts = array(
		CURLOPT_RETURNTRANSFER	=> true,
		CURLOPT_SSL_VERIFYPEER	=> true,
		CURLOPT_MAXREDIRS	=> 10
	);

	function __construct() {
		$args = func_get_args();
		if (count($args)==1) {
			$this->pay_module = $args[0];
		} else {
			throw new Exception('Payment module must be provided to constructor.');
		}
	}

	private function debugLog($text) {
		if (method_exists($this->pay_module,'debugLog')) {
			$this->pay_module->debugLog($text);
		}
	}

	public function matchCurrencyConfig($currencyCode, $conf_num) {
		$conf_cur = trim(strtoupper($this->pay_module->getCurrencyConfig($conf_num)));
		$conf_mid = trim($this->pay_module->getMerchantConfig($conf_num));
		$conf_key = trim($this->pay_module->getLicenceConfig($conf_num));
		if (empty($conf_cur)) { return null; }		// Currency code not configured
		if (empty($conf_mid)) { return null; }		// Merchant ID not configured
		if (empty($conf_key)) { return null; }		// Licence key not configured
		if (!ctype_digit($conf_mid)) { return null; }	// Merchant ID is not numeric
		if (strcasecmp($conf_cur,$currencyCode)!=0) { return null; }	// Does not match required currency
		return array($conf_mid,$conf_key,$conf_cur);	// Matched, return config details
	}

	public function getCurrencyConfig($currencyCode) {
		for ($conf_num=1;$conf_num<=5;$conf_num++) {
			$conf=$this->matchCurrencyConfig($currencyCode,$conf_num);
			if (is_array($conf) && !empty($conf)) {
				return $conf;
			}
		}
		return null;
	}

	public function canUseForCurrency($currencyCode) {
		$conf=$this->getCurrencyConfig($currencyCode);
		if (is_array($conf) && !empty($conf)) { return true; }
		return false;	// No configured currency matches the required currency
	}

	public function setRequestAddress($fname,$lname,$addr1,$addr2,$addr3,$area,$zip,$country,$email,$phone) {
		$this->request_addr = array(
			'cardholder'	=> array(
				'firstName'	=> trim($fname),
				'lastName'	=> trim($lname),
				'email'		=> trim($email),
				'address'	=> array (
					'address1'	=> trim($addr1),
					'address2'	=> trim($addr2),
					'address3'	=> trim($addr3),
					'area'		=> trim($area),
					'postcode'	=> trim($zip),
					'country'	=> trim(strtoupper($country)))));
	}

	public function setRequestCart($mid,$key,$cart_id,$price,$cart_desc) {
		$this->request_cart = array(
			'merchantid'	=> (int)$mid,
			'licenceKey'	=> $key,
			'identifier'	=> trim($cart_id),
			'amount'	=> (int)$price,
			'cart'		=> array(
				'productInformation'	=> trim($cart_desc)));
	}

	public function setRequestClient($client_name,$client_version) {
		$this->request_client = array(
			'clientVersion'	=> trim($client_name).' '.trim($client_version));
	}

	public function setRequestConfig($testmode, $postback_url, $return_success_url, $return_failure_url) {
		$this->request_config = array(
			//'test'		=> 'simulator',
			'test'		=> $testmode?'true':'false',
			'config'	=> array(
				'lockParams'	=> array('cardholder'),
				'redirect_success'=> $return_success_url,
				'redirect_failure'=> $return_failure_url)
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

	public function getJSON($testmode, $postback_url, $return_success_url, $return_failure_url) {
                // note, call to this function at line 120 results in PHP warnings for lack of
                // specified parameters; yet getJSON simply collates information that forms part of
                // the current instance of CityPay_Paylink
		$params=array_merge($this->request_cart,$this->request_client,$this->request_addr,$this->request_config);
		return json_encode($params);
	}

	public function getPaylinkURL($curl_options=null) {
		if (!function_exists('curl_init')) {
			throw new Exception('PayLink requires the CURL module to be installed. See http://php.net/manual/en/book.curl.php');
		}
		$json = $this->getJSON();
		$this->debugLog($json);
		$curl_opts = $this->curl_opts;	// Initial default CURL options
		// Add in any CURL options sent as params to this function
		if (isset($curl_options) && is_array($curl_options)) {
			foreach($curl_options as $key => $value) {
				$curl_opts[$key] = $value;
			}
		}
		// Add the relevant data options
                $curl_opts[CURLOPT_POST] = true;
		$curl_opts[CURLOPT_POSTFIELDS] = $json;
		$curl_opts[CURLOPT_RETURNTRANSFER] = true;
		$curl_opts[CURLOPT_HTTPHEADER] = array(
			'Accept: application/json',
			'Content-Type: application/json;charset=UTF-8',
			'Content-Length: ' . strlen($json));
                $curl_opts[CURLOPT_VERBOSE] = true;
                $curl_stderr = fopen('php://temp', 'w+');
                $curl_opts[CURLOPT_STDERR] = $curl_stderr;
		$ch = curl_init('https://secure.citypay.com/paylink3/create');
		curl_setopt_array($ch, $curl_opts);
                $response = curl_exec($ch);
                curl_close($ch);
                if (empty($response))
                {
                    rewind($curl_stderr);
                    $req_stderr = stream_get_contents($curl_stderr, 4096);
                    fclose($curl_stderr);
                    $req_info = curl_getinfo($ch);
                    $req_errno = curl_errno($ch);
                    $req_error = curl_error($ch);
                    $this->debugLog("Request information - ".print_r($req_info, true));
                    $this->debugLog("Request errno - ".print_r($req_errno, true));
                    $this->debugLog("Request error - ".print_r($req_error, true));
                    $this->debugLog("cURL trace - ".print_r($req_stderr, true));
                    $this->debugLog("Response - ".print_r($response, true));
                    throw new Exception('Error generating PayLink token');
                }
                
                $results = json_decode($response,true);
                if ($results['result']!=1) {
                        $this->debugLog($response);
                        $this->debugLog(print_r($results,true));
                        throw new Exception('Invalid response from PayLink');
                }
                $paylink_url=$results['url'];
                if (empty($paylink_url)) {
                        $this->debugLog(print_r($results,true));
                        throw new Exception('No URL obtained from PayLink');
                }
                return $paylink_url;
	}

	public function validPostbackIP($remote_addr,$allowed_ip) {
		if (empty($allowed_ip)) {
			$allowed_ip="54.246.184.81, 54.246.184.93, 54.246.184.95";
		}
		if (strcasecmp($allowed_ip,'Any')!=0) {
			$ip_list=explode(',',$ip_conf);
			foreach ($ip_list as $ip_check) {
				if (strcmp(trim($ip_check),$remote_addr)==0) {
					return true;
				}
			}
			return false;
		}
		return true;
	}

	public function getPostbackData() {
		// Check response data - need the raw post data, can't use the processed post value as data is
		// in json format and not name/value pairs
		$HTTP_RAW_POST_DATA = isset($HTTP_RAW_POST_DATA) ? $HTTP_RAW_POST_DATA : file_get_contents("php://input");
		if (empty($HTTP_RAW_POST_DATA)) {
			return null;
		}
		$postback_data = array_change_key_case(json_decode($HTTP_RAW_POST_DATA,true), CASE_LOWER);
		if (empty($postback_data)) {
			return null;
		}
		$this->debugLog(print_r($postback_data,true));
		return $postback_data;
	}

	public function isAuthorised($postback_data) {
		$result=$postback_data['authorised'];
		$this->debugLog('isAuthorised result is type '.gettype($result).' value = '.$result);
		if (is_string($result)) { return (strtolower($result) === 'true'); }
		if (is_bool($result)) { return $result === true; }
		return false;
	}

	public function validatePostbackData($postback_data,$key) {
		$hash_src = $postback_data['authcode'].
			$postback_data['amount'].$postback_data['errorcode'].
			$postback_data['merchantid'].$postback_data['transno'].
			$postback_data['identifier'].$key;
		// Check both the sha1 and sha256 hash values to ensure that results have not
		// been tampered with
		$check=base64_encode(sha1($hash_src,true));
		if (strcmp($postback_data['sha1'],$check)!=0) { return false; }
		$check=base64_encode(hash('sha256',$hash_src,true));
		if (strcmp($postback_data['sha256'],$check)!=0) { return false; }
		return true;	// Hash values match expected value
	}
}

?>
