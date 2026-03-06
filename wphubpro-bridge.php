<?php
/**
 * Plugin Name: WPHubPro Bridge
 * Plugin URI: https://wphub.pro/bridge
 * Description: REST bridge for WPHubPro platform. Provides plugin, theme, and site health management via WP REST Controllers.
 * Version: 2.1.0
 * Author: WPHub PRO
 * Author URI: https://wphub.pro
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$autoload = __DIR__ . '/lib/appwrite-autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

if ( ! defined( 'WPHUBPRO_BRIDGE_PLUGIN_FILE' ) ) {
	define('WPHUBPRO_BRIDGE_PLUGIN_FILE', __FILE__);
}
if (!defined('WPHUBPRO_BRIDGE_ABSPATH')) {
	define('WPHUBPRO_BRIDGE_ABSPATH', plugin_dir_path(__FILE__));
}

// Autoload includes
foreach ([
	'class-wphubpro-bridge-logger.php',
	'class-wphubpro-bridge-connect.php',
	'class-wphubpro-bridge-connection-status.php',
	'class-wphubpro-bridge-plugins.php',
	'class-wphubpro-bridge-themes.php',
	'class-wphubpro-bridge-details.php',
	'class-wphubpro-bridge-health.php',
	'class-wphubpro-bridge-debug.php',
	'class-wphubpro-bridge.php',
	'class-wphubpro-bridge-admin.php',
	'class-wphubpro-bridge-ajax.php',
	'class-wphubpro-bridge-frontend.php',
] as $file) {
	$inc = __DIR__ . '/includes/' . $file;
	if (file_exists($inc)) require_once $inc;
}

// Main loader
add_action('plugins_loaded', function() {
	if (class_exists('WPHubPro_Bridge')) {
		WPHubPro_Bridge::instance();
	}
});

