<?php
namespace WPHubPro;

use WPHubPro\Auth\Crypto;

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
class Config {

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
	/** Option: Internal schema version for one-time migrations (integer). */
	const OPTION_DB_VERSION = 'wphubpro_bridge_db_version';

	const DEFAULT_REDIRECT_BASE_URL = 'https://app.wphub.pro';
	const DEFAULT_STATUS = 'disconnected';

	/** REST API authentication provider. */
	const REST_API_AUTH_PROVIDER = array( 'WPHubPro\\Auth\\Auth', 'validate_api_key' );


	/** REST namespace for bridge routes. */
	const REST_NAMESPACE = 'wphubpro/v1';

	/**
	 * Appwrite Base URL.
	 *
	 * @return string
	 */
	public static function get_base_url() : string {
		return (string) get_option( self::OPTION_BASE_URL, '' );
	}

	/**
	 * Appwrite Base URL.
	 *
	 * @return string
	 */
	public static function get_api_base_url() : string {
		return (string) get_option( self::OPTION_API_BASE_URL, 'https://appwrite.wphub.pro/v1' );
	}

	/**
	 * Appwrite project ID.
	 *
	 * @return string
	 */
	public static function get_project_id() : string {
		return (string) get_option( self::OPTION_PROJECT_ID, '' );
	}

	/**
	 * API key / shared secret (bridge_secret for Hub→Bridge auth).
	 * Decrypts if stored in encrypted form (wp_salt-based).
	 *
	 * @return string
	 */
	public static function get_api_key() : string {
		return Crypto::retrieve_and_decrypt( self::OPTION_API_KEY );
	}

	/**
	 * Site secret (Bridge→Hub auth). Falls back to bridge_secret for legacy.
	 *
	 * @return string
	 */
	public static function get_site_secret() : string {
		$site_secret = Crypto::retrieve_and_decrypt( self::OPTION_SITE_SECRET );
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
	public static function get_site_id() : string {
		return (string) get_option( self::OPTION_SITE_ID, '' );
	}

	/**
	 * User JWT for Appwrite.
	 *
	 * @return string
	 */
	public static function get_user_jwt() : string {
		return (string) get_option( self::OPTION_USER_JWT, '' );
	}

	/**
	 * Heartbeat URL.
	 *
	 * @return string
	 */
	public static function get_heartbeat_url() : string {
		return (string) get_option( self::OPTION_HEARTBEAT_URL, '' );
	}

	/**
	 * Last heartbeat timestamp (ISO 8601).
	 *
	 * @return string
	 */
	public static function get_last_heartbeat_at() : string {
		return (string) get_option( self::OPTION_LAST_HEARTBEAT_AT, '' );
	}

	/**
	 * API request log entries.
	 *
	 * @return array
	 */
	public static function get_log() : array {
		$log = get_option( self::OPTION_LOG, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Connection status: 'connected' or 'disconnected'.
	 *
	 * @return string
	 */
	public static function get_status() : string {
		return (string) get_option( self::OPTION_STATUS, self::DEFAULT_STATUS );
	}

	/**
	 * Redirect base URL for connect flow.
	 *
	 * @return string
	 */
	public static function get_redirect_base_url() : string {
		return (string) get_option( self::OPTION_REDIRECT_BASE_URL, self::DEFAULT_REDIRECT_BASE_URL );
	}

	/**
	 * Recovery agent installed version.
	 *
	 * @return string
	 */
	public static function get_recovery_agent_version() : string {
		return (string) get_option( self::OPTION_RECOVERY_AGENT_VERSION, '' );
	}

	/**
	 * Last bridge update timestamp.
	 *
	 * @return string|null
	 */
	public static function get_last_update() : ?string {
		$v = get_option( self::OPTION_LAST_UPDATE, null );
		if ( $v === null || $v === false || $v === '' ) {
			return null;
		}

		return (string) $v;
	}

	/**
	 * Installed bridge version from the main plugin file header (WordPress "Version:").
	 * Prefer this over options: after a manual or ZIP update the option can stay stale until
	 * something writes it again; the file on disk is always authoritative.
	 *
	 * @return string Empty if the file cannot be read or has no Version header.
	 */
	public static function get_bridge_version_from_plugin_file() : string {
		if ( ! defined( 'WPHUBPRO_BRIDGE_PLUGIN_FILE' ) ) {
			return '';
		}
		$file = WPHUBPRO_BRIDGE_PLUGIN_FILE;
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$headers = get_file_data( $file, array( 'Version' => 'Version' ), 'plugin' );
		$v       = isset( $headers['Version'] ) ? trim( (string) $headers['Version'] ) : '';
		return $v;
	}

	/**
	 * Bridge plugin version data. WP options store only installed version.
	 * Latest version is fetched from WPHub when user clicks "Check for updates".
	 *
	 * @return array{installed: string, latest: string}
	 */
	public static function get_bridge_plugin_data() : array {
		$installed = self::get_bridge_version_from_plugin_file();
		if ( $installed === '' ) {
			$installed = defined( 'WPHUBPRO_BRIDGE_VERSION' ) ? (string) WPHUBPRO_BRIDGE_VERSION : '';
		}
		if ( $installed === '' ) {
			$raw = get_option( self::OPTION_BRIDGE_PLUGIN, '' );
			if ( is_string( $raw ) && $raw !== '' ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) && ! empty( $decoded['installed'] ) ) {
					$installed = (string) $decoded['installed'];
				}
			} else {
				$legacy = get_option( 'bridge_version', '' );
				if ( $legacy !== '' && $legacy !== false ) {
					$installed = (string) $legacy;
				}
			}
		}

		return array( 'installed' => $installed, 'latest' => $installed );
	}

	/**
	 * Installed bridge plugin version.
	 *
	 * @return string
	 */
	public static function get_bridge_version() : string {
		$data = self::get_bridge_plugin_data();
		return $data['installed'];
	}

	/**
	 * WordPress active plugins list (core option).
	 *
	 * @return array
	 */
	public static function get_active_plugins() : array {
		$active = get_option( 'active_plugins', array() );
		return is_array( $active ) ? $active : array();
	}

	/**
	 * 
	 */
	public static function remove_options() : void {
		delete_option( Config::OPTION_API_KEY );
		delete_option( Config::OPTION_SITE_SECRET );
		delete_option( Config::OPTION_USER_JWT );
		delete_option( Config::OPTION_BASE_URL );
		delete_option( Config::OPTION_PROJECT_ID );
		delete_option( Config::OPTION_SITE_ID );
		delete_option( Config::OPTION_HEARTBEAT_URL );
		delete_option( Config::OPTION_API_BASE_URL );
		delete_option( Config::OPTION_LAST_HEARTBEAT_AT );
		update_option( Config::OPTION_STATUS, 'disconnected' );
	}
}
