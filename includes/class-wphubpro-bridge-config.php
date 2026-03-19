<?php
/**
 * Central config for WPHubPro Bridge options.
 *
 * Provides get methods for all options used via get_option() across the bridge.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Config class: read-only access to bridge options.
 */
class WPHubPro_Bridge_Config {

	/** Option: Base URL. */
	const OPTION_BASE_URL = 'wphubpro_base_url';
	/** Option: Base URL. */
	const OPTION_API_BASE_URL = 'wphubpro_api_base_url';
	/** Option: Appwrite project ID. */
	const OPTION_PROJECT_ID = 'wphubpro_project_id';
	/** Option: API key / bridge_secret (Hub→Bridge auth). */
	const OPTION_API_KEY = 'wphubpro_api_key';
	/** Option: Site secret (Bridge→Hub auth). */
	const OPTION_SITE_SECRET = 'wphubpro_site_secret';
	/** Option: Site ID from platform. */
	const OPTION_SITE_ID = 'wphubpro_site_id';
	/** Option: User JWT for Appwrite. */
	const OPTION_USER_JWT = 'wphubpro_user_jwt';
	/** Option: Heartbeat URL. */
	const OPTION_HEARTBEAT_URL = 'wphubpro_heartbeat_url';
	/** Option: Last heartbeat timestamp. */
	const OPTION_LAST_HEARTBEAT_AT = 'wphubpro_last_heartbeat_at';
	/** Option: API request log (last N entries). */
	const OPTION_LOG = 'wphubpro_log';
	/** Option: Connection status (connected|disconnected). */
	const OPTION_STATUS = 'wphub_status';
	/** Option: Redirect base URL for connect flow. */
	const OPTION_REDIRECT_BASE_URL = 'wphubpro_redirect_base_url';
	/** Option: Recovery agent installed version. */
	const OPTION_RECOVERY_AGENT_VERSION = 'wphubpro_recovery_agent_version';
	/** Option: Last bridge update timestamp. */
	const OPTION_LAST_UPDATE = 'wphubpro_last_update';
	/** Option: Bridge plugin installed version (JSON: { installed }). */
	const OPTION_BRIDGE_PLUGIN = 'bridge_plugin';

	const DEFAULT_REDIRECT_BASE_URL = 'https://app.wphub.pro';
	const DEFAULT_STATUS = 'disconnected';

	/**
	 * Appwrite Base URL.
	 *
	 * @return string
	 */
	public static function get_base_url() {
		return get_option( self::OPTION_BASE_URL, '' );
	}

	/**
	 * Appwrite Base URL.
	 *
	 * @return string
	 */
	public static function get_api_base_url() {
		return get_option( self::OPTION_API_BASE_URL, 'https://api.wphub.pro/v1' );
	}

	/**
	 * Appwrite project ID.
	 *
	 * @return string
	 */
	public static function get_project_id() {
		return get_option( self::OPTION_PROJECT_ID, '' );
	}

	/**
	 * API key / shared secret (bridge_secret for Hub→Bridge auth).
	 * Decrypts if stored in encrypted form (wp_salt-based).
	 *
	 * @return string
	 */
	public static function get_api_key() {
		return WPHubPro_Bridge_Crypto::retrieve_and_decrypt( self::OPTION_API_KEY );
	}

	/**
	 * Site secret (Bridge→Hub auth). Falls back to bridge_secret for legacy.
	 *
	 * @return string
	 */
	public static function get_site_secret() {
		$site_secret = WPHubPro_Bridge_Crypto::retrieve_and_decrypt( self::OPTION_SITE_SECRET );
		if ( ! empty( $site_secret ) ) {
			return $site_secret;
		}
		return self::get_api_key();
	}

	/**
	 * Site ID from platform.
	 *
	 * @return string
	 */
	public static function get_site_id() {
		return get_option( self::OPTION_SITE_ID, '' );
	}

	/**
	 * User JWT for Appwrite.
	 *
	 * @return string
	 */
	public static function get_user_jwt() {
		return get_option( self::OPTION_USER_JWT, '' );
	}

	/**
	 * Heartbeat URL.
	 *
	 * @return string
	 */
	public static function get_heartbeat_url() {
		return get_option( self::OPTION_HEARTBEAT_URL, '' );
	}

	/**
	 * Last heartbeat timestamp (ISO 8601).
	 *
	 * @return string
	 */
	public static function get_last_heartbeat_at() {
		return get_option( self::OPTION_LAST_HEARTBEAT_AT, '' );
	}

	/**
	 * API request log entries.
	 *
	 * @return array
	 */
	public static function get_log() {
		$log = get_option( self::OPTION_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Connection status: 'connected' or 'disconnected'.
	 *
	 * @return string
	 */
	public static function get_status() {
		return get_option( self::OPTION_STATUS, self::DEFAULT_STATUS );
	}

	/**
	 * Redirect base URL for connect flow.
	 *
	 * @return string
	 */
	public static function get_redirect_base_url() {
		return get_option( self::OPTION_REDIRECT_BASE_URL, self::DEFAULT_REDIRECT_BASE_URL );
	}

	/**
	 * Recovery agent installed version.
	 *
	 * @return string
	 */
	public static function get_recovery_agent_version() {
		return get_option( self::OPTION_RECOVERY_AGENT_VERSION, '' );
	}

	/**
	 * Last bridge update timestamp.
	 *
	 * @return string|null
	 */
	public static function get_last_update() {
		return get_option( self::OPTION_LAST_UPDATE, null );
	}

	/**
	 * Bridge plugin version data. WP options store only installed version.
	 * Latest version is fetched from WPHub when user clicks "Check for updates".
	 *
	 * @return array{installed: string, latest: string}
	 */
	public static function get_bridge_plugin_data() {
		$installed = defined( 'WPHUBPRO_BRIDGE_VERSION' ) ? WPHUBPRO_BRIDGE_VERSION : '';
		$raw       = get_option( self::OPTION_BRIDGE_PLUGIN, '' );
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) && ! empty( $decoded['installed'] ) ) {
				$installed = $decoded['installed'];
			}
		} else {
			$legacy = get_option( 'bridge_version', '' );
			if ( $legacy ) {
				$installed = $legacy;
			}
		}
		return array( 'installed' => $installed, 'latest' => $installed );
	}

	/**
	 * Installed bridge plugin version.
	 *
	 * @return string
	 */
	public static function get_bridge_version() {
		$data = self::get_bridge_plugin_data();
		return $data['installed'];
	}

	/**
	 * WordPress active plugins list (core option).
	 *
	 * @return array
	 */
	public static function get_active_plugins() {
		$active = get_option( 'active_plugins', array() );
		return is_array( $active ) ? $active : array();
	}
}
