<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once(dirname(__FILE__) . '/WC_Gateway_CityPay.php');
require_once(dirname(__FILE__) . '/wc-paylink-client.php');

/** @noinspection PhpUndefinedClassInspection */
class WC_Gateway_CityPayPaylink extends WC_Gateway_CityPay
{

    public $id;
    public $title;
    public $icon;
    public $has_fields = false;
    public $method_title;
    public $merchant_curr;
    public $merchant_id;
    public $licence_key;
    public $version;
    public $cart_desc;

    /**
     * @var CityPay_PayLink paylink object
     */
    public $paylink = null;
    public $postback_url;

    public function __construct()
    {
        parent::__construct();

        $this->id = 'citypay';
        $context = get_file_data(__DIR__ . '/wc-payment-gateway-citypay.php', []);
        $this->version = $context['Version'];


        $this->enabled = $this->get_option('enabled');
        $this->debug = $this->get_option('debug');
        $this->icon = plugin_dir_url(__FILE__) . 'assets/logo-x500-greyscale.png';
        $this->testmode = $this->get_option('testmode');
        $this->has_fields = false;    // No additional fields in checkout page
        $this->log = new WC_Logger();
        $this->method_title = __('CityPay', 'wc-payment-gateway-citypay');
        $this->method_description = __('Accept payments using CityPay Paylink', 'wc-payment-gateway-citypay');
//        $this->supports = array('products');

        $this->init_form_fields();
        $this->init_settings();


        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->merchant_curr = $this->get_option('merchant_curr');
        $this->merchant_id = $this->get_option('merchant_id');
        $this->cart_desc = $this->get_option('cart_desc');
        $this->licence_key = $this->get_option('licence_key');

        $this->form_submission_method = true;

        $postback_base = $this->get_option('postback_base');
        $this->postback_url = trailingslashit($postback_base) . 'wc-api/citypay-postback';

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_citypay-postback', array($this, 'check_postback'));
//        add_action('valid-citypay-postback', array($this, 'successful_request'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        // Add hook for postbacks
        add_action('init', array($this, 'check_postback'));


    }


    public function admin_options()
    {
        include_once('admin_options.php');
    }


    function init_form_fields()
    {
        // reconstruct the path so we can show users where it is located
        $this->log_path = trailingslashit(WC_LOG_DIR) . 'citypay-' . sanitize_file_name(wp_hash('citypay')) . '.log';
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'wc-payment-gateway-citypay'),
                'type' => 'checkbox',
                'label' => __('Enable CityPay', 'wc-payment-gateway-citypay'),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Title', 'wc-payment-gateway-citypay'),
                'type' => 'text',
                'description' => __('This controls the payment method title which the user sees during checkout.', 'wc-payment-gateway-citypay'),
                'default' => __('Credit/Debit card', 'wc-payment-gateway-citypay'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'wc-payment-gateway-citypay'),
                'type' => 'textarea',
                'description' => __('This controls the payment method description which the user sees during checkout.', 'wc-payment-gateway-citypay'),
                'default' => __('Pay using a credit or debit card via CityPay', 'wc-payment-gateway-citypay'),
                'desc_tip' => true,
            ),
            'merchant_id' => array(
                'title' => __('Merchant ID', 'wc-payment-gateway-citypay'),
                'type' => 'text',
                'description' => __('Enter your CityPay Merchant ID.', 'wc-payment-gateway-citypay'),
                'default' => '',
                'desc_tip' => true,
                'placeholder' => '[MerchantID]'
            ),
            'licence_key' => array(
                'title' => __('Licence Key', 'wc-payment-gateway-citypay'),
                'type' => 'text',
                'description' => __('Enter your CityPay PayLink licence key.', 'wc-payment-gateway-citypay'),
                'default' => '',
                'desc_tip' => true,
                'placeholder' => '[LicenceKey]'
            ),
            'merchant_curr' => array(
                'title' => __('Merchant Currency', 'wc-payment-gateway-citypay'),
                'type' => 'select',
                'description' => __('Enter the currency code for your CityPay merchant account.', 'wc-payment-gateway-citypay'),
                'default' => 'GBP',
                'desc_tip' => true,
                'options' => array(
                    'GBP' => "&pound; GBP",
                    'USD' => "$ USD",
                    'EUR' => "&euro; EUR",
                    'AUD' => "$ AUD"
                )
            ),
            'cart_desc' => array(
                'title' => __('Transaction description', 'wc-payment-gateway-citypay'),
                'type' => 'text',
                'description' => __('This controls the transaction description shown within the CityPay PayLink payment page.', 'wc-payment-gateway-citypay'),
                'default' => __('Your order from StoreName', 'wc-payment-gateway-citypay'),
                'desc_tip' => true,
            ),
            'postback_base' => array(
                'title' => __('Postback Site Address (URL)', 'wc-payment-gateway-citypay'),
                'type' => 'url',
                'description' => __('Enter the base postback URL if different. This value can centralise multiple stores or allow development to use remote reverse proxies for postback testing.', 'wc-payment-gateway-citypay'),
                'default' => get_site_url()
            ),
            'testmode' => array(
                'title' => __('Test Mode', 'wc-payment-gateway-citypay'),
                'type' => 'checkbox',
                'label' => __('Generate transaction in test mode', 'wc-payment-gateway-citypay'),
                'default' => 'yes',
                'description' => __('Use this whilst testing your integration. You must disable test mode when you are ready to take live transactions'),
            ),
            'debug' => array(
                'title' => __('Debug Log', 'wc-payment-gateway-citypay'),
                'type' => 'checkbox',
                'label' => __('Enable Debug logging', 'wc-payment-gateway-citypay'),
                'default' => 'no',
                'description' => sprintf(__('Log payments events, such as postback requests, inside <code>%s</code>', 'wc-payment-gateway-citypay'), $this->log_path),
            )
        );
    }

    //function validate_licence_key_field($key);


    /**
     * Generates a CityPay Paylink 3 URL by constructing a JSON call to CityPay and returning a response object
     * @param $order int
     * @return mixed
     * @throws Exception
     */
    function generate_paylink_url($order_id)
    {
        try {

            $order = wc_get_order($order_id);
            $this->debugLog('get_request_url(' . $order_id . ')');

            if (is_null($this->paylink)) {
                $this->paylink = new CityPay_PayLink($this);
            }

            $order_num = ltrim($order->get_order_number(), '#');
            $order_key = $order->get_order_key();
            $cart_id = 'OrderID#' . $order_id;
            $cart_desc = trim($this->cart_desc);
            if (empty($cart_desc)) {
                $cart_desc = 'Order ' . $order_num;
            }

            $this->paylink->setBaseCall(
                $this->merchant_id,
                $this->licence_key,
                $cart_id,
                (int)number_format((float)$order->get_total(), 2, '', ''),
                get_woocommerce_currency(),
                $cart_desc
            );
            $this->paylink->setRequestClient($this->version);
            $this->paylink->setCardHolder(
                $order->get_billing_first_name(),
                $order->get_billing_last_name(),
                $order->get_billing_address_1(),
                $order->get_billing_address_2(),
                $order->get_billing_city(),
                $order->get_billing_state(),
                $order->get_billing_postcode(),
                $order->get_billing_country(),
                $order->get_billing_email()
            );
            $this->paylink->setRequestConfig(
                $this->testmode == 'yes',
                $this->postback_url . '?order_id=' . $order_id . '&pl_orderkey=' . $order_key,
                add_query_arg('utm_nooverride', '1', $this->get_return_url($order)),
                $order->get_cancel_order_url()
            );

            $paylinkToken = $this->paylink->createPaylinkToken();
            $order->add_order_note("CityPay Paylink Token: " . $paylinkToken['id']);
            update_post_meta($order->get_id(), 'CityPay Paylink Token', $paylinkToken['id']);
            return $paylinkToken['url'];

        } catch (Exception $e) {
            $message = $e->getMessage();
            $order->add_order_note($e->getMessage());
            $this->errorLog('Error generating PayLink URL: ' . $e);
            throw new Exception($message);
        }


    }

    function is_currency_supported()
    {
        return in_array(get_woocommerce_currency(), array('GBP', 'USD', 'EUR', 'AUD'));
    }

    /**
     * @param int $order_id
     * @return array
     * @throws Exception
     */
    function process_payment($order_id)
    {
        // Process the payment and return the result
        if (!$this->is_currency_supported()) {
            throw new Exception(__('You cannot use this currency with CityPay.', 'wc-payment-gateway-citypay'));
        }

        $this->debugLog("process_payment(" . $order_id . ')');
        $paylinkUrl = $this->generate_paylink_url($order_id);

        // seemingly we need to forward to a payment url (handled by receipt_page)
        return array(
            'result' => 'success',
            'redirect' => $paylinkUrl
        );
    }


    /**
     * @param $postback_data
     * @return bool
     * @throws Exception
     */
    public function validatePostbackDigest($postback_data)
    {
        $this->debugLog('validatePostbackData()');
        $this->debugLog($postback_data);
        $hash_src = $postback_data['authcode'] .
            $postback_data['amount'] .
            $postback_data['errorcode'] .
            $postback_data['merchantid'] .
            $postback_data['transno'] .
            $postback_data['identifier'] .
            $this->licence_key;
        // Check both the sha256 hash values to ensure that results have not
        // been tampered with
        $check = base64_encode(hash('sha256', $hash_src, true));
        if (strcmp($postback_data['sha256'], $check) != 0) {
            $this->warningLog('Digest mismatch');
            throw new Exception('Digest mismatch');
        }
        $this->infoLog('Postback data is valid, digest match');
        return true;    // Hash values match expected value
    }


    function check_postback()
    {
        try {
            // Check for postback requests
            $pl_orderkey = $_GET['pl_orderkey'];
            $pl_orderid = $_GET['order_id'];

            if (isset($pl_orderkey) && isset($pl_orderid)) {
                @ob_clean();    // Erase output buffer
                $order_key = sanitize_text_field($pl_orderkey);
                $order_id = sanitize_text_field($pl_orderid);
                $order = wc_get_order($order_id);

                // Check order not already completed
                // Most of the time this should mark an order as 'processing' so that admin can process/post the items.
                // If the cart contains only downloadable items then the order is 'completed' since the admin needs to take no action.
                if ($order->status == 'processing' || $order->status == 'completed') {
                    $this->debugLog('Aborting, Order #' . $order->get_id() . ' is already complete.');
                    header('HTTP/1.1 200 OK');
                    return;
                }

                $this->debugLog('Checking postback is valid... ' . $order_key . ',' . $order_id);

                // Check response data - need the raw post data, can't use the processed post value as data is
                // in json format and not name/value pairs
                $HTTP_RAW_POST_DATA = isset($HTTP_RAW_POST_DATA) ? $HTTP_RAW_POST_DATA : file_get_contents("php://input");
                if (empty($HTTP_RAW_POST_DATA)) {
                    $this->errorLog('No post data');
                    throw new Exception('No http post data');
                }

                $postback_data = array_change_key_case(json_decode($HTTP_RAW_POST_DATA, true), CASE_LOWER);
                if (is_null($postback_data)) {
                    $this->errorLog('No postback data');
                    throw new Exception('No postback data');
                }

                $this->validatePostbackDigest($postback_data);


                // Postback has been recieved and validated, update order details
                $postback_data = stripslashes_deep($postback_data);
                $trans_no = $postback_data['transno'];
                $authcode = $postback_data['authcode'];
                $result = $postback_data['authorised'];
                $expmonth = str_pad($postback_data['expmonth'], 2, '0', STR_PAD_LEFT);

                $this->debugLog('Found order #' . $order->get_id());
                $this->debugLog('Trans No ' . $trans_no);
                $this->debugLog('Status ' . $order->status);

                if ((is_string($result) && strtolower($result) === 'true') || $result) {

                    // Transaction authorised
                    update_post_meta($order->get_id(), 'CityPay TransNo', $trans_no);
                    update_post_meta($order->get_id(), 'CityPay Identifier', $postback_data['identifier']);
                    $maskedpan = $postback_data['cardscheme'] . '/' . $postback_data['maskedpan'] . ' ' . $postback_data['expyear'] . '/' . $expmonth;
                    update_post_meta($order->get_id(), 'Card used', $maskedpan);

                    if ($postback_data['mode'] == 'test') {
                        $order->add_order_note(sprintf(__('Test CityPay Postback Payment OK. TransNo: %s, AuthCode: %s', 'wc-payment-gateway-citypay'), $trans_no, $authcode));
                    } else {
                        $order->add_order_note(sprintf(__('CityPay Postback Payment OK. TransNo: %s, AuthCode: %s', 'wc-payment-gateway-citypay'), $trans_no, $authcode));
                    }

                    $order->payment_complete();
                    $this->debugLog('Authorised, Payment complete.');
                    header('HTTP/1.1 200 OK');
                    return;
                }

                // Declined/Cancelled
                $this->debugLog('Declined');
                $this->debugLog('Not authorised: ' . $postback_data['errorid'] . ' ' . $postback_data['errormessage']);
                $order->update_status('failed',
                    sprintf(__('CityPay Postback Payment Not Authorised, TransNo: %s. Result: %s Error: %s: %s.', 'wc-payment-gateway-citypay'),
                        $trans_no, $postback_data['result'], $postback_data['errorid'], $postback_data['errormessage'])
                );

            }

            header('HTTP/1.1 200 OK');


        } catch (Exception $e) {
            wp_die("CityPay Postback Error: " . sanitize_text_field($e->getMessage()));
        }

    }

}


?>