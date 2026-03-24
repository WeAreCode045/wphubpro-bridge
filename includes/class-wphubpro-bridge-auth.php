<?php
/**
 * REST authentication for WPHubPro Bridge (X-WPHub-Key, connect-token exchange, CORS).
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hub→Bridge REST auth, one-time connect token exchange, and CORS for those routes.
 *
 * Static-only: call init() once (e.g. from WPHubPro_Bridge_Connect::register_rest_routes).
 */
class WPHubPro_Bridge_Auth {

	/**
	 * Bootstrap: attach CORS and related filters.
	 */
	public static function init() {
		self::add_hooks();
	}

	/**
	 * Register WordPress hooks.
	 */
	private static function add_hooks() {
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'cors_headers_for_save_connection' ), 5, 4 );
	}

	/**
	 * Add CORS headers for save-connection and exchange-token; handle OPTIONS preflight.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_HTTP_Response $result  Result to send.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool
	 */
	public static function cors_headers_for_save_connection( $served, $result, $request, $server ) {
		$origin = $request->get_header( 'Origin' );
		if ( $origin ) {
			$origin = str_replace( array( "\r", "\n" ), '', $origin );
			header( 'Access-Control-Allow-Origin: ' . $origin );
			header( 'Access-Control-Allow-Credentials: true' );
		}
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, X-WPHub-Key' );
		header( 'Access-Control-Max-Age: 86400' );
		if ( $request->get_method() === 'OPTIONS' ) {
			status_header( 200 );
			exit;
		}
		return $served;
	}

	/**
	 * Validate API key from request header.
	 *
	 * @return bool
	 */
	public static function validate_api_key() {
		$stored_key   = WPHubPro_Bridge_Config::get_api_key();
		$provided_key = isset( $_SERVER['HTTP_X_WPHUB_KEY'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WPHUB_KEY'] ) ) : '';
		if ( empty( $stored_key ) || empty( $provided_key ) ) {
			return false;
		}
		if ( ! hash_equals( $stored_key, $provided_key ) ) {
			return false;
		}
		self::ensure_admin_user_for_api_request();
		return true;
	}

	/**
	 * Set current user to first administrator when request is authenticated via X-WPHub-Key.
	 * Plugins like Elementor may check current_user_can() during activation.
	 */
	private static function ensure_admin_user_for_api_request() {
		if ( get_current_user_id() > 0 ) {
			return;
		}
		$admins = get_users( array(
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
		) );
		if ( ! empty( $admins ) ) {
			wp_set_current_user( $admins[0]->ID );
		}
	}

	/**
	 * Permission callback for exchange-token: validate connect_token instead of WP auth.
	 * Cross-origin requests from Hub do not send WordPress cookies, so we use the
	 * one-time token as proof that the user initiated connect from the WP admin.
	 *
	 * @param WP_REST_Request $request Request with connect_token query param.
	 * @return bool
	 */
	public static function validate_exchange_token_permission( $request ) {
		$connect_token = $request->get_param( 'connect_token' );
		if ( empty( $connect_token ) ) {
			return false;
		}
		$transient_key = 'wphubpro_connect_' . sanitize_text_field( $connect_token );
		return get_transient( $transient_key ) !== false;
	}

	/**
	 * Exchange one-time connect_token for bridge_secret. Invalidates token after use.
	 *
	 * @param WP_REST_Request $request Request with connect_token query param.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_exchange_token( $request ) {
		$connect_token = $request->get_param( 'connect_token' );
		if ( empty( $connect_token ) ) {
			return new WP_Error( 'missing_token', 'connect_token is required', array( 'status' => 400 ) );
		}
		$transient_key = 'wphubpro_connect_' . sanitize_text_field( $connect_token );
		$bridge_secret = get_transient( $transient_key );
		if ( $bridge_secret === false ) {
			return new WP_Error( 'invalid_token', 'Token expired or invalid', array( 'status' => 400 ) );
		}
		delete_transient( $transient_key );
		return rest_ensure_response( array( 'bridge_secret' => $bridge_secret ) );
	}
}
