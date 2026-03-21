<?php
/**
 * Plugin Name: WPHubPro Bridge
 * Plugin URI: https://wphub.pro/bridge
 * Description: WPHubPro Bridge is a plugin that provides a bridge between the WPHubPro platform and WordPress. It allows you to manage your WordPress site from the WPHubPro platform.
 * Version: 2.3.12
 * Author: WPHub PRO
 * Author URI: https://wphub.pro
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WPHUBPRO_BRIDGE_PLUGIN_FILE' ) ) {
	define('WPHUBPRO_BRIDGE_PLUGIN_FILE', __FILE__);
}
if ( ! defined( 'WPHUBPRO_BRIDGE_ABSPATH' ) ) {
	define( 'WPHUBPRO_BRIDGE_ABSPATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WPHUBPRO_BRIDGE_VERSION' ) ) {
	define( 'WPHUBPRO_BRIDGE_VERSION', '2.2.43' );
}

// Autoload includes
foreach ( array(
	'Error/BaseError.php',
	'Error/AuthenticationError.php',
	'Error/ValidationError.php',
	'Error/NotFoundError.php',
	'Error/RequestError.php',
	'class-wphubpro-bridge-api.php',
	'class-wphubpro-bridge-crypto.php',
	'class-wphubpro-bridge-config.php',
	'class-wphubpro-bridge-logger.php',
	'class-wphubpro-bridge-api-logger.php',
	'class-wphubpro-bridge-auth.php',
	'class-wphubpro-bridge-connect.php',
	'class-wphubpro-bridge-connection-status.php',
	'class-wphubpro-bridge-plugin-bridge-guard.php',
	'class-wphubpro-bridge-plugin-params.php',
	'class-wphubpro-bridge-plugin-upgrader-helper.php',
	'class-wphubpro-bridge-plugins.php',
	'class-wphubpro-bridge-theme-params.php',
	'class-wphubpro-bridge-theme-upgrader-helper.php',
	'class-wphubpro-bridge-themes.php',
	'class-wphubpro-bridge-sync.php',
	'class-wphubpro-bridge-heartbeat.php',
	'class-wphubpro-bridge-details.php',
	'class-wphubpro-bridge-health.php',
	'class-wphubpro-bridge.php',
	'class-wphubpro-bridge-admin.php',
	'class-wphubpro-bridge-ajax.php',
	'class-wphubpro-bridge-frontend.php',
) as $file ) {
	$inc = __DIR__ . '/includes/' . $file;
	if ( file_exists( $inc ) ) {
		require_once $inc;
	}
}

// Error classes (require Logger first)
foreach ( array( 'BaseError.php', 'AuthenticationError.php', 'ValidationError.php', 'NotFoundError.php' ) as $err_file ) {
	$inc = __DIR__ . '/includes/Error/' . $err_file;
	if ( file_exists( $inc ) ) {
		require_once $inc;
	}
}

// Main loader
add_action('plugins_loaded', function() {
	if (class_exists('WPHubPro_Bridge')) {
		WPHubPro_Bridge::instance();
	}
	if(class_exists('WPHubPro_Bridge_Admin') && is_admin()) {
		add_action('init', array(WPHubPro_Bridge_Admin::instance(), 'init'));
	}
	if (class_exists('WPHubPro_Bridge_Heartbeat')) {
		WPHubPro_Bridge_Heartbeat::init();
	}
	if (class_exists('WPHubPro_Bridge_Sync')) {
		WPHubPro_Bridge_Sync::init();
	}
});

/**
 * Deactivation: explicitly preserve connection options.
 * Options (WPHUBPRO_API_KEY, WPHUBPRO_USER_JWT, etc.) must remain in wp_options
 * so the connection works again after reactivation without reconnecting.
 */
register_deactivation_hook(__FILE__, function() {
	// Intentionally do nothing. Do not delete options on deactivation.
});

/**
 * Install WPHubPro Recovery Agent as mu-plugin on activation and when bridge is updated.
 */
function wphubpro_bridge_ensure_recovery_agent() {
	$bridge_version = defined( 'WPHUBPRO_BRIDGE_VERSION' ) ? WPHUBPRO_BRIDGE_VERSION : '2.1.0';
	$data = array( 'installed' => $bridge_version );
	update_option( WPHubPro_Bridge_Config::OPTION_BRIDGE_PLUGIN, wp_json_encode( $data ) );

	$source = WPHUBPRO_BRIDGE_ABSPATH . 'recovery/wphubpro-recovery-agent.php';
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
	$installed = WPHubPro_Bridge_Config::get_recovery_agent_version();
	if ( $installed === $bridge_version && file_exists( $dest ) ) {
		return;
	}
	if ( copy( $source, $dest ) ) {
		update_option( WPHubPro_Bridge_Config::OPTION_RECOVERY_AGENT_VERSION, $bridge_version );
	}
}

register_activation_hook( __FILE__, 'wphubpro_bridge_ensure_recovery_agent' );

add_action( 'plugins_loaded', function() {
	// Ensure recovery agent is up to date after bridge updates (activation runs only on activate).
	wphubpro_bridge_ensure_recovery_agent();
}, 1 );

