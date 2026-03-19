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
		if ( function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}
		$updates  = get_site_transient( 'update_themes' );
		$response = array();

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
	 * Parse theme slug from request (query, body param, or raw body).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string
	 */
	private function parse_theme_slug( $request ) {
		$slug = $request->get_param( 'slug' );
		if ( empty( $slug ) ) {
			$body_raw = $request->get_param( 'body' );
			if ( is_string( $body_raw ) ) {
				$decoded = json_decode( $body_raw, true );
				if ( is_array( $decoded ) && ! empty( $decoded['slug'] ) ) {
					return sanitize_text_field( $decoded['slug'] );
				}
			}
			if ( ! empty( $request->get_body() ) ) {
				$decoded = json_decode( $request->get_body(), true );
				if ( is_array( $decoded ) && ! empty( $decoded['slug'] ) ) {
					return sanitize_text_field( $decoded['slug'] );
				}
			}
		}
		return $slug ? sanitize_text_field( $slug ) : '';
	}

	/**
	 * Validate theme slug and that theme exists.
	 *
	 * @param string $slug Theme slug.
	 * @return WP_Error|null
	 */
	private function validate_theme_slug( $slug ) {
		if ( empty( $slug ) ) {
			return new WP_Error( 'invalid_theme', 'Invalid or missing theme slug' );
		}
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'theme_not_found', 'Theme not found: ' . $slug );
		}
		return null;
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
		$slug     = $this->parse_theme_slug( $request );
		$err      = $this->validate_theme_slug( $slug );
		if ( is_wp_error( $err ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'activate', $endpoint, array( 'slug' => $slug ), array( 'error' => $err->get_error_message() ) );
			return $err;
		}
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		do_action( 'wphub_theme_action_pre', 'activate', $slug, array( 'slug' => $slug ) );
		$resp = apply_filters( 'wphub_theme_activate', switch_theme( $slug ), $slug, array( 'slug' => $slug ) );
		WPHubPro_Bridge_Logger::log_action( $site_url, 'activate', $endpoint, array( 'slug' => $slug ), is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => true ) );
		if ( ! is_wp_error( $resp ) ) {
			WPHubPro_Bridge_Sync::schedule_sync();
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
		$slug     = $this->parse_theme_slug( $request );
		$err      = $this->validate_theme_slug( $slug );
		if ( is_wp_error( $err ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'update', $endpoint, array( 'slug' => $slug ), array( 'error' => $err->get_error_message() ) );
			return $err;
		}
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		do_action( 'wphub_theme_action_pre', 'update', $slug, array( 'slug' => $slug ) );
		$skin    = new Automatic_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$resp     = apply_filters( 'wphub_theme_update', $upgrader->update( $slug ), $slug, array( 'slug' => $slug ) );
		WPHubPro_Bridge_Logger::log_action( $site_url, 'update', $endpoint, array( 'slug' => $slug ), is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => $resp ) );
		if ( ! is_wp_error( $resp ) ) {
			WPHubPro_Bridge_Sync::schedule_sync();
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
		$slug     = $this->parse_theme_slug( $request );
		$err      = $this->validate_theme_slug( $slug );
		if ( is_wp_error( $err ) ) {
			WPHubPro_Bridge_Logger::log_action( $site_url, 'delete', $endpoint, array( 'slug' => $slug ), array( 'error' => $err->get_error_message() ) );
			return $err;
		}
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		do_action( 'wphub_theme_action_pre', 'delete', $slug, array( 'slug' => $slug ) );
		$resp = apply_filters( 'wphub_theme_delete', delete_theme( $slug ), $slug, array( 'slug' => $slug ) );
		WPHubPro_Bridge_Logger::log_action( $site_url, 'delete', $endpoint, array( 'slug' => $slug ), is_wp_error( $resp ) ? array( 'error' => $resp->get_error_message() ) : array( 'success' => true ) );
		// Sync via delete_theme hook (fires when delete_theme is called)
		if ( ! is_wp_error( $resp ) ) {
			WPHubPro_Bridge_Sync::schedule_sync();
		}
		return $resp;
	}
}
