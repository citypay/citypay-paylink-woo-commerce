<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_CityPay extends WC_Payment_Gateway
{
    public $debug = 'no';
    public $testmode = 'no';
    public $log;

    public function __construct()
    {
    }

    /* Check if the module is available for the current checkout process */
    public function is_available()
    {
        return $this->enabled === "yes";
    }

    function debugLog($text)
    {
        if ('yes' == $this->debug) {
            $this->log->debug($text, array('source' => $this->id));
        }
    }

    function infoLog($text)
    {
        $this->log->info($text, array('source' => $this->id));
    }

    function errorLog($text)
    {
        $this->log->error($text, array('source' => $this->id));
    }

    function warningLog($text)
    {
        $this->log->warning($text, array('source' => $this->id));
    }

    function formatedAmount($amount) {
        return (int)number_format((float)$amount, 2, '', '');
    }

    public function get_icon() {
        $base = plugin_dir_url(__FILE__) . 'assets/cards/';
        $icons = [];

        $main_logo = plugin_dir_url(__FILE__) . 'assets/citypay-logo100.png';
        $icons[] = sprintf(
            '<img src="%s" alt="%s" style="height:24px; margin-right:6px;" />',
            esc_url($main_logo),
            esc_attr__('CityPay', 'wc-payment-gateway-citypay')
        );

        // Conditionally add card logos based on your settings
        if ($this->get_option('show_visa_logo', 'yes') === 'yes') {
            $icons[] = sprintf('<img src="%s" alt="Visa" style="height:24px; margin-right:6px; max-height: 40px;" />', esc_url($base.'cs-logo-vs-50h.png'));
        }
        if ($this->get_option('show_mastercard_logo', 'yes') === 'yes') {
            $icons[] = sprintf('<img src="%s" alt="Mastercard" style="height:24px; margin-right:6px; max-height: 40px;" />', esc_url($base.'cs-logo-mc-50h.png'));
        }
        if ($this->get_option('show_maestro_logo', 'yes') === 'yes') {
            $icons[] = sprintf('<img src="%s" alt="Maestro" style="height:24px; margin-right:6px; max-height: 40px;" />', esc_url($base.'cs-logo-ma-50h.png'));
        }
        if ($this->get_option('show_visa_electron_logo', 'no') === 'yes') {
            $icons[] = sprintf('<img src="%s" alt="Visa Electron" style="height:24px; margin-right:6px; max-height: 40px;" />', esc_url($base.'cs-logo-ve-50h.png'));
        }
        if ($this->get_option('show_amex_logo', 'no') === 'yes') {
            $icons[] = sprintf('<img src="%s" alt="American Express" style="height:24px; margin-right:6px; max-height: 40px;" />', esc_url($base.'cs-logo-am-50h.png'));
        }
        if ($this->get_option('show_diners_club_logo', 'no') === 'yes') {
            $icons[] = sprintf('<img src="%s" alt="Diners Club" style="height:24px; margin-right:6px; max-height: 40px;" />', esc_url($base.'cs-logo-dn-50h.png'));
        }
        if ($this->get_option('show_jcb_logo', 'no') === 'yes') {
            $icons[] = sprintf('<img src="%s" alt="JCB" style="height:24px; margin-right:6px; max-height: 40px;" />', esc_url($base.'cs-logo-jc-50h.png'));
        }
        if ($this->get_option('show_apple_pay_logo', 'no') === 'yes') {
            $icons[] = sprintf('<img src="%s" alt="JCB" style="height:24px; margin-right:6px; max-height: 40px;" />', esc_url($base.'cs-logo-ap-50h.png'));
        }
        if ($this->get_option('show_google_pay_logo', 'no') === 'yes') {
            $icons[] = sprintf('<img src="%s" alt="JCB" style="height:24px; margin-right:6px; max-height: 40px;" />', esc_url($base.'cs-logo-gp-50h.png'));
        }

        $html = implode('', $icons);

        return apply_filters('woocommerce_gateway_icon', $html, $this->id);
    }

}




?>