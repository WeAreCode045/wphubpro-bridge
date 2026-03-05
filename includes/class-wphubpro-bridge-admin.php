<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package StandaloneTech
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( ! class_exists( 'WPHubPro_Bridge_Admin' ) ) {
	/**
	 * Plugin WPHubPro_Bridge_Admin Class.
	 */
	class WPHubPro_Bridge_Admin {
		/**
		 * Initialize the class and set its properties.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
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
	}
}

new WPHubPro_Bridge_Admin();
