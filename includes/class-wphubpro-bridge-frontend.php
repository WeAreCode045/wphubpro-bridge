<?php
/**
 * Frontend assets (public-facing).
 *
 * Hook convention: singleton with private add_hooks() invoked from the constructor
 * (same pattern as WPHubPro_Bridge, WPHubPro_Bridge_Admin).
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues styles and scripts on the front end.
 */
class WPHubPro_Bridge_Frontend {

	/**
	 * Instance of the class.
	 *
	 * @var WPHubPro_Bridge_Frontend|null
	 */
	private static $instance = null;

	/**
	 * Get the instance of the class.
	 *
	 * @return WPHubPro_Bridge_Frontend
	 */
	public static function instance() : WPHubPro_Bridge_Frontend {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: register hooks.
	 */
	private function __construct() {
		$this->add_hooks();
	}

	/**
	 * Register WordPress hooks.
	 */
	private function add_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Register the stylesheets for the frontend area.
	 */
	public function enqueue_styles() {
		wp_enqueue_style( 'wphubpro-bridge-frontend', untrailingslashit( plugins_url( '/', WPHUBPRO_BRIDGE_PLUGIN_FILE ) ) . '/assets/css/frontend.css', array(), '1.0.0', 'all' );
	}

	/**
	 * Register the JavaScript for the frontend area.
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 'wphubpro-bridge-frontend', untrailingslashit( plugins_url( '/', WPHUBPRO_BRIDGE_PLUGIN_FILE ) ) . '/assets/js/frontend.js', array( 'jquery' ), '1.0.0', false );
	}
}
