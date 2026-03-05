<?php
/**
 * The ajax functionality of the plugin.
 *
 * @package StandaloneTech
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( ! class_exists( 'WPHubPro_Bridge_Ajax' ) ) {
	/**
	 * Plugin WPHubPro_Bridge_Ajax Class.
	 */
	class WPHubPro_Bridge_Ajax {
		/**
		 * Initialize the class and set its properties.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// Your ajax hooks here.
		}
	}
}

new WPHubPro_Bridge_Ajax();
