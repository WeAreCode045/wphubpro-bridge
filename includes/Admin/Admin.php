<?php
namespace WPHUBPRO\Admin;

/**
 * The admin-specific functionality of the plugin.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Admin UI: menus and assets.
 *
 * Bootstrap: init() runs on WordPress {@see 'init'} (see wphubpro-bridge.php) so menus register at the correct time.
 */
class Admin {
	/**
	 * Instance of the class.
	 *
	 * @var Admin|null
	 */
	private static $instance = null;

	/**
	 * Get the instance of the class.
	 *
	 * @return Admin
	 */
	public static function instance() : Admin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Called on WordPress `init` (see main plugin file).
	 */
	public function init() {
		$this->add_hooks();
	}

	/**
	 * Register WordPress hooks.
	 */
	private function add_hooks() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Register the stylesheets for the admin area.s
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {
		wp_enqueue_style( 'wphubpro-bridge-admin', untrailingslashit( plugins_url( '/', WPHUBPRO_BRIDGE_PLUGIN_FILE ) ) . '/assets/css/admin.css', array(), '1.0.0', 'all' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 'wphubpro-bridge-admin', untrailingslashit( plugins_url( '/', WPHUBPRO_BRIDGE_PLUGIN_FILE ) ) . '/assets/js/admin.js', array( 'jquery' ), '1.0.0', false );
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
}
