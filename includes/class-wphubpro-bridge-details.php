<?php
/**
 * Site details for WPHubPro Bridge.
 *
 * Returns installed/latest WordPress version, plugin/theme counts, PHP version info.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site details: WordPress version, plugin/theme counts, PHP info.
 */
class WPHubPro_Bridge_Details {

	/**
	 * Get site details as array (for sync to Hub wp_meta).
	 *
	 * @return array
	 */
	public static function get_wp_meta_array() {
		$instance = new self();
		$wp_installed  = get_bloginfo( 'version' );
		$wp_latest     = $instance->get_latest_wp_version();
		$plugins_count = $instance->get_plugins_count();
		$themes_count  = $instance->get_themes_count();
		$php_info      = $instance->get_php_version_info();

		return array(
			'wp_version'        => $wp_installed,
			'wp_version_latest'  => $wp_latest,
			'plugins_count'      => $plugins_count,
			'themes_count'       => $themes_count,
			'php_version'        => PHP_VERSION,
			'php_check'          => $php_info,
		);
	}

	/**
	 * Get site details.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_details( $request ) {
		$wp_installed  = get_bloginfo( 'version' );
		$wp_latest     = $this->get_latest_wp_version();
		$plugins_count = $this->get_plugins_count();
		$themes_count  = $this->get_themes_count();
		$php_info      = $this->get_php_version_info();

		$response = array(
			'wp_version'        => $wp_installed,
			'wp_version_latest' => $wp_latest,
			'plugins_count'     => $plugins_count,
			'themes_count'      => $themes_count,
			'php_version'       => PHP_VERSION,
			'php_check'         => $php_info,
		);

		WPHubPro_Bridge_Logger::log_action( 'get', 'details', array(), $response );

		return rest_ensure_response( $response );
	}

	/**
	 * Get latest WordPress version from update API.
	 *
	 * @return string|null
	 */
	private function get_latest_wp_version() {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_version_check();

		$core_updates = get_site_transient( 'update_core' );
		if ( ! $core_updates || ! isset( $core_updates->updates ) || ! is_array( $core_updates->updates ) ) {
			return get_bloginfo( 'version' );
		}

		foreach ( $core_updates->updates as $update ) {
			if ( 'latest' === ( $update->response ?? '' ) && isset( $update->current ) ) {
				return $update->current;
			}
		}

		// Upgrade available: first update has the target version
		$updates = get_core_updates();
		if ( is_array( $updates ) && ! empty( $updates ) && isset( $updates[0]->current ) ) {
			return $updates[0]->current;
		}

		return get_bloginfo( 'version' );
	}

	/**
	 * Get number of installed plugins.
	 *
	 * @return int
	 */
	private function get_plugins_count() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		return is_array( $all ) ? count( $all ) : 0;
	}

	/**
	 * Get number of installed themes.
	 *
	 * @return int
	 */
	private function get_themes_count() {
		$all = wp_get_themes();
		return is_array( $all ) ? count( $all ) : 0;
	}

	/**
	 * Get PHP version check result from wp_check_php_version().
	 *
	 * @return array|false
	 */
	private function get_php_version_info() {
		if ( ! function_exists( 'wp_check_php_version' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		$check = function_exists( 'wp_check_php_version' ) ? wp_check_php_version() : false;
		if ( false === $check || ! is_array( $check ) ) {
			return null;
		}
		return $check;
	}
}
