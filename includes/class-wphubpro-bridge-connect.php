<?php
/**
 * Connect & site linking for WPHubPro Bridge.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles site connect, connection storage, and admin UI.
 */
class WPHubPro_Bridge_Connect extends WPHubPro_Bridge_API {

	/**
	 * Instance of the class.
	 * @var WPHubPro_Bridge_Connect|null
	 */
	private static $instance = null;

	/**
	 * Instance of WPHubPro_Bridge_Sync.
	 * @var WPHubPro_Bridge_Sync
	 */
	private $sync;

	/**
	 * Get the instance of the class.
	 *
	 * @return WPHubPro_Bridge_Connect
	 */
	public static function instance() : WPHubPro_Bridge_Connect {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->sync = WPHubPro_Bridge_Sync::instance();
	}

	/**
	 * Register REST routes for connect, disconnect, save-connection, redirect settings, and bridge updates.
	 */
	public function register_rest_routes() {
		WPHubPro_Bridge_Auth::init();

		$namespace = WPHubPro_Bridge_Config::REST_NAMESPACE;

		// Connect (requires manage_options)
		register_rest_route( $namespace, '/connect', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_connect' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );

		// Exchange one-time token for bridge_secret. Validates token (not WP auth) because
		// the request is cross-origin from Hub and cookies are not sent.
		register_rest_route( $namespace, '/exchange-token', array(
			'methods'             => 'GET',
			'callback'            => array( 'WPHubPro_Bridge_Auth', 'handle_exchange_token' ),
			'permission_callback' => array( 'WPHubPro_Bridge_Auth', 'validate_exchange_token_permission' ),
			'args'                => array(
				'connect_token' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// Connection status (admin only)
		register_rest_route( $namespace, '/connection-status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_connection_status' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );

		// Disconnect (remove from hub, admin only)
		register_rest_route( $namespace, '/disconnect', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_disconnect' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );

		// Redirect URL settings for connect flow (admin only)
		register_rest_route( $namespace, '/connect/redirect-settings', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_redirect_settings' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );
		register_rest_route( $namespace, '/connect/redirect-settings', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_redirect_settings' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'use_default' => array(
					'required' => true,
					'type'     => 'boolean',
				),
				'custom_url'  => array(
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		) );

		

		// Save connection (api_key, endpoint, project) from platform - validates via X-WPHub-Key
		register_rest_route( $namespace, '/save-connection', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_save_connection' ),
			'permission_callback' => array( 'WPHubPro_Bridge_Auth', 'validate_api_key' ),
			'args'                => array(
				'api_key'           => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'bridge_secret'     => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'site_secret'       => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'encrypted_api_key' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'endpoint'          => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'project_id'        => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'site_id'           => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'heartbeat_url'     => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		) );
	}

	/**
	 * REST callback: connection status payload.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_connection_status() {
		return rest_ensure_response( WPHubPro_Bridge_Connection_Status::fetch() );
	}

	/**
	 * Handle disconnect: remove API key and JWT/connection options locally.
	 *
	 * @return array{success: bool}
	 */
	public function handle_disconnect() {
		WPHubPro_Bridge_Config::remove_options();
		WPHubPro_Bridge_Heartbeat::unschedule();
		WPHubPro_Bridge_Health::unschedule();
		return array( 'success' => true );
	}

	/**
	 * Handle save connection: store bridge_secret, site_secret, endpoint, project, site_id from platform.
	 *
	 * Validates via X-WPHub-Key (bridge_secret). Legacy: api_key only (single shared key).
	 *
	 * @param WP_REST_Request $request Request with api_key/bridge_secret, site_secret, endpoint, project_id, site_id, heartbeat_url.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_save_connection( $request ) {
		$api_key           = $request->get_param( 'api_key' );
		$bridge_secret     = $request->get_param( 'bridge_secret' );
		$site_secret       = $request->get_param( 'site_secret' );
		$encrypted_api_key = $request->get_param( 'encrypted_api_key' );
		$endpoint          = $request->get_param( 'endpoint' );
		$project_id        = $request->get_param( 'project_id' );
		$site_id           = $request->get_param( 'site_id' );
		$heartbeat_url     = $request->get_param( 'heartbeat_url' );

		$bridge_secret_to_store = $bridge_secret ?: $api_key;
		if ( empty( $bridge_secret_to_store ) ) {
			return new WP_Error( 'missing_api_key', 'api_key or bridge_secret is required', array( 'status' => 400 ) );
		}
		// Store plaintext only – WPHubPro_Bridge_Auth::validate_api_key compares X-WPHub-Key with stored. encrypted_api_key is for Hub storage.
		$bridge_secret_to_store = sanitize_text_field( $bridge_secret_to_store );
		if ( class_exists( 'WPHubPro_Bridge_Crypto' ) ) {
			WPHubPro_Bridge_Crypto::encrypt_and_store( WPHubPro_Bridge_Config::OPTION_API_KEY, $bridge_secret_to_store );
		} else {
			update_option( WPHubPro_Bridge_Config::OPTION_API_KEY, $bridge_secret_to_store );
		}
		if ( ! empty( $site_secret ) ) {
			$site_secret = sanitize_text_field( $site_secret );
			if ( class_exists( 'WPHubPro_Bridge_Crypto' ) ) {
				WPHubPro_Bridge_Crypto::encrypt_and_store( WPHubPro_Bridge_Config::OPTION_SITE_SECRET, $site_secret );
			} else {
				update_option( WPHubPro_Bridge_Config::OPTION_SITE_SECRET, $site_secret );
			}
		} else {
			delete_option( WPHubPro_Bridge_Config::OPTION_SITE_SECRET );
		}
		if ( ! empty( $endpoint ) ) {
			update_option( WPHubPro_Bridge_Config::OPTION_API_BASE_URL, untrailingslashit( $endpoint ) );
		}
		if ( ! empty( $project_id ) ) {
			update_option( WPHubPro_Bridge_Config::OPTION_PROJECT_ID, $project_id );
		}
		if ( ! empty( $site_id ) ) {
			update_option( WPHubPro_Bridge_Config::OPTION_SITE_ID, sanitize_text_field( $site_id ) );
		}
		if ( ! empty( $heartbeat_url ) ) {
			update_option( WPHubPro_Bridge_Config::OPTION_HEARTBEAT_URL, esc_url_raw( untrailingslashit( $heartbeat_url ) ) );
		} else {
			delete_option( WPHubPro_Bridge_Config::OPTION_HEARTBEAT_URL );
		}
		update_option( WPHubPro_Bridge_Config::OPTION_STATUS, 'connected' );

		// Initial plugin/theme sync after connect
		if ( class_exists( 'WPHubPro_Bridge_Sync' ) ) {
			WPHubPro_Bridge_Sync::schedule_sync();
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Handle connect request: generate bridge_secret and one-time connect_token, redirect with token only.
	 *
	 * @return array{redirect: string}
	 */
	public function handle_connect() {
		$bridge_secret = wp_generate_password( 64, true, true );
		$connect_token = wp_generate_password( 32, false );
		if ( class_exists( 'WPHubPro_Bridge_Crypto' ) ) {
			WPHubPro_Bridge_Crypto::encrypt_and_store( WPHubPro_Bridge_Config::OPTION_API_KEY, $bridge_secret );
		} else {
			update_option( WPHubPro_Bridge_Config::OPTION_API_KEY, $bridge_secret );
		}
		set_transient( 'wphubpro_connect_' . $connect_token, $bridge_secret, 5 * MINUTE_IN_SECONDS );
		$params = array(
			'site_url'      => get_site_url(),
			'user_login'    => wp_get_current_user()->user_login,
			'connect_token' => $connect_token,
		);
		$base    = WPHubPro_Bridge_Config::get_redirect_base_url();
		$base    = untrailingslashit( $base );
		$redirect = $base . '/#' . add_query_arg( $params, '/connect-success' );
		return array( 'redirect' => $redirect );
	}

	/**
	 * Get redirect URL settings for connect flow.
	 *
	 * @return WP_REST_Response
	 */
	public function get_redirect_settings() {
		$current = get_option( WPHubPro_Bridge_Config::OPTION_REDIRECT_BASE_URL, '' );
		$default = WPHubPro_Bridge_Config::DEFAULT_REDIRECT_BASE_URL;
		$use_default = ( $current === '' || $current === $default );
		return rest_ensure_response( array(
			'use_default'  => $use_default,
			'custom_url'   => $use_default ? '' : $current,
			'default_url'  => $default,
		) );
	}

	/**
	 * Save redirect URL settings.
	 *
	 * @param WP_REST_Request $request Request with use_default and optional custom_url.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_redirect_settings( $request ) {
		$use_default = $request->get_param( 'use_default' );
		if ( $use_default ) {
			delete_option( WPHubPro_Bridge_Config::OPTION_REDIRECT_BASE_URL );
			return rest_ensure_response( array(
				'success'     => true,
				'use_default' => true,
			) );
		}
		$custom_url = $request->get_param( 'custom_url' );
		$custom_url = is_string( $custom_url ) ? trim( $custom_url ) : '';
		$custom_url = untrailingslashit( esc_url_raw( $custom_url ) );
		if ( empty( $custom_url ) || strpos( $custom_url, 'https://' ) !== 0 ) {
			return new WP_Error( 'invalid_url', 'Custom URL must be a valid HTTPS URL.', array( 'status' => 400 ) );
		}
		update_option( WPHubPro_Bridge_Config::OPTION_REDIRECT_BASE_URL, $custom_url );
		return rest_ensure_response( array(
			'success'     => true,
			'use_default' => false,
			'custom_url'  => $custom_url,
		) );
	}

	
}
