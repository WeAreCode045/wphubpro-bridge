<?php
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
class WPHubPro_Bridge_Themes {

	/**
	 * Get list of all themes with status and update info.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_themes_list( $request ) {
		$all_themes = wp_get_themes();
		$current    = get_stylesheet();
		$updates    = get_site_transient( 'update_themes' );
		$response   = array();

		foreach ( $all_themes as $slug => $theme ) {
			$response[] = array(
				'slug'    => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => ( $slug === $current ),
				'update'  => isset( $updates->response[ $slug ] ) ? $updates->response[ $slug ]['new_version'] : null,
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
		WPHubPro_Bridge_Logger::log_action( $site_url, 'list', 'themes', array(), $log_resp );

		return rest_ensure_response( $response );
	}

	/**
	 * Manage theme: activate, delete, update, install.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public function manage_theme( $request ) {
		$site_url = get_site_url();
		$endpoint = 'themes/manage';
		$req_data = array(
			'action' => $request->get_param( 'action' ),
			'slug'   => $request->get_param( 'slug' ),
		);

		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$action = $req_data['action'];
		$slug   = $req_data['slug'];

		do_action( 'wphub_theme_action_pre', $action, $slug, $req_data );

		$skin = new Automatic_Upgrader_Skin();

		switch ( $action ) {
			case 'activate':
				$resp = apply_filters( 'wphub_theme_activate', switch_theme( $slug ), $slug, $req_data );
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, array( 'success' => true ) );
				return true;

			case 'delete':
				$resp = apply_filters( 'wphub_theme_delete', delete_theme( $slug ), $slug, $req_data );
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => true ) );
				return $resp;

			case 'update':
				$upgrader = new Theme_Upgrader( $skin );
				$resp     = apply_filters( 'wphub_theme_update', $upgrader->update( $slug ), $slug, $req_data );
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => $resp ) );
				return $resp;

			case 'install':
				$api      = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
				$upgrader = new Theme_Upgrader( $skin );
				$resp     = apply_filters( 'wphub_theme_install', $upgrader->install( $api->download_link ), $slug, $req_data );
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'installed' => is_array( $resp ) ? true : $resp ) );
				return $resp;

			default:
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, array( 'error' => 'Action not supported' ) );
				return new WP_Error( 'invalid_action', 'Action not supported' );
		}
	}
}
