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
	 * @return WP_REST_Response
	 */
	public function get_themes_list() {
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
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, $resp );
				return true;

			case 'delete':
				$resp = apply_filters( 'wphub_theme_delete', delete_theme( $slug ), $slug, $req_data );
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, $resp );
				return $resp;

			case 'update':
				$upgrader = new Theme_Upgrader( $skin );
				$resp     = apply_filters( 'wphub_theme_update', $upgrader->update( $slug ), $slug, $req_data );
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, $resp );
				return $resp;

			case 'install':
				$api      = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
				$upgrader = new Theme_Upgrader( $skin );
				$resp     = apply_filters( 'wphub_theme_install', $upgrader->install( $api->download_link ), $slug, $req_data );
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, $resp );
				return $resp;

			default:
				WPHubPro_Bridge_Logger::log_action( $site_url, $action, $endpoint, $req_data, 'Action not supported' );
				return new WP_Error( 'invalid_action', 'Action not supported' );
		}
	}
}
