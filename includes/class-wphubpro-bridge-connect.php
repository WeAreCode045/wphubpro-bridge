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

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'rest_api_init', array( $this, 'add_save_connection_cors' ) );
	}

	/**
	 * Allow CORS for save-connection so platform can POST from browser.
	 */
	public function add_save_connection_cors() {
		add_filter( 'rest_pre_serve_request', array( $this, 'cors_headers_for_save_connection' ), 5, 4 );
	}

	/**
	 * Add CORS headers for save-connection and handle OPTIONS preflight.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_HTTP_Response $result  Result to send.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool
	 */
	public function cors_headers_for_save_connection( $served, $result, $request, $server ) {
		$route = $request->get_route();
		if ( ! $route || strpos( $route, 'wphubpro/v1/save-connection' ) === false ) {
			return $served;
		}
		$origin = $request->get_header( 'Origin' );
		if ( $origin ) {
			// Strip CR/LF to prevent header injection. Do not use esc_attr() - HTTP headers must not be HTML-escaped.
			$origin = str_replace( array( "\r", "\n" ), '', $origin );
			header( 'Access-Control-Allow-Origin: ' . $origin );
		}
		header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
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
		$stored_key   = get_option( 'wphubpro_api_key' );
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
		delete_option( 'wphubpro_api_key' );
		delete_option( 'WPHUBPRO_USER_JWT' );
		delete_option( 'WPHUBPRO_ENDPOINT' );
		delete_option( 'WPHUBPRO_PROJECT_ID' );
		delete_option( 'WPHUBPRO_SITE_ID' );
		delete_option( 'WPHUBPRO_HEARTBEAT_URL' );
		delete_option( 'WPHUBPRO_LAST_HEARTBEAT_AT' );
		update_option( 'wphub_status', 'disconnected' );
		WPHubPro_Bridge_Heartbeat::unschedule();
		return array( 'success' => true );
	}

	/**
	 * Handle save connection: store api_key, endpoint, project, site_id from platform.
	 *
	 * Called by ConnectSuccessPage after site create/update. Validates via X-WPHub-Key.
	 * Overwrites wphubpro_api_key with api_key from body.
	 *
	 * @param WP_REST_Request $request Request with api_key, endpoint, project_id, site_id, heartbeat_url.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_save_connection( $request ) {
		$api_key      = $request->get_param( 'api_key' );
		$endpoint     = $request->get_param( 'endpoint' );
		$project_id   = $request->get_param( 'project_id' );
		$site_id      = $request->get_param( 'site_id' );
		$heartbeat_url = $request->get_param( 'heartbeat_url' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_api_key', 'api_key is required', array( 'status' => 400 ) );
		}

		update_option( 'wphubpro_api_key', sanitize_text_field( $api_key ) );
		if ( ! empty( $endpoint ) ) {
			update_option( 'WPHUBPRO_ENDPOINT', untrailingslashit( $endpoint ) );
		}
		if ( ! empty( $project_id ) ) {
			update_option( 'WPHUBPRO_PROJECT_ID', $project_id );
		}
		if ( ! empty( $site_id ) ) {
			update_option( 'WPHUBPRO_SITE_ID', sanitize_text_field( $site_id ) );
		}
		if ( ! empty( $heartbeat_url ) ) {
			update_option( 'WPHUBPRO_HEARTBEAT_URL', esc_url_raw( untrailingslashit( $heartbeat_url ) ) );
		} else {
			delete_option( 'WPHUBPRO_HEARTBEAT_URL' );
		}
		update_option( 'wphub_status', 'connected' );

		WPHubPro_Bridge_Heartbeat::schedule();

		// Initial plugin/theme sync after connect
		if ( class_exists( 'WPHubPro_Bridge_Sync' ) ) {
			add_action( 'shutdown', array( 'WPHubPro_Bridge_Sync', 'sync_meta_to_appwrite' ), 5 );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Handle connect request: generate API key and return redirect URL.
	 *
	 * Base URL is configurable via Debug tab.
	 *
	 * @return array{redirect: string}
	 */
	public function handle_connect() {
		error_log( '[WPHubPro Bridge] connect GET' );
		$api_key = wp_generate_password( 32, false );
		update_option( 'wphubpro_api_key', $api_key );
		$params = array(
			'site_url'   => get_site_url(),
			'user_login' => wp_get_current_user()->user_login,
			'api_key'    => $api_key,
		);
		$base    = WPHubPro_Bridge_Debug::get_redirect_base_url();
		$base    = untrailingslashit( $base );
		$redirect = $base . '/#' . add_query_arg( $params, '/connect-success' );
		return array( 'redirect' => $redirect );
	}
}
