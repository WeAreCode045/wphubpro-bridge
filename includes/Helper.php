<?php
namespace WPHubPro;

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
class Helper {

	/**
	 * Get site details as array (for sync to Hub wp_meta).
	 *
	 * @return array
	 */
	public static function get_wp_meta_array(): array {
		$wp_installed  = (string) get_bloginfo( 'version' );
		$wp_latest     = (string) self::get_latest_wp_version();
		$plugins_count = self::get_plugins_count();
		$themes_count  = self::get_themes_count();
		$php_info      = self::get_php_version_info();

		return array(
			'wp_version'         => $wp_installed,
			'wp_version_latest'  => $wp_latest,
			'plugins_count'      => $plugins_count,
			'themes_count'       => $themes_count,
			'php_version'        => PHP_VERSION,
			'php_check'          => $php_info,
			'bridge_version'     => Config::get_bridge_version(),
		);
	}

	/**
	 * Get plugins list in meta format (file, name, version, active, update).
	 *
	 * @return array
	 */
	public static function get_plugins_meta() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins   = get_plugins();
		$active_plugins = Config::get_active_plugins();
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		$updates = get_site_transient( 'update_plugins' );
		$updates_response = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) ? $updates->response : array();

		$meta = array();
		foreach ( $all_plugins as $file => $data ) {
			$update_version = isset( $updates_response[ $file ] ) && ! empty( $updates_response[ $file ]->new_version )
				? $updates_response[ $file ]->new_version
				: null;
			$meta[] = array(
				'file'    => (string) $file,
				'name'    => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'active'  => in_array( $file, (array) $active_plugins, true ),
				'update'  => null !== $update_version ? (string) $update_version : null,
			);
		}
		return $meta;
	}

	/**
	 * Get themes list in meta format (stylesheet, name, version, active, update).
	 *
	 * @return array
	 */
	public static function get_themes_meta() {
		$all_themes = wp_get_themes();
		$current    = get_stylesheet();
		if ( function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}
		$updates = get_site_transient( 'update_themes' );
		$meta    = array();
		$resp    = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) )
			? $updates->response
			: array();

		foreach ( $all_themes as $slug => $theme ) {
			$upd = null;
			if ( isset( $resp[ $slug ]['new_version'] ) ) {
				$upd = (string) $resp[ $slug ]['new_version'];
			}
			$meta[] = array(
				'stylesheet' => (string) $slug,
				'name'       => (string) $theme->get( 'Name' ),
				'version'    => (string) $theme->get( 'Version' ),
				'active'     => ( (string) $slug === (string) $current ),
				'update'     => $upd,
			);
		}
		return $meta;
	}

	// public static function get_details() {
	// 	return array(
	// 		'wp_version'        => get_bloginfo( 'version' ),
	// 		'wp_version_latest' => self::get_latest_wp_version(),
	// 		'plugins_count'     => self::get_plugins_count(),
	// 		'themes_count'      => self::get_themes_count(),
	// 		'php_version'       => PHP_VERSION,
	// 		'php_check'         => self::get_php_version_info(),
	// 	);
	// }

	/**
	 * REST handler for site details (not registered on any route).
	 *
	 * @deprecated 2.8.2 Unused; sync uses {@see self::get_wp_meta_array()} instead.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_details( $request ) {
		$wp_installed  = (string) get_bloginfo( 'version' );
		$wp_latest     = (string) self::get_latest_wp_version();
		$plugins_count = self::get_plugins_count();
		$themes_count  = self::get_themes_count();
		$php_info      = self::get_php_version_info();

		$response = array(
			'wp_version'        => $wp_installed,
			'wp_version_latest' => $wp_latest,
			'plugins_count'     => $plugins_count,
			'themes_count'      => $themes_count,
			'php_version'       => PHP_VERSION,
			'php_check'         => $php_info,
			'bridge_version'    => Config::get_bridge_version(),
		);

		Logger::log_action( 'get', 'details', array(), $response );

		return rest_ensure_response( $response );
	}

	/**
	 * Get latest WordPress version from update API.
	 *
	 * @return string|null
	 */
	private static function get_latest_wp_version(): string {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_version_check();

		$core_updates = get_site_transient( 'update_core' );
		if ( ! $core_updates || ! isset( $core_updates->updates ) || ! is_array( $core_updates->updates ) ) {
			return (string) get_bloginfo( 'version' );
		}

		foreach ( $core_updates->updates as $update ) {
			if ( 'latest' === ( $update->response ?? '' ) && isset( $update->current ) ) {
				return (string) $update->current;
			}
		}

		$updates = get_core_updates();
		if ( is_array( $updates ) && ! empty( $updates ) && isset( $updates[0]->current ) ) {
			return (string) $updates[0]->current;
		}

		return (string) get_bloginfo( 'version' );
	}

	/**
	 * Get number of installed plugins.
	 *
	 * @return int
	 */
	private static function get_plugins_count(): int {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();

		return is_array( $all ) ? (int) count( $all ) : 0;
	}

	/**
	 * Get number of installed themes.
	 *
	 * @return int
	 */
	private static function get_themes_count(): int {
		$all = wp_get_themes();

		return is_array( $all ) ? (int) count( $all ) : 0;
	}

	/**
	 * Get PHP version check result from wp_check_php_version().
	 *
	 * @return array<string, mixed>|null
	 */
	private static function get_php_version_info(): ?array {
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
