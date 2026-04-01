<?php
namespace WPHubPro;

use WPHubPro\Api\Health;
use WPHubPro\Api\Updater;
use WPHubPro\Auth\Auth;
use WPHubPro\Plugin\Plugins;
use WPHubPro\Theme\Themes;
use WPHubPro\Admin\Admin;
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
if ( ! class_exists( Bridge::class ) ) {

class Bridge {

	private static $instance = null;

	/** @var ConnectionStatus */
	private $connection_status;

	/** @var Connect */
	private $connect;

	/** @var Updater */
	private $updater;

	/** @var Plugins */
	private $plugins;

	/** @var Themes */
	private $themes;

	/** @var Health */
	private $health;

	/** @var Heartbeat */
	private $heartbeat;

	public static function instance() : self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->connect = Connect::instance();
		$this->connection_status = ConnectionStatus::instance();
		$this->updater = Updater::instance();
		$this->plugins = new Plugins();
		$this->themes  = new Themes();
		$this->health     = new Health();
		$this->heartbeat = new Heartbeat();

		$this->add_hooks();
	}

	/**
	 * Register WordPress hooks.
	 */
	private function add_hooks() {
		add_action( 'init', array( Admin::instance(), 'init' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all REST API routes.
	 */
	public function register_routes() : void {
		$this->connection_status->register_rest_routes();
		$this->connect->register_rest_routes();
		$this->updater->register_rest_routes();
		
		$namespace = Config::REST_NAMESPACE;
		$validate  = array( Auth::class, 'validate_api_key' );

		$this->heartbeat->register_rest_routes();

		// Hub-triggered: push latest Site Health snapshot to the platform (site-health function).
		register_rest_route( $namespace, '/health/push', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'push_health_status_to_hub' ),
			'permission_callback' => $validate,
		) );

		// Plugins (list + manage — single registration point).
		$this->plugins->register_rest_routes();

		// Themes (list + manage — single registration point).
		$this->themes->register_rest_routes();


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
		$log = Config::get_log();
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
	/**
	 * Collect WordPress Site Health (and related modules) and POST to the Hub.
	 *
	 * Called via wp-proxy from the dashboard “Check health” action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function push_health_status_to_hub( $request ) {
		$result = Health::send_health_status();
		if ( false === $result ) {
			return new \WP_Error(
				'wphubpro_health_push_failed',
				__( 'Could not send health data to the hub. Check the connection or try again.', 'wphubpro-bridge' ),
				array( 'status' => 502 )
			);
		}
		return rest_ensure_response( array(
			'success' => true,
			'message' => __( 'Health report sent to the hub.', 'wphubpro-bridge' ),
		) );
	}

	public function get_error_log( $request ) {
		$log_file = null;
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && defined( 'WP_CONTENT_DIR' ) ) {
			$log_file = WP_CONTENT_DIR . '/debug.log';
		}
		if ( ! $log_file || ! is_readable( $log_file ) ) {
			$php_log = (string) ini_get( 'error_log' );
			if ( $php_log !== '' && is_readable( $php_log ) ) {
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
