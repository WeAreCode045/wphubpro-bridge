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
 *
 * Hook convention (instance-based): private add_hooks() is called from __construct().
 * Static services (Auth, Sync, Heartbeat) use public static init() → private static add_hooks().
 */
if ( ! class_exists( 'WPHubPro_Bridge' ) ) {

class WPHubPro_Bridge {

	private static $instance = null;

	/** @var WPHubPro_Bridge_Connect */
	private $connect;

	/** @var WPHubPro_Bridge_Updater */
	private $updater;

	/** @var WPHubPro_Bridge_Plugins */
	private $plugins;

	/** @var WPHubPro_Bridge_Themes */
	private $themes;

	/** @var WPHubPro_Bridge_Details */
	private $details;

	/** @var WPHubPro_Bridge_Health */
	private $health;

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

		$this->add_hooks();
	}

	/**
	 * Register WordPress hooks.
	 */
	private function add_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all REST API routes.
	 */
	public function register_routes() {
		$this->connect->register_rest_routes();
		$this->updater->register_rest_routes();
		
		$namespace = WPHubPro_Bridge_Config::REST_NAMESPACE;
		$validate  = array( 'WPHubPro_Bridge_Auth', 'validate_api_key' );

		// Heartbeat poke (platform can call to verify bridge is reachable)
		register_rest_route( $namespace, '/heartbeat/poke', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( 'WPHubPro_Bridge_Heartbeat', 'handle_poke' ),
		) );

		// Plugins (list + manage — single registration point).
		$this->plugins->register_rest_routes();

		// Themes (list + manage — single registration point).
		$this->themes->register_rest_routes();

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
		$log = WPHubPro_Bridge_Config::get_log();
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
				'error' => __( 'Error log files not found or not readable. Enable WP_DEBUG_LOG or check PHP error_log.', 'wphubpro-bridge' ),
			) );
		}
		$lines = @file( $log_file, FILE_IGNORE_NEW_LINES );
		if ( ! is_array( $lines ) ) {
			return rest_ensure_response( array( 'lines' => array(), 'file' => $log_file, 'error' => __( 'Could not read log file.', 'wphubpro-bridge' ) ) );
		}
		$last_200 = array_slice( $lines, -400 );
		return rest_ensure_response( array( 'lines' => $last_200, 'file' => $log_file ) );
	}

	
}

}
