<?php
namespace WPHubPro\Health;

/**
 * Core health information for WPHubPro Bridge.
 *
 * Returns core information about the WordPress site.
 *
 * @package WPHubPro\Health
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site details: WordPress version, plugin/theme counts, PHP info.
 */
class WooCommerce {

    public static function is_active() {
        return is_plugin_active('woocommerce/woocommerce.php');
    }

    public static function get_version() {
        return defined('WC_VERSION') ? WC_VERSION : null;
    }

    public static function get_status() {
        // Check pages exist (best-effort)
        $checkout_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('checkout') : null;
        $cart_url     = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('cart') : null;

        return [
            'active' => (bool)$active,
            'version' => $version,
            'checkout_url' => $checkout_url ?: null,
            'cart_url' => $cart_url ?: null,
        ];
    }

}