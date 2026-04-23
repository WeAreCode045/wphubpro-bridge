<?php
namespace WPHubPro\Api;

use WPHubPro\Config;
use WPHubPro\Logger;
use WPHubPro\Plugin\Plugins;

/**
 * Updater: Bridge updates itself.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST handlers for checking and applying bridge plugin updates from the platform.
 */
class Updater extends ApiBase {

	private static $instance = null;
	private static $path = '/bridge/';

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register REST routes for bridge update: check for updates and install (admin only).
	 */
	public function register_rest_routes() {

		$namespace = Config::REST_NAMESPACE;
		register_rest_route( $namespace, self::$path . 'check-update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_update_check' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );
		register_rest_route( $namespace, self::$path . 'install-update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_update_install' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	/**
	 * Handle update check.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_update_check() {
		try {
			$response = self::instance()->post( 
				Config::BRIDGE_FUNCTION_ID, 
				array( 'action' => 'get_download_url' ) 
			);
			
            // Get the latest version from the response.
			$latest_version = self::get_latest_version($response);

			// Get the installed version.
			$installed_version = Config::get_bridge_version();

			// Check if the installed version is older than the latest version.
			$update_available = ! empty( $installed_version ) && version_compare( $latest_version, $installed_version, '>' );
			
			return rest_ensure_response( array(
				'success'          => true,
				'latest_version'   => $latest_version,
				'download_url'     => $resp_body['downloadUrl'] ?? '',
				'update_available' => $update_available,
			) );
		} catch ( \Exception $e ) {
			Logger::log_action( 'handle_update_check', 'error', array(), array(
				'msg' => $e->getMessage(),
			) );
			return false;
		}
	}

	/**
	 * Handle update install.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_update_install() {
		try {
			// Get the download URL from the API.
			$response = self::instance()->post( 
				Config::BRIDGE_FUNCTION_ID, 
				array( 'action' => 'get_download_url' ) 
			);
			// Get the latest version from the response.
			$latest_version = self::get_latest_version($response);
			
			// Get the download URL from the response.
			$download_url = self::get_download_url($response);
			
			// Install the bridge from the download URL.
			$request = new \WP_REST_Request( 'POST', '/wphubpro/v1/plugins/manage/install-from-zip' );
			$request->set_param( 'zip_url', $download_url );
			$request->set_param( 'plugin', 'wphubpro-bridge/wphubpro-bridge.php' );

			// Install the plugin from the download URL.
			$plugins = new Plugins();
			$result  = $plugins->install_plugin_from_zip_url( $request );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			// Update the bridge version.
			if ( !self::update_bridge_version($latest_version) ) {
				return new \WP_Error( 'update_failed', 'Could not update bridge version.', array( 'status' => 502 ) );
			}

			return rest_ensure_response( array( 'success' => true, 'message' => 'Bridge updated successfully.' ) );
		} catch ( \Exception $e ) {
			Logger::log_action( 'handle_update_check', 'error', array(), array(
				'msg' => $e->getMessage(),
			) );
			return false;
		}
	}
	/**
	 * Update the bridge version.
	 *
	 * @param string $version The version to update.
	 * @return bool True on success, false on failure.
	 */
	private static function update_bridge_version($version) {
		if ( !preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return false;
			
		}
		update_option( Config::OPTION_BRIDGE_PLUGIN, wp_json_encode( array( 'installed' => $version ) ) );
		return true;
	}


	/**
	 * Get the latest version from the response.
	 *
	 * @param array $response The response from the API.
	 * @return string|null The latest version.
	 * @throws Exception If the response is not a 2xx response.
	 */
	private static function get_latest_version($response) {

		$resp_body = isset( $response['responseBody'] ) ? json_decode( $response['responseBody'], true ) : $response;
		if ( is_array( $resp_body ) && ! empty( $resp_body['success'] ) && ! empty( $resp_body['version'] ) ) {
			return sanitize_text_field( $resp_body['version'] );
		}
		return null;
	}

	/**
	 * Get the download URL from the response.
	 *
	 * @param array $response The response from the API.
	 * @return string|null The download URL.
	 * @throws Exception If the response is not a 2xx response.
	 */
	private static function get_download_url($response) {
		$resp_body = isset( $response['responseBody'] ) ? json_decode( $response['responseBody'], true ) : $response;
		if ( is_array( $resp_body ) && ! empty( $resp_body['success'] ) && ! empty( $resp_body['downloadUrl'] ) ) {
			return sanitize_text_field( $resp_body['downloadUrl'] );
		}
		return null;
	}
}
