<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CityPay – WooCommerce Gateway (Paylink)
 *
 * Paylink "create" lives in wc-paylink-client.php, using CITYPAY_PAYLINK_API_ROOT.'/create'
 * (original behaviour). Test-vs-live for Paylink is carried via the JSON "test" flag.
 */

require_once dirname( __FILE__ ) . '/WC_Gateway_CityPay.php';
require_once dirname( __FILE__ ) . '/wc-paylink-client.php';
require_once dirname( __FILE__ ) . '/trait-wc-gateway-cp-subscriptions.php';

class WC_Gateway_CityPayPaylink extends WC_Gateway_CityPay {

    use WC_Gateway_CP_Subscriptions;

    public $id = 'citypay';
    public $method_title;
    public $method_description;
    public $title;
    public $description;
    public $icon;
    public $has_fields = false;

    public $merchant_curr;
    public $merchant_id;
    public $cp_subscriptions;
    public $subs_merchant_id;
    public $client_id;
    public $licence_key;
    public $version;
    public $cart_desc;
    public $t_ident_prefix;

    /** @var CityPay_PayLink|null */
    public $paylink = null;

    public $postback_url;
    public $testmode;
    public $debug;
    /** @var WC_Logger */
    public $log;
    public $log_path;

    public function __construct() {
        parent::__construct();

        $context       = get_file_data( __DIR__ . '/wc-payment-gateway-citypay.php', array( 'version' => 'Version' ) );
        $this->version = isset( $context['version'] ) ? $context['version'] : '0.0.0';

        $this->method_title       = __( 'CityPay', 'wc-payment-gateway-citypay' );
        $this->method_description = __( 'Accept payments using CityPay Paylink', 'wc-payment-gateway-citypay' );
        $this->icon               = plugin_dir_url( __FILE__ ) . 'assets/citypay-logo100.png';

        $this->has_fields = false;
        $this->supports   = array( 'products' );

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled            = $this->get_option( 'enabled' );
        $this->testmode           = $this->get_option( 'testmode', 'yes' );
        $this->debug              = $this->get_option( 'debug', 'no' );
        $this->title              = $this->get_option( 'title', __( 'Credit/Debit card', 'wc-payment-gateway-citypay' ) );
        $this->description        = $this->get_option( 'description', __( 'Pay using a credit or debit card via CityPay', 'wc-payment-gateway-citypay' ) );
        $this->merchant_curr      = $this->get_option( 'merchant_curr', 'GBP' );
        $this->merchant_id        = $this->get_option( 'merchant_id', '' );
        $this->cp_subscriptions   = $this->get_option( 'cp_subscriptions', 'no' );
        $this->subs_merchant_id   = $this->get_option( 'subs_merchant_id', '' );
        $this->client_id          = $this->get_option( 'client_id', '' );
        $this->subscriptions_prefix = $this->get_option( 'subscriptions_prefix', '' );
        $this->cart_desc          = $this->get_option( 'cart_desc', __( 'Your order from StoreName', 'wc-payment-gateway-citypay' ) );
        $this->t_ident_prefix     = $this->get_option( 't_ident_prefix', 'OrderID#' );
        $this->licence_key        = $this->get_option( 'licence_key', '' );

        $this->log      = new WC_Logger();
        $this->log_path = trailingslashit( WC_LOG_DIR ) . 'citypay-' . sanitize_file_name( wp_hash( 'citypay' ) ) . '.log';

        $postback_base      = $this->get_option( 'postback_base', get_site_url() );
        $this->postback_url = trailingslashit( $postback_base ) . 'wc-api/citypay-postback';

        $this->init_subscriptions();

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_api_citypay-postback', array( $this, 'check_postback' ) );
        add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
        add_action( 'init', array( $this, 'check_postback' ) );
    }

    public function admin_options() {
        include_once 'admin_options.php';
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'wc-payment-gateway-citypay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable CityPay', 'wc-payment-gateway-citypay' ),
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => __( 'Title', 'wc-payment-gateway-citypay' ),
                'type'        => 'text',
                'description' => __( 'Payment method title visible at checkout.', 'wc-payment-gateway-citypay' ),
                'default'     => __( 'Credit/Debit card', 'wc-payment-gateway-citypay' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'wc-payment-gateway-citypay' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description visible at checkout.', 'wc-payment-gateway-citypay' ),
                'default'     => __( 'Pay using a credit or debit card via CityPay', 'wc-payment-gateway-citypay' ),
                'desc_tip'    => true,
            ),
            'merchant_id' => array(
                'title'       => __( 'Merchant ID', 'wc-payment-gateway-citypay' ),
                'type'        => 'text',
                'description' => __( 'Your CityPay Merchant ID.', 'wc-payment-gateway-citypay' ),
                'default'     => '',
                'placeholder' => 'Merchant ID',
                'desc_tip'    => true,
            ),
            'licence_key' => array(
                'title'       => __( 'Licence Key', 'wc-payment-gateway-citypay' ),
                'type'        => 'text',
                'description' => __( 'Your CityPay Paylink licence key.', 'wc-payment-gateway-citypay' ),
                'default'     => '',
                'placeholder' => 'Licence Key',
                'desc_tip'    => true,
            ),
            'merchant_curr' => array(
                'title'       => __( 'Merchant Currency', 'wc-payment-gateway-citypay' ),
                'type'        => 'select',
                'description' => __( 'Currency code for your CityPay merchant account.', 'wc-payment-gateway-citypay' ),
                'default'     => 'GBP',
                'desc_tip'    => true,
                'options'     => array(
                    'GBP' => '&pound; GBP',
                    'USD' => '$ USD',
                    'EUR' => '&euro; EUR',
                    'AUD' => '$ AUD',
                ),
            ),
            'cart_desc' => array(
                'title'       => __( 'Transaction description', 'wc-payment-gateway-citypay' ),
                'type'        => 'text',
                'description' => __( 'Shown on the CityPay Paylink payment page.', 'wc-payment-gateway-citypay' ),
                'default'     => __( 'Your order from StoreName', 'wc-payment-gateway-citypay' ),
                'desc_tip'    => true,
            ),
            't_ident_prefix' => array(
                'title'       => __( 'Transaction identifier prefix', 'wc-payment-gateway-citypay' ),
                'type'        => 'text',
                'description' => __( 'Prefix (5–50 chars) concatenated with Order ID, e.g. "OrderID#".', 'wc-payment-gateway-citypay' ),
                'default'     => 'OrderID#',
            ),
            'postback_base' => array(
                'title'       => __( 'Postback Site Address (URL)', 'wc-payment-gateway-citypay' ),
                'type'        => 'url',
                'description' => __( 'Base site URL to construct the postback endpoint.', 'wc-payment-gateway-citypay' ),
                'default'     => get_site_url(),
            ),

            // Subscriptions
            'subscriptions' => array(
                'title' => __( 'Subscriptions', 'wc-payment-gateway-citypay' ),
                'type'  => 'title',
            ),
            'cp_subscriptions' => array(
                'title'       => __( 'Subscriptions Enable/Disable', 'wc-payment-gateway-citypay' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Subscriptions', 'wc-payment-gateway-citypay' ),
                'default'     => 'no',
                'description' => __( 'Accept WooCommerce Subscriptions via CityPay.', 'wc-payment-gateway-citypay' ),
            ),
            'client_id' => array(
                'title'       => __( 'Client ID', 'wc-payment-gateway-citypay' ),
                'type'        => 'text',
                'description' => __( 'Your CityPay Client ID (required for subscriptions and API auth).', 'wc-payment-gateway-citypay' ),
                'default'     => '',
                'placeholder' => 'Client ID',
            ),
            'subscriptions_prefix' => array(
                'title'       => __( 'Subscriptions Prefix', 'wc-payment-gateway-citypay' ),
                'type'        => 'text',
                'description' => __( 'Different prefixes for each store using the same Client ID. Max length: 8', 'wc-payment-gateway-citypay' ),
                'default'     => '',
                'placeholder' => 'Subscriptions Prefix',
            ),
            'subs_merchant_id' => array(
                'title'       => __( 'Subscriptions Merchant ID', 'wc-payment-gateway-citypay' ),
                'type'        => 'text',
                'description' => __( 'If empty, main Merchant ID is used.', 'wc-payment-gateway-citypay' ),
                'default'     => '',
                'placeholder' => 'Subscriptions Merchant ID',
            ),

            // Card / mark logos controls
            'card_logo_section' => array(
                'title'       => __( 'Show Which Payment Cards on Checkout?', 'wc-payment-gateway-citypay' ),
                'type'        => 'title',
                'description' => __( 'Choose which marks appear next to CityPay at checkout.', 'wc-payment-gateway-citypay' ),
            ),
            'show_visa_logo' => array(
                'title'   => __( 'Visa', 'wc-payment-gateway-citypay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Show Visa logo at checkout', 'wc-payment-gateway-citypay' ),
                'default' => 'yes',
            ),
            'show_mastercard_logo' => array(
                'title'   => __( 'Mastercard', 'wc-payment-gateway-citypay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Show Mastercard logo at checkout', 'wc-payment-gateway-citypay' ),
                'default' => 'yes',
            ),
            'show_maestro_logo' => array(
                'title'   => __( 'Maestro', 'wc-payment-gateway-citypay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Show Maestro logo at checkout', 'wc-payment-gateway-citypay' ),
                'default' => 'yes',
            ),
            'show_visa_electron_logo' => array(
                'title'   => __( 'Visa Electron', 'wc-payment-gateway-citypay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Show Visa Electron logo at checkout', 'wc-payment-gateway-citypay' ),
                'default' => 'no',
            ),
            'show_amex_logo' => array(
                'title'   => __( 'American Express', 'wc-payment-gateway-citypay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Show American Express logo at checkout', 'wc-payment-gateway-citypay' ),
                'default' => 'no',
            ),
            'show_diners_club_logo' => array(
                'title'   => __( 'Diners Club', 'wc-payment-gateway-citypay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Show Diners Club logo at checkout', 'wc-payment-gateway-citypay' ),
                'default' => 'no',
            ),
            'show_jcb_logo' => array(
                'title'   => __( 'JCB', 'wc-payment-gateway-citypay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Show JCB logo at checkout', 'wc-payment-gateway-citypay' ),
                'default' => 'no',
            ),
            'show_apple_pay_logo' => array(
                'title'   => __( 'Apple&nbsp;Pay', 'wc-payment-gateway-citypay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Show Apple Pay mark at checkout', 'wc-payment-gateway-citypay' ),
                'default' => 'no',
            ),
            'show_google_pay_logo' => array(
                'title'   => __( 'Google&nbsp;Pay', 'wc-payment-gateway-citypay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Show Google Pay mark at checkout', 'wc-payment-gateway-citypay' ),
                'default' => 'no',
            ),

            // Test / Debug
            'test' => array(
                'title' => __( 'Test', 'wc-payment-gateway-citypay' ),
                'type'  => 'title',
            ),
            'testmode' => array(
                'title'       => __( 'Test Mode', 'wc-payment-gateway-citypay' ),
                'type'        => 'checkbox',
                'label'       => __( 'Generate transaction in test mode', 'wc-payment-gateway-citypay' ),
                'default'     => 'yes',
            ),
            'debug' => array(
                'title'       => __( 'Debug Log', 'wc-payment-gateway-citypay' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Debug logging', 'wc-payment-gateway-citypay' ),
                'default'     => 'no',
                'description' => sprintf( __( 'Logs inside <code>%s</code>', 'wc-payment-gateway-citypay' ), esc_html( $this->log_path ) ),
            ),
        );
    }

    public function get_icon() {
        $icons = array();

        // CityPay mark
        $main_logo = plugin_dir_url( __FILE__ ) . 'assets/citypay-logo100.png';
        $icons[]   = sprintf(
            '<img src="%s" alt="%s" style="height:24px; margin-right:6px;" />',
            esc_url( $main_logo ),
            esc_attr__( 'CityPay', 'wc-payment-gateway-citypay' )
        );

        $base = plugin_dir_url( __FILE__ ) . 'assets/cards/';

        if ( $this->get_option( 'show_visa_logo', 'yes' ) === 'yes' ) {
            $icons[] = sprintf( '<img src="%s" alt="Visa" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-vs-50h.png' ) );
        }
        if ( $this->get_option( 'show_mastercard_logo', 'yes' ) === 'yes' ) {
            $icons[] = sprintf( '<img src="%s" alt="Mastercard" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-mc-50h.png' ) );
        }
        if ( $this->get_option( 'show_maestro_logo', 'yes' ) === 'yes' ) {
            $icons[] = sprintf( '<img src="%s" alt="Maestro" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-ma-50h.png' ) );
        }
        if ( $this->get_option( 'show_visa_electron_logo', 'no' ) === 'yes' ) {
            $icons[] = sprintf( '<img src="%s" alt="Visa Electron" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-ve-50h.png' ) );
        }
        if ( $this->get_option( 'show_amex_logo', 'no' ) === 'yes' ) {
            $icons[] = sprintf( '<img src="%s" alt="American Express" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-am-50h.png' ) );
        }
        if ( $this->get_option( 'show_diners_club_logo', 'no' ) === 'yes' ) {
            $icons[] = sprintf( '<img src="%s" alt="Diners Club" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-dn-50h.png' ) );
        }
        if ( $this->get_option( 'show_jcb_logo', 'no' ) === 'yes' ) {
            $icons[] = sprintf( '<img src="%s" alt="JCB" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-jc-50h.png' ) );
        }
        if ( $this->get_option( 'show_apple_pay_logo', 'no' ) === 'yes' ) {
            $icons[] = sprintf( '<img src="%s" alt="Apple Pay" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-ap-50h.png' ) );
        }
        if ( $this->get_option( 'show_google_pay_logo', 'no' ) === 'yes' ) {
            $icons[] = sprintf( '<img src="%s" alt="Google Pay" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-gp-50h.svg.png' ) );
        }

        $html = implode( '', $icons );
        return apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
    }

    public function is_currency_supported() {
        return in_array( get_woocommerce_currency(), array( 'GBP', 'USD', 'EUR', 'AUD' ), true );
    }

    public function generate_paylink_url( $order_id ) {
        $order = wc_get_order( $order_id );
        try {
            $this->debugLog( 'get_request_url(' . $order_id . ')' );

            if ( is_null( $this->paylink ) ) {
                $this->paylink = new CityPay_PayLink( $this );
            }

            $order_num = ltrim( $order->get_order_number(), '#' );
            $order_key = $order->get_order_key();
            $cart_id   = $this->t_ident_prefix . $order_id;

            $cart_desc = trim( $this->cart_desc );
            if ( $cart_desc === '' ) {
                $cart_desc = 'Order ' . $order_num;
            }

            $this->paylink->setBaseCall(
                $this->merchant_id,
                $this->licence_key,
                $cart_id,
                $this->formatedAmount( $order->get_total() ),
                get_woocommerce_currency(),
                $cart_desc
            );

            $this->paylink->setRequestClient( $this->version );

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
                ( $this->testmode === 'yes' ),
                $this->postback_url . '?order_id=' . $order_id . '&pl_orderkey=' . $order_key,
                add_query_arg( 'utm_nooverride', '1', $this->get_return_url( $order ) ),
                $order->get_cancel_order_url()
            );

            // Subscriptions
            if ( $this->is_subscriptions_enabled() && function_exists( 'wcs_order_contains_subscription' ) ) {
                if ( wcs_order_contains_subscription( $order_id ) ) {
                    $subscriptions   = wcs_get_subscriptions_for_order( $order_id );
                    $subscription    = array_values( $subscriptions )[0];
                    $subscription_id = $subscription->get_id();

                    $order->add_order_note( 'Added fields to create card holder account. Subscription ID: ' . $subscription_id );

                    $accountNo = $this->subscriptions_prefix . $order->get_customer_id() . bin2hex( random_bytes( 16 ) );
                    update_post_meta( $subscription_id, 'AccountNo', $accountNo );
                    $subscription->add_order_note( 'Subscription AccountNo: ' . $accountNo );

                    $this->paylink->addSubscriptionId( $subscription_id );
                    $this->paylink->setOptionsAndAccountNo( $accountNo );
                    $this->paylink->setRecurring( true );
                }
            }

            $paylinkToken = $this->paylink->createPaylinkToken();
            $order->add_order_note( 'CityPay Paylink Token: ' . $paylinkToken['id'] );
            update_post_meta( $order->get_id(), 'CityPay Paylink Token', $paylinkToken['id'] );

            return $paylinkToken['url'];

        } catch ( Exception $e ) {
            $message = $e->getMessage();
            $order->add_order_note( $message );
            $this->errorLog( 'Error generating PayLink URL: ' . $e );
            throw new Exception( $message );
        }
    }

    public function process_payment( $order_id ) {
        if ( ! $this->is_currency_supported() ) {
            throw new Exception( __( 'You cannot use this currency with CityPay.', 'wc-payment-gateway-citypay' ) );
        }
        $this->debugLog( 'process_payment(' . $order_id . ')' );
        $url = $this->generate_paylink_url( $order_id );

        return array(
            'result'   => 'success',
            'redirect' => $url,
        );
    }

    public function checkSha256( $hash_src, $sha256Response ) {
        $check = base64_encode( hash( 'sha256', $hash_src, true ) );
        if ( strcmp( $sha256Response, $check ) !== 0 ) {
            $this->warningLog( 'Digest mismatch' );
            throw new Exception( 'Digest mismatch' );
        }
        $this->infoLog( 'Data is valid, digest matched "' . $check . '"' );
        return true;
    }

    public function validatePostbackDigest( $postback_data ) {
        $this->debugLog( 'validatePostbackData()' );
        $hash_src =
            $postback_data['authcode'] .
            $postback_data['amount'] .
            $postback_data['errorcode'] .
            $postback_data['merchantid'] .
            $postback_data['transno'] .
            $postback_data['identifier'] .
            $this->licence_key;

        $this->checkSha256( $hash_src, $postback_data['sha256'] );
    }

    public function validateChargeResponseData( $chargeResponseData ) {
        $this->debugLog( 'validateChargeResponseData()' );
        $hash_src =
            $chargeResponseData['authcode'] .
            $chargeResponseData['amount'] .
            $chargeResponseData['result_code'] .
            $chargeResponseData['merchantid'] .
            $chargeResponseData['transno'] .
            $chargeResponseData['identifier'] .
            $this->licence_key;

        $this->checkSha256( $hash_src, $chargeResponseData['sha256'] );
    }

    public function get_merchant_id() {
        $subs_merchant_id = $this->subs_merchant_id;
        if ( $this->is_subscriptions_enabled() && $this->cp_subscriptions === 'yes' && ! empty( $subs_merchant_id ) ) {
            return $subs_merchant_id;
        }
        return $this->merchant_id;
    }

    public function check_postback() {
        try {
            $pl_orderkey = isset( $_GET['pl_orderkey'] ) ? sanitize_text_field( wp_unslash( $_GET['pl_orderkey'] ) ) : null;
            $pl_orderid  = isset( $_GET['order_id'] ) ? sanitize_text_field( wp_unslash( $_GET['order_id'] ) ) : null;

            if ( ! $pl_orderkey || ! $pl_orderid ) {
                return;
            }

            @ob_clean();

            $order = wc_get_order( $pl_orderid );
            if ( ! $order ) {
                $this->errorLog( 'Postback for missing order ' . $pl_orderid );
                header( 'HTTP/1.1 200 OK' );
                return;
            }

            $this->debugLog( 'Current order status: ' . $order->get_status() );

            if ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
                $this->debugLog( 'Aborting, Order #' . $order->get_id() . ' is already complete.' );
                header( 'HTTP/1.1 200 OK' );
                return;
            }

            $this->debugLog( 'Checking postback is valid... ' . $pl_orderkey . ',' . $pl_orderid );

            $raw = file_get_contents( 'php://input' );
            if ( empty( $raw ) ) {
                $this->errorLog( 'No http post data' );
                throw new Exception( 'No http post data' );
            }

            $this->debugLog( $raw );

            $postback_data = array_change_key_case( json_decode( $raw, true ), CASE_LOWER );
            if ( is_null( $postback_data ) ) {
                $this->errorLog( 'No postback data' );
                throw new Exception( 'No postback data' );
            }

            $this->validatePostbackDigest( $postback_data );

            $trans_no     = isset( $postback_data['transno'] ) ? $postback_data['transno'] : '';
            $authcode     = isset( $postback_data['authcode'] ) ? $postback_data['authcode'] : '';
            $authorised   = isset( $postback_data['authorised'] ) ? $postback_data['authorised'] : ( isset( $postback_data['isauthorised'] ) ? $postback_data['isauthorised'] : false );
            $b_authorised = is_string( $authorised ) ? strtolower( $authorised ) === 'true' : (bool) $authorised;
            $expmonth     = isset( $postback_data['expmonth'] ) ? str_pad( $postback_data['expmonth'], 2, '0', STR_PAD_LEFT ) : '';
            $is_test      = ( isset( $postback_data['mode'] ) && $postback_data['mode'] === 'test' );

            $this->debugLog( 'Found order:      #' . $order->get_id() );
            $this->debugLog( 'order status:     ' . $order->get_status() );
            $this->debugLog( 'Authorised:       ' . ( $b_authorised ? 'true' : 'false' ) );
            $this->debugLog( 'Incoming transNo: ' . $trans_no );

            if ( $b_authorised ) {
                update_post_meta( $order->get_id(), 'CityPay TransNo', $trans_no );
                if ( isset( $postback_data['identifier'] ) ) {
                    update_post_meta( $order->get_id(), 'CityPay Identifier', $postback_data['identifier'] );
                }
                $maskedpan_long =
                    ( isset( $postback_data['cardscheme'] ) ? $postback_data['cardscheme'] : '' ) . '/' .
                    ( isset( $postback_data['maskedpan'] ) ? $postback_data['maskedpan'] : '' ) . ' ' .
                    ( isset( $postback_data['expyear'] ) ? $postback_data['expyear'] : '' ) . '/' . $expmonth;
                update_post_meta( $order->get_id(), 'Card used', $maskedpan_long );

                // Attribution
                $authcode_val   = isset( $postback_data['authcode'] ) ? (string) $postback_data['authcode'] : '';
                $amount_minor   = isset( $postback_data['amount'] ) ? (int) $postback_data['amount'] : 0;
                $currency_code  = isset( $postback_data['currency'] ) ? strtoupper( (string) $postback_data['currency'] ) : $order->get_currency();
                $amount_display = function_exists( 'wc_price' )
                    ? wc_price( $amount_minor / 100, array( 'currency' => $currency_code ) )
                    : ( $currency_code . ' ' . number_format( $amount_minor / 100, 2 ) );

                $authorised_disp = 'Yes';
                $card_scheme = '';
                if ( isset( $postback_data['cardscheme'] ) ) {
                    $card_scheme = ucwords( strtolower( (string) $postback_data['cardscheme'] ) );
                }
                $name_on_card = isset( $postback_data['name_on_card'] ) ? (string) $postback_data['name_on_card'] : '';
                $masked_pan   = isset( $postback_data['maskedpan'] ) ? (string) $postback_data['maskedpan'] : '';
                $transno_val  = $trans_no;

                $dt_iso = isset( $postback_data['datetime'] ) ? (string) $postback_data['datetime'] : '';
                $dt_display = $dt_iso;
                if ( ! empty( $dt_iso ) ) {
                    $ts = strtotime( $dt_iso );
                    if ( $ts ) {
                        $dt_display = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
                    }
                }

                update_post_meta( $order->get_id(), '_cp_attrib_authcode',           $authcode_val );
                update_post_meta( $order->get_id(), '_cp_attrib_amount_display',     $amount_display );
                update_post_meta( $order->get_id(), '_cp_attrib_authorised_display', $authorised_disp );
                update_post_meta( $order->get_id(), '_cp_attrib_card_scheme',        $card_scheme );
                update_post_meta( $order->get_id(), '_cp_attrib_name_on_card',       $name_on_card );
                update_post_meta( $order->get_id(), '_cp_attrib_masked_pan',         $masked_pan );
                update_post_meta( $order->get_id(), '_cp_attrib_transno',            $transno_val );
                update_post_meta( $order->get_id(), '_cp_attrib_datetime_iso',       $dt_iso );
                update_post_meta( $order->get_id(), '_cp_attrib_datetime_display',   $dt_display );

                $order->add_order_note( sprintf(
                    __( '%s CityPay Postback Payment OK. TransNo: %s, AuthCode: %s', 'wc-payment-gateway-citypay' ),
                    ( $is_test ? 'Test' : '' ),
                    $trans_no,
                    $authcode_val
                ) );
                $order->payment_complete();
                $this->debugLog( 'Authorised, Payment complete.' );
                header( 'HTTP/1.1 200 OK' );
                return;
            }

            // Declined
            $this->debugLog( 'Declined' );
            $this->debugLog( 'Not authorised: ' . ( $postback_data['errorid'] ?? '' ) . ' ' . ( $postback_data['errormessage'] ?? '' ) );
            $order->add_order_note( sprintf(
                __( 'CityPay Postback Payment Not Authorised, TransNo: %s. Result: %s Error: %s: %s.', 'wc-payment-gateway-citypay' ),
                $trans_no,
                ( $postback_data['result'] ?? '' ),
                ( $postback_data['errorid'] ?? '' ),
                ( $postback_data['errormessage'] ?? '' )
            ) );
            $order->update_status( 'failed' );
            header( 'HTTP/1.1 200 OK' );
            return;

        } catch ( Exception $e ) {
            wp_die( 'CityPay Postback Error: ' . esc_html( $e->getMessage() ) );
        }
    }

    public function receipt_page( $order_id ) {
        $url = esc_url( $this->generate_paylink_url( $order_id ) );
        echo '<p>' . esc_html__( 'Thank you for your order, please click the button below to pay with CityPay.', 'wc-payment-gateway-citypay' ) . '</p>';
        printf( '<a class="button alt" href="%s">%s</a>', $url, esc_html__( 'Pay with CityPay', 'wc-payment-gateway-citypay' ) );
    }

    public function debugLog( $msg ) {
        if ( 'yes' === $this->debug ) {
            $this->log->debug( $msg, array( 'source' => 'citypay' ) );
        }
    }
    public function infoLog( $msg ) {
        $this->log->info( $msg, array( 'source' => 'citypay' ) );
    }
    public function warningLog( $msg ) {
        $this->log->warning( $msg, array( 'source' => 'citypay' ) );
    }
    public function errorLog( $msg ) {
        $this->log->error( $msg, array( 'source' => 'citypay' ) );
    }

    /**
     * Convert major to minor units (integer).
     */
    public function formatedAmount( $amount ) {
        return (int) round( (float) $amount * 100 );
    }
}