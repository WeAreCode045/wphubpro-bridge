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
if ( ! defined( 'WPHUBPRO_BRIDGE_ABSPATH' ) ) {
	define( 'WPHUBPRO_BRIDGE_ABSPATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WPHUBPRO_BRIDGE_VERSION' ) ) {
	define( 'WPHUBPRO_BRIDGE_VERSION', '2.1.0' );
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

/**
 * Deactivation: explicitly preserve connection options.
 * Options (wphubpro_api_key, WPHUBPRO_USER_JWT, etc.) must remain in wp_options
 * so the connection works again after reactivation without reconnecting.
 */
register_deactivation_hook(__FILE__, function() {
	// Intentionally do nothing. Do not delete options on deactivation.
});

/**
 * Install WPHubPro Recovery Agent as mu-plugin on activation and when bridge is updated.
 */
function wphubpro_bridge_ensure_recovery_agent() {
	$source = WPHUBPRO_BRIDGE_ABSPATH . 'wphubpro-recovery-agent.php';
	if ( ! file_exists( $source ) ) {
		return;
	}
	$mu_dir = WP_CONTENT_DIR . '/mu-plugins';
	if ( ! is_dir( $mu_dir ) ) {
		wp_mkdir_p( $mu_dir );
	}
	if ( ! is_writable( $mu_dir ) ) {
		return;
	}
	$dest = $mu_dir . '/wphubpro-recovery-agent.php';
	$bridge_version = defined( 'WPHUBPRO_BRIDGE_VERSION' ) ? WPHUBPRO_BRIDGE_VERSION : '2.1.0';
	$installed = get_option( 'wphubpro_recovery_agent_version', '' );
	if ( $installed === $bridge_version && file_exists( $dest ) ) {
		return;
	}
	if ( copy( $source, $dest ) ) {
		update_option( 'wphubpro_recovery_agent_version', $bridge_version );
	}
}

register_activation_hook( __FILE__, 'wphubpro_bridge_ensure_recovery_agent' );

add_action( 'plugins_loaded', function() {
	// Ensure recovery agent is up to date after bridge updates (activation runs only on activate).
	wphubpro_bridge_ensure_recovery_agent();
}, 1 );

