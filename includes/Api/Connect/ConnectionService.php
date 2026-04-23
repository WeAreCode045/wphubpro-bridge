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
 * `POST …/save-connection` mirrors the Hub `manage-sites` handler `connect_site`: flat JSON after merging
 * optional nested `body` with top-level fields, plus `api_key` (and optional `username`);
 * `X-WPHub-Key` must match the bridge secret.
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

	/** @var bool Avoid duplicate shutdown hooks when save-connection runs more than once per request. */
	private static bool $connect_site_execution_scheduled = false;

	/** Keys accepted on save-connection (aligned with manage-sites connect_site + Hub SPA). */
	private const SAVE_CONNECTION_KEYS = array(
		'api_key',
		'bridge_secret',
		'site_secret',
		'endpoint',
		'project_id',
		'site_id',
		'username',
		'heartbeat_url',
		'encrypted_api_key',
	);

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
	 * @param \WP_REST_Request $request Same shape as manage-sites `connect_site` POST body to WordPress.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_connection_from_request( \WP_REST_Request $request ) {
		$params = self::collect_save_connection_params( $request );
		$skip_relay = strtolower( (string) $request->get_header( 'x_wphub_execution' ) ) === 'manage-sites';

		$response = self::apply_save_connection_payload( $params );

		if ( ! $skip_relay && ! ( $response instanceof \WP_Error ) ) {
			self::schedule_connect_site_execution_after_save();
		}

		return $response;
	}

	/**
	 * After browser-originated save-connection, relay through Hub manage-sites `connect_site` (skipped when
	 * the request is already from that execution — header {@see handleConnectSite} sets X-WPHub-Execution).
	 */
	private static function schedule_connect_site_execution_after_save(): void {
		if ( self::$connect_site_execution_scheduled ) {
			return;
		}
		self::$connect_site_execution_scheduled = true;

		add_action(
			'shutdown',
			static function () {
				( new ConnectExecution() )->invoke_connect_site();
			},
			20
		);
	}

	/**
	 * Normalize request into one associative array (nested `body` merged first, top-level wins — same order as connectSite.js).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function collect_save_connection_params( \WP_REST_Request $request ): array {
		$json = $request->get_json_params();
		if ( ! is_array( $json ) ) {
			$json = array();
		}

		foreach ( self::SAVE_CONNECTION_KEYS as $key ) {
			if ( array_key_exists( $key, $json ) ) {
				continue;
			}
			$v = $request->get_param( $key );
			if ( null !== $v && '' !== $v ) {
				$json[ $key ] = $v;
			}
		}

		$inner = array();
		if ( isset( $json['body'] ) && is_array( $json['body'] ) ) {
			$inner = $json['body'];
			unset( $json['body'] );
		}

		return array_merge( $inner, $json );
	}

	/**
	 * Persist options from merged payload (connect_site sends `api_key`; browser flow may send `bridge_secret`).
	 *
	 * @param array<string, mixed> $input Flat params after merge.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function apply_save_connection_payload( array $input ) {
		$api_key       = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		$bridge_secret = isset( $input['bridge_secret'] ) ? trim( (string) $input['bridge_secret'] ) : '';

		$bridge_secret_to_store = $api_key !== '' ? $api_key : $bridge_secret;
		if ( $bridge_secret_to_store === '' ) {
			return new \WP_Error( 'missing_api_key', 'api_key or bridge_secret is required', array( 'status' => 400 ) );
		}

		$bridge_secret_to_store = sanitize_text_field( $bridge_secret_to_store );
		self::store_plain_secret_option( Config::OPTION_API_KEY, $bridge_secret_to_store );

		$site_secret = isset( $input['site_secret'] ) ? trim( (string) $input['site_secret'] ) : '';
		if ( $site_secret !== '' ) {
			self::store_plain_secret_option( Config::OPTION_SITE_SECRET, sanitize_text_field( $site_secret ) );
		} else {
			\delete_option( Config::OPTION_SITE_SECRET );
		}

		$endpoint = isset( $input['endpoint'] ) ? trim( (string) $input['endpoint'] ) : '';
		if ( $endpoint !== '' ) {
			\update_option( Config::OPTION_API_BASE_URL, untrailingslashit( esc_url_raw( $endpoint ) ) );
		}

		$project_id = isset( $input['project_id'] ) ? trim( (string) $input['project_id'] ) : '';
		if ( $project_id !== '' ) {
			\update_option( Config::OPTION_PROJECT_ID, sanitize_text_field( $project_id ) );
		}

		$site_id = isset( $input['site_id'] ) ? trim( (string) $input['site_id'] ) : '';
		if ( $site_id !== '' ) {
			\update_option( Config::OPTION_SITE_ID, sanitize_text_field( $site_id ) );
		}

		// $username = isset( $input['username'] ) ? trim( (string) $input['username'] ) : '';
		// if ( $username !== '' ) {
		// 	update_option( Config::OPTION_WP_ADMIN_USERNAME, sanitize_text_field( $username ) );
		// } else {
		// 	delete_option( Config::OPTION_WP_ADMIN_USERNAME );
		// }

		// $heartbeat_url = isset( $input['heartbeat_url'] ) ? trim( (string) $input['heartbeat_url'] ) : '';
		// if ( $heartbeat_url !== '' ) {
		// 	update_option( Config::OPTION_HEARTBEAT_URL, esc_url_raw( $heartbeat_url ) );
		// } else {
		// 	delete_option( Config::OPTION_HEARTBEAT_URL );
		}

		// $encrypted_api_key = isset( $input['encrypted_api_key'] ) ? trim( (string) $input['encrypted_api_key'] ) : '';
		// if ( $encrypted_api_key !== '' ) {
		// 	update_option( Config::OPTION_ENCRYPTED_API_KEY, $encrypted_api_key );
		// } else {
		// 	delete_option( Config::OPTION_ENCRYPTED_API_KEY );
		// }

		\update_option( Config::OPTION_STATUS, 'connected' );

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
