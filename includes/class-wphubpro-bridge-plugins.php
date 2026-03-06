<?php
/**
 * Plugin management for WPHubPro Bridge.
 *
 * Handles: list, install, update, activate, deactivate, delete (remove).
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin management: install, update, activate, deactivate, delete.
 */
class WPHubPro_Bridge_Plugins {

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
		$updates       = get_site_transient( 'update_plugins' );
		$response      = array();

		foreach ( $all_plugins as $file => $data ) {
			$response[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => in_array( $file, (array) $active_plugins, true ),
				'update'  => isset( $updates->response[ $file ] ) ? $updates->response[ $file ]->new_version : null,
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
	 * Manage plugin: activate, deactivate, delete, update, install.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public function manage_plugin( $request ) {
		$site_url  = get_site_url();
		$endpoint  = 'plugins/manage';
		$req_data  = array(
			'action' => $request->get_param( 'action' ),
			'plugin' => $request->get_param( 'plugin' ),
			'slug'   => $request->get_param( 'slug' ),
		);

		// Debug: log alle binnenkomende plugin-actions
		error_log( '[WPHubPro Bridge] plugins/manage INCOMING: ' . wp_json_encode( array(
			'get_params'   => $req_data,
			'body_params'  => $request->get_body_params(),
			'query_params' => $request->get_query_params(),
		) ) );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$action = $req_data['action'];
		$plugin = $req_data['plugin'];
		$slug   = $req_data['slug'];

		do_action( 'wphub_plugin_action_pre', $action, $plugin, $slug, $req_data );

		if ( in_array( $action, array( 'activate', 'deactivate', 'delete', 'update' ), true ) ) {
			if ( empty( $plugin ) && ! empty( $slug ) ) {
				$plugin = $this->resolve_plugin_file( $slug );
			}
			if ( empty( $plugin ) || strpos( $plugin, '/' ) === false ) {
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, array( 'error' => 'Invalid or missing plugin param' ) );
				return new WP_Error( 'invalid_plugin', 'Invalid or missing plugin param: expected plugin file path (e.g. akismet/akismet.php)' );
			}
		}

		$skin = new Automatic_Upgrader_Skin();

		switch ( $action ) {
			case 'activate':
				$resp = apply_filters( 'wphub_plugin_activate', activate_plugin( $plugin ), $plugin, $slug, $req_data );
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => true ) );
				return $resp;

			case 'deactivate':
				$resp = apply_filters( 'wphub_plugin_deactivate', deactivate_plugins( $plugin ), $plugin, $slug, $req_data );
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, array( 'success' => true ) );
				return true;

			case 'delete':
				if ( empty( $plugin ) && ! empty( $slug ) ) {
					$plugin = $this->resolve_plugin_file( $slug );
				}
				if ( empty( $plugin ) || strpos( $plugin, '/' ) === false ) {
					WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, array( 'error' => 'Invalid or missing plugin param' ) );
					return new WP_Error( 'invalid_plugin', 'Invalid or missing plugin param: expected plugin file path (e.g. akismet/akismet.php)' );
				}
				$resp = apply_filters( 'wphub_plugin_delete', delete_plugins( array( $plugin ) ), $plugin, $slug, $req_data );
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => true ) );
				return $resp;

			case 'update':
				if ( empty( $plugin ) && ! empty( $slug ) ) {
					$plugin = $this->resolve_plugin_file( $slug );
				}
				if ( empty( $plugin ) || strpos( $plugin, '/' ) === false ) {
					WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, array( 'error' => 'Invalid or missing plugin param' ) );
					return new WP_Error( 'invalid_plugin', 'Invalid or missing plugin param: expected plugin file path (e.g. akismet/akismet.php)' );
				}
				$upgrader = new Plugin_Upgrader( $skin );
				$resp     = apply_filters( 'wphub_plugin_update', $upgrader->update( $plugin ), $plugin, $slug, $req_data );
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => $resp ) );
				return $resp;

			case 'install':
				$api      = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
				$upgrader = new Plugin_Upgrader( $skin );
				$resp     = apply_filters( 'wphub_plugin_install', $upgrader->install( $api->download_link ), $plugin, $slug, $req_data );
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'installed' => is_array( $resp ) ? true : $resp ) );
				return $resp;

			default:
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, array( 'error' => 'Action not supported' ) );
				return new WP_Error( 'invalid_action', 'Action not supported' );
		}
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
