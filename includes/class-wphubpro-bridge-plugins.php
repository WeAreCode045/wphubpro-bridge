<?php
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
class WPHubPro_Bridge_Plugins {

	/** Plugin file path for the bridge (e.g. wphubpro-bridge/wphubpro-bridge.php). */
	private static function get_bridge_plugin_file() {
		if ( defined( 'WPHUBPRO_BRIDGE_PLUGIN_FILE' ) ) {
			return plugin_basename( WPHUBPRO_BRIDGE_PLUGIN_FILE );
		}
		return 'wphubpro-bridge/wphubpro-bridge.php';
	}

	/**
	 * Whether the given plugin file is the bridge itself.
	 *
	 * @param string $plugin Plugin file (e.g. wphubpro-bridge/wphubpro-bridge.php).
	 * @return bool
	 */
	private function is_bridge_plugin( $plugin ) {
		if ( empty( $plugin ) ) {
			return false;
		}
		$bridge_file = self::get_bridge_plugin_file();
		return $plugin === $bridge_file || strpos( $plugin, 'wphubpro-bridge/' ) === 0;
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
		$all_plugins   = get_plugins();
		$active_plugins = WPHubPro_Bridge_Config::get_active_plugins();

		// Always refresh update availability from WordPress.org when serving the plugins list.
		// wp_update_plugins() respects its own timeout (avoids excessive API calls) but ensures
		// we return fresh data whenever the transient has expired.
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		$updates = get_site_transient( 'update_plugins' );

		$response = array();
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
			'count'  => count( $response ),
			'plugins' => array_slice( array_map( function ( $p ) {
				return array( 'name' => $p['name'], 'active' => $p['active'] );
			}, $response ), 0, 10 ),
		);
		if ( count( $response ) > 10 ) {
			$log_resp['_truncated'] = count( $response ) . ' total';
		}
		WPHubPro_Bridge_Logger::log_action( $site_url, 'list', 'plugins', array(), $log_resp );

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
		$params   = $this->parse_plugin_params( $request );
		$plugin   = $params['plugin'];
		$slug     = $params['slug'];

		if ( empty( $plugin ) && ! empty( $slug ) ) {
			$plugin = $this->resolve_plugin_file( $slug );
		}
		$err = $this->validate_plugin_file( $plugin );
		if ( is_wp_error( $err ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'activate', $endpoint, $params, array( 'error' => 'Invalid or missing plugin parameter' ) );
			return $err;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		do_action( 'wphub_plugin_action_pre', 'activate', $plugin, $slug, $params );
		error_log( '[WPHubPro Bridge] ' . $endpoint . ' INCOMING: ' . wp_json_encode( array( 'plugin' => $plugin, 'slug' => $slug ) ) );

		$resp = apply_filters( 'wphub_plugin_activate', activate_plugin( $plugin ), $plugin, $slug, $params );
		WPHubPro_Bridge_Logger::log_action( $site_url, 'activate', $endpoint, $params, is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => true ) );
		if ( ! is_wp_error( $resp ) ) {
			WPHubPro_Bridge_Sync::schedule_sync();
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
		$params   = $this->parse_plugin_params( $request );
		$plugin   = $params['plugin'];
		$slug     = $params['slug'];

		if ( empty( $plugin ) && ! empty( $slug ) ) {
			$plugin = $this->resolve_plugin_file( $slug );
		}
		$err = $this->validate_plugin_file( $plugin );
		if ( is_wp_error( $err ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'deactivate', $endpoint, $params, array( 'error' => 'Invalid or missing plugin param' ) );
			return $err;
		}
		if ( $this->is_bridge_plugin( $plugin ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'deactivate', $endpoint, $params, array( 'error' => 'Cannot deactivate WPHubPro Bridge from platform.' ) );
			return new WP_Error( 'forbidden', __( 'WPHubPro Bridge cannot be deactivated from the platform. Deactivate it in WordPress Admin > Plugins to manage the connection.', 'wphubpro-bridge' ), array( 'status' => 403 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		do_action( 'wphub_plugin_action_pre', 'deactivate', $plugin, $slug, $params );
		error_log( '[WPHubPro Bridge] ' . $endpoint . ' INCOMING: ' . wp_json_encode( array( 'plugin' => $plugin, 'slug' => $slug ) ) );

		apply_filters( 'wphub_plugin_deactivate', deactivate_plugins( $plugin ), $plugin, $slug, $params );
		WPHubPro_Bridge_Logger::log_action( $site_url, 'deactivate', $endpoint, $params, array( 'success' => true ) );
		WPHubPro_Bridge_Sync::schedule_sync();
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
		$params   = $this->parse_plugin_params( $request );
		$plugin   = $params['plugin'];
		$slug     = $params['slug'];

		if ( empty( $plugin ) && ! empty( $slug ) ) {
			$plugin = $this->resolve_plugin_file( $slug );
		}
		$err = $this->validate_plugin_file( $plugin );
		if ( is_wp_error( $err ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'error' => 'Invalid or missing plugin param' ) );
			return $err;
		}
		if ( empty( $plugin ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'error' => 'Plugin not found' ) );
			return new WP_Error( 'plugin_not_found', __( 'Plugin not found.', 'wphubpro-bridge' ), array( 'status' => 404 ) );
		}

		$zip_url   = $request->get_param( 'zip_url' );
		$zip_base64 = $request->get_param( 'zip_base64' );
		if ( empty( $zip_url ) && empty( $zip_base64 ) && ! empty( $request->get_body() ) ) {
			$decoded = json_decode( $request->get_body(), true );
			if ( is_array( $decoded ) ) {
				$zip_url   = $zip_url ?: ( $decoded['zip_url'] ?? '' );
				$zip_base64 = $zip_base64 ?: ( $decoded['zip_base64'] ?? '' );
			}
		}
		$zip_url   = is_string( $zip_url ) ? esc_url_raw( $zip_url ) : '';
		$zip_base64 = is_string( $zip_base64 ) ? trim( $zip_base64 ) : '';

		$is_bridge = $this->is_bridge_plugin( $plugin );
		if ( $is_bridge && empty( $zip_url ) && empty( $zip_base64 ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'error' => 'Bridge update requires zip_url or zip_base64' ) );
			return new WP_Error( 'missing_package', __( 'Bridge update requires zip_url from the platform.', 'wphubpro-bridge' ), array( 'status' => 400 ) );
		}
		if ( ! $is_bridge && ( ! empty( $zip_url ) || ! empty( $zip_base64 ) ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'error' => 'zip_url/zip_base64 only allowed for bridge plugin' ) );
			return new WP_Error( 'forbidden', __( 'zip_url is only for the WPHubPro Bridge plugin.', 'wphubpro-bridge' ), array( 'status' => 403 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		do_action( 'wphub_plugin_action_pre', 'update', $plugin, $slug, $params );
		error_log( '[WPHubPro Bridge] ' . $endpoint . ' INCOMING: ' . wp_json_encode( array( 'plugin' => $plugin, 'slug' => $slug ) ) );

		$was_active = is_plugin_active( $plugin );

		$skin    = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		if ( $is_bridge ) {
			// Same as install_plugin_version / normal update: Plugin_Upgrader::run with package URL.
			$package = '';
			if ( ! empty( $zip_base64 ) ) {
				$decoded = base64_decode( $zip_base64, true );
				if ( $decoded === false || strlen( $decoded ) < 100 ) {
					WPHubPro_Bridge_Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'error' => 'Invalid zip_base64' ) );
					return new WP_Error( 'invalid_zip_base64', __( 'Invalid zip_base64 data.', 'wphubpro-bridge' ), array( 'status' => 400 ) );
				}
				$tmp = wp_tempnam( 'wphubpro-bridge-' );
				if ( ! $tmp || file_put_contents( $tmp, $decoded ) === false ) {
					WPHubPro_Bridge_Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'error' => 'Could not write temp file' ) );
					return new WP_Error( 'temp_file', __( 'Could not write temporary file.', 'wphubpro-bridge' ), array( 'status' => 500 ) );
				}
				$package = $tmp;
			} else {
				$package = $zip_url;
			}

			$resp = $upgrader->run( array(
				'package'           => $package,
				'destination'       => WP_PLUGIN_DIR,
				'clear_destination' => true,
				'clear_working'     => true,
				'hook_extra'        => array(
					'plugin' => $plugin,
					'type'   => 'plugin',
					'action' => 'update',
				),
			) );

			if ( ! empty( $zip_base64 ) && ! empty( $package ) && file_exists( $package ) ) {
				@unlink( $package );
			}
		} else {
			// Normal plugin: upgrade from WordPress.org (same as before).
			$resp = $upgrader->upgrade( $plugin );
		}

		$resp = apply_filters( 'wphub_plugin_update', $resp, $plugin, $slug, $params );

		if ( ! is_wp_error( $resp ) && $was_active ) {
			$activate_result = activate_plugin( $plugin );
			if ( is_wp_error( $activate_result ) ) {
				WPHubPro_Bridge_Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'warning' => 'Upgrade succeeded but reactivation failed: ' . $activate_result->get_error_message() ) );
			}
		}

		WPHubPro_Bridge_Logger::log_action( $site_url, 'update', $endpoint, $params, is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => $resp ) );
		if ( ! is_wp_error( $resp ) ) {
			WPHubPro_Bridge_Sync::schedule_sync();
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
		$params   = $this->parse_plugin_params( $request );
		$plugin   = $params['plugin'];
		$slug     = $params['slug'];

		if ( empty( $plugin ) && ! empty( $slug ) ) {
			$plugin = $this->resolve_plugin_file( $slug );
		}
		$err = $this->validate_plugin_file( $plugin );
		if ( is_wp_error( $err ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'delete', $endpoint, $params, array( 'error' => 'Invalid or missing plugin param' ) );
			return $err;
		}
		if ( $this->is_bridge_plugin( $plugin ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'delete', $endpoint, $params, array( 'error' => 'Cannot uninstall WPHubPro Bridge from platform.' ) );
			return new WP_Error( 'forbidden', __( 'WPHubPro Bridge cannot be uninstalled from the platform. Use WordPress Admin > Plugins to remove it.', 'wphubpro-bridge' ), array( 'status' => 403 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		do_action( 'wphub_plugin_action_pre', 'delete', $plugin, $slug, $params );
		error_log( '[WPHubPro Bridge] ' . $endpoint . ' INCOMING: ' . wp_json_encode( array( 'plugin' => $plugin, 'slug' => $slug ) ) );

		if ( is_plugin_active( $plugin ) ) {
			$deact = deactivate_plugins( $plugin );
			if ( is_wp_error( $deact ) ) {
				WPHubPro_Bridge_Logger::log_action( $site_url, 'delete', $endpoint, $params, array( 'error' => 'Deactivate before uninstall failed: ' . $deact->get_error_message() ) );
				return $deact;
			}
		}
		uninstall_plugin( $plugin );
		$resp = apply_filters( 'wphub_plugin_delete', delete_plugins( array( $plugin ) ), $plugin, $slug, $params );
		WPHubPro_Bridge_Logger::log_action( $site_url, 'delete', $endpoint, $params, is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => true ) );
		// Sync via shutdown (no delete_plugin hook; run after request)
		if ( ! is_wp_error( $resp ) ) {
			WPHubPro_Bridge_Sync::schedule_sync();
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
		$params   = $this->parse_plugin_params( $request );
		$plugin   = $params['plugin'];
		$slug     = $params['slug'];
		$version  = sanitize_text_field( (string) $request->get_param( 'version' ) );

		if ( empty( $plugin ) && ! empty( $slug ) ) {
			$plugin = $this->resolve_plugin_file( $slug );
		}
		$plugin_slug = $plugin && strpos( $plugin, '/' ) !== false ? dirname( $plugin ) : ( $slug ?: '' );

		if ( empty( $plugin_slug ) || empty( $version ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'install-version', $endpoint, array_merge( $params, array( 'version' => $version ) ), array( 'error' => 'Missing plugin slug or version' ) );
			return new WP_Error( 'missing_params', __( 'Plugin slug and version are required.', 'wphubpro-bridge' ), array( 'status' => 400 ) );
		}

		$err = $this->validate_plugin_file( $plugin );
		if ( is_wp_error( $err ) && ! $plugin ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'install-version', $endpoint, $params, array( 'error' => 'Invalid or missing plugin parameter' ) );
			return $err;
		}
		if ( ! empty( $plugin ) && $this->is_bridge_plugin( $plugin ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'install-version', $endpoint, $params, array( 'error' => 'Cannot change WPHubPro Bridge version from platform.' ) );
			return new WP_Error( 'forbidden', __( 'WPHubPro Bridge cannot be modified from the platform.', 'wphubpro-bridge' ), array( 'status' => 403 ) );
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
			WPHubPro_Bridge_Logger::log_action( $site_url, 'install-version', $endpoint, array_merge( $params, array( 'version' => $version ) ), array( 'error' => 'Version not found', 'available' => array_slice( $versions_available, -20 ) ) );
			return new WP_Error( 'version_not_found', __( 'Version not found in WordPress.org. Plugin may not be in the official library.', 'wphubpro-bridge' ), array( 'status' => 404 ) );
		}

		$download_url = $versions[ $version ];
		if ( empty( $download_url ) || ! filter_var( $download_url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_url', __( 'Invalid download URL for this version.', 'wphubpro-bridge' ), array( 'status' => 500 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$target_plugin = $plugin ?: $plugin_slug . '/' . $plugin_slug . '.php';
		if ( ! $plugin ) {
			$target_plugin = $this->resolve_plugin_file( $plugin_slug ) ?: $plugin_slug . '/' . $plugin_slug . '.php';
		}
		$was_active = ! empty( $target_plugin ) && is_plugin_active( $target_plugin );

		$skin    = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->run( array(
			'package'           => $download_url,
			'destination'       => WP_PLUGIN_DIR,
			'clear_destination' => true,
			'clear_working'     => true,
			'hook_extra'        => array(
				'plugin' => $target_plugin,
				'type'   => 'plugin',
				'action' => 'update',
			),
		) );

		if ( ! is_wp_error( $result ) && $was_active ) {
			$reactivate = activate_plugin( $target_plugin );
			if ( is_wp_error( $reactivate ) ) {
				WPHubPro_Bridge_Logger::log_action( $site_url, 'install-version', $endpoint, $params, array( 'warning' => 'Install succeeded but reactivation failed: ' . $reactivate->get_error_message() ) );
			}
		}

		WPHubPro_Bridge_Logger::log_action( $site_url, 'install-version', $endpoint, array_merge( $params, array( 'version' => $version ) ), is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : array( 'success' => true ) );
		if ( ! is_wp_error( $result ) ) {
			WPHubPro_Bridge_Sync::schedule_sync();
		}
		return $result;
	}

	/**
	 * Update the WPHubPro Bridge plugin from a zip URL or base64 payload.
	 * Only allows updating the bridge plugin.
	 * Accepts zip_url (HTTPS) or zip_base64 (proxy flow – avoids WordPress downloading from Appwrite).
	 *
	 * @param WP_REST_Request $request Request object. Expects plugin (bridge file path) and zip_url or zip_base64.
	 * @return mixed
	 */
	public function install_plugin_from_zip_url( $request ) {
		$endpoint = 'plugins/manage/install-from-zip';
		$site_url = get_site_url();
		$params   = $this->parse_plugin_params( $request );
		$plugin   = $params['plugin'];
		$zip_url  = $request->get_param( 'zip_url' );
		$zip_base64 = $request->get_param( 'zip_base64' );
		if ( empty( $zip_url ) && empty( $zip_base64 ) && ! empty( $request->get_body() ) ) {
			$decoded = json_decode( $request->get_body(), true );
			if ( is_array( $decoded ) ) {
				$zip_url = $decoded['zip_url'] ?? '';
				$zip_base64 = $decoded['zip_base64'] ?? '';
			}
		}
		$zip_url = is_string( $zip_url ) ? esc_url_raw( $zip_url ) : '';
		$zip_base64 = is_string( $zip_base64 ) ? trim( $zip_base64 ) : '';

		if ( empty( $plugin ) ) {
			$plugin = self::get_bridge_plugin_file();
		}
		if ( ! $this->is_bridge_plugin( $plugin ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'install-from-zip', $endpoint, array( 'plugin' => $plugin ), array( 'error' => 'Only the WPHubPro Bridge plugin can be updated via zip URL.' ) );
			return new WP_Error( 'forbidden', __( 'Only the WPHubPro Bridge plugin can be updated from a zip URL.', 'wphubpro-bridge' ), array( 'status' => 403 ) );
		}
		if ( empty( $zip_url ) && empty( $zip_base64 ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'install-from-zip', $endpoint, array(), array( 'error' => 'zip_url or zip_base64 is required.' ) );
			return new WP_Error( 'invalid_input', __( 'zip_url or zip_base64 is required.', 'wphubpro-bridge' ), array( 'status' => 400 ) );
		}
		if ( empty( $zip_base64 ) && ( empty( $zip_url ) || strpos( $zip_url, 'https://' ) !== 0 ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'install-from-zip', $endpoint, array(), array( 'error' => 'Valid HTTPS zip_url is required when zip_base64 is not provided.' ) );
			return new WP_Error( 'invalid_zip_url', __( 'A valid HTTPS zip URL is required.', 'wphubpro-bridge' ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$package = '';
		if ( ! empty( $zip_base64 ) ) {
			$decoded = base64_decode( $zip_base64, true );
			if ( $decoded === false || strlen( $decoded ) < 100 ) {
				WPHubPro_Bridge_Logger::log_action( $site_url, 'install-from-zip', $endpoint, array(), array( 'error' => 'Invalid zip_base64' ) );
				return new WP_Error( 'invalid_zip_base64', __( 'Invalid zip_base64 data.', 'wphubpro-bridge' ), array( 'status' => 400 ) );
			}
			$tmp = wp_tempnam( 'wphubpro-bridge-' );
			if ( ! $tmp || file_put_contents( $tmp, $decoded ) === false ) {
				WPHubPro_Bridge_Logger::log_action( $site_url, 'install-from-zip', $endpoint, array(), array( 'error' => 'Could not write temp file' ) );
				return new WP_Error( 'temp_file', __( 'Could not write temporary file.', 'wphubpro-bridge' ), array( 'status' => 500 ) );
			}
			$package = $tmp;
		} else {
			$package = $zip_url;
		}

		$was_active = is_plugin_active( $plugin );
		$skin       = new Automatic_Upgrader_Skin();
		$upgrader   = new Plugin_Upgrader( $skin );
		$result     = $upgrader->run( array(
			'package'           => $package,
			'destination'       => WP_PLUGIN_DIR,
			'clear_destination' => true,
			'clear_working'     => true,
			'hook_extra'        => array(
				'plugin' => $plugin,
				'type'   => 'plugin',
				'action' => 'update',
			),
		) );

		if ( ! empty( $zip_base64 ) && ! empty( $package ) && file_exists( $package ) ) {
			@unlink( $package );
		}

		if ( ! is_wp_error( $result ) && $was_active ) {
			$reactivate = activate_plugin( $plugin );
			if ( is_wp_error( $reactivate ) ) {
				WPHubPro_Bridge_Logger::log_action( $site_url, 'install-from-zip', $endpoint, array(), array( 'warning' => 'Update succeeded but reactivation failed: ' . $reactivate->get_error_message() ) );
			}
		}

		$log_source = ! empty( $zip_base64 ) ? 'zip_base64' : 'zip_url';
		WPHubPro_Bridge_Logger::log_action( $site_url, 'install-from-zip', $endpoint, array( $log_source => $log_source ), is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : array( 'success' => true ) );
		if ( ! is_wp_error( $result ) ) {
			WPHubPro_Bridge_Sync::schedule_sync();
		}
		return $result;
	}

	/**
	 * Parse plugin and slug from request (query, body param, or raw body).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array{plugin: string, slug: string}
	 */
	private function parse_plugin_params( $request ) {
		$plugin = $request->get_param( 'plugin' );
		$slug   = $request->get_param( 'slug' );
		if ( empty( $plugin ) || empty( $slug ) ) {
			$body_raw = $request->get_param( 'body' );
			if ( is_string( $body_raw ) ) {
				$decoded = json_decode( $body_raw, true );
				if ( is_array( $decoded ) ) {
					if ( empty( $plugin ) && ! empty( $decoded['plugin'] ) ) {
						$plugin = sanitize_text_field( $decoded['plugin'] );
					}
					if ( empty( $slug ) && ! empty( $decoded['slug'] ) ) {
						$slug = sanitize_text_field( $decoded['slug'] );
					}
				}
			}
			if ( ( empty( $plugin ) || empty( $slug ) ) && ! empty( $request->get_body() ) ) {
				$decoded = json_decode( $request->get_body(), true );
				if ( is_array( $decoded ) ) {
					if ( empty( $plugin ) && ! empty( $decoded['plugin'] ) ) {
						$plugin = sanitize_text_field( $decoded['plugin'] );
					}
					if ( empty( $slug ) && ! empty( $decoded['slug'] ) ) {
						$slug = sanitize_text_field( $decoded['slug'] );
					}
				}
			}
		}
		if ( ! empty( $plugin ) && strpos( $plugin, '/' ) === false && strpos( $plugin, '-' ) !== false ) {
			$plugin = $this->normalize_plugin_to_path( $plugin );
		}
		return array( 'plugin' => $plugin ?: '', 'slug' => $slug ?: '' );
	}

	/**
	 * Convert slug-style plugin (e.g. elementor-elementor.php) to path format (elementor/elementor.php).
	 *
	 * @param string $plugin Plugin identifier (slug or path).
	 * @return string
	 */
	private function normalize_plugin_to_path( $plugin ) {
		if ( empty( $plugin ) || strpos( $plugin, '/' ) !== false ) {
			return $plugin;
		}
		$base = preg_replace( '/\.php$/i', '', $plugin );
		if ( strpos( $base, '-' ) !== false ) {
			$base = str_replace( '-', '/', $base );
			return $base . '.php';
		}
		return $plugin;
	}

	/**
	 * Validate plugin file path format.
	 *
	 * @param string $plugin Plugin file path.
	 * @return WP_Error|null WP_Error on failure, null on success.
	 */
	private function validate_plugin_file( $plugin ) {
		if ( empty( $plugin ) || strpos( $plugin, '/' ) === false ) {
			return new WP_Error( 'invalid_plugin', 'Invalid or missing plugin param: expected plugin file path (e.g. akismet/akismet.php)' );
		}
		return null;
	}

	/**
	 * Resolve plugin file from slug.
	 *
	 * @param string $slug Plugin slug.
	 * @return string|null Plugin file path or null.
	 */
	private function resolve_plugin_file( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();
		foreach ( $all_plugins as $file => $data ) {
			if ( strpos( $file, $slug ) !== false ) {
				return $file;
			}
		}
		return null;
	}
}
