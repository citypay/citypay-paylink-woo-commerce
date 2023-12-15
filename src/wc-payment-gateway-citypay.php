<?php

/*
Plugin Name: CityPay WooCommerce Plugin
Plugin URI: https://github.com/citypay/citypay-paylink-woo-commerce
Description: Accept CityPay payments on your WooCommerce powered store!
Version: 2.1.3
Author: CityPay Limited
Author URI: https://citypay.com
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.en.html

*/

if (!defined('ABSPATH')) {
    exit;
}

// API Settings
define('CITYPAY_PAYLINK_API_ROOT', 'https://secure.citypay.com/paylink3');
add_filter('woocommerce_payment_gateways', 'add_citypay_gateway_class');


function add_citypay_gateway_class($methods)
{
    $methods[] = 'WC_Gateway_CityPayPaylink';
    return $methods;
}

add_action('plugins_loaded', 'init_citypay_gateway_class');

function init_citypay_gateway_class()
{
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'citypay_gateway_woocommerce_error');
        return;
    }
    require_once(dirname(__FILE__) . '/WC_Gateway_CityPay_Paylink.php');
}

function citypay_gateway_woocommerce_error()
{
    echo '<div class="error"><p>CityPay Gateway plugin requires WooCommerce to be activated.</p></div>';
}

?>
