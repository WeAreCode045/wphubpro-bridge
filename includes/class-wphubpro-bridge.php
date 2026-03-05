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

		// Plugins
		register_rest_route( $namespace, '/plugins', array(
			'methods'             => 'GET',
			'callback'            => array( $this->plugins, 'get_plugins_list' ),
			'permission_callback' => $validate,
		) );
		register_rest_route( $namespace, '/plugins/manage', array(
			'methods'             => 'POST',
			'callback'            => array( $this->plugins, 'manage_plugin' ),
			'permission_callback' => $validate,
			'args'                => array(
				'action' => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'activate', 'deactivate', 'delete', 'update', 'install' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'plugin' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'slug'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// Themes
		register_rest_route( $namespace, '/themes', array(
			'methods'             => 'GET',
			'callback'            => array( $this->themes, 'get_themes_list' ),
			'permission_callback' => $validate,
		) );
		register_rest_route( $namespace, '/themes/manage', array(
			'methods'             => 'POST',
			'callback'            => array( $this->themes, 'manage_theme' ),
			'permission_callback' => $validate,
		) );

		// Site details (WordPress version, plugin/theme counts, PHP info)
		register_rest_route( $namespace, '/details', array(
			'methods'             => 'GET',
			'callback'            => array( $this->details, 'get_details' ),
			'permission_callback' => $validate,
		) );

		// Feature-specific route registration (placeholders)
		$this->health->register_routes( $namespace );
		$this->debug->register_routes( $namespace );
	}
}

}
