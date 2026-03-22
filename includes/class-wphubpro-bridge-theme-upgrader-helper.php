<?php
/**
 * Theme_Upgrader wrapper for theme updates via REST.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load admin dependencies and run Theme_Upgrader::update.
 */
class WPHubPro_Bridge_Theme_Upgrader_Helper {

	/**
	 * Load theme upgrader dependencies.
	 */
	public static function load_upgrader_dependencies() {
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	/**
	 * Update a theme from WordPress.org (or configured update source).
	 *
	 * @param string $slug Theme stylesheet slug.
	 * @return bool|WP_Error
	 */
	public static function run_theme_update( $slug ) {
		self::load_upgrader_dependencies();
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		return $upgrader->update( $slug );
	}

	/**
	 * Install a theme from a zip package (URL or local path).
	 *
	 * @param string $package Package URL or path.
	 * @return bool|WP_Error
	 */
	public static function run_theme_install_from_package( $package ) {
		self::load_upgrader_dependencies();
		require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		return $upgrader->install( $package );
	}
}
