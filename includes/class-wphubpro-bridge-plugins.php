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
		$active_plugins = get_option( 'active_plugins' );

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
		// Sync via activated_plugin hook
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
		// Sync via deactivated_plugin hook
		return true;
	}

	/**
	 * Update a plugin.
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
		if ( $this->is_bridge_plugin( $plugin ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'error' => 'Cannot update WPHubPro Bridge from platform.' ) );
			return new WP_Error( 'forbidden', __( 'WPHubPro Bridge cannot be updated from the platform. Update it in WordPress Admin > Plugins to keep the connection intact.', 'wphubpro-bridge' ), array( 'status' => 403 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		do_action( 'wphub_plugin_action_pre', 'update', $plugin, $slug, $params );
		error_log( '[WPHubPro Bridge] ' . $endpoint . ' INCOMING: ' . wp_json_encode( array( 'plugin' => $plugin, 'slug' => $slug ) ) );

		// When updating via REST API (not cron), Plugin_Upgrader deactivates the plugin but does NOT
		// reactivate it. We must restore activation state after a successful upgrade.
		$was_active = is_plugin_active( $plugin );

		$skin    = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$resp     = apply_filters( 'wphub_plugin_update', $upgrader->upgrade( $plugin ), $plugin, $slug, $params );

		if ( ! is_wp_error( $resp ) && $was_active ) {
			$activate_result = activate_plugin( $plugin );
			if ( is_wp_error( $activate_result ) ) {
				WPHubPro_Bridge_Logger::log_action( $site_url, 'update', $endpoint, $params, array( 'warning' => 'Upgrade succeeded but reactivation failed: ' . $activate_result->get_error_message() ) );
			}
		}

		WPHubPro_Bridge_Logger::log_action( $site_url, 'update', $endpoint, $params, is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => $resp ) );
		// Sync via upgrader_process_complete hook
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
			add_action( 'shutdown', array( 'WPHubPro_Bridge_Sync', 'sync_meta_to_appwrite' ), 5 );
		}
		return $resp;
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
		return array( 'plugin' => $plugin ?: '', 'slug' => $slug ?: '' );
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
