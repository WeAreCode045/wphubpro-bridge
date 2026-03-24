<?php
namespace WPHubPro\Theme;

use WPHubPro\Api\Sync;
use WPHubPro\Config;
use WPHubPro\Logger;
use WPHubPro\Plugin\UpgraderHelper as PluginUpgraderHelper;

/**
 * Theme management for WPHubPro Bridge.
 *
 * Handles: list, install, update, activate, delete.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme management: install, update, activate, delete.
 */
class Themes {

	/**
	 * REST path for theme-management routes.
	 *
	 * @var string
	 */
	private static $path = '/themes/manage/';

	/**
	 * Register all theme-related REST routes (list + manage).
	 */
	public function register_rest_routes() {
		register_rest_route( Config::REST_NAMESPACE, '/themes', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_themes_list' ),
			'permission_callback' => Config::REST_API_AUTH_PROVIDER,
		) );

		$args = Params::rest_base_args();

		register_rest_route( Config::REST_NAMESPACE, self::$path . 'activate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'activate_theme' ),
			'permission_callback' => Config::REST_API_AUTH_PROVIDER,
			'args'                => $args,
		) );

		register_rest_route( Config::REST_NAMESPACE, self::$path . 'update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_theme' ),
			'permission_callback' => Config::REST_API_AUTH_PROVIDER,
			'args'                => $args,
		) );

		register_rest_route( Config::REST_NAMESPACE, self::$path . 'delete', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'delete_theme' ),
			'permission_callback' => Config::REST_API_AUTH_PROVIDER,
			'args'                => $args,
		) );

		register_rest_route( Config::REST_NAMESPACE, self::$path . 'install-from-zip', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'install_theme_from_zip' ),
			'permission_callback' => Config::REST_API_AUTH_PROVIDER,
		) );
	}

	/**
	 * Get list of all themes with status and update info.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_themes_list( $request ) {
		$all_themes = wp_get_themes();
		$current    = get_stylesheet();
		if ( function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}
		$updates = get_site_transient( 'update_themes' );

		$updates_response = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) )
			? $updates->response
			: array();

		$response = array();

		foreach ( $all_themes as $slug => $theme ) {
			$response[] = array(
				'slug'    => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => ( $slug === $current ),
				'update'  => isset( $updates_response[ $slug ]['new_version'] ) ? $updates_response[ $slug ]['new_version'] : null,
			);
		}

		$site_url = get_site_url();
		$log_resp = array(
			'count'  => count( $response ),
			'themes' => array_slice( array_map( function ( $t ) {
				return array( 'name' => $t['name'], 'active' => $t['active'] );
			}, $response ), 0, 10 ),
		);
		if ( count( $response ) > 10 ) {
			$log_resp['_truncated'] = count( $response ) . ' total';
		}
		Logger::log_action( $site_url, 'list', 'themes', array(), $log_resp );

		return rest_ensure_response( $response );
	}

	/**
	 * Install a theme from a zip URL or zip_base64 (Hub / WPHubPro library).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public function install_theme_from_zip( $request ) {
		$endpoint = 'themes/manage/install-from-zip';
		$site_url = get_site_url();

		$zip        = PluginUpgraderHelper::get_zip_params_from_request( $request );
		$zip_url    = $zip['zip_url'];
		$zip_base64 = $zip['zip_base64'];

		if ( empty( $zip_url ) && empty( $zip_base64 ) ) {
			Logger::log_action( $site_url, 'install-from-zip', $endpoint, array(), array( 'error' => 'zip_url or zip_base64 is required.' ) );
			return new \WP_Error( 'invalid_input', __( 'zip_url or zip_base64 is required.', 'wphubpro-bridge' ), array( 'status' => 400 ) );
		}
		if ( empty( $zip_base64 ) && ( empty( $zip_url ) || strpos( $zip_url, 'https://' ) !== 0 ) ) {
			Logger::log_action( $site_url, 'install-from-zip', $endpoint, array(), array( 'error' => 'Valid HTTPS zip_url is required when zip_base64 is not provided.' ) );
			return new \WP_Error( 'invalid_zip_url', __( 'A valid HTTPS zip URL is required.', 'wphubpro-bridge' ), array( 'status' => 400 ) );
		}

		$resolved = PluginUpgraderHelper::resolve_package_from_zip_inputs( $zip_url, $zip_base64 );
		if ( is_wp_error( $resolved ) ) {
			Logger::log_action( $site_url, 'install-from-zip', $endpoint, array(), array( 'error' => $resolved->get_error_message() ) );
			return $resolved;
		}
		$package   = $resolved['package'];
		$temp_path = $resolved['temp_path'];

		$result = UpgraderHelper::run_theme_install_from_package( $package );

		PluginUpgraderHelper::maybe_delete_temp_path( $temp_path );

		$log_source = ! empty( $zip_base64 ) ? 'zip_base64' : 'zip_url';
		Logger::log_action( $site_url, 'install-from-zip', $endpoint, array( $log_source => $log_source ), is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : array( 'success' => true ) );
		if ( ! is_wp_error( $result ) ) {
			Sync::schedule_sync();
		}
		return $result;
	}

	/**
	 * Activate a theme.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public function activate_theme( $request ) {
		$endpoint = 'themes/manage/activate';
		$site_url = get_site_url();
		$slug     = Params::parse_slug_from_request( $request );
		$err      = Params::validate_theme_slug( $slug );
		if ( is_wp_error( $err ) ) {
			Logger::log_action( $site_url, 'activate', $endpoint, array( 'slug' => $slug ), array( 'error' => $err->get_error_message() ) );
			return $err;
		}
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		do_action( 'wphub_theme_action_pre', 'activate', $slug, array( 'slug' => $slug ) );
		$resp = apply_filters( 'wphub_theme_activate', switch_theme( $slug ), $slug, array( 'slug' => $slug ) );
		Logger::log_action( $site_url, 'activate', $endpoint, array( 'slug' => $slug ), is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => true ) );
		if ( ! is_wp_error( $resp ) ) {
			Sync::schedule_sync();
		}
		return is_wp_error( $resp ) ? $resp : true;
	}

	/**
	 * Update a theme.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public function update_theme( $request ) {
		$endpoint = 'themes/manage/update';
		$site_url = get_site_url();
		$slug     = Params::parse_slug_from_request( $request );
		$err      = Params::validate_theme_slug( $slug );
		if ( is_wp_error( $err ) ) {
			Logger::log_action( $site_url, 'update', $endpoint, array( 'slug' => $slug ), array( 'error' => $err->get_error_message() ) );
			return $err;
		}
		do_action( 'wphub_theme_action_pre', 'update', $slug, array( 'slug' => $slug ) );
		$resp = apply_filters( 'wphub_theme_update', UpgraderHelper::run_theme_update( $slug ), $slug, array( 'slug' => $slug ) );
		Logger::log_action( $site_url, 'update', $endpoint, array( 'slug' => $slug ), is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => $resp ) );
		if ( ! is_wp_error( $resp ) ) {
			Sync::schedule_sync();
		}
		return $resp;
	}

	/**
	 * Delete (deinstall) a theme.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public function delete_theme( $request ) {
		$endpoint = 'themes/manage/delete';
		$site_url = get_site_url();
		$slug     = Params::parse_slug_from_request( $request );
		$err      = Params::validate_theme_slug( $slug );
		if ( is_wp_error( $err ) ) {
			Logger::log_action( $site_url, 'delete', $endpoint, array( 'slug' => $slug ), array( 'error' => $err->get_error_message() ) );
			return $err;
		}
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		do_action( 'wphub_theme_action_pre', 'delete', $slug, array( 'slug' => $slug ) );
		$resp = apply_filters( 'wphub_theme_delete', delete_theme( $slug ), $slug, array( 'slug' => $slug ) );
		Logger::log_action( $site_url, 'delete', $endpoint, array( 'slug' => $slug ), is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => true ) );
		if ( ! is_wp_error( $resp ) ) {
			Sync::schedule_sync();
		}
		return $resp;
	}
}
