<?php
namespace WPHubPro\Api\Connect;

use WPHubPro\Api\Health;
use WPHubPro\Api\Heartbeat;
use WPHubPro\Api\Sync;
use WPHubPro\Auth\Crypto;
use WPHubPro\Config;

/**
 * Persists Hub→Bridge connection data, connect-flow redirect URL settings, and local disconnect.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connection storage and connect-flow helpers (no REST wiring).
 */
class ConnectionService {

	private const TRANSIENT_PREFIX = 'wphubpro_connect_';

	/**
	 * Remove API key and connection options, and unschedule jobs.
	 *
	 * @return array{success: bool}
	 */
	public static function disconnect_local(): array {
		Config::remove_options();
		Heartbeat::unschedule();
		Health::unschedule();

		return array( 'success' => true );
	}

	/**
	 * Apply save-connection payload from the Hub (X-WPHub-Key validated by REST layer).
	 *
	 * @param \WP_REST_Request $request Request with bridge/site secrets, endpoint, ids.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_connection_from_request( \WP_REST_Request $request ) {
		$api_key           = $request->get_param( 'api_key' );
		$bridge_secret     = $request->get_param( 'bridge_secret' );
		$site_secret       = $request->get_param( 'site_secret' );
		$endpoint          = $request->get_param( 'endpoint' );
		$project_id        = $request->get_param( 'project_id' );
		$site_id           = $request->get_param( 'site_id' );

		$bridge_secret_to_store = $bridge_secret ? $bridge_secret : $api_key;
		if ( empty( $bridge_secret_to_store ) ) {
			return new \WP_Error( 'missing_api_key', 'api_key or bridge_secret is required', array( 'status' => 400 ) );
		}

		$bridge_secret_to_store = sanitize_text_field( (string) $bridge_secret_to_store );
		self::store_plain_secret_option( Config::OPTION_API_KEY, $bridge_secret_to_store );

		if ( ! empty( $site_secret ) ) {
			self::store_plain_secret_option( Config::OPTION_SITE_SECRET, sanitize_text_field( (string) $site_secret ) );
		} else {
			delete_option( Config::OPTION_SITE_SECRET );
		}

		if ( ! empty( $endpoint ) ) {
			update_option( Config::OPTION_API_BASE_URL, untrailingslashit( (string) $endpoint ) );
		}
		if ( ! empty( $project_id ) ) {
			update_option( Config::OPTION_PROJECT_ID, (string) $project_id );
		}
		if ( ! empty( $site_id ) ) {
			update_option( Config::OPTION_SITE_ID, sanitize_text_field( (string) $site_id ) );
		}

		update_option( Config::OPTION_STATUS, 'connected' );

		if ( class_exists( Sync::class ) ) {
			Sync::schedule_sync();
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Generate bridge_secret and one-time connect_token; store secret and transient; return Hub redirect URL.
	 *
	 * @return array{redirect: string}
	 */
	public static function build_connect_redirect_response(): array {
		$bridge_secret = wp_generate_password( 64, true, true );
		$connect_token = wp_generate_password( 32, false );

		self::store_plain_secret_option( Config::OPTION_API_KEY, $bridge_secret );
		set_transient( self::TRANSIENT_PREFIX . $connect_token, $bridge_secret, 5 * MINUTE_IN_SECONDS );

		$params = array(
			'site_url'      => get_site_url(),
			'user_login'    => wp_get_current_user()->user_login,
			'connect_token' => $connect_token,
		);
		$base     = Config::get_base_url();
		$base     = untrailingslashit( $base );
		$redirect = $base . add_query_arg( $params, '/connect-success' );

		return array( 'redirect' => $redirect );
	}

	/**
	 * Redirect URL settings for the connect flow (admin UI).
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_redirect_settings_response(): \WP_REST_Response {
		Config::get_base_url();
		$current     = (string) get_option( Config::OPTION_BASE_URL, '' );
		$default     = Config::DEFAULT_REDIRECT_BASE_URL;
		$use_default = ( $current === '' || $current === $default );

		return rest_ensure_response(
			array(
				'use_default' => $use_default,
				'custom_url'  => $use_default ? '' : $current,
				'default_url' => $default,
			)
		);
	}

	/**
	 * Persist custom Hub base URL or reset to default.
	 *
	 * @param \WP_REST_Request $request Request with use_default and optional custom_url.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_redirect_settings_from_request( \WP_REST_Request $request ) {
		$use_default = (bool) $request->get_param( 'use_default' );
		if ( $use_default ) {
			delete_option( Config::OPTION_BASE_URL );
			delete_option( 'wphubpro_redirect_base_url' );

			return rest_ensure_response(
				array(
					'success'     => true,
					'use_default' => true,
				)
			);
		}

		$custom_url = $request->get_param( 'custom_url' );
		$custom_url = is_string( $custom_url ) ? trim( $custom_url ) : '';
		$custom_url = untrailingslashit( esc_url_raw( $custom_url ) );

		if ( empty( $custom_url ) || strpos( $custom_url, 'https://' ) !== 0 ) {
			return new \WP_Error( 'invalid_url', 'Custom URL must be a valid HTTPS URL.', array( 'status' => 400 ) );
		}

		update_option( Config::OPTION_BASE_URL, $custom_url );

		return rest_ensure_response(
			array(
				'success'     => true,
				'use_default' => false,
				'custom_url'  => $custom_url,
			)
		);
	}

	/**
	 * Store plaintext secret; uses Crypto when available (Auth::validate_api_key compares to stored value).
	 *
	 * @param string $option_name Option key (e.g. Config::OPTION_API_KEY).
	 * @param string $value       Plaintext value.
	 */
	private static function store_plain_secret_option( string $option_name, string $value ): void {
		if ( class_exists( Crypto::class ) ) {
			Crypto::encrypt_and_store( $option_name, $value );
		} else {
			update_option( $option_name, $value );
		}
	}
}
