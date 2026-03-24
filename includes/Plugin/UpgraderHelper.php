<?php
namespace WPHubPro\Plugin;

/**
 * Zip/package handling and Plugin_Upgrader runs for bridge plugin flows.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared upgrader + zip URL/base64 logic.
 */
class UpgraderHelper {

	/**
	 * Load admin upgrader dependencies.
	 */
	public static function load_upgrader_dependencies() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	/**
	 * Read zip_url / zip_base64 from request params and JSON body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{zip_url: string, zip_base64: string}
	 */
	public static function get_zip_params_from_request( $request ) {
		$zip_url    = $request->get_param( 'zip_url' );
		$zip_base64 = $request->get_param( 'zip_base64' );
		if ( empty( $zip_url ) && empty( $zip_base64 ) && ! empty( $request->get_body() ) ) {
			$decoded = json_decode( $request->get_body(), true );
			if ( is_array( $decoded ) ) {
				$zip_url    = $zip_url ?: ( $decoded['zip_url'] ?? '' );
				$zip_base64 = $zip_base64 ?: ( $decoded['zip_base64'] ?? '' );
			}
		}
		return array(
			'zip_url'    => is_string( $zip_url ) ? esc_url_raw( $zip_url ) : '',
			'zip_base64' => is_string( $zip_base64 ) ? trim( $zip_base64 ) : '',
		);
	}

	/**
	 * Build local path or remote URL package from zip inputs.
	 *
	 * @param string $zip_url    Sanitized URL (may be empty).
	 * @param string $zip_base64 Trimmed base64 (may be empty).
	 * @return array{package: string, temp_path: string|null}|WP_Error
	 */
	public static function resolve_package_from_zip_inputs( $zip_url, $zip_base64 ) {
		if ( ! empty( $zip_base64 ) ) {
			$decoded = base64_decode( $zip_base64, true );
			if ( $decoded === false || strlen( $decoded ) < 100 ) {
				return new \WP_Error( 'invalid_zip_base64', __( 'Invalid zip_base64 data.', 'wphubpro-bridge' ), array( 'status' => 400 ) );
			}
			$tmp = wp_tempnam( 'wphubpro-bridge-' );
			if ( ! $tmp || file_put_contents( $tmp, $decoded ) === false ) {
				return new \WP_Error( 'temp_file', __( 'Could not write temporary file.', 'wphubpro-bridge' ), array( 'status' => 500 ) );
			}
			return array( 'package' => $tmp, 'temp_path' => $tmp );
		}
		return array( 'package' => $zip_url, 'temp_path' => null );
	}

	/**
	 * Run Plugin_Upgrader for a package (URL or local path).
	 *
	 * @param string $package     Package URL or path.
	 * @param string $plugin_file Plugin basename for hook_extra.
	 * @param string $action      hook_extra action (default update).
	 * @return bool|WP_Error
	 */
	public static function run_plugin_package( $package, $plugin_file, $action = 'update' ) {
		self::load_upgrader_dependencies();
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		return $upgrader->run( array(
			'package'           => $package,
			'destination'       => WP_PLUGIN_DIR,
			'clear_destination' => true,
			'clear_working'     => true,
			'hook_extra'        => array(
				'plugin' => $plugin_file,
				'type'   => 'plugin',
				'action' => $action,
			),
		) );
	}

	/**
	 * @param string|null $temp_path Path from resolve_package_from_zip_inputs.
	 */
	public static function maybe_delete_temp_path( $temp_path ) {
		if ( ! empty( $temp_path ) && file_exists( $temp_path ) ) {
			@unlink( $temp_path );
		}
	}
}
