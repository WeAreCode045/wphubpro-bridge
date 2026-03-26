<?php
namespace WPHubPro\Plugin;

/**
 * Bridge plugin identity checks (cannot deactivate/uninstall from platform, etc.).
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helpers for detecting the WPHubPro Bridge plugin file.
 */
class BridgeGuard {

	/**
	 * Plugin file path for the bridge (e.g. wphubpro-bridge/wphubpro-bridge.php).
	 *
	 * @return string
	 */
	public static function get_bridge_plugin_file() : string {
		if ( defined( 'WPHUBPRO_BRIDGE_PLUGIN_FILE' ) ) {
			return plugin_basename( WPHUBPRO_BRIDGE_PLUGIN_FILE );
		}
		return 'wphubpro-bridge/wphubpro-bridge.php';
	}

	/**
	 * Whether the given plugin file is the bridge itself.
	 *
	 * @param string $plugin Plugin file (e.g. wphubpro-bridge/wphubpro-bridge.php).
	 * @return bool
	 */
	public static function is_bridge_plugin( string $plugin ) : bool {
		if ( empty( $plugin ) ) {
			return false;
		}
		$bridge_file = self::get_bridge_plugin_file();
		return $plugin === $bridge_file || strpos( $plugin, 'wphubpro-bridge/' ) === 0;
	}
}
