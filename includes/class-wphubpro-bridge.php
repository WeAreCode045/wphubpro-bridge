<?php
/**
 * WPHubPro Bridge – main orchestrator.
 *
 * Loads feature classes and registers REST routes.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main bridge class – coordinates feature modules.
 */
if ( ! class_exists( 'WPHubPro_Bridge' ) ) {

class WPHubPro_Bridge {

	private static $instance = null;

	/** @var WPHubPro_Bridge_Connect */
	private $connect;

	/** @var WPHubPro_Bridge_Plugins */
	private $plugins;

	/** @var WPHubPro_Bridge_Themes */
	private $themes;

	/** @var WPHubPro_Bridge_Details */
	private $details;

	/** @var WPHubPro_Bridge_Health */
	private $health;

	/** @var WPHubPro_Bridge_Debug */
	private $debug;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->connect = WPHubPro_Bridge_Connect::instance();
		$this->plugins = new WPHubPro_Bridge_Plugins();
		$this->themes  = new WPHubPro_Bridge_Themes();
		$this->details = new WPHubPro_Bridge_Details();
		$this->health  = new WPHubPro_Bridge_Health();
		$this->debug   = new WPHubPro_Bridge_Debug();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'log_rest_request' ), 10, 3 );
	}

	/**
	 * Register all REST API routes.
	 */
	public function register_routes() {
		$namespace = 'wphubpro/v1';
		$validate  = array( 'WPHubPro_Bridge_Connect', 'validate_api_key' );

		// Connect (requires manage_options)
		register_rest_route( $namespace, '/connect', array(
			'methods'             => 'GET',
			'callback'            => array( $this->connect, 'handle_connect' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );

		// Connection status (admin only)
		register_rest_route( $namespace, '/connection-status', array(
			'methods'             => 'GET',
			'callback'            => function () {
				return rest_ensure_response( WPHubPro_Bridge_Connection_Status::fetch() );
			},
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );

		// Disconnect (remove from hub, admin only)
		register_rest_route( $namespace, '/disconnect', array(
			'methods'             => 'POST',
			'callback'            => array( $this->connect, 'handle_disconnect' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );

		// Heartbeat poke (platform can call to verify bridge is reachable)
		register_rest_route( $namespace, '/heartbeat/poke', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( 'WPHubPro_Bridge_Heartbeat', 'handle_poke' ),
		) );

		// Save connection (api_key, endpoint, project) from platform - validates via X-WPHub-Key
		register_rest_route( $namespace, '/save-connection', array(
			'methods'             => 'POST',
			'callback'            => array( $this->connect, 'handle_save_connection' ),
			'permission_callback' => array( 'WPHubPro_Bridge_Connect', 'validate_api_key' ),
			'args'                => array(
				'api_key'      => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'endpoint'      => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'project_id'    => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'site_id'       => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'heartbeat_url' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		) );

		// Plugins
		register_rest_route( $namespace, '/plugins', array(
			'methods'             => 'GET',
			'callback'            => array( $this->plugins, 'get_plugins_list' ),
			'permission_callback' => $validate,
		) );
		$plugin_args = array(
			'plugin' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'slug'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		register_rest_route( $namespace, '/plugins/manage/activate', array(
			'methods'             => 'POST',
			'callback'            => array( $this->plugins, 'activate_plugin' ),
			'permission_callback' => $validate,
			'args'                => $plugin_args,
		) );
		register_rest_route( $namespace, '/plugins/manage/deactivate', array(
			'methods'             => 'POST',
			'callback'            => array( $this->plugins, 'deactivate_plugin' ),
			'permission_callback' => $validate,
			'args'                => $plugin_args,
		) );
		register_rest_route( $namespace, '/plugins/manage/uninstall', array(
			'methods'             => 'POST',
			'callback'            => array( $this->plugins, 'uninstall_plugin' ),
			'permission_callback' => $validate,
			'args'                => $plugin_args,
		) );
		register_rest_route( $namespace, '/plugins/manage/update', array(
			'methods'             => 'POST',
			'callback'            => array( $this->plugins, 'update_plugin' ),
			'permission_callback' => $validate,
			'args'                => $plugin_args,
		) );

		// Themes
		register_rest_route( $namespace, '/themes', array(
			'methods'             => 'GET',
			'callback'            => array( $this->themes, 'get_themes_list' ),
			'permission_callback' => $validate,
		) );
		$theme_args = array(
			'slug' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
		register_rest_route( $namespace, '/themes/manage/activate', array(
			'methods'             => 'POST',
			'callback'            => array( $this->themes, 'activate_theme' ),
			'permission_callback' => $validate,
			'args'                => $theme_args,
		) );
		register_rest_route( $namespace, '/themes/manage/update', array(
			'methods'             => 'POST',
			'callback'            => array( $this->themes, 'update_theme' ),
			'permission_callback' => $validate,
			'args'                => $theme_args,
		) );
		register_rest_route( $namespace, '/themes/manage/delete', array(
			'methods'             => 'POST',
			'callback'            => array( $this->themes, 'delete_theme' ),
			'permission_callback' => $validate,
			'args'                => $theme_args,
		) );

		// Site details (WordPress version, plugin/theme counts, PHP info)
		register_rest_route( $namespace, '/details', array(
			'methods'             => 'GET',
			'callback'            => array( $this->details, 'get_details' ),
			'permission_callback' => $validate,
		) );

		// Bridge logs (from option WPHUBPRO_LOG; this call is not logged)
		register_rest_route( $namespace, '/logs', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_logs' ),
			'permission_callback' => $validate,
		) );

		// PHP error log (last 20 lines; this call is not logged)
		register_rest_route( $namespace, '/error-log', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_error_log' ),
			'permission_callback' => $validate,
		) );

		// Feature-specific route registration (placeholders)
		$this->health->register_routes( $namespace );
		$this->debug->register_routes( $namespace );
	}

	/**
	 * Log each wphubpro/v1 request to WPHUBPRO_LOG option (last 20).
	 *
	 * @param WP_REST_Response|WP_Error $response Result to send.
	 * @param WP_REST_Server            $server   Server instance.
	 * @param WP_REST_Request           $request  Request object.
	 * @return WP_REST_Response|WP_Error Unchanged response.
	 */
	/**
	 * Return bridge API log from option WPHUBPRO_LOG (used by platform; this request is not logged).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_logs( $request ) {
		$log = get_option( 'WPHUBPRO_LOG', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		return rest_ensure_response( array( 'logs' => $log ) );
	}

	/**
	 * Return last 20 lines of the site's PHP error log (WP debug.log or PHP error_log).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_error_log( $request ) {
		$log_file = null;
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && defined( 'WP_CONTENT_DIR' ) ) {
			$log_file = WP_CONTENT_DIR . '/debug.log';
		}
		if ( ! $log_file || ! is_readable( $log_file ) ) {
			$php_log = ini_get( 'error_log' );
			if ( $php_log && is_readable( $php_log ) ) {
				$log_file = $php_log;
			}
		}
		if ( ! $log_file || ! is_readable( $log_file ) ) {
			return rest_ensure_response( array(
				'lines' => array(),
				'file'  => null,
				'error' => __( 'Error log file not found or not readable. Enable WP_DEBUG_LOG or check PHP error_log.', 'wphubpro-bridge' ),
			) );
		}
		$lines = @file( $log_file, FILE_IGNORE_NEW_LINES );
		if ( ! is_array( $lines ) ) {
			return rest_ensure_response( array( 'lines' => array(), 'file' => $log_file, 'error' => __( 'Could not read log file.', 'wphubpro-bridge' ) ) );
		}
		$last_200 = array_slice( $lines, -400 );
		return rest_ensure_response( array( 'lines' => $last_200, 'file' => $log_file ) );
	}

	/**
	 * Log each wphubpro/v1 request to WPHUBPRO_LOG option (last 20).
	 * Excludes /logs to avoid logging the logs request itself.
	 *
	 * @param WP_REST_Response|WP_Error $response Result to send.
	 * @param WP_REST_Server            $server   Server instance.
	 * @param WP_REST_Request          $request  Request object.
	 * @return WP_REST_Response|WP_Error Unchanged response.
	 */
	public function log_rest_request( $response, $server, $request ) {
		$route = $request->get_route();
		if ( strpos( $route, 'wphubpro/v1' ) !== false && strpos( $route, '/logs' ) === false && strpos( $route, '/error-log' ) === false ) {
			WPHubPro_Bridge_Logger::push_api_log( $request, $response );
		}
		return $response;
	}
}

}
