<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once(dirname(__FILE__) . '/WC_Gateway_CityPay.php');
require_once(dirname(__FILE__) . '/wc-paylink-client.php');
require_once dirname(__FILE__) . '/trait-wc-gateway-cp-subscriptions.php';

/** @noinspection PhpUndefinedClassInspection */

class WC_Gateway_CityPayPaylink extends WC_Gateway_CityPay
{
    use WC_Gateway_CP_Subscriptions;
    public $id;
    public $title;
    public $icon;
    public $has_fields = false;
    public $method_title;
    public $merchant_curr;
    public $merchant_id;
    public $cp_subscriptions;
    public $subs_merchant_id;
    public $client_id;
    public $licence_key;
    public $version;
    public $cart_desc;
    public $t_ident_prefix;

    /**
     * @var CityPay_PayLink paylink object
     */
    public $paylink = null;
    public $postback_url;

    public function __construct()
    {
        parent::__construct();

        $this->id = 'citypay';
        $context = get_file_data(__DIR__ . '/wc-payment-gateway-citypay.php', ['version' => 'Version']);
        $this->version = $context['version'];


        $this->enabled = $this->get_option('enabled');
        $this->debug = $this->get_option('debug');
        $this->icon = plugin_dir_url(__FILE__) . 'assets/logo-x500.png';
        $this->testmode = $this->get_option('testmode');
        $this->has_fields = false;    // No additional fields in checkout page
        $this->log = new WC_Logger();
        $this->method_title = __('CityPay', 'wc-payment-gateway-citypay');
        $this->method_description = __('Accept payments using CityPay Paylink', 'wc-payment-gateway-citypay');

        $this->supports = array(
            'products',
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->merchant_curr = $this->get_option('merchant_curr');
        $this->merchant_id = $this->get_option('merchant_id');
        $this->cp_subscriptions = $this->get_option('cp_subscriptions');
        $this->subs_merchant_id = $this->get_option('subs_merchant_id');
        $this->client_id = $this->get_option('client_id');
        $this->subscriptions_prefix = $this->get_option('subscriptions_prefix');
        $this->cart_desc = $this->get_option('cart_desc');
        $this->t_ident_prefix = $this->get_option('t_ident_prefix');
        $this->licence_key = $this->get_option('licence_key');

        $this->form_submission_method = true;

        $postback_base = $this->get_option('postback_base');
        $this->postback_url = trailingslashit($postback_base) . 'wc-api/citypay-postback';

        $this->init_subscriptions();

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_citypay-postback', array($this, 'check_postback'));
        // add_action('valid-citypay-postback', array($this, 'successful_request'));
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
                'placeholder' => 'Merchant ID'
            ),
            'licence_key' => array(
                'title' => __('Licence Key', 'wc-payment-gateway-citypay'),
                'type' => 'text',
                'description' => __('Enter your CityPay PayLink licence key.', 'wc-payment-gateway-citypay'),
                'default' => '',
                'desc_tip' => true,
                'placeholder' => 'Licence Key'
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
            't_ident_prefix' => array(
                'title' => __('Transaction identifier prefix', 'wc-payment-gateway-citypay'),
                'type' => 'text',
                'description' => __('A Identifier identifies a particular transaction linked to a Merchant account.', 'wc-payment-gateway-citypay'),
                'default' => __('OrderID#', 'wc-payment-gateway-citypay'),
                'desc_tip' => true,
            ),
            'postback_base' => array(
                'title' => __('Postback Site Address (URL)', 'wc-payment-gateway-citypay'),
                'type' => 'url',
                'description' => __('Enter the base postback URL if different. This value can centralise multiple stores or allow development to use remote reverse proxies for postback testing.', 'wc-payment-gateway-citypay'),
                'default' => get_site_url()
            ),
            'subscriptions' => array(
                'title' => __( 'Subscriptions', 'viva-wallet-for-woocommerce' ),
                'type' => 'title',
            ),
            'cp_subscriptions' => array(
                'title' => __('Subscriptions Enable/Disable', 'wc-payment-gateway-citypay'),
                'type' => 'checkbox',
                'label' => __('Enable Subscriptions', 'wc-payment-gateway-citypay'),
                'default' => 'no',
                'description' => __('Allows the plugin to accept subscriptions from the Woocommerce Subscription Plugin.'),
            ),
            'client_id' => array(
                'title' => __('Client ID', 'wc-payment-gateway-citypay'),
                'type' => 'text',
                'description' => __('Enter your CityPay Client ID to be able to process subscriptions.', 'wc-payment-gateway-citypay'),
                'default' => '',
                'placeholder' => 'Client ID'
            ),
            'subscriptions_prefix' => array(
                'title' => __('Subscriptions Prefix', 'wc-payment-gateway-citypay'),
                'type' => 'text',
                'description' => __('Enter a Subscription Prefix for this store. If you have others stores using the same Client ID, use a different prefix for each. (maxLength: 8)', 'wc-payment-gateway-citypay'),
                'default' => '',
                'placeholder' => 'Subscriptions Prefix'
            ),
            'subs_merchant_id' => array(
                'title' => __('Subscriptions Merchant ID', 'wc-payment-gateway-citypay'),
                'type' => 'text',
                'description' => __('Enter your CityPay Subscriptions Merchant ID. (If empty the MerchantID will be used).', 'wc-payment-gateway-citypay'),
                'default' => '',
                'placeholder' => 'Subscriptions Merchant ID'
            ),
            'test' => array(
                'title' => __( 'Test', 'viva-wallet-for-woocommerce' ),
                'type' => 'title',
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
            $cart_id = $this->t_ident_prefix . $order_id;
            $cart_desc = trim($this->cart_desc);
            if (empty($cart_desc)) {
                $cart_desc = 'Order ' . $order_num;
            }

            $this->paylink->setBaseCall(
                $this->merchant_id,
                $this->licence_key,
                $cart_id,
                $this->formatedAmount($order->get_total()),
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

            // add fields option and accountNo in PayLink token request to create cardholder account
            if ($this->is_subscriptions_enabled()) {
                if (wcs_order_contains_subscription($order_id)) {
                    $subscriptions = wcs_get_subscriptions_for_order($order_id);
                    $subscription = array_values($subscriptions)[0]; // Single subscription in the order.
                    $subscription_id = $subscription->get_id();
                    $order->add_order_note('Added fields to create card holder account. Subscription ID: ' . $subscription_id);
                    $accountNo = $this->subscriptions_prefix . $order->get_customer_id() . bin2hex(random_bytes(16)); //account Max Length is 50. subscriptions_prefix 8, customer_id 10, random_bytes 32
                    update_post_meta($subscription_id, 'AccountNo', $accountNo);
                    $subscription->add_order_note('Subscription AccountNo: ' . $accountNo);
                    $this->paylink->addSubscriptionId($subscription_id);
                    $this->paylink->setOptionsAndAccountNo($accountNo);
                }
            }

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
     * // Check both the sha256 hash values to ensure that results have not been tampered
     * @param $hash_src
     * @param $sha256Response
     * @return bool
     * @throws Exception
     */
    public function checkSha256($hash_src, $sha256Response) {
        $check = base64_encode(hash('sha256', $hash_src, true));
        if (strcmp($sha256Response, $check) != 0) {
            $this->warningLog('Digest mismatch');
            throw new Exception('Digest mismatch');
        }
        $this->infoLog('Data is valid, digest matched "' . $check . '"');
        return true;    // Hash values match expected value
    }

    /**
     * @param $postback_data
     * @return void
     * @throws Exception
     */
    public function validatePostbackDigest($postback_data)
    {
        $this->debugLog('validatePostbackData()');
        $hash_src = $postback_data['authcode'] .
            $postback_data['amount'] .
            $postback_data['errorcode'] .
            $postback_data['merchantid'] .
            $postback_data['transno'] .
            $postback_data['identifier'] .
            $this->licence_key;

        $this->checkSha256($hash_src, $postback_data['sha256']);
    }

    /**
     * Validates digest from charge (renewal) response
     * @param $chargeResponseData
     * @return void
     * @throws Exception
     */
    public function validateChargeResponseData($chargeResponseData)
    {
        $this->debugLog('validateChargeResponseData()');
        $hash_src = $chargeResponseData['authcode'] .
            $chargeResponseData['amount'] .
            $chargeResponseData['result_code'] .
            $chargeResponseData['merchantid'] .
            $chargeResponseData['transno'] .
            $chargeResponseData['identifier'] .
            $this->licence_key;

        $this->checkSha256($hash_src, $chargeResponseData['sha256']);
    }

    /**
     * Returns the merchantID to process renewals
     * @return mixed Merchant ID
     */
    function get_merchant_id() {
        $subs_merchant_id = $this->subs_merchant_id;

        if ($this->is_subscriptions_enabled() && $this->cp_subscriptions && !empty($subs_merchant_id)) {
            return $this->subs_merchant_id;
        }
        return $this->merchant_id;
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

                $this->debugLog('Current order status: ' . $order->status);

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

                $this->debugLog($HTTP_RAW_POST_DATA);

                $postback_data = array_change_key_case(json_decode($HTTP_RAW_POST_DATA, true), CASE_LOWER);
                if (is_null($postback_data)) {
                    $this->errorLog('No postback data');
                    throw new Exception('No postback data');
                }

                $this->validatePostbackDigest($postback_data);


                // Postback has been received and validated, update order details
                $postback_data = stripslashes_deep($postback_data);
                $trans_no = $postback_data['transno'];
                $authcode = $postback_data['authcode'];
                $authorised = $postback_data['authorised'];
                $b_authorised = is_string($authorised) ? strtolower($authorised) == 'true' : $authorised;
                $expmonth = str_pad($postback_data['expmonth'], 2, '0', STR_PAD_LEFT);
                $is_test = $postback_data['mode'] == 'test';

                $this->debugLog('Found order:      #' . $order->get_id());
                $this->debugLog('order status:     ' . $order->status);
                $this->debugLog('Authorised:       ' . ($b_authorised ? 'true' : 'false'));
                $this->debugLog('Incoming transNo: ' . $trans_no);


                if ($b_authorised) {

                    // Transaction authorised
                    update_post_meta($order->get_id(), 'CityPay TransNo', $trans_no);
                    update_post_meta($order->get_id(), 'CityPay Identifier', $postback_data['identifier']);
                    $maskedpan = $postback_data['cardscheme'] . '/' . $postback_data['maskedpan'] . ' ' . $postback_data['expyear'] . '/' . $expmonth;
                    update_post_meta($order->get_id(), 'Card used', $maskedpan);

                    $order->add_order_note(sprintf(__('%s CityPay Postback Payment OK. TransNo: %s, AuthCode: %s',
                        'wc-payment-gateway-citypay'), $is_test ? "Test" : "", $trans_no, $authcode));

                    $order->payment_complete();
                    $this->debugLog('Authorised, Payment complete.');
                    header('HTTP/1.1 200 OK');
                    return;
                }

                // Declined/Cancelled
                $this->debugLog('Declined');
                $this->debugLog('Not authorised: ' . $postback_data['errorid'] . ' ' . $postback_data['errormessage']);
                $order->add_order_note(sprintf(__('CityPay Postback Payment Not Authorised, TransNo: %s. Result: %s Error: %s: %s.', 'wc-payment-gateway-citypay'),
                    $trans_no, $postback_data['result'], $postback_data['errorid'], $postback_data['errormessage']));
                $order->update_status('failed');

            }

            header('HTTP/1.1 200 OK');


        } catch (Exception $e) {
            wp_die("CityPay Postback Error: " . esc_html_e($e->getMessage()));
        }

    }

}


?>