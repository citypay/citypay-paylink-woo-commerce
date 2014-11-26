<?php
/*
Plugin Name: CityPay Payments
Plugin URI: http://www.citypay.com/
Description: CityPay PayLink Payment Pages for WooCommerce
Version: 1.0.0
Author: CityPay
Author URI: http://www.citypay.com/
License: GPL2
*/

if (!defined('ABSPATH')) { exit; } // Exit if accessed directly
if (!defined('WP_CONTENT_URL')) { define('WP_CONTENT_URL', get_option('siteurl').'/wp-content'); }
if (!defined('WP_PLUGIN_URL')) { define('WP_PLUGIN_URL', WP_CONTENT_URL.'/plugins'); }
if (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', ABSPATH.'wp-content'); }
if (!defined('WP_PLUGIN_DIR')) { define('WP_PLUGIN_DIR', WP_CONTENT_DIR.'/plugins'); }

if(!function_exists('pl_wc_list_network_plugins')) {
	function pl_wc_list_network_plugins() {
		if (!is_multisite()) {
			return false;
		}
		$sitewide_plugins = array_keys((array) get_site_option('active_sitewide_plugins'));
		if (!is_array($sitewide_plugins)) {
			return false;
		}
		return $sitewide_plugins;
	}
}

if (in_array('woocommerce/woocommerce.php', (array)get_option('active_plugins')) || in_array('woocommerce/woocommerce.php', (array)pl_wc_list_network_plugins())) {
	add_action('plugins_loaded', 'citypay_woocommerce_init', 0);
	add_filter('woocommerce_payment_gateways', 'citypay_woocommerce_add_gateway');
}

function citypay_woocommerce_init() {
	class WC_Gateway_CityPay extends WC_Payment_Gateway {
		public $min_wc_ver="1.6.0";
		public $id = 'citypay';
		public $icon;
		public $has_fields = false;
		public $method_title;
		public $title;
		public $settings;
		public $woocom_ver;
		public $woocom_is_v2;
		private $paylink = null;
		private $postback_url;

		public function __construct() {
			global $woocommerce;

			$this->woocom_ver=$woocommerce->version;

			if (version_compare($this->woocom_ver,'2.0.0')>=0) {
				// WooCommerce 2.0.0 or later
				$this->woocom_is_v2=true;
			} else {
				$this->woocom_is_v2=false;
			}

			$this->id = 'citypay';
			$this->icon = '';
			$this->has_fields = false;	// No additional fields in checkout page
			$this->method_title = __('CityPay', 'woocommerce');

			// Load the settings.
			$this->init_paylink();
			$this->init_form_fields();	// Config page fields
			$this->init_settings();
			if (version_compare($this->woocom_ver,$this->min_wc_ver)>=0) {

				$this->postback_url = str_replace('https:', 'http:', add_query_arg('wc-api', 'WC_Gateway_CityPay', home_url('/')));
				$this->title			= $this->get_config_option('title');
				$this->description		= $this->get_config_option('description');
				$this->merchant_curr		= $this->get_config_option('merchant_curr');
				$this->merchant_id		= $this->get_config_option('merchant_id');
				$this->cart_desc		= $this->get_config_option('cart_desc');
				$this->licence_key		= $this->get_config_option('licence_key');
				$this->testmode			= $this->get_config_option('testmode');
				$this->debug			= $this->get_config_option('debug');
				$this->form_submission_method	= true;
				$this->endpoint			= 'https://secure.citypay.com/paylink3/';
				$this->testurl			= $this->endpoint;
				$this->liveurl			= $this->endpoint;

				// Logs
				if ('yes'==$this->debug) {
					$this->log = $woocommerce->logger();
				}

				if ($this->woocom_is_v2) {
					// Actions
					add_action('valid-citypay-postback', array($this, 'successful_request'));
					add_action('woocommerce_receipt_citypay', array($this, 'receipt_page'));
					add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));
					// Add hook for postbacks
					add_action('woocommerce_api_wc_gateway_citypay', array($this, 'check_postback'));
				} else {
					// Actions
					add_action('valid-citypay-postback', array(&$this, 'successful_request'));
					add_action('woocommerce_receipt_citypay', array(&$this, 'receipt_page'));
					add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
					// Add hook for postbacks
					add_action('init', array(&$this, 'check_postback') );
				}
			} else {
				$this->enabled = false;
			}
		}

		public function init_paylink() {
			if (!class_exists('CityPay_PayLink')) {
				require_once 'includes/paylink.php';
			}
			if (is_null($this->paylink)) {
				$this->paylink = new CityPay_PayLink($this);
			}
		}

		public function get_config_option($key) {
			if ($this->woocom_is_v2) {
				return $this->get_option($key);
			} else {
				return $this->settings[$key];
			}
		}

		public function getCurrencyConfig($conf_num) {
			if ($conf_num==1) { return $this->merchant_curr; }
			return null;
		}

		public function getMerchantConfig($conf_num) {
			if ($conf_num==1) { return $this->merchant_id; }
			return null;
		}

		public function getLicenceConfig($conf_num) {
			if ($conf_num==1) { return $this->licence_key; }
			return null;
		}

		/* Check if the module is available for the current checkout process */
		public function is_available() {
			$this->init_paylink();
			if ($this->enabled === "yes") {
				// Enabled, check that the currency is supported
				if ($this->paylink->canUseForCurrency(get_woocommerce_currency())==false) {
					// Currency not supported
					return false;
				}
				// Enabled and currency supported
				return true;
			}
			// Not enabled
			return false;
		}

		public function admin_options() {
			if (version_compare($this->woocom_ver,$this->min_wc_ver)>=0) {
				$this->show_admin_options();
			} else {
				$this->not_available();
			}
		}

		public function not_available() {
			?>
			<div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woocommerce' ); ?></strong>: <?php _e( sprintf('Requires WooCommerce %s or later',$this->min_wc_ver), 'woocommerce' ); ?></p></div>
			<?php
		}

		public function show_admin_options() {
			// Admin Panel Options
			$configured = true;
			if ((empty($this->merchant_curr)) || (empty($this->merchant_id)) || (empty($this->licence_key))) { $configured=false; }

			?>
			<h3><?php _e('CityPay PayLink', 'woocommerce'); ?></h3>
			<?php if (!$configured) : ?>
				<div id="wc_get_started">
				<span class="main"><?php _e('CityPay PayLink Payment Page', 'woocommerce'); ?></span>
				<span><a href="http://www.citypay.com/" target="_blank">CityPay</a> <?php _e('are a PCI DSS Level 1 certified payment gateway. We guarantee that we will handle the storage, processing and transmission of your customer\'s cardholder data in a manner which meets or exceeds the highest standards in the industry.', 'woocommerce'); ?></span>
				<span><br><b>NOTE: </b> You must enter your merchant ID and licence key</span>
				</div>
			<?php else : ?>
				<p><?php _e('CityPay PayLink Payment Page', 'woocommerce'); ?></p>
			<?php endif; ?>

			<table class="form-table">
			<?php $this->generate_settings_html(); ?>
			</table><!--/.form-table-->
			<?php
		}

		function init_form_fields() {
			// Initialise Gateway Settings Form Fields
			$this->form_fields = array(
				'enabled' => array(
					'title'		=> __('Enable/Disable', 'woocommerce'),
					'type'		=> 'checkbox',
					'label'		=> __('Enable CityPay', 'woocommerce'),
					'default'	=> 'yes'
				),
				'title' => array(
					'title'		=> __('Title', 'woocommerce'),
					'type'		=> 'text',
					'description'	=> __('This controls the payment method title which the user sees during checkout.', 'woocommerce'),
					'default'	=> __('Credit/Debit card', 'woocommerce'),
					'desc_tip'	=> true,
				),
				'description' => array(
					'title'		=> __('Description', 'woocommerce'),
					'type'		=> 'textarea',
					'description'	=> __('This controls the payment method description which the user sees during checkout.', 'woocommerce'),
					'default'	=> __('Pay using a credit or debit card via CityPay', 'woocommerce'),
					'desc_tip'	=> true,
				),
				'cart_desc' => array(
					'title'		=> __('Transaction description', 'woocommerce'),
					'type'		=> 'text',
					'description'	=> __('This controls the transaction description shown within the CityPay PayLink payment page.', 'woocommerce'),
					'default'	=> __('Your order from StoreName', 'woocommerce'),
					'desc_tip'	=> true,
				),
				'merchant_id' => array(
					'title'		=> __('Merchant ID', 'woocommerce'),
					'type'		=> 'text',
					'description'	=> __('Enter your CityPay Merchant ID.', 'woocommerce'),
					'default'	=> '',
					'desc_tip'	=> true,
					'placeholder'	=> '[MerchantID]'
				),
				'merchant_curr' => array(
					'title'		=> __('Merchant Currency', 'woocommerce'),
					'type'		=> 'text',
					'description'	=> __('Enter the currency code for your CityPay merchant account.', 'woocommerce'),
					'default'	=> '',
					'desc_tip'	=> true,
					'placeholder'	=> '[Currency]'
				),
				'licence_key' => array(
					'title'		=> __('Licence Key', 'woocommerce'),
					'type'		=> 'text',
					'description'	=> __('Enter your CityPay PayLink licence key.', 'woocommerce'),
					'default'	=> '',
					'desc_tip'	=> true,
					'placeholder'	=> '[LicenceKey]'
				),
				'testmode' => array(
					'title'		=> __('Test Mode', 'woocommerce'),
					'type'		=> 'checkbox',
					'label'		=> __('Generate transaction is test mode', 'woocommerce'),
					'default'	=> 'yes',
					'description'	=> __('Use this whilst testing your integration. You must disable test mode when you are ready to take live transactions'),
				),
				'debug' => array(
					'title'		=> __('Debug Log', 'woocommerce'),
					'type'		=> 'checkbox',
					'label'		=> __('Enable logging', 'woocommerce'),
					'default'	=> 'no',
					'description'	=> sprintf(__('Log payments events, such as postback requests, inside <code>woocommerce/logs/citypay-%s.txt</code>', 'woocommerce'), sanitize_file_name(wp_hash('citypay'))),
				)
			);
			if (!$this->woocom_is_v2) {
				$this->form_fields['debug']['description'] =
					__('Log payments events, such as postback requests, inside <code>woocommerce/logs/citypay.txt</code>', 'woocommerce');
			}
		}

		function debugLog($text) {
			if ('yes'==$this->debug) {
				$this->log->add('citypay', $text);
			}
		}

		function get_request_url($order) {
			// Get transaction details for passing to payment page
			global $woocommerce;

			$this->init_paylink();

			$order_num	= ltrim($order->get_order_number(), '#');
			$order_id	= $order->id;
			$order_key	= $order->order_key;

			if ('yes'==$this->debug) {
				$this->log->add('citypay', 'Generating payment form for order '.$order_num);
				$this->log->add('citypay', 'OrderID '.$order_id);
				$this->log->add('citypay', 'OrderKey '.$order_key);
			}
			// Authorised (Thank your page)
			$return_url = add_query_arg('utm_nooverride', '1', $this->get_return_url($order));
			$cancel_url = $order->get_cancel_order_url();
			$postback_url = $this->postback_url.'&pl_orderkey='.$order_key;
			$cart_id = 'OrderID#'.$order_id;
			$cart_desc=trim($this->cart_desc);
			if (empty($cart_desc)) { $cart_desc='Order {order_id}'; }
			$cart_desc = preg_replace('/{order_id}/i',$order_num,$cart_desc);

			$currency = get_woocommerce_currency();
			$conf = $this->paylink->getCurrencyConfig($currency);

			if (is_array($conf) && !empty($conf)) {
				$mid	= $conf[0];
				$key	= $conf[1];
			} else {
				$message = 'Order currency '.$currency.' not configured';
				$this->log->add('citypay', $message);
				throw new Exception($message);
			}

			$price = (int)number_format((float)$order->get_order_total(),2,'','');
			if ($this->testmode=='yes') {
				$testmode=true;
			} else {
				$testmode=false;
			}

			$this->paylink->setRequestCart(
				$mid,
				$key,
				$cart_id,
				$price,
				$cart_desc);
			$this->paylink->setRequestClient('WooCommerce', $this->woocom_ver);
			$this->paylink->setRequestAddress(
				$order->billing_first_name,
				$order->billing_last_name,
				$order->billing_address_1,
				$order->billing_address_2,
				$order->billing_city,
				$order->billing_state,
				$order->billing_postcode,
				$order->billing_country,
				$order->billing_email,
				$order->billing_phone);
			$this->paylink->setRequestConfig(
				$testmode,
				$postback_url,
				$return_url,
				$cancel_url);
			try {
				$paylink_url=$this->paylink->getPaylinkURL();
			} catch (Exception $e) {
				$message=$e->getMessage();
				$this->log->add('citypay', 'Error getting PayLink URL: '.$message);
				throw new Exception($message);
			}
			return $paylink_url;

		}

		function generate_redirect_form($order_id) {
			// Generate the form to send the payment details to the gateway
			global $woocommerce;

			$order = new WC_Order($order_id);
			$paylink_url = $this->get_request_url($order);

			echo '<p>'.__('Thank you for your order, please click the button below to pay via CityPay.', 'woocommerce').'</p>';

			$m1=esc_js(__('Thank you for your order.', 'woocommerce'));
			$m2=esc_js(__('You will now be transferred to the secure payment pages.', 'woocommerce'));

			$woocommerce->add_inline_js('
				jQuery("body").block({
					message: "'.$m1.'<br>'.$m2.'",
					baseZ: 99999,
					overlayCSS:
					{
						background: "#fff",
						opacity: 0.6
					},
					css: {
						padding:	"20px",
						zindex:		"9999999",
						textAlign:	"center",
						color:		"#555",
						border:		"3px solid #aaa",
						backgroundColor:"#fff",
						cursor:		"wait",
						lineHeight:	"24px",
					}
				});
				jQuery("#submit_citpay_payment_form").click();
			');
			echo '<form action="'.esc_url($paylink_url).'" method="get" id="citpay_payment_form" target="_top">'.
				'<input type="submit" class="button alt" id="submit_citpay_payment_form" value="'.__('Pay via CityPay', 'woocommerce').'" /> <a class="button cancel" href="'.esc_url($order->get_cancel_order_url()).'">'.__('Cancel order &amp; restore cart', 'woocommerce').'</a></form>';
		}

		function process_payment($order_id) {
			// Process the payment and return the result

			$order = new WC_Order($order_id);

			return array(
				'result'	=> 'success',
				'redirect'	=> add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
			);
		}

		function receipt_page($order) {
			// Output for the order received page.
			global $woocommerce;
			try {
				$this->generate_redirect_form($order);
			} catch (Exception $e) {
				$error="Sorry, unable to process your order at this time.";
				if (function_exists('wc_add_notice')) {
					wc_add_notice( __( $error, 'woocommerce' ), 'error' );
				} else {
					$woocommerce->add_error( __($error, 'woocommerce') );
					$woocommerce->show_messages();
				}
			}
		}

		function check_postback_is_valid() {
			// Check postback is valid (check signature using secret key)
			global $woocommerce;

			if ('yes'==$this->debug) {
				$this->log->add('citypay', 'Checking postback is valid...');
			}
			$order_key=$_GET['pl_orderkey'];
			$this->init_paylink();
			$postback_data = $this->paylink->getPostbackData();
			if (is_null($postback_data)) {
				$this->log->add('citypay', 'Not postback data');
				throw new Exception('No postback data');
			}
			$conf=$this->paylink->getCurrencyConfig($postback_data['currency']);
			if (is_array($conf) && !empty($conf)) {
				$mid	= (int)$conf[0];
				$key	= $conf[1];
			} else {
				$this->log->add('citypay', 'Order currency not configured');
				throw new Exception('Order currency not configured');
			}
			if (!$this->paylink->validatePostbackData($postback_data,$key)) {
				$this->log->add('citypay', 'Unable to validate postback data');
				throw new Exception('Unable to validate postback data');
			}
			if ('yes'==$this->debug) {
				$this->log->add('citypay', 'Postback data is valid');
			}
			// Add the WooCommerce order key into the postback data
			$postback_data['order_key']=$order_key;
			return $postback_data;
		}

		function check_postback() {
			// Check for postback requests
			if (isset($_GET['pl_orderkey'])) {
				@ob_clean();	// Erase output buffer
				try {
					$postback_data=$this->check_postback_is_valid();
					header('HTTP/1.1 200 OK');
					do_action("valid-citypay-postback", $postback_data);
				} catch (Exception $e) {
					wp_die("Postback Error");
				}
			}
		}

		function successful_request($postback_data) {
			// Postback has been recieved and validated, update order details
			global $woocommerce;
			$onhold=0;
			$postback_data = stripslashes_deep($postback_data);
			$order = $this->get_citypay_order($postback_data);
			$tranref=$postback_data['transno'];
			if ('yes'==$this->debug) {
				$this->log->add('citypay', 'Found order #'.$order->id);
				$this->log->add('citypay', 'Transaction ref '.$tranref);
				$this->log->add('citypay', 'Status '.$order->status);
			}
			// Check order not already completed
			if ($order->status=='completed') {
				if ('yes'==$this->debug) {
					$this->log->add('citypay', 'Aborting, Order #'.$order->id.' is already complete.');
				}
				exit;
			}
			// Check transaction status
			if ($this->paylink->isAuthorised($postback_data)) {
				// Transaction authorised
				if ('yes'==$this->debug) {
					$this->log->add('citypay', 'Authorised');
				}
				update_post_meta($order->id, 'Transaction ID', $tranref);
				if (!empty($postback_data['maskedpan'])) {
					update_post_meta($order->id, 'Card used', $postback_data['maskedpan']);
				}
				$surcharge=floatval($postback_data['surcharge']);
				if ($surcharge>0) {
					// Surcharge was added. Include in the additional data for the transaction,
					// using the text version sent in the results.
					update_post_meta($order->id, 'Surcharge', $postback_data['surcharge']);
				}
				$order->add_order_note(sprintf(__('Payment completed, ref %s.', 'woocommerce'),$tranref));
				$order->payment_complete();
				if ('yes'==$this->debug) {
					$this->log->add('citypay', 'Payment complete.');
				}
			} else {
				// Declined/Cancelled
				if ('yes'==$this->debug) {
					$this->log->add('citypay', 'Not authorised: '.$postback_data['errorid'].' '.$postback_data['errormessage']);
				}
				$order->update_status('failed', sprintf(__('Payment %s declined - %s: %s.', 'woocommerce'), $tranref, $postback_data['errorid'], $postback_data['errormessage']));
			}
			exit;
		}

		function get_citypay_order($postback_data) {
			// Identify the order from the cart ID
			$order_id	= preg_replace('/^OrderID#/','',$postback_data['identifier']);
			$order_key	= $postback_data['order_key'];
			if ($this->debug=='yes') {
				$this->log->add('citypay', 'Order ID '.$order_id.', key '.$order_key);
			}
			$order = new WC_Order($order_id);
			// Validate key
			if ($order->order_key!==$order_key) {
				if ($this->debug=='yes') {
					$this->log->add('citypay', 'Error: Order Key does not match invoice.');
					$this->log->add('citypay', 'Expected '.$order->order_key);
				}
				exit;
			}
			return $order;
		}
	}
}

function citypay_woocommerce_add_gateway($methods) {
	$methods[] = 'WC_Gateway_CityPay';
	return $methods;
}

?>
