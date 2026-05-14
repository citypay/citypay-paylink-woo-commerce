<?php
/*
Plugin Name: CityPay WooCommerce Plugin
Plugin URI: https://github.com/citypay/citypay-paylink-woo-commerce
Description: Accept CityPay payments on your WooCommerce powered store!
Version: 2.1.11-beta1
Author: CityPay Limited
Author URI: https://citypay.com
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.en.html
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'CITYPAY_PAYMENTS_VERSION' ) ) {
	define( 'CITYPAY_PAYMENTS_VERSION', '2.1.11-beta1' );
}

/* -----------------------------------------------------------
 * WooCommerce dependency check (guarded for duplicate folders)
 * --------------------------------------------------------- */
if ( ! function_exists( 'citypay_gateway_wc_active' ) ) {
	function citypay_gateway_wc_active() {
		return class_exists( 'WooCommerce' ) && defined( 'WC_VERSION' );
	}
}
if ( ! function_exists( 'citypay_gateway_woocommerce_missing_notice' ) ) {
	function citypay_gateway_woocommerce_missing_notice() {
		echo '<div class="error"><p>' .
			esc_html__( 'CityPay Gateway plugin requires WooCommerce to be activated.', 'wc-payment-gateway-citypay' ) .
			'</p></div>';
	}
}
if ( ! function_exists( 'citypay_is_synchronised_subscription_product' ) ) {
	function citypay_is_synchronised_subscription_product( $product ) {
		if ( ! ( $product instanceof WC_Product ) || ! class_exists( 'WC_Subscriptions_Product' ) ) {
			return false;
		}

		if ( ! method_exists( 'WC_Subscriptions_Product', 'is_subscription' ) || ! WC_Subscriptions_Product::is_subscription( $product ) ) {
			return false;
		}

		$product_id = $product->get_id();
		if ( ! $product_id ) {
			return false;
		}

		$sync_date = get_post_meta( $product_id, '_subscription_payment_sync_date', true );

		if ( empty( $sync_date ) && method_exists( $product, 'get_parent_id' ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				$sync_date = get_post_meta( $parent_id, '_subscription_payment_sync_date', true );
			}
		}

		return ! empty( $sync_date );
	}
}

/* -----------------------
 * Bootstrap the gateway
 * --------------------- */
add_action( 'plugins_loaded', function () {

	if ( ! citypay_gateway_wc_active() ) {
		add_action( 'admin_notices', 'citypay_gateway_woocommerce_missing_notice' );
		return;
	}

	// Core gateway & traits (as per original plugin)
	require_once __DIR__ . '/WC_Gateway_CityPay.php';
	require_once __DIR__ . '/trait-wc-gateway-cp-subscriptions.php';
	require_once __DIR__ . '/trait-wc-citypay-api.php';

	// Load client and gateway
	require_once __DIR__ . '/wc-paylink-client.php';
	require_once __DIR__ . '/WC_Gateway_CityPay_Paylink.php';

	// Register payment method
	add_filter( 'woocommerce_payment_gateways', function( $methods ) {
		$methods[] = 'WC_Gateway_CityPayPaylink';
		return $methods;
	} );
}, 20 );

/* ---------------------------
 * Woo Blocks registration
 * ------------------------- */
add_action( 'woocommerce_blocks_payment_method_type_registration', function( $payment_method_registry ) {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) { return; }
	require_once __DIR__ . '/includes/class-wc-gateway-citypaypaylink-blocks.php';
	if ( class_exists( 'WC_Gateway_CityPayPaylink_Blocks' ) ) {
		$payment_method_registry->register( new \WC_Gateway_CityPayPaylink_Blocks() );
	}
}, 20 );

add_action( 'woocommerce_blocks_loaded', function() {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\IntegrationRegistry' ) ) { return; }
	require_once __DIR__ . '/includes/class-wc-gateway-citypaypaylink-blocks.php';
	if ( class_exists( 'WC_Gateway_CityPayPaylink_Blocks' ) ) {
		$registry = \Automattic\WooCommerce\Blocks\Payments\Integrations\IntegrationRegistry::get_instance();
		$registry->register( new \WC_Gateway_CityPayPaylink_Blocks() );
	}
}, 20 );

/* -----------------------------------------------------------
 * Admin assets for the “Test Settings and Connection to Citypay”
 * (only enqueued if those asset files exist in your build)
 * --------------------------------------------------------- */
add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( strpos( $hook, 'wc-settings' ) === false ) { return; }
	if ( ! isset( $_GET['section'] ) || $_GET['section'] !== 'citypay' ) { return; } // phpcs:ignore

	$css = __DIR__ . '/assets/admin/citypay-admin-test.css';
	$js  = __DIR__ . '/assets/admin/citypay-admin-test.js';

	if ( file_exists( $css ) ) {
		wp_enqueue_style( 'cp-citypay-admin-test',
			plugin_dir_url( __FILE__ ) . 'assets/admin/citypay-admin-test.css',
			array(), CITYPAY_PAYMENTS_VERSION );
	}
	if ( file_exists( $js ) ) {
		wp_enqueue_script( 'cp-citypay-admin-test',
			plugin_dir_url( __FILE__ ) . 'assets/admin/citypay-admin-test.js',
			array( 'jquery' ), CITYPAY_PAYMENTS_VERSION, true );
		wp_localize_script( 'cp-citypay-admin-test', 'CP_CITYPAY_TEST', array(
			'nonce' => wp_create_nonce( 'cp_citypay_test_nonce' ),
		) );
	}
} );

/* -----------------------------------------------------------
 * AJAX tester: v6 /ping (strict env by test mode, verbose logs)
 * --------------------------------------------------------- */
add_action( 'wp_ajax_cp_citypay_test', function() {

	check_ajax_referer( 'cp_citypay_test_nonce' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wc-payment-gateway-citypay' ) ) );
	}

	$logger  = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;
	$log_ctx = array( 'source' => 'citypay' );

	$opts = get_option( 'woocommerce_citypay_settings', array() );
	$def  = function( $k, $default = '' ) use ( $opts ) { return isset( $opts[ $k ] ) ? trim( (string) $opts[ $k ] ) : $default; };
	$mask = function( $v ) { $v=(string)$v; $n=strlen($v); return $n<=6?str_repeat('*',$n):str_repeat('*',$n-6).substr($v,-6); };

	$checks = array(); $all_ok = true;
	$push = function( $label, $ok, $message = '' ) use ( &$checks, &$all_ok, $logger, $log_ctx ) {
		$checks[] = array( 'label' => $label, 'pass' => (bool) $ok, 'message' => (string) $message );
		if ( ! $ok ) { $all_ok = false; }
		if ( $logger ) { $logger->notice( sprintf( '[TEST] %s => %s %s', $label, $ok ? 'OK' : 'FAIL', $message ? "($message)" : '' ), $log_ctx ); }
	};

	$merchant_id   = $def( 'merchant_id' );
	$licence_key   = $def( 'licence_key' );
	$client_id     = $def( 'client_id' );
	$postback_base = $def( 'postback_base' );
	$title         = $def( 'title' );
	$cart_desc     = $def( 'cart_desc' );
	$enabled       = $def( 'enabled', 'no' );
	$subs_enabled  = $def( 'cp_subscriptions', 'no' );
	$subs_mid      = $def( 'subs_merchant_id' );
	$testmode      = $def( 'testmode', 'yes' );

	$push( __( 'Merchant ID set', 'wc-payment-gateway-citypay' ), ! empty( $merchant_id ) );
	$push( __( 'Licence Key set', 'wc-payment-gateway-citypay' ), ! empty( $licence_key ) );
	$push( __( 'Client ID set', 'wc-payment-gateway-citypay' ), ! empty( $client_id ) );
	$push( __( 'Postback URL set', 'wc-payment-gateway-citypay' ), ! empty( $postback_base ) );
	$push( __( 'Title set', 'wc-payment-gateway-citypay' ), ! empty( $title ) );
	$push( __( 'Transaction Description set', 'wc-payment-gateway-citypay' ), ! empty( $cart_desc ) );
	$push( __( 'Gateway enabled', 'wc-payment-gateway-citypay' ), ( $enabled === 'yes' ) );
	if ( $subs_enabled === 'yes' ) {
		$push( __( 'Subscriptions Merchant ID set (subscriptions enabled)', 'wc-payment-gateway-citypay' ), !empty( $subs_mid ) || !empty( $merchant_id ));
	} else {
		$checks[] = array( 'label' => __( 'Subscriptions are disabled', 'wc-payment-gateway-citypay' ), 'pass' => true, 'message' => '(skip check)' );
	}

	$summary = ''; $code = ''; $message = ''; $ping_ok = false;
	if ( $all_ok ) {
		if ( ! class_exists( 'ApiKey' ) ) { require_once __DIR__ . '/ApiKey.php'; }
		try {
			$apiKey     = new ApiKey( $client_id, $licence_key );
			$context    = get_file_data( __DIR__ . '/wc-payment-gateway-citypay.php', array( 'version' => 'Version' ) );
			$wc_version = ( function_exists( 'WC' ) && WC() ) ? WC()->version : 'unknown';
			$ua         = 'WooCommerce-'.$wc_version.'/CityPay-WC-'.( $context['version'] ?? CITYPAY_PAYMENTS_VERSION );

			$root    = ( $testmode === 'yes' ) ? 'https://sandbox.citypay.com/v6' : 'https://api.citypay.com/v6';
			$payload = wp_json_encode( array( 'identifier' => 'WooCommerce-Test-Button' ) );
			if ( $logger ) { $logger->debug( '[TEST] Using API root: '.$root.' (testmode='.$testmode.')', $log_ctx ); }

			$args = array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'cp-api-key'   => $apiKey->generate(),
					'User-Agent'   => $ua,
				),
				'timeout' => 20,
				'method'  => 'POST',
				'body'    => $payload,
			);

			if ( $logger ) {
				$lh=$args['headers']; $lh['cp-api-key']=$mask($lh['cp-api-key']);
				$logger->debug('[TEST] PING request '.$root.'/ping', $log_ctx);
				$logger->debug('[TEST] Headers: '.wp_json_encode($lh), $log_ctx);
				$logger->debug('[TEST] Payload: '.$payload, $log_ctx);
			}

			$resp = wp_remote_post( $root.'/ping', $args );
			$status = is_wp_error($resp)?0:(int)wp_remote_retrieve_response_code($resp);
			$body   = is_wp_error($resp)?$resp->get_error_message():wp_remote_retrieve_body($resp);

			if ( $logger ) {
				if ( is_wp_error($resp) ) { $logger->error('[TEST] wp_remote_post error: '.$body, $log_ctx); }
				else { $logger->debug('[TEST] Response code: '.$status, $log_ctx); $logger->debug('[TEST] Response body: '.$body, $log_ctx); }
			}

			$pkt = is_wp_error($resp)?null:json_decode($body,true);
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$push( __( 'CityPay API ping', 'wc-payment-gateway-citypay' ), false, __( 'Invalid JSON response', 'wc-payment-gateway-citypay' ) );
			} else {
				$code    = isset($pkt['code']) ? (string)$pkt['code'] : '';
				$message = isset($pkt['message']) ? (string)$pkt['message'] : '';
				if ( $code === '044' ) { $ping_ok=true; $push( __( 'CityPay API ping', 'wc-payment-gateway-citypay' ), true, __( 'Connection successful.', 'wc-payment-gateway-citypay' ) ); }
				elseif ( $code === '007' ) { $push( __( 'CityPay API ping', 'wc-payment-gateway-citypay' ), false, __( 'IP address not authorised by CityPay. Please email support@citypay.com with your server IP.', 'wc-payment-gateway-citypay' ) ); }
				else { $push( __( 'CityPay API ping', 'wc-payment-gateway-citypay' ), false, $code.': '.$message ); }
			}

		} catch ( \Throwable $e ) {
			$push( __( 'CityPay API ping', 'wc-payment-gateway-citypay' ), false, $e->getMessage() );
		}
	}

	if ( $all_ok && $ping_ok ) {
		$summary = __( 'All checks successful. The plugin is configured correctly and the connection to CityPay was successful.', 'wc-payment-gateway-citypay' );
	} else {
		if ( $code === '007' ) {
			$summary = __( 'CityPay connection reached the API, but your server IP is not authorised. Please email support@citypay.com with your server IP.', 'wc-payment-gateway-citypay' );
		} elseif ( ! empty( $code ) ) {
			$summary = sprintf( __( 'Connection to CityPay failed with code %s: %s', 'wc-payment-gateway-citypay' ), esc_html($code), esc_html($message) );
		} else {
			$summary = __( 'Some checks failed. Please review the items above.', 'wc-payment-gateway-citypay' );
		}
	}

	wp_send_json_success( array(
		'checks'  => $checks,
		'summary' => $summary,
		'code'    => $code,
		'message' => $message,
	) );
} );

/* -------------------------------------------------------------------------
 * Admin UI: CityPay meta box (right side) + list columns (classic + HPOS)
 * Only show values for CityPay orders.
 * ---------------------------------------------------------------------- */

/** Helper: show UI only for CityPay orders */
if ( ! function_exists( 'cp_is_citypay_order' ) ) {
	function cp_is_citypay_order( $order ) {
		return ( $order instanceof WC_Order ) && ( $order->get_payment_method() === 'citypay' );
	}
}

if ( ! function_exists( 'cp_get_order_meta_value' ) ) {
	function cp_get_order_meta_value( $order_or_id, $key ) {
		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		if ( $order instanceof WC_Order ) {
			return $order->get_meta( $key, true );
		}

		return '';
	}
}

/**
 * Register meta box on both classic and HPOS order edit screens.
 * - Classic screen id uses post type "shop_order"
 * - HPOS screen id is "woocommerce_page_wc-orders"
 */
add_action( 'add_meta_boxes', function( $post_type, $post ) {

	// Always register for both screens so it appears under "Screen Options".
	add_meta_box(
		'cp_citypay_payment_box',
		__( 'CityPay Payment', 'wc-payment-gateway-citypay' ),
		function ( $post_or_order ) {

			// Resolve WC_Order object (works for classic & HPOS)
			$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
			if ( ! ( $order instanceof WC_Order ) ) { return; }

			// Only show content for CityPay orders; otherwise show nothing
			if ( ! cp_is_citypay_order( $order ) ) { return; }

			$get = function( $k ) use ( $order ) { return cp_get_order_meta_value( $order, $k ); };

			$rows = array(
				__( 'Authorisation Code', 'wc-payment-gateway-citypay' ) => $get('_cp_attrib_authcode'),
				__( 'Amount',             'wc-payment-gateway-citypay' ) => $get('_cp_attrib_amount_display'), // wc_price() HTML
				__( 'Authorised',         'wc-payment-gateway-citypay' ) => $get('_cp_attrib_authorised_display'),
				__( 'Card Scheme',        'wc-payment-gateway-citypay' ) => $get('_cp_attrib_card_scheme'),
				__( 'Name on Card',       'wc-payment-gateway-citypay' ) => $get('_cp_attrib_name_on_card'),
				__( 'PAN',                'wc-payment-gateway-citypay' ) => $get('_cp_attrib_masked_pan'),
				__( 'Transaction Number', 'wc-payment-gateway-citypay' ) => $get('_cp_attrib_transno'),
				__( 'Date/Time',          'wc-payment-gateway-citypay' ) => ( $get('_cp_attrib_datetime_display') ?: $get('_cp_attrib_datetime_iso') ),
			);

			echo '<table class="widefat striped" style="margin:0;">';
			foreach ( $rows as $label => $val ) {
				if ( $val === '' || $val === null ) { continue; }
				echo '<tr><th style="width:48%">' . esc_html( $label ) . '</th><td>';
				if ( $label === __( 'Amount', 'wc-payment-gateway-citypay' ) ) {
					echo wp_kses_post( (string) $val ); // render £ correctly
				} else {
					echo esc_html( (string) $val );
				}
				echo '</td></tr>';
			}
			echo '</table>';
		},
		array( 'shop_order', 'woocommerce_page_wc-orders' ), // contexts (both classic & HPOS)
		'side',
		'default'
	);

}, 10, 2 );

/** Classic orders list: add columns + values (blank for non-CityPay rows). */
add_filter( 'manage_edit-shop_order_columns', function( $columns ) {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'order_status' === $key ) {
			$new['cp_authcode']   = __( 'Authorisation Code', 'wc-payment-gateway-citypay' );
			$new['cp_cardscheme'] = __( 'Card Scheme', 'wc-payment-gateway-citypay' );
			$new['cp_cardname']   = __( 'Name on Card', 'wc-payment-gateway-citypay' );
			$new['cp_transno']    = __( 'Transaction Number', 'wc-payment-gateway-citypay' );
		}
	}
	if ( ! isset( $new['cp_authcode'] ) ) {
		$new['cp_authcode']   = __( 'Authorisation Code', 'wc-payment-gateway-citypay' );
		$new['cp_cardscheme'] = __( 'Card Scheme', 'wc-payment-gateway-citypay' );
		$new['cp_cardname']   = __( 'Name on Card', 'wc-payment-gateway-citypay' );
		$new['cp_transno']    = __( 'Transaction Number', 'wc-payment-gateway-citypay' );
	}
	return $new;
}, 20 );

add_action( 'manage_shop_order_posts_custom_column', function( $column ) {
	if ( ! in_array( $column, array( 'cp_authcode','cp_cardscheme','cp_cardname','cp_transno' ), true ) ) { return; }
	$post_id = get_the_ID();
	$order   = wc_get_order( $post_id );
	if ( ! $order || ! cp_is_citypay_order( $order ) ) { echo ''; return; }

	switch ( $column ) {
		case 'cp_authcode':
			echo esc_html( (string) cp_get_order_meta_value( $order, '_cp_attrib_authcode' ) );
			break;

		case 'cp_cardscheme':
			$val = cp_get_order_meta_value( $order, '_cp_attrib_card_scheme' );
			if ( $val === '' ) {
				$card_used = cp_get_order_meta_value( $order, 'Card used' );
				if ( is_string( $card_used ) && strpos( $card_used, '/' ) !== false ) {
					$val = ucwords( strtolower( trim( explode( '/', $card_used )[0] ) ) );
				}
			}
			echo esc_html( (string) $val );
			break;

		case 'cp_cardname':
			echo esc_html( (string) cp_get_order_meta_value( $order, '_cp_attrib_name_on_card' ) );
			break;

		case 'cp_transno':
			$val = cp_get_order_meta_value( $order, '_cp_attrib_transno' );
			if ( $val === '' ) { $val = cp_get_order_meta_value( $order, 'CityPay TransNo' ); }
			echo esc_html( (string) $val );
			break;
	}
}, 20 );

add_filter( 'manage_edit-shop_order_sortable_columns', function( $columns ) {
	$columns['cp_authcode'] = 'cp_authcode';
	$columns['cp_transno']  = 'cp_transno';
	return $columns;
} );

add_action( 'pre_get_posts', function( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) { return; }
	$orderby = $query->get( 'orderby' );
	if ( 'cp_authcode' === $orderby ) {
		$query->set( 'meta_key', '_cp_attrib_authcode' );
		$query->set( 'orderby', 'meta_value' );
	} elseif ( 'cp_transno' === $orderby ) {
		$query->set( 'meta_key', '_cp_attrib_transno' );
		$query->set( 'orderby', 'meta_value' );
	}
} );

/** HPOS/new orders list: add columns + values (blank for non-CityPay rows). */
add_filter( 'woocommerce_shop_order_list_table_columns', function( $columns ) {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'order_status' === $key ) {
			$new['cp_authcode']   = __( 'Authorisation Code', 'wc-payment-gateway-citypay' );
			$new['cp_cardscheme'] = __( 'Card Scheme', 'wc-payment-gateway-citypay' );
			$new['cp_cardname']   = __( 'Name on Card', 'wc-payment-gateway-citypay' );
			$new['cp_transno']    = __( 'Transaction Number', 'wc-payment-gateway-citypay' );
		}
	}
	if ( ! isset( $new['cp_authcode'] ) ) {
		$new['cp_authcode']   = __( 'Authorisation Code', 'wc-payment-gateway-citypay' );
		$new['cp_cardscheme'] = __( 'Card Scheme', 'wc-payment-gateway-citypay' );
		$new['cp_cardname']   = __( 'Name on Card', 'wc-payment-gateway-citypay' );
		$new['cp_transno']    = __( 'Transaction Number', 'wc-payment-gateway-citypay' );
	}
	return $new;
}, 20 );

add_action( 'woocommerce_shop_order_list_table_custom_column', function( $column, $order ) {
	if ( ! in_array( $column, array( 'cp_authcode','cp_cardscheme','cp_cardname','cp_transno' ), true ) ) { return; }
	if ( ! ( $order instanceof WC_Order ) || ! cp_is_citypay_order( $order ) ) { echo ''; return; }

	switch ( $column ) {
		case 'cp_authcode':
			echo esc_html( (string) cp_get_order_meta_value( $order, '_cp_attrib_authcode' ) );
			break;

		case 'cp_cardscheme':
			$val = cp_get_order_meta_value( $order, '_cp_attrib_card_scheme' );
			if ( $val === '' ) {
				$card_used = cp_get_order_meta_value( $order, 'Card used' );
				if ( is_string( $card_used ) && strpos( $card_used, '/' ) !== false ) {
					$val = ucwords( strtolower( trim( explode( '/', $card_used )[0] ) ) );
				}
			}
			echo esc_html( (string) $val );
			break;

		case 'cp_cardname':
			echo esc_html( (string) cp_get_order_meta_value( $order, '_cp_attrib_name_on_card' ) );
			break;

		case 'cp_transno':
			$val = cp_get_order_meta_value( $order, '_cp_attrib_transno' );
			if ( $val === '' ) { $val = cp_get_order_meta_value( $order, 'CityPay TransNo' ); }
			echo esc_html( (string) $val );
			break;
	}
}, 20, 2 );

add_filter( 'woocommerce_shop_order_list_table_sortable_columns', function( $columns ) {
	$columns['cp_authcode'] = 'cp_authcode';
	$columns['cp_transno']  = 'cp_transno';
	return $columns;
} );
