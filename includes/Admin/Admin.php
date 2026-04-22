<?php
namespace WPHubPro\Admin;

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

	const MENU_HOOK = 'toplevel_page_wphubpro-bridge';

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

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
	}

	/**
	 * Body class on the Bridge admin screen for scoped layout tweaks.
	 *
	 * @param string $classes Space-separated classes.
	 * @return string
	 */
	public function admin_body_class( string $classes ) : string {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return $classes;
		}
		$screen = get_current_screen();
		if ( $screen && self::MENU_HOOK === $screen->id ) {
			$classes .= ' wphubpro-bridge-admin';
		}
		return $classes;
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public function enqueue_styles( string $hook_suffix ) {
		if ( self::MENU_HOOK !== $hook_suffix ) {
			return;
		}

		$base_url = untrailingslashit( plugins_url( '/', WPHUBPRO_BRIDGE_PLUGIN_FILE ) );
		$ubold_path = WPHUBPRO_BRIDGE_ABSPATH . 'assets/ubold/ubold.bridge.scoped.css';
		$admin_css  = WPHUBPRO_BRIDGE_ABSPATH . 'assets/css/admin.css';
		$ubold_ver  = is_readable( $ubold_path ) ? (string) filemtime( $ubold_path ) : WPHUBPRO_BRIDGE_VERSION;
		$admin_ver  = is_readable( $admin_css ) ? (string) filemtime( $admin_css ) : WPHUBPRO_BRIDGE_VERSION;

		wp_enqueue_style(
			'wphubpro-bridge-ubold',
			$base_url . '/assets/ubold/ubold.bridge.scoped.css',
			array(),
			$ubold_ver,
			'all'
		);
		wp_enqueue_style(
			'wphubpro-bridge-admin',
			$base_url . '/assets/css/admin.css',
			array( 'wphubpro-bridge-ubold' ),
			$admin_ver,
			'all'
		);
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public function enqueue_scripts( string $hook_suffix ) {
		if ( self::MENU_HOOK !== $hook_suffix ) {
			return;
		}

		$base_url  = untrailingslashit( plugins_url( '/', WPHUBPRO_BRIDGE_PLUGIN_FILE ) );
		$admin_js  = WPHUBPRO_BRIDGE_ABSPATH . 'assets/js/admin.js';
		$admin_ver = is_readable( $admin_js ) ? (string) filemtime( $admin_js ) : WPHUBPRO_BRIDGE_VERSION;

		wp_enqueue_script(
			'wphubpro-bridge-admin',
			$base_url . '/assets/js/admin.js',
			array(),
			$admin_ver,
			true
		);

		wp_localize_script(
			'wphubpro-bridge-admin',
			'wphubproBridgeAdmin',
			array(
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'urls'  => array(
					'connect'           => get_rest_url( null, 'wphubpro/v1/connect' ),
					'status'            => get_rest_url( null, 'wphubpro/v1/connection-status' ),
					'disconnect'        => get_rest_url( null, 'wphubpro/v1/disconnect' ),
					'redirectSettings'  => get_rest_url( null, 'wphubpro/v1/connect/redirect-settings' ),
					'checkUpdate'       => get_rest_url( null, 'wphubpro/v1/bridge/check-update' ),
					'installUpdate'     => get_rest_url( null, 'wphubpro/v1/bridge/install-update' ),
					'pushHealth'        => get_rest_url( null, 'wphubpro/v1/admin/push-health' ),
				),
				'i18n'  => array(
					'confirmDisconnect' => __( 'Weet je zeker dat je deze site wilt verwijderen van de hub?', 'wphubpro-bridge' ),
					'installButton'     => __( 'Nu installeren', 'wphubpro-bridge' ),
					'installing'        => __( 'Installeren…', 'wphubpro-bridge' ),
					'installFailed'     => __( 'Installatie mislukt', 'wphubpro-bridge' ),
					'checking'          => __( 'Controleren…', 'wphubpro-bridge' ),
					'readyVersion'      => __( 'Gereed. v', 'wphubpro-bridge' ),
					'errorShort'        => __( 'Fout', 'wphubpro-bridge' ),
					'errorWithMessage'  => __( 'Fout: ', 'wphubpro-bridge' ),
					'unknown'           => __( 'Onbekend', 'wphubpro-bridge' ),
					'urlMustHttps'      => __( 'URL moet met https:// beginnen.', 'wphubpro-bridge' ),
					'promptPlatformUrl' => __( 'Platform URL (redirect na koppelen):', 'wphubpro-bridge' ),
					'promptLeaveEmpty'  => __( 'Leeg laten voor standaard (%s).', 'wphubpro-bridge' ),
					'pushHealth'        => __( 'Gezondheid naar hub sturen', 'wphubpro-bridge' ),
					'pushHealthSending' => __( 'Verzenden…', 'wphubpro-bridge' ),
					'pushHealthOk'      => __( 'Gezondheidsrapport naar de hub verzonden.', 'wphubpro-bridge' ),
				),
			)
		);
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
	 * Render the Bridge admin dashboard.
	 */
	public function render_admin_page() {
		include WPHUBPRO_BRIDGE_ABSPATH . 'templates/admin-page.php';
	}
}
