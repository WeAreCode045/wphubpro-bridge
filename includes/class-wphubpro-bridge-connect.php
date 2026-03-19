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
 * Handles site connect, API key validation, and admin menu.
 */
class WPHubPro_Bridge_Connect {

	private static $instance = null;

	/** @var WPHubPro_Bridge_Sync */
	private $sync;


	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'rest_api_init', array( $this, 'add_save_connection_cors' ) );
		$this->sync = WPHubPro_Bridge_Sync::instance();
	}

	/**
	 * Allow CORS for save-connection so platform can POST from browser.
	 */
	public function add_save_connection_cors() {
		add_filter( 'rest_pre_serve_request', array( $this, 'cors_headers_for_save_connection' ), 5, 4 );
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
	public function cors_headers_for_save_connection( $served, $result, $request, $server ) {
		$route = $request->get_route();
		$is_save = $route && strpos( $route, 'wphubpro/v1/save-connection' ) !== false;
		$is_exchange = $route && strpos( $route, 'wphubpro/v1/exchange-token' ) !== false;
		if ( ! $is_save && ! $is_exchange ) {
			return $served;
		}
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
		return hash_equals( $stored_key, $provided_key );
	}

	/**
	 * Add admin menu for WPHubPro Bridge.
	 */
	public function add_admin_menu() {
		add_menu_page(
			'WPHubPro Bridge',
			'WPHubPro Bridge',
			'manage_options',
			'wphubpro-bridge',
			array( $this, 'render_admin_page' ),
			'dashicons-admin-links',
			80
		);
	}

	/**
	 * Render the connect admin page with tabs.
	 */
	public function render_admin_page() {
		$tab      = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'connect';
		$base_url = admin_url( 'admin.php?page=wphubpro-bridge' );
		include WPHUBPRO_BRIDGE_ABSPATH . 'templates/admin-page.php';
	}

	/**
	 * Handle disconnect: remove API key and JWT/connection options locally.
	 *
	 * @return array{success: bool}
	 */
	public function handle_disconnect() {
		delete_option( WPHubPro_Bridge_Config::OPTION_API_KEY );
		delete_option( 'wphub_api_key' );
		delete_option( WPHubPro_Bridge_Config::OPTION_SITE_SECRET );
		delete_option( WPHubPro_Bridge_Config::OPTION_USER_JWT );
		delete_option( WPHubPro_Bridge_Config::OPTION_BASE_URL );
		delete_option( WPHubPro_Bridge_Config::OPTION_PROJECT_ID );
		delete_option( WPHubPro_Bridge_Config::OPTION_SITE_ID );
		delete_option( WPHubPro_Bridge_Config::OPTION_HEARTBEAT_URL );
		delete_option( WPHubPro_Bridge_Config::OPTION_API_BASE_URL );
		delete_option( WPHubPro_Bridge_Config::OPTION_LAST_HEARTBEAT_AT );
		update_option( WPHubPro_Bridge_Config::OPTION_STATUS, 'disconnected' );
		WPHubPro_Bridge_Heartbeat::unschedule();
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

		$bridge_secret_to_store = ! empty( $encrypted_api_key )
			? sanitize_text_field( $encrypted_api_key )
			: sanitize_text_field( $bridge_secret_to_store );
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

		// WPHubPro_Bridge_Heartbeat::schedule();

		// Initial plugin/theme sync after connect
		if ( class_exists( 'WPHubPro_Bridge_Sync' ) ) {
			add_action( 'shutdown', array( $this->sync, 'sync_meta_to_appwrite' ), 5 );
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
		$base    = WPHubPro_Bridge_Debug::get_redirect_base_url();
		$base    = untrailingslashit( $base );
		$redirect = $base . '/#' . add_query_arg( $params, '/connect-success' );
		return array( 'redirect' => $redirect );
	}

	/**
	 * Exchange one-time connect_token for bridge_secret. Invalidates token after use.
	 *
	 * @param WP_REST_Request $request Request with connect_token query param.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_exchange_token( $request ) {
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
