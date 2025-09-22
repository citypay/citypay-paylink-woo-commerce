<?php
/*
Plugin Name: CityPay WooCommerce Plugin
Plugin URI: https://github.com/citypay/citypay-paylink-woo-commerce
Description: Accept CityPay payments on your WooCommerce powered store!
Version: 2.1.9
Author: CityPay Limited
Author URI: https://citypay.com
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.en.html
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'CITYPAY_PAYMENTS_VERSION' ) ) {
    define( 'CITYPAY_PAYMENTS_VERSION', '2.1.9' );
}

/**
 * WooCommerce dependency check + notice (guarded to avoid redeclare when duplicate plugin folders exist).
 */
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

/**
 * Bootstrap the gateway once plugins are loaded.
 *
 * IMPORTANT for Paylink create:
 *  - The legacy client posts to https://secure.citypay.com/paylink3/create
 *  - Test/Live is controlled by the JSON "test" flag (not by host).
 */
add_action( 'plugins_loaded', function () {

    if ( ! citypay_gateway_wc_active() ) {
        add_action( 'admin_notices', 'citypay_gateway_woocommerce_missing_notice' );
        return;
    }

    // Core gateway & traits (load constants/traits first, as original plugin did).
    require_once __DIR__ . '/WC_Gateway_CityPay.php';
    require_once __DIR__ . '/trait-wc-gateway-cp-subscriptions.php';
    require_once __DIR__ . '/trait-wc-citypay-api.php';

    // Back-compat for the Paylink client: define the ORIGINAL Paylink 3 root.
    // Do NOT map this to v6 API hosts; the client relies on this exact root.
    if ( ! defined( 'CITYPAY_PAYLINK_API_ROOT' ) ) {
        define( 'CITYPAY_PAYLINK_API_ROOT', 'https://secure.citypay.com/paylink3' );
    }

    // Load client and gateway (client uses CITYPAY_PAYLINK_API_ROOT.'/create').
    require_once __DIR__ . '/wc-paylink-client.php';
    require_once __DIR__ . '/WC_Gateway_CityPay_Paylink.php';

    // Register the payment gateway with WooCommerce.
    add_filter( 'woocommerce_payment_gateways', function( $methods ) {
        $methods[] = 'WC_Gateway_CityPayPaylink';
        return $methods;
    } );
}, 20 );

/**
 * Register CityPay gateway with WooCommerce Blocks (editor + frontend).
 * We require the integration file inside the hooks so Blocks base classes load first.
 */
add_action( 'woocommerce_blocks_payment_method_type_registration', function( $payment_method_registry ) {
    if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }
    require_once __DIR__ . '/includes/class-wc-gateway-citypaypaylink-blocks.php';
    if ( class_exists( 'WC_Gateway_CityPayPaylink_Blocks' ) ) {
        $payment_method_registry->register( new \WC_Gateway_CityPayPaylink_Blocks() );
    }
}, 20 );

/**
 * Fallback for older Blocks loading flow.
 */
add_action( 'woocommerce_blocks_loaded', function() {
    if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\IntegrationRegistry' ) ) {
        return;
    }
    require_once __DIR__ . '/includes/class-wc-gateway-citypaypaylink-blocks.php';
    if ( class_exists( 'WC_Gateway_CityPayPaylink_Blocks' ) ) {
        $registry = \Automattic\WooCommerce\Blocks\Payments\Integrations\IntegrationRegistry::get_instance();
        $registry->register( new \WC_Gateway_CityPayPaylink_Blocks() );
    }
}, 20 );

/**
 * Admin assets for the CityPay settings page (for the "Test Settings and Connection to Citypay" button).
 */
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( strpos( $hook, 'wc-settings' ) === false ) {
        return;
    }
    if ( ! isset( $_GET['section'] ) || $_GET['section'] !== 'citypay' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }

    wp_enqueue_style(
        'cp-citypay-admin-test',
        plugin_dir_url( __FILE__ ) . 'assets/admin/citypay-admin-test.css',
        array(),
        CITYPAY_PAYMENTS_VERSION
    );
    wp_enqueue_script(
        'cp-citypay-admin-test',
        plugin_dir_url( __FILE__ ) . 'assets/admin/citypay-admin-test.js',
        array( 'jquery' ),
        CITYPAY_PAYMENTS_VERSION,
        true
    );
    wp_localize_script( 'cp-citypay-admin-test', 'CP_CITYPAY_TEST', array(
        'nonce' => wp_create_nonce( 'cp_citypay_test_nonce' ),
    ) );
} );

/**
 * Admin-AJAX handler: Test settings + connection to CityPay, with detailed logging.
 * Uses POST /v6/ping with Accept: application/json, payload {"identifier":"WooCommerce-Test-Button"},
 * STRICTLY obeys Test mode (sandbox vs api). Displays raw returned code (no "P" prefix).
 * Nice-to-have: logs the chosen API root explicitly.
 */
add_action( 'wp_ajax_cp_citypay_test', function() {

    check_ajax_referer( 'cp_citypay_test_nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wc-payment-gateway-citypay' ) ) );
    }

    $logger  = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;
    $log_ctx = array( 'source' => 'citypay' );

    $opts = get_option( 'woocommerce_citypay_settings', array() );
    $def  = function( $k, $default = '' ) use ( $opts ) { return isset( $opts[ $k ] ) ? trim( (string) $opts[ $k ] ) : $default; };

    $mask = function( $val, $keep_end = 6 ) {
        $val = (string) $val;
        if ( $val === '' ) { return ''; }
        $len = strlen( $val );
        if ( $len <= $keep_end ) { return str_repeat( '*', $len ); }
        return str_repeat( '*', max( 0, $len - $keep_end ) ) . substr( $val, -$keep_end );
    };

    $checks = array();
    $all_ok = true;
    $push   = function( $label, $ok, $message = '' ) use ( &$checks, &$all_ok, $logger, $log_ctx ) {
        $checks[] = array( 'label' => $label, 'pass' => (bool) $ok, 'message' => (string) $message );
        if ( ! $ok ) { $all_ok = false; }
        if ( $logger ) {
            $logger->notice( sprintf( '[TEST] %s => %s %s', $label, $ok ? 'OK' : 'FAIL', $message ? "({$message})" : '' ), $log_ctx );
        }
    };

    // Local config snapshot + checks (1–8).
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

    if ( $logger ) {
        $logger->debug( '[TEST] Starting CityPay config self-test', $log_ctx );
        $logger->debug( '[TEST] Config snapshot: ' . wp_json_encode( array(
            'merchant_id'   => $mask( $merchant_id ),
            'client_id'     => $mask( $client_id ),
            'licence_key'   => $mask( $licence_key ),
            'postback_base' => $postback_base,
            'title'         => $title,
            'cart_desc'     => $cart_desc,
            'enabled'       => $enabled,
            'subs_enabled'  => $subs_enabled,
            'subs_mid'      => $mask( $subs_mid ),
            'testmode'      => $testmode,
        ) ), $log_ctx );
    }

    $push( __( 'Merchant ID set', 'wc-payment-gateway-citypay' ), ! empty( $merchant_id ) );
    $push( __( 'Licence Key set', 'wc-payment-gateway-citypay' ), ! empty( $licence_key ) );
    $push( __( 'Client ID set', 'wc-payment-gateway-citypay' ), ! empty( $client_id ) );
    $push( __( 'Postback URL set', 'wc-payment-gateway-citypay' ), ! empty( $postback_base ) );
    $push( __( 'Title set', 'wc-payment-gateway-citypay' ), ! empty( $title ) );
    $push( __( 'Transaction Description set', 'wc-payment-gateway-citypay' ), ! empty( $cart_desc ) );
    $push( __( 'Gateway enabled', 'wc-payment-gateway-citypay' ), ( $enabled === 'yes' ) );

    if ( $subs_enabled === 'yes' ) {
        $push( __( 'Subscriptions Merchant ID set (subscriptions enabled)', 'wc-payment-gateway-citypay' ), ! empty( $subs_mid ) );
    } else {
        $checks[] = array( 'label' => __( 'Subscriptions are disabled', 'wc-payment-gateway-citypay' ), 'pass' => true, 'message' => '(skip check)' );
        if ( $logger ) { $logger->debug( '[TEST] Subscriptions disabled — skipping subs MID check', $log_ctx ); }
    }

    // 9) Remote ping (STRICT: obey Test mode only; no auto-retry).
    $summary   = '';
    $server_ip = '';
    $code      = '';
    $message   = '';
    $ping_ok   = false;

    if ( ! empty( $_SERVER['SERVER_ADDR'] ) ) {
        $server_ip = sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) );
    } elseif ( function_exists( 'gethostbyname' ) ) {
        $server_ip = sanitize_text_field( @gethostbyname( gethostname() ) );
    }
    if ( $logger ) { $logger->debug( '[TEST] Server IP (best effort): ' . $server_ip, $log_ctx ); }

    if ( $all_ok ) {
        if ( ! class_exists( 'ApiKey' ) ) { require_once __DIR__ . '/ApiKey.php'; }

        try {
            $apiKey     = new ApiKey( $client_id, $licence_key );
            $context    = get_file_data( __DIR__ . '/wc-payment-gateway-citypay.php', array( 'version' => 'Version' ) );
            $wc_version = ( function_exists( 'WC' ) && WC() ) ? WC()->version : 'unknown';
            $user_agent = 'WooCommerce-' . $wc_version . '/CityPay-WC-' . ( isset( $context['version'] ) ? $context['version'] : CITYPAY_PAYMENTS_VERSION );

            $temp_key = $apiKey->generate();
            $root     = ( $testmode === 'yes' ) ? 'https://sandbox.citypay.com/v6' : 'https://api.citypay.com/v6';
            $payload  = wp_json_encode( array( 'identifier' => 'WooCommerce-Test-Button' ) );

            // Nice-to-have: explicit log of chosen API root
            if ( $logger ) {
                $logger->debug( '[TEST] Using API root: ' . $root . ' (testmode=' . $testmode . ')', $log_ctx );
            }

            $args = array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                    'cp-api-key'   => $temp_key,
                    'User-Agent'   => $user_agent,
                ),
                'timeout' => 20,
                'method'  => 'POST',
                'body'    => $payload,
            );

            if ( $logger ) {
                $lh = $args['headers'];
                $lh['cp-api-key'] = str_repeat( '*', max( 0, strlen( $lh['cp-api-key'] ) - 6 ) ) . substr( $lh['cp-api-key'], -6 );
                $logger->debug( '[TEST] PING request ' . $root . '/ping', $log_ctx );
                $logger->debug( '[TEST] Headers: ' . wp_json_encode( $lh ), $log_ctx );
                $logger->debug( '[TEST] Payload: ' . (string) $args['body'], $log_ctx );
            }

            $resp   = wp_remote_post( $root . '/ping', $args );
            $status = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
            $body   = is_wp_error( $resp ) ? $resp->get_error_message() : wp_remote_retrieve_body( $resp );

            if ( $logger ) {
                if ( is_wp_error( $resp ) ) {
                    $logger->error( '[TEST] wp_remote_post error: ' . $body, $log_ctx );
                } else {
                    $logger->debug( '[TEST] Response code: ' . $status, $log_ctx );
                    $logger->debug( '[TEST] Response body: ' . ( is_string( $body ) ? $body : print_r( $body, true ) ), $log_ctx );
                }
            }

            $pkt = is_wp_error( $resp ) ? null : json_decode( $body, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $push( __( 'CityPay API ping', 'wc-payment-gateway-citypay' ), false, __( 'Invalid JSON response', 'wc-payment-gateway-citypay' ) );
                if ( $logger ) { $logger->error( '[TEST] JSON decode error: ' . json_last_error_msg(), $log_ctx ); }
            } else {
                $code    = isset( $pkt['code'] ) ? (string) $pkt['code'] : '';
                $message = isset( $pkt['message'] ) ? (string) $pkt['message'] : '';
                if ( $code === '044' ) {
                    $ping_ok = true;
                    $push( __( 'CityPay API ping', 'wc-payment-gateway-citypay' ), true, __( 'Connection successful.', 'wc-payment-gateway-citypay' ) );
                    if ( $logger ) { $logger->notice( '[TEST] Ping OK (044) — connection successful', $log_ctx ); }
                } elseif ( $code === '007' ) {
                    $push(
                        __( 'CityPay API ping', 'wc-payment-gateway-citypay' ),
                        false,
                        __( 'IP address not authorised by CityPay. Please email support@citypay.com with your server IP.', 'wc-payment-gateway-citypay' )
                    );
                    if ( $logger ) { $logger->warning( '[TEST] Ping returned 007 — IP not authorised. Server IP: ' . $server_ip, $log_ctx ); }
                } else {
                    $push(
                        __( 'CityPay API ping', 'wc-payment-gateway-citypay' ),
                        false,
                        $code . ': ' . $message
                    );
                    if ( $logger ) { $logger->warning( sprintf( '[TEST] Ping returned non-success code: %s %s', $code, $message ), $log_ctx ); }
                }
            }
        } catch ( \Throwable $e ) {
            $push( __( 'CityPay API ping', 'wc-payment-gateway-citypay' ), false, $e->getMessage() );
            if ( $logger ) { $logger->error( '[TEST] Exception during ping: ' . $e->getMessage(), $log_ctx ); }
        }
    } else {
        if ( $logger ) { $logger->warning( '[TEST] Skipping ping — one or more pre-checks failed', $log_ctx ); }
    }

    if ( $all_ok && $ping_ok ) {
        $summary = __( 'All checks successful. The plugin is configured correctly and the connection to CityPay was successful.', 'wc-payment-gateway-citypay' );
    } else {
        if ( $code === '007' ) {
            $summary = __( 'CityPay connection reached the API, but your server IP is not authorised. Please email support@citypay.com with this IP.', 'wc-payment-gateway-citypay' );
        } elseif ( ! empty( $code ) ) {
            $summary = sprintf(
                /* translators: 1: response code, 2: response message */
                __( 'Connection to CityPay failed with code %s: %s', 'wc-payment-gateway-citypay' ),
                esc_html( $code ),
                esc_html( $message )
            );
        } else {
            $summary = __( 'Some checks failed. Please review the items above.', 'wc-payment-gateway-citypay' );
        }
    }

    wp_send_json_success( array(
        'checks'  => $checks,
        'summary' => $summary,
        'ip'      => $server_ip,
        'code'    => $code,
        'message' => $message,
    ) );
} );


// ==============================
// Admin UI: CityPay meta box + list columns
// ==============================
if ( is_admin() ) {

    /**
     * Metabox: "CityPay Payment" on the order edit screen (right side).
     */
    add_action( 'add_meta_boxes', function () {
        add_meta_box(
            'cp_citypay_payment_box',
            __( 'CityPay Payment', 'wc-payment-gateway-citypay' ),
            function ( $post ) {
                $order_id = $post->ID;

                // Helper to get meta quickly
                $m = function( $key ) use ( $order_id ) { return get_post_meta( $order_id, $key, true ); };

                // Retrieve values (with small fallbacks where helpful)
                $authcode        = $m('_cp_attrib_authcode');
                $amount_display  = $m('_cp_attrib_amount_display'); // already formatted with wc_price()
                $authorised_disp = $m('_cp_attrib_authorised_display'); // "Yes"/"No"
                $card_scheme     = $m('_cp_attrib_card_scheme');
                $name_on_card    = $m('_cp_attrib_name_on_card');
                $masked_pan      = $m('_cp_attrib_masked_pan');
                $transno         = $m('_cp_attrib_transno') ?: $m('CityPay TransNo');
                $datetime        = $m('_cp_attrib_datetime_display') ?: $m('_cp_attrib_datetime_iso');

                // Fallback: try to derive card scheme from "Card used" if missing
                if ( empty( $card_scheme ) ) {
                    $card_used = $m('Card used'); // e.g. "Visa/Visa***1048 2025/12"
                    if ( is_string( $card_used ) && strpos( $card_used, '/' ) !== false ) {
                        $card_scheme = ucwords( strtolower( trim( explode( '/', $card_used )[0] ) ) );
                    }
                }

                // Render tidy table with only non-empty rows
                $rows = array(
                    __( 'Authorisation Code', 'wc-payment-gateway-citypay' ) => $authcode,
                    __( 'Amount',             'wc-payment-gateway-citypay' ) => $amount_display,
                    __( 'Authorised',         'wc-payment-gateway-citypay' ) => $authorised_disp,
                    __( 'Card Scheme',        'wc-payment-gateway-citypay' ) => $card_scheme,
                    __( 'Name on Card',       'wc-payment-gateway-citypay' ) => $name_on_card,
                    __( 'PAN',                'wc-payment-gateway-citypay' ) => $masked_pan,
                    __( 'Transaction Number', 'wc-payment-gateway-citypay' ) => $transno,
                    __( 'Date/Time',          'wc-payment-gateway-citypay' ) => $datetime,
                );

                echo '<table class="widefat striped" style="margin:0;">';
                foreach ( $rows as $label => $val ) {
                    if ( $val === '' || $val === null ) { continue; }
                    printf(
                        '<tr><th style="width:48%%">%s</th><td>%s</td></tr>',
                        esc_html( $label ),
                        esc_html( (string) $val )
                    );
                }
                echo '</table>';
            },
            'shop_order',
            'side',
            'default'
        );
    } );

    /**
     * Orders list: add columns.
     * Columns:
     *  - Authorisation Code  {authcode}
     *  - Card Scheme         {cardScheme} (title case)
     *  - Name on Card        {name_on_card}
     *  - Transaction Number  {transno}
     */
    add_filter( 'manage_edit-shop_order_columns', function( $columns ) {

        // Decide where to insert; place after order_status
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

        // In case order_status not found for some reason, append at end
        if ( ! isset( $new['cp_authcode'] ) ) {
            $new['cp_authcode']   = __( 'Authorisation Code', 'wc-payment-gateway-citypay' );
            $new['cp_cardscheme'] = __( 'Card Scheme', 'wc-payment-gateway-citypay' );
            $new['cp_cardname']   = __( 'Name on Card', 'wc-payment-gateway-citypay' );
            $new['cp_transno']    = __( 'Transaction Number', 'wc-payment-gateway-citypay' );
        }

        return $new;
    }, 20 );

    add_action( 'manage_shop_order_posts_custom_column', function( $column ) {
        if ( ! in_array( $column, array( 'cp_authcode', 'cp_cardscheme', 'cp_cardname', 'cp_transno' ), true ) ) {
            return;
        }

        $post_id = get_the_ID();
        if ( ! $post_id ) { return; }

        $val = '';
        switch ( $column ) {
            case 'cp_authcode':
                $val = get_post_meta( $post_id, '_cp_attrib_authcode', true );
                break;

            case 'cp_cardscheme':
                $val = get_post_meta( $post_id, '_cp_attrib_card_scheme', true );
                if ( $val === '' ) {
                    // Fallback from "Card used"
                    $card_used = get_post_meta( $post_id, 'Card used', true );
                    if ( is_string( $card_used ) && strpos( $card_used, '/' ) !== false ) {
                        $val = ucwords( strtolower( trim( explode( '/', $card_used )[0] ) ) );
                    }
                }
                break;

            case 'cp_cardname':
                $val = get_post_meta( $post_id, '_cp_attrib_name_on_card', true );
                break;

            case 'cp_transno':
                $val = get_post_meta( $post_id, '_cp_attrib_transno', true );
                if ( $val === '' ) {
                    $val = get_post_meta( $post_id, 'CityPay TransNo', true );
                }
                break;
        }

        echo esc_html( (string) $val );
    }, 20 );

    /**
     * Optional: make our columns sortable (authcode, transno).
     */
    add_filter( 'manage_edit-shop_order_sortable_columns', function( $columns ) {
        $columns['cp_authcode'] = 'cp_authcode';
        $columns['cp_transno']  = 'cp_transno';
        return $columns;
    } );

    // Sorting handler
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
}
