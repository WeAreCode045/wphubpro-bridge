<?php
/**
 * Plugin Name: WPHubPro Bridge
 * Plugin URI: https://wphub.pro/bridge
 * Description: WPHubPro Bridge is a plugin that provides a bridge between the WPHubPro platform and WordPress. It allows you to manage your WordPress site from the WPHubPro platform.
 * Version: 2.5.6
 * Author: WPHub PRO
 * Author URI: https://wphub.pro
 */

use WPHubPro\Api\Sync;
use WPHubPro\Autoloader;
use WPHubPro\Bridge;
use WPHubPro\Config;
use WPHubPro\Cron\Scheduler;

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
	define( 'WPHUBPRO_BRIDGE_VERSION', '2.5.6' );
}

require_once WPHUBPRO_BRIDGE_ABSPATH . 'src/Autoloader.php';

Autoloader::register();


// Main loader
add_action('plugins_loaded', function() {
	if ( class_exists( Bridge::class ) ) {
		Bridge::instance();
	}
	if ( class_exists( Scheduler::class ) ) {
		Scheduler::init();
	}
	if ( class_exists( Sync::class ) ) {
		Sync::init();
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
	update_option( Config::OPTION_BRIDGE_PLUGIN, wp_json_encode( $data ) );

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
	$installed = Config::get_recovery_agent_version();
	if ( $installed === $bridge_version && file_exists( $dest ) ) {
		return;
	}
	if ( copy( $source, $dest ) ) {
		update_option( Config::OPTION_RECOVERY_AGENT_VERSION, $bridge_version );
	}
}

register_activation_hook( __FILE__, 'wphubpro_bridge_ensure_recovery_agent' );

add_action( 'plugins_loaded', function() {
	// Ensure recovery agent is up to date after bridge updates (activation runs only on activate).
	wphubpro_bridge_ensure_recovery_agent();
}, 1 );
