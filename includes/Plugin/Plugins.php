<?php
namespace WPHUBPRO\Plugin;

/**
 * Plugin management for WPHubPro Bridge.
 *
 * Handles: list, activate, deactivate, update, uninstall.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin management: list, activate, deactivate, update, uninstall.
 */
class Plugins {
	
	/**
	 * REST path for plugin-management related routes.
	 *
	 * @var string
	 */
	private static $path = '/plugins/manage/';

	/**
	 * Register all plugin-related REST routes (list + manage).
	 */
	public function register_rest_routes() {
		register_rest_route( \WPHUBPRO\Config::REST_NAMESPACE, '/plugins', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_plugins_list' ),
			'permission_callback' => \WPHUBPRO\Config::REST_API_AUTH_PROVIDER,
		) );

		$base = \WPHUBPRO\Plugin\Params::rest_base_args();

		register_rest_route( \WPHUBPRO\Config::REST_NAMESPACE, self::$path . 'activate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'activate_plugin' ),
			'permission_callback' => \WPHUBPRO\Config::REST_API_AUTH_PROVIDER,
			'args'                => $base,
		) );

		register_rest_route( \WPHUBPRO\Config::REST_NAMESPACE, self::$path . 'deactivate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'deactivate_plugin' ),
			'permission_callback' => \WPHUBPRO\Config::REST_API_AUTH_PROVIDER,
			'args'                => $base,
		) );

		register_rest_route( \WPHUBPRO\Config::REST_NAMESPACE, self::$path . 'uninstall', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'uninstall_plugin' ),
			'permission_callback' => \WPHUBPRO\Config::REST_API_AUTH_PROVIDER,
			'args'                => $base,
		) );

		register_rest_route( \WPHUBPRO\Config::REST_NAMESPACE, self::$path . 'update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_plugin' ),
			'permission_callback' => \WPHUBPRO\Config::REST_API_AUTH_PROVIDER,
			'args'                => \WPHUBPRO\Plugin\Params::rest_update_args(),
		) );

		register_rest_route( \WPHUBPRO\Config::REST_NAMESPACE, self::$path . 'install-version', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'install_plugin_version' ),
			'permission_callback' => \WPHUBPRO\Config::REST_API_AUTH_PROVIDER,
			'args'                => \WPHUBPRO\Plugin\Params::rest_version_args(),
		) );

		register_rest_route( \WPHUBPRO\Config::REST_NAMESPACE, self::$path . 'install-from-zip', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'install_plugin_from_zip_url' ),
			'permission_callback' => \WPHUBPRO\Config::REST_API_AUTH_PROVIDER,
			'args'                => \WPHUBPRO\Plugin\Params::rest_zip_args(),
		) );
	}

	/**
	 * Get list of all plugins with status and update info.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_plugins_list( $request ) {
		error_log( '[WPHubPro Bridge] plugins/list GET' );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins    = get_plugins();
		$active_plugins = \WPHUBPRO\Config::get_active_plugins();

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		$updates = get_site_transient( 'update_plugins' );

		$response         = array();
		$updates_response = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) ? $updates->response : array();

		foreach ( $all_plugins as $file => $data ) {
			$update_version = isset( $updates_response[ $file ] ) && ! empty( $updates_response[ $file ]->new_version )
				? $updates_response[ $file ]->new_version
				: null;
			$response[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => in_array( $file, (array) $active_plugins, true ),
				'update'  => $update_version,
			);
		}

		$site_url = get_site_url();
		$log_resp = array(
			'count'   => count( $response ),
			'plugins' => array_slice( array_map( function ( $p ) {
				return array( 'name' => $p['name'], 'active' => $p['active'] );
			}, $response ), 0, 10 ),
		);
		if ( count( $response ) > 10 ) {
			$log_resp['_truncated'] = count( $response ) . ' total';
		}
		\WPHUBPRO\Logger::log_action( $site_url, 'list', 'plugins', array(), $log_resp );

		return rest_ensure_response( $response );
	}

	/**
	 * Activate a plugin.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public function activate_plugin( $request ) {
		$endpoint = 'plugins/manage/activate';
		$site_url = get_site_url();
		$params   = \WPHUBPRO\Plugin\Params::parse_from_request( $request );
		$plugin   = $params['plugin'];
		$slug     = $params['slug'];

		if ( empty( $plugin ) && ! empty( $slug ) ) {
			$plugin = \WPHUBPRO\Plugin\Params::resolve_plugin_file( $slug );
		}
		$err = \WPHUBPRO\Plugin\Params::validate_plugin_file( $plugin );
		if ( is_wp_error( $err ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'activate', $endpoint, $params, array( 'error' => 'Invalid or missing plugin parameter' ) );
			return $err;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		do_action( 'wphub_plugin_action_pre', 'activate', $plugin, $slug, $params );
		error_log( '[WPHubPro Bridge] ' . $endpoint . ' INCOMING: ' . wp_json_encode( array( 'plugin' => $plugin, 'slug' => $slug ) ) );

		$resp = apply_filters( 'wphub_plugin_activate', activate_plugin( $plugin ), $plugin, $slug, $params );
		\WPHUBPRO\Logger::log_action( $site_url, 'activate', $endpoint, $params, is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => true ) );
		if ( ! is_wp_error( $resp ) ) {
			\WPHUBPRO\Api\Sync::schedule_sync();
		}
		return $resp;
	}

	/**
	 * Deactivate a plugin.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public function deactivate_plugin( $request ) {
		$endpoint = 'plugins/manage/deactivate';
		$site_url = get_site_url();
		$params   = \WPHUBPRO\Plugin\Params::parse_from_request( $request );
		$plugin   = $params['plugin'];
		$slug     = $params['slug'];

		if ( empty( $plugin ) && ! empty( $slug ) ) {
			$plugin = \WPHUBPRO\Plugin\Params::resolve_plugin_file( $slug );
		}
		$err = \WPHUBPRO\Plugin\Params::validate_plugin_file( $plugin );
		if ( is_wp_error( $err ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'deactivate', $endpoint, $params, array( 'error' => 'Invalid or missing plugin param' ) );
			return $err;
		}
		if ( Bridge_Guard::is_bridge_plugin( $plugin ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'deactivate', $endpoint, $params, array( 'error' => 'Cannot deactivate WPHubPro Bridge from platform.' ) );
			return new \WP_Error( 'forbidden', __( 'WPHubPro Bridge cannot be deactivated from the platform. Deactivate it in WordPress Admin > Plugins to manage the connection.', 'wphubpro-bridge' ), array( 'status' => 403 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		do_action( 'wphub_plugin_action_pre', 'deactivate', $plugin, $slug, $params );
		error_log( '[WPHubPro Bridge] ' . $endpoint . ' INCOMING: ' . wp_json_encode( array( 'plugin' => $plugin, 'slug' => $slug ) ) );

		apply_filters( 'wphub_plugin_deactivate', deactivate_plugins( $plugin ), $plugin, $slug, $params );
		\WPHUBPRO\Logger::log_action( $site_url, 'deactivate', $endpoint, $params, array( 'success' => true ) );
		\WPHUBPRO\Api\Sync::schedule_sync();
		return true;
	}

	/**
	 * Update a plugin.
	 * For normal plugins: uses Plugin_Upgrader::upgrade() (download from WordPress.org).
	 * For bridge plugin: same logic but package URL from zip_url or zip_base64 (bucket).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public function update_plugin( $request ) {
		$endpoint = 'plugins/manage/update';
		$site_url = get_site_url();
		$params   = \WPHUBPRO\Plugin\Params::parse_from_request( $request );
		$plugin   = $params['plugin'];
		$slug     = $params['slug'];

		if ( empty( $plugin ) && ! empty( $slug ) ) {
			$plugin = \WPHUBPRO\Plugin\Params::resolve_plugin_file( $slug );
		}
		$err = \WPHUBPRO\Plugin\Params::validate_plugin_file( $plugin );
		if ( is_wp_error( $err ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'error' => 'Invalid or missing plugin param' ) );
			return $err;
		}
		if ( empty( $plugin ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'error' => 'Plugin not found' ) );
			return new \WP_Error( 'plugin_not_found', __( 'Plugin not found.', 'wphubpro-bridge' ), array( 'status' => 404 ) );
		}

		$zip        = \WPHUBPRO\Plugin\Upgrader_Helper::get_zip_params_from_request( $request );
		$zip_url    = $zip['zip_url'];
		$zip_base64 = $zip['zip_base64'];

		$is_bridge = Bridge_Guard::is_bridge_plugin( $plugin );
		if ( $is_bridge && empty( $zip_url ) && empty( $zip_base64 ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'error' => 'Bridge update requires zip_url or zip_base64' ) );
			return new \WP_Error( 'missing_package', __( 'Bridge update requires zip_url from the platform.', 'wphubpro-bridge' ), array( 'status' => 400 ) );
		}
		if ( ! $is_bridge && ( ! empty( $zip_url ) || ! empty( $zip_base64 ) ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'error' => 'zip_url/zip_base64 only allowed for bridge plugin' ) );
			return new \WP_Error( 'forbidden', __( 'zip_url is only for the WPHubPro Bridge plugin.', 'wphubpro-bridge' ), array( 'status' => 403 ) );
		}

		\WPHUBPRO\Plugin\Upgrader_Helper::load_upgrader_dependencies();
		do_action( 'wphub_plugin_action_pre', 'update', $plugin, $slug, $params );
		error_log( '[WPHubPro Bridge] ' . $endpoint . ' INCOMING: ' . wp_json_encode( array( 'plugin' => $plugin, 'slug' => $slug ) ) );

		$was_active = is_plugin_active( $plugin );

		if ( $is_bridge ) {
			$resolved = \WPHUBPRO\Plugin\Upgrader_Helper::resolve_package_from_zip_inputs( $zip_url, $zip_base64 );
			if ( is_wp_error( $resolved ) ) {
				\WPHUBPRO\Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'error' => $resolved->get_error_message() ) );
				return $resolved;
			}
			$resp = \WPHUBPRO\Plugin\Upgrader_Helper::run_plugin_package( $resolved['package'], $plugin, 'update' );
			\WPHUBPRO\Plugin\Upgrader_Helper::maybe_delete_temp_path( $resolved['temp_path'] );
		} else {
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$resp     = $upgrader->upgrade( $plugin );
		}

		$resp = apply_filters( 'wphub_plugin_update', $resp, $plugin, $slug, $params );

		if ( ! is_wp_error( $resp ) && $was_active ) {
			$activate_result = activate_plugin( $plugin );
			if ( is_wp_error( $activate_result ) ) {
				\WPHUBPRO\Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'warning' => 'Upgrade succeeded but reactivation failed: ' . $activate_result->get_error_message() ) );
			}
		}

		\WPHUBPRO\Logger::log_action( $site_url, 'update', $endpoint, $params, is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => $resp ) );
		if ( ! is_wp_error( $resp ) ) {
			\WPHUBPRO\Api\Sync::schedule_sync();
		}
		return $resp;
	}

	/**
	 * Uninstall (deactivate, run uninstall, delete) a plugin.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public function uninstall_plugin( $request ) {
		$endpoint = 'plugins/manage/uninstall';
		$site_url = get_site_url();
		$params   = \WPHUBPRO\Plugin\Params::parse_from_request( $request );
		$plugin   = $params['plugin'];
		$slug     = $params['slug'];

		if ( empty( $plugin ) && ! empty( $slug ) ) {
			$plugin = \WPHUBPRO\Plugin\Params::resolve_plugin_file( $slug );
		}
		$err = \WPHUBPRO\Plugin\Params::validate_plugin_file( $plugin );
		if ( is_wp_error( $err ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'delete', $endpoint, $params, array( 'error' => 'Invalid or missing plugin param' ) );
			return $err;
		}
		if ( Bridge_Guard::is_bridge_plugin( $plugin ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'delete', $endpoint, $params, array( 'error' => 'Cannot uninstall WPHubPro Bridge from platform.' ) );
			return new \WP_Error( 'forbidden', __( 'WPHubPro Bridge cannot be uninstalled from the platform. Use WordPress Admin > Plugins to remove it.', 'wphubpro-bridge' ), array( 'status' => 403 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		do_action( 'wphub_plugin_action_pre', 'delete', $plugin, $slug, $params );
		error_log( '[WPHubPro Bridge] ' . $endpoint . ' INCOMING: ' . wp_json_encode( array( 'plugin' => $plugin, 'slug' => $slug ) ) );

		if ( is_plugin_active( $plugin ) ) {
			$deact = deactivate_plugins( $plugin );
			if ( is_wp_error( $deact ) ) {
				\WPHUBPRO\Logger::log_action( $site_url, 'delete', $endpoint, $params, array( 'error' => 'Deactivate before uninstall failed: ' . $deact->get_error_message() ) );
				return $deact;
			}
		}
		uninstall_plugin( $plugin );
		$resp = apply_filters( 'wphub_plugin_delete', delete_plugins( array( $plugin ) ), $plugin, $slug, $params );
		\WPHUBPRO\Logger::log_action( $site_url, 'delete', $endpoint, $params, is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => true ) );
		if ( ! is_wp_error( $resp ) ) {
			\WPHUBPRO\Api\Sync::schedule_sync();
		}
		return $resp;
	}

	/**
	 * Install or rollback to a specific version from WordPress.org.
	 *
	 * @param WP_REST_Request $request Request object. Expects plugin (file path) and version.
	 * @return mixed
	 */
	public function install_plugin_version( $request ) {
		$endpoint = 'plugins/manage/install-version';
		$site_url = get_site_url();
		$params   = \WPHUBPRO\Plugin\Params::parse_from_request( $request );
		$plugin   = $params['plugin'];
		$slug     = $params['slug'];
		$version  = sanitize_text_field( (string) $request->get_param( 'version' ) );

		if ( empty( $plugin ) && ! empty( $slug ) ) {
			$plugin = \WPHUBPRO\Plugin\Params::resolve_plugin_file( $slug );
		}
		$plugin_slug = $plugin && strpos( $plugin, '/' ) !== false ? dirname( $plugin ) : ( $slug ?: '' );

		if ( empty( $plugin_slug ) || empty( $version ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'install-version', $endpoint, array_merge( $params, array( 'version' => $version ) ), array( 'error' => 'Missing plugin slug or version' ) );
			return new \WP_Error( 'missing_params', __( 'Plugin slug and version are required.', 'wphubpro-bridge' ), array( 'status' => 400 ) );
		}

		$err = \WPHUBPRO\Plugin\Params::validate_plugin_file( $plugin );
		if ( is_wp_error( $err ) && ! $plugin ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'install-version', $endpoint, $params, array( 'error' => 'Invalid or missing plugin parameter' ) );
			return $err;
		}
		if ( ! empty( $plugin ) && Bridge_Guard::is_bridge_plugin( $plugin ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'install-version', $endpoint, $params, array( 'error' => 'Cannot change WPHubPro Bridge version from platform.' ) );
			return new \WP_Error( 'forbidden', __( 'WPHubPro Bridge cannot be modified from the platform.', 'wphubpro-bridge' ), array( 'status' => 403 ) );
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		$info = plugins_api( 'plugin_information', array(
			'slug'   => $plugin_slug,
			'fields' => array( 'versions' => true ),
		) );
		$versions = isset( $info->versions ) ? (array) $info->versions : array();
		if ( is_wp_error( $info ) || empty( $versions ) || ! isset( $versions[ $version ] ) ) {
			$versions_available = array_keys( $versions );
			\WPHUBPRO\Logger::log_action( $site_url, 'install-version', $endpoint, array_merge( $params, array( 'version' => $version ) ), array( 'error' => 'Version not found', 'available' => array_slice( $versions_available, -20 ) ) );
			return new \WP_Error( 'version_not_found', __( 'Version not found in WordPress.org. Plugin may not be in the official library.', 'wphubpro-bridge' ), array( 'status' => 404 ) );
		}

		$download_url = $versions[ $version ];
		if ( empty( $download_url ) || ! filter_var( $download_url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_url', __( 'Invalid download URL for this version.', 'wphubpro-bridge' ), array( 'status' => 500 ) );
		}

		\WPHUBPRO\Plugin\Upgrader_Helper::load_upgrader_dependencies();

		$target_plugin = $plugin ?: $plugin_slug . '/' . $plugin_slug . '.php';
		if ( ! $plugin ) {
			$target_plugin = \WPHUBPRO\Plugin\Params::resolve_plugin_file( $plugin_slug ) ?: $plugin_slug . '/' . $plugin_slug . '.php';
		}
		$was_active = ! empty( $target_plugin ) && is_plugin_active( $target_plugin );

		$result = \WPHUBPRO\Plugin\Upgrader_Helper::run_plugin_package( $download_url, $target_plugin, 'update' );

		if ( ! is_wp_error( $result ) && $was_active ) {
			$reactivate = activate_plugin( $target_plugin );
			if ( is_wp_error( $reactivate ) ) {
				\WPHUBPRO\Logger::log_action( $site_url, 'install-version', $endpoint, $params, array( 'warning' => 'Install succeeded but reactivation failed: ' . $reactivate->get_error_message() ) );
			}
		}

		\WPHUBPRO\Logger::log_action( $site_url, 'install-version', $endpoint, array_merge( $params, array( 'version' => $version ) ), is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : array( 'success' => true ) );
		if ( ! is_wp_error( $result ) ) {
			\WPHUBPRO\Api\Sync::schedule_sync();
		}
		return $result;
	}

	/**
	 * Update the WPHubPro Bridge plugin from a zip URL or base64 payload.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public function install_plugin_from_zip_url( $request ) {
		$endpoint = 'plugins/manage/install-from-zip';
		$site_url = get_site_url();
		$params   = \WPHUBPRO\Plugin\Params::parse_from_request( $request );
		$plugin   = $params['plugin'];

		$zip        = \WPHUBPRO\Plugin\Upgrader_Helper::get_zip_params_from_request( $request );
		$zip_url    = $zip['zip_url'];
		$zip_base64 = $zip['zip_base64'];

		if ( empty( $plugin ) ) {
			$plugin = Bridge_Guard::get_bridge_plugin_file();
		}
		if ( ! Bridge_Guard::is_bridge_plugin( $plugin ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'install-from-zip', $endpoint, array( 'plugin' => $plugin ), array( 'error' => 'Only the WPHubPro Bridge plugin can be updated via zip URL.' ) );
			return new \WP_Error( 'forbidden', __( 'Only the WPHubPro Bridge plugin can be updated from a zip URL.', 'wphubpro-bridge' ), array( 'status' => 403 ) );
		}
		if ( empty( $zip_url ) && empty( $zip_base64 ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'install-from-zip', $endpoint, array(), array( 'error' => 'zip_url or zip_base64 is required.' ) );
			return new \WP_Error( 'invalid_input', __( 'zip_url or zip_base64 is required.', 'wphubpro-bridge' ), array( 'status' => 400 ) );
		}
		if ( empty( $zip_base64 ) && ( empty( $zip_url ) || strpos( $zip_url, 'https://' ) !== 0 ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'install-from-zip', $endpoint, array(), array( 'error' => 'Valid HTTPS zip_url is required when zip_base64 is not provided.' ) );
			return new \WP_Error( 'invalid_zip_url', __( 'A valid HTTPS zip URL is required.', 'wphubpro-bridge' ), array( 'status' => 400 ) );
		}

		$resolved = \WPHUBPRO\Plugin\Upgrader_Helper::resolve_package_from_zip_inputs( $zip_url, $zip_base64 );
		if ( is_wp_error( $resolved ) ) {
			\WPHUBPRO\Logger::log_action( $site_url, 'install-from-zip', $endpoint, array(), array( 'error' => $resolved->get_error_message() ) );
			return $resolved;
		}
		$package   = $resolved['package'];
		$temp_path = $resolved['temp_path'];

		$was_active = is_plugin_active( $plugin );
		$result     = \WPHUBPRO\Plugin\Upgrader_Helper::run_plugin_package( $package, $plugin, 'update' );

		\WPHUBPRO\Plugin\Upgrader_Helper::maybe_delete_temp_path( $temp_path );

		if ( ! is_wp_error( $result ) && $was_active ) {
			$reactivate = activate_plugin( $plugin );
			if ( is_wp_error( $reactivate ) ) {
				\WPHUBPRO\Logger::log_action( $site_url, 'install-from-zip', $endpoint, array(), array( 'warning' => 'Update succeeded but reactivation failed: ' . $reactivate->get_error_message() ) );
			}
		}

		$log_source = ! empty( $zip_base64 ) ? 'zip_base64' : 'zip_url';
		\WPHUBPRO\Logger::log_action( $site_url, 'install-from-zip', $endpoint, array( $log_source => $log_source ), is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : array( 'success' => true ) );
		if ( ! is_wp_error( $result ) ) {
			\WPHUBPRO\Api\Sync::schedule_sync();
		}
		return $result;
	}
}
