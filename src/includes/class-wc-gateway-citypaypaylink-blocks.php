<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * WooCommerce Blocks integration for CityPay Paylink.
 */
if ( ! class_exists( 'WC_Gateway_CityPayPaylink_Blocks', false ) && class_exists( AbstractPaymentMethodType::class ) ) {

    class WC_Gateway_CityPayPaylink_Blocks extends AbstractPaymentMethodType {

        /** @var string Must match $this->id in WC_Gateway_CityPay_Paylink */
        protected $name = 'citypay';

        /** @var array<string, mixed> */
        protected $settings = array();

        /** @var WC_Gateway_CityPayPaylink|null */
        protected $gateway = null;

        public function get_name() {
            return $this->name;
        }

        public function initialize() {
            $this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );

            // Reuse the PHP gateway instance (for title/description/is_available())
            if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
                $all = WC()->payment_gateways()->payment_gateways();
                $this->gateway = isset( $all[ $this->name ] ) ? $all[ $this->name ] : null;
            }
        }

        public function is_active() {
            if ( $this->gateway instanceof WC_Gateway_CityPayPaylink ) {
                return $this->gateway->is_available();
            }

            return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
        }

        /**
         * Hardcode the support flags exposed to WooCommerce Blocks.
         *
         * @return array<int, string>
         */
        public function get_supported_features() {
            return array( 'products', 'subscriptions' );
        }

        public function get_payment_method_script_handles() {
            $handle = 'wc-citypay-blocks';
            $url    = plugin_dir_url( __FILE__ ) . '../assets/js/blocks-citypay.js';
            $deps   = array( 'wc-blocks-registry', 'wp-element', 'wp-i18n' );

            wp_register_script(
                $handle,
                $url,
                $deps,
                defined( 'CITYPAY_PAYMENTS_VERSION' ) ? CITYPAY_PAYMENTS_VERSION : ( defined( 'WP_DEBUG' ) && WP_DEBUG ? time() : '1.0.0' ),
                true
            );

            wp_localize_script( $handle, 'wcCityPayBlocksData', $this->get_payment_method_data() );

            return array( $handle );
        }

        public function get_payment_method_data() {
            $title       = $this->gateway
                ? $this->gateway->get_title()
                : ( isset( $this->settings['title'] ) && '' !== $this->settings['title']
                    ? $this->settings['title']
                    : __( 'CityPay', 'wc-payment-gateway-citypay' ) );
            $description = $this->gateway
                ? wp_kses_post( $this->gateway->get_description() )
                : ( isset( $this->settings['description'] ) ? wp_kses_post( $this->settings['description'] ) : '' );
            $icons       = array();

            // Always show the CityPay mark first
            $icons[] = array(
                'src' => esc_url( plugin_dir_url( __FILE__ ) . '../assets/citypay-logo100.png' ),
                'alt' => 'CityPay',
            );

            // Pull settings and map to marks
            $settings  = $this->settings;
            $get       = function( $key, $default = 'no' ) use ( $settings ) {
                return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
            };
            $cards_base = plugin_dir_url( __FILE__ ) . '../assets/cards/';
            $add = function( $file, $alt ) use ( &$icons, $cards_base ) {
                $icons[] = array( 'src' => esc_url( $cards_base . $file ), 'alt' => esc_attr( $alt ) );
            };

            if ( 'yes' === $get( 'show_visa_logo', 'yes' ) )         { $add( 'cs-logo-vs-50h.png', 'Visa' ); }
            if ( 'yes' === $get( 'show_mastercard_logo', 'yes' ) )   { $add( 'cs-logo-mc-50h.png', 'Mastercard' ); }
            if ( 'yes' === $get( 'show_maestro_logo', 'yes' ) )      { $add( 'cs-logo-ma-50h.png', 'Maestro' ); }
            if ( 'yes' === $get( 'show_visa_electron_logo', 'no' ) ) { $add( 'cs-logo-ve-50h.png', 'Visa Electron' ); }
            if ( 'yes' === $get( 'show_amex_logo', 'no' ) )          { $add( 'cs-logo-am-50h.png', 'American Express' ); }
            if ( 'yes' === $get( 'show_diners_club_logo', 'no' ) )   { $add( 'cs-logo-dn-50h.png', 'Diners Club' ); }
            if ( 'yes' === $get( 'show_jcb_logo', 'no' ) )           { $add( 'cs-logo-jc-50h.png', 'JCB' ); }

            // NEW: Apple Pay and Google Pay marks
            if ( 'yes' === $get( 'show_apple_pay_logo', 'no' ) )     { $add( 'cs-logo-ap-50h.png', 'Apple Pay' ); }
            if ( 'yes' === $get( 'show_google_pay_logo', 'no' ) )    { $add( 'cs-logo-gp-50h.svg.png', 'Google Pay' ); }

            return array(
                'title'       => wp_kses_post( $title ),
                'description' => wp_kses_post( $description ),
                'icons'       => $icons,
                'supports'    => $this->get_supported_features(),
                'isActive'    => $this->is_active(),
                'gatewayId'   => $this->get_name(),
            );
        }
    }
}
