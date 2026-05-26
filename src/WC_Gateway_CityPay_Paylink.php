<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * CityPay – WooCommerce Gateway (Paylink)
 *
 * Notes:
 * - Paylink "create" is implemented in wc-paylink-client.php and posts to
 *   CITYPAY_PAYLINK_API_ROOT . '/create' (original Paylink 3 endpoint).
 * - Test vs Live for Paylink is carried via the JSON "test" flag in the payload.
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
	public $supports = array( 'products' );

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

		$context        = get_file_data( __DIR__ . '/wc-payment-gateway-citypay.php', array( 'version' => 'Version' ) );
		$this->version  = isset( $context['version'] ) ? $context['version'] : '0.0.0';

		$this->method_title       = __( 'CityPay', 'wc-payment-gateway-citypay' );
		$this->method_description = __( 'Accept payments using CityPay Paylink', 'wc-payment-gateway-citypay' );
		$this->icon               = plugin_dir_url( __FILE__ ) . 'assets/citypay-logo100.png';

		// Initialise logger & path BEFORE building form fields so the path renders in the settings UI.
		$this->log      = new WC_Logger();
		$this->log_path = trailingslashit( WC_LOG_DIR ) . 'citypay-' . sanitize_file_name( wp_hash( 'citypay' ) ) . '.log';

		$this->has_fields = false;

		$this->init_form_fields();   // uses $this->log_path in the "Debug Log" description
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

		$postback_base      = $this->get_option( 'postback_base', get_site_url() );
		$this->postback_url = trailingslashit( $postback_base ) . 'wc-api/citypay-postback';

		$this->init_subscriptions();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_citypay-postback', array( $this, 'check_postback' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
	}

	public function admin_options() {
		include_once 'admin_options.php';
	}

	public function init_form_fields() {

		// Ensure a non-empty path even if constructor order changes in the future.
		$path = $this->log_path ?: trailingslashit( WC_LOG_DIR ) . 'citypay-' . sanitize_file_name( wp_hash( 'citypay' ) ) . '.log';

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
			'client_id' => array(
				'title'       => __( 'Client ID', 'wc-payment-gateway-citypay' ),
				'type'        => 'text',
				'description' => __( 'Your CityPay Client ID (required for Paylink token creation, subscriptions and API auth).', 'wc-payment-gateway-citypay' ),
				'default'     => '',
				'placeholder' => 'Client ID',
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

			// Subscriptions (if WC Subscriptions present)
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

			// Marks / card logos
			'card_logo_section' => array(
				'title'       => __( 'Show Which Payment Cards on Checkout?', 'wc-payment-gateway-citypay' ),
				'type'        => 'title',
				'description' => __( 'Choose which marks appear next to CityPay at checkout.', 'wc-payment-gateway-citypay' ),
			),
			'show_visa_logo' => array(
				'title'   => __( 'Visa', 'wc-payment-gateway-citypay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Visa logo at checkout', 'wc-payment-gateway-citypay' ),
				'default' => 'no',
			),
			'show_mastercard_logo' => array(
				'title'   => __( 'Mastercard', 'wc-payment-gateway-citypay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Mastercard logo at checkout', 'wc-payment-gateway-citypay' ),
				'default' => 'no',
			),
			'show_maestro_logo' => array(
				'title'   => __( 'Maestro', 'wc-payment-gateway-citypay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show Maestro logo at checkout', 'wc-payment-gateway-citypay' ),
				'default' => 'no',
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
				'description' => __( 'Use while testing. Disable when you are ready to take live transactions.', 'wc-payment-gateway-citypay' ),
			),
			'debug' => array(
				'title'       => __( 'Debug Log', 'wc-payment-gateway-citypay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Debug logging', 'wc-payment-gateway-citypay' ),
				'default'     => 'no',
				'description' => sprintf(
					__( 'Logs events inside <code>%s</code>', 'wc-payment-gateway-citypay' ),
					esc_html( $path )
				),
			),
		);
	}

	/**
	 * Render checkout icons based on admin toggles.
	 */
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

		if ( $this->get_option( 'show_visa_logo', 'no' ) === 'yes' )         { $icons[] = sprintf( '<img src="%s" alt="Visa" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-vs-50h.png' ) ); }
		if ( $this->get_option( 'show_mastercard_logo', 'no' ) === 'yes' )   { $icons[] = sprintf( '<img src="%s" alt="Mastercard" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-mc-50h.png' ) ); }
		if ( $this->get_option( 'show_maestro_logo', 'no' ) === 'yes' )      { $icons[] = sprintf( '<img src="%s" alt="Maestro" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-ma-50h.png' ) ); }
		if ( $this->get_option( 'show_visa_electron_logo', 'no' ) === 'yes' ) { $icons[] = sprintf( '<img src="%s" alt="Visa Electron" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-ve-50h.png' ) ); }
		if ( $this->get_option( 'show_amex_logo', 'no' ) === 'yes' )          { $icons[] = sprintf( '<img src="%s" alt="American Express" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-am-50h.png' ) ); }
		if ( $this->get_option( 'show_diners_club_logo', 'no' ) === 'yes' )   { $icons[] = sprintf( '<img src="%s" alt="Diners Club" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-dn-50h.png' ) ); }
		if ( $this->get_option( 'show_jcb_logo', 'no' ) === 'yes' )           { $icons[] = sprintf( '<img src="%s" alt="JCB" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-jc-50h.png' ) ); }
		if ( $this->get_option( 'show_apple_pay_logo', 'no' ) === 'yes' )     { $icons[] = sprintf( '<img src="%s" alt="Apple Pay" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-ap-50h.png' ) ); }
		if ( $this->get_option( 'show_google_pay_logo', 'no' ) === 'yes' )    { $icons[] = sprintf( '<img src="%s" alt="Google Pay" style="height:24px; margin-right:6px;" />', esc_url( $base . 'cs-logo-gp-50h.svg.png' ) ); }

		$html = implode( '', $icons );
		return apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
	}

	public function is_currency_supported() {
		return in_array( get_woocommerce_currency(), array( 'GBP', 'USD', 'EUR', 'AUD' ), true );
	}

	/**
	 * Generate Paylink token then hand back redirect URL to Woo.
	 *
	 * @param int $order_id
	 * @return string
	 * @throws Exception
	 */
	public function generate_paylink_url( $order_id ) {
		$order = wc_get_order( $order_id );
		try {
			if ( is_null( $this->paylink ) ) { $this->paylink = new CityPay_PayLink( $this ); }

			$order_num = ltrim( $order->get_order_number(), '#' );
			$order_key = $order->get_order_key();
			$cart_id   = $this->t_ident_prefix . $order_id;

			$cart_desc = trim( $this->cart_desc );
			if ( $cart_desc === '' ) { $cart_desc = 'Order ' . $order_num; }

			$this->paylink->setBaseCall(
				$this->merchant_id,
				$this->licence_key,
				$cart_id,
				$this->get_paylink_amount_for_order( $order ),
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

			// Subscriptions (if WC Subscriptions present and order contains a subscription)
			if ( $this->is_subscriptions_enabled() && function_exists( 'wcs_order_contains_subscription' ) ) {
				if ( wcs_order_contains_subscription( $order_id ) ) {
					$accountNo = $this->subscriptions_prefix . $order->get_customer_id() . bin2hex( random_bytes( 16 ) );
					$this->save_subscription_account_no_to_order( $order, $accountNo );

					$subscriptions   = wcs_get_subscriptions_for_order( $order_id );
					if ( ! empty( $subscriptions ) ) {
						$subscription    = array_values( $subscriptions )[0];
						$subscription_id = $subscription->get_id();

						$order->add_order_note( 'Added fields to create card holder account. Subscription ID: ' . $subscription_id );
						$this->update_entity_meta_value( $subscription, 'AccountNo', $accountNo );
						$subscription->add_order_note( 'Subscription AccountNo: ' . $accountNo );
						$this->paylink->addSubscriptionId( $subscription_id );
					} else {
						$order->add_order_note( 'Generated CityPay subscription AccountNo before subscription record was available.' );
						$this->debugLog( 'No subscription record found yet for order #' . $order_id . ' while generating Paylink URL.' );
					}

					$this->paylink->setOptionsAndAccountNo( $accountNo );
					$this->paylink->setRecurring( true );

					if ( $this->is_zero_amount_sync_subscription_order( $order ) ) {
						$this->paylink->setTxType( 'E' );
					}
				}
			}

			$paylinkToken = $this->paylink->createPaylinkToken();
			$paylink_token_id = $paylinkToken['token'] ?? $paylinkToken['id'] ?? '';
			$order->add_order_note( 'CityPay Paylink Token: ' . $paylink_token_id );
			$this->update_entity_meta_value( $order, 'CityPay Paylink Token', $paylink_token_id );

			return $paylinkToken['url'];

		} catch ( Exception $e ) {
			$order->add_order_note( $e->getMessage() );
			if ( $this->log ) { $this->log->error( 'Error generating PayLink URL: ' . $e->getMessage(), array( 'source' => 'citypay' ) ); }
			throw $e;
		}
	}

	public function process_payment( $order_id ) {
		if ( ! $this->is_currency_supported() ) {
			throw new Exception( __( 'You cannot use this currency with CityPay.', 'wc-payment-gateway-citypay' ) );
		}
		$url = $this->generate_paylink_url( $order_id );
		return array( 'result' => 'success', 'redirect' => $url );
	}

	protected function get_paylink_amount_for_order( $order ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			return 0;
		}

		$total = $order->get_total();

		if ( $this->is_subscriptions_enabled()
			&& class_exists( 'WC_Subscriptions_Order' )
			&& function_exists( 'wcs_order_contains_subscription' )
			&& function_exists( 'citypay_is_synchronised_subscription_product' )
			&& wcs_order_contains_subscription( $order->get_id() ) ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();

				if ( $product && citypay_is_synchronised_subscription_product( $product ) ) {
					$total = WC_Subscriptions_Order::get_total_initial_payment( $order );
					$this->debugLog( 'Using synchronized subscription initial payment amount for order #' . $order->get_id() . ': ' . $total );
					break;
				}
			}
		}

		return $this->formatedAmount( $total );
	}

	protected function is_zero_amount_sync_subscription_order( $order ) {
		if ( ! ( $order instanceof WC_Order ) || ! $this->is_subscriptions_enabled() ) {
			return false;
		}

		if ( ! function_exists( 'wcs_order_contains_subscription' ) || ! wcs_order_contains_subscription( $order->get_id() ) ) {
			return false;
		}

		if ( ! function_exists( 'citypay_is_synchronised_subscription_product' ) ) {
			return false;
		}

		$has_synced_product = false;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			if ( $product && citypay_is_synchronised_subscription_product( $product ) ) {
				$has_synced_product = true;
				break;
			}
		}

		if ( ! $has_synced_product ) {
			return false;
		}

		return $this->get_paylink_amount_for_order( $order ) === 0;
	}

	protected function get_subscription_account_meta_key() {
		return '_citypay_account_no';
	}

	protected function get_entity_meta_value( $entity, $key ) {
		if ( $entity instanceof WC_Data ) {
			return $entity->get_meta( $key, true );
		}

		return '';
	}

	protected function update_entity_meta_value( $entity, $key, $value ) {
		if ( ! ( $entity instanceof WC_Data ) ) {
			return;
		}

		$entity->update_meta_data( $key, $value );
		$entity->save_meta_data();
	}

	protected function save_subscription_account_no_to_order( $order, $accountNo ) {
		if ( ! ( $order instanceof WC_Order ) || empty( $accountNo ) ) {
			return;
		}

		$this->update_entity_meta_value( $order, $this->get_subscription_account_meta_key(), $accountNo );
	}

	protected function sync_subscription_account_no_from_order( $order ) {
		if ( ! ( $order instanceof WC_Order ) || ! $this->is_subscriptions_enabled() || ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}

		$accountNo = $this->get_entity_meta_value( $order, $this->get_subscription_account_meta_key() );
		if ( empty( $accountNo ) ) {
			return;
		}

		$subscriptions = wcs_get_subscriptions_for_order( $order->get_id() );
		if ( empty( $subscriptions ) ) {
			$this->debugLog( 'No subscriptions found to sync AccountNo for order #' . $order->get_id() );
			return;
		}

		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription || ! method_exists( $subscription, 'get_id' ) ) {
				continue;
			}

			$subscription_id = $subscription->get_id();
			$this->update_entity_meta_value( $subscription, 'AccountNo', $accountNo );
			$subscription->add_order_note( 'Subscription AccountNo: ' . $accountNo );
			$this->debugLog( 'Synced AccountNo to subscription #' . $subscription_id . ' for order #' . $order->get_id() );
		}
	}

	/**
	 * SHA256 digest checker.
	 */
	public function checkSha256( $hash_src, $sha256Response ) {
		$check = base64_encode( hash( 'sha256', $hash_src, true ) );
		if ( strcmp( $sha256Response, $check ) !== 0 ) {
			if ( $this->log ) { $this->log->warning( 'Digest mismatch', array( 'source' => 'citypay' ) ); }
			throw new Exception( 'Digest mismatch' );
		}
		if ( $this->log ) { $this->log->info( 'Data is valid, digest matched "' . $check . '"', array( 'source' => 'citypay' ) ); }
		return true;
	}

	public function validatePostbackDigest( $postback_data ) {
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

	public function validateChargeResponseData( $d ) {
		$hash_src =
			$d['authcode'] .
			$d['amount'] .
			$d['result_code'] .
			$d['merchantid'] .
			$d['transno'] .
			$d['identifier'] .
			$this->licence_key;

		$this->checkSha256( $hash_src, $d['sha256'] );
	}

	public function get_merchant_id() {
		return ( $this->is_subscriptions_enabled() && $this->cp_subscriptions === 'yes' && ! empty( $this->subs_merchant_id ) )
			? $this->subs_merchant_id
			: $this->merchant_id;
	}

	/**
	 * Handle Paylink postback, store attribution meta, complete order.
	 */
	public function check_postback() {
		try {
			$this->debugLog( 'check_postback invoked. method=' . ( $_SERVER['REQUEST_METHOD'] ?? '' ) . ' uri=' . ( $_SERVER['REQUEST_URI'] ?? '' ) );

			$pl_orderkey = isset( $_GET['pl_orderkey'] ) ? sanitize_text_field( wp_unslash( $_GET['pl_orderkey'] ) ) : null;
			$pl_orderid  = isset( $_GET['order_id'] ) ? sanitize_text_field( wp_unslash( $_GET['order_id'] ) ) : null;

			if ( ! $pl_orderkey || ! $pl_orderid ) {
				$this->debugLog( 'check_postback skipped: missing pl_orderkey or order_id' );
				return;
			}
			@ob_clean();
			$this->debugLog( 'check_postback params: order_id=' . $pl_orderid . ', pl_orderkey_present=' . ( $pl_orderkey ? 'yes' : 'no' ) );

			$order = wc_get_order( $pl_orderid );
			if ( ! $order ) {
				$this->errorLog( 'check_postback: order not found for order_id=' . $pl_orderid );
				header( 'HTTP/1.1 200 OK' );
				return;
			}

			$this->debugLog( 'check_postback found order #' . $order->get_id() . ' status=' . $order->get_status() );

			if ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
				$this->debugLog( 'check_postback skipped: order already complete-ish status=' . $order->get_status() );
				header( 'HTTP/1.1 200 OK' );
				return;
			}

			$raw = file_get_contents( 'php://input' );
			$this->debugLog(
				'check_postback input meta: content_type=' . ( $_SERVER['CONTENT_TYPE'] ?? '' ) .
				', content_length=' . ( $_SERVER['CONTENT_LENGTH'] ?? '' ) .
				', raw_len=' . strlen( (string) $raw )
			);
			if ( empty( $raw ) ) { throw new Exception( 'No http post data' ); }

			$postback_data = array_change_key_case( json_decode( $raw, true ), CASE_LOWER );
			if ( is_null( $postback_data ) ) {
				$this->errorLog( 'check_postback json decode failed: ' . json_last_error_msg() . '; raw_head=' . substr( (string) $raw, 0, 300 ) );
				throw new Exception( 'No postback data' );
			}
			$this->debugLog( 'check_postback keys: ' . implode( ',', array_keys( $postback_data ) ) );

			$this->validatePostbackDigest( $postback_data );
			$this->debugLog( 'check_postback digest validation passed for order #' . $order->get_id() );

			$trans_no     = $postback_data['transno'] ?? '';
			$authcode     = $postback_data['authcode'] ?? '';
			$authorised   = $postback_data['authorised'] ?? ( $postback_data['isauthorised'] ?? false );
			$b_authorised = is_string( $authorised ) ? strtolower( $authorised ) === 'true' : (bool) $authorised;
			$expmonth     = isset( $postback_data['expmonth'] ) ? str_pad( $postback_data['expmonth'], 2, '0', STR_PAD_LEFT ) : '';
			$is_test      = ( isset( $postback_data['mode'] ) && $postback_data['mode'] === 'test' );
			$this->debugLog(
				'check_postback parsed: transno=' . $trans_no .
				', authcode=' . $authcode .
				', authorised_raw=' . wp_json_encode( $authorised ) .
				', authorised_type=' . gettype( $authorised ) .
				', authorised_bool=' . ( $b_authorised ? 'true' : 'false' ) .
				', expmonth=' . ( $postback_data['expmonth'] ?? '' ) .
				', expyear=' . ( $postback_data['expyear'] ?? '' ) .
				', mode=' . ( $postback_data['mode'] ?? '' )
			);

			if ( $b_authorised ) {
				$this->debugLog( 'check_postback entering authorised branch for order #' . $order->get_id() );
				$order->update_meta_data( 'CityPay TransNo', $trans_no );
				if ( isset( $postback_data['identifier'] ) ) {
					$order->update_meta_data( 'CityPay Identifier', $postback_data['identifier'] );
				}
				$maskedpan_long =
					( $postback_data['cardscheme'] ?? '' ) . '/' .
					( $postback_data['maskedpan'] ?? '' ) . ' ' .
					( $postback_data['expyear'] ?? '' ) . '/' . $expmonth;
				$order->update_meta_data( 'Card used', $maskedpan_long );

				// Attribution meta for admin UI
				$amount_minor   = isset( $postback_data['amount'] ) ? (int) $postback_data['amount'] : 0;
				$currency_code  = isset( $postback_data['currency'] ) ? strtoupper( (string) $postback_data['currency'] ) : $order->get_currency();
				$amount_display = function_exists( 'wc_price' )
					? wc_price( $amount_minor / 100, array( 'currency' => $currency_code ) )
					: ( $currency_code . ' ' . number_format( $amount_minor / 100, 2 ) );

				$card_scheme   = isset( $postback_data['cardscheme'] ) ? ucwords( strtolower( (string) $postback_data['cardscheme'] ) ) : '';
				$name_on_card  = (string) ( $postback_data['name_on_card'] ?? '' );
				$masked_pan    = (string) ( $postback_data['maskedpan'] ?? '' );
				$dt_iso        = (string) ( $postback_data['datetime'] ?? '' );
				$dt_display    = $dt_iso;
				if ( ! empty( $dt_iso ) ) { $ts = strtotime( $dt_iso ); if ( $ts ) { $dt_display = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ); } }

				$order->update_meta_data( '_cp_attrib_authcode',         (string) $authcode );
				$order->update_meta_data( '_cp_attrib_amount_display',   $amount_display );
				$order->update_meta_data( '_cp_attrib_authorised_display', 'Yes' );
				$order->update_meta_data( '_cp_attrib_card_scheme',      $card_scheme );
				$order->update_meta_data( '_cp_attrib_name_on_card',     $name_on_card );
				$order->update_meta_data( '_cp_attrib_masked_pan',       $masked_pan );
				$order->update_meta_data( '_cp_attrib_transno',          (string) $trans_no );
				$order->update_meta_data( '_cp_attrib_datetime_iso',     $dt_iso );
				$order->update_meta_data( '_cp_attrib_datetime_display', $dt_display );
				$order->save_meta_data();

				$order->add_order_note( sprintf(
					'%s CityPay Postback Payment OK. TransNo: %s, AuthCode: %s',
					( $is_test ? 'Test' : '' ),
					$trans_no,
					$authcode
				) );
				$this->debugLog( 'check_postback before payment_complete for order #' . $order->get_id() . ' current_status=' . $order->get_status() );
				$order->payment_complete();
				$this->sync_subscription_account_no_from_order( $order );
				$this->debugLog( 'check_postback payment_complete done for order #' . $order->get_id() . ' new_status=' . $order->get_status() );
				header( 'HTTP/1.1 200 OK' );
				return;
			}

			$this->debugLog( 'check_postback entering declined branch for order #' . $order->get_id() );
			$order->add_order_note( sprintf(
				'CityPay Postback Payment Not Authorised, TransNo: %s. Result: %s Error: %s: %s.',
				$trans_no,
				( $postback_data['result'] ?? '' ),
				( $postback_data['errorid'] ?? '' ),
				( $postback_data['errormessage'] ?? '' )
			) );
			$order->update_status( 'failed' );
			header( 'HTTP/1.1 200 OK' );
			return;

		} catch ( Throwable $e ) {
			$this->errorLog( 'check_postback exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			$this->debugLog( 'check_postback trace: ' . $e->getTraceAsString() );
			wp_die( 'CityPay Postback Error: ' . esc_html( $e->getMessage() ) );
		}
	}

	public function receipt_page( $order_id ) {
		$url = esc_url( $this->generate_paylink_url( $order_id ) );
		echo '<p>' . esc_html__( 'Thank you for your order, please click the button below to pay with CityPay.', 'wc-payment-gateway-citypay' ) . '</p>';
		printf( '<a class="button alt" href="%s">%s</a>', $url, esc_html__( 'Pay with CityPay', 'wc-payment-gateway-citypay' ) );
	}

	/**
	 * Convert Woo amount (major units) to minor units integer.
	 */
	public function formatedAmount( $amount ) {
		return (int) round( (float) $amount * 100 );
	}
}
