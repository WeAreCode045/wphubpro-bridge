<?php
/**
 * Sync plugins_meta and themes_meta to Appwrite sites collection.
 *
 * Called after plugin/theme actions (activate, deactivate, update, install, uninstall).
 * Pushes data to sync-site-meta Appwrite Function via JWT.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync plugins and themes meta to Appwrite.
 */
class WPHubPro_Bridge_Sync {

	/**
	 * Sync plugins_meta and themes_meta to Appwrite.
	 *
	 * Fetches current plugins and themes, formats them, and POSTs to sync-site-meta function.
	 *
	 * @return bool True on success, false on failure (logged).
	 */
	public static function sync_meta_to_appwrite() {
		$site_id  = get_option( 'WPHUBPRO_SITE_ID' );
		$jwt      = get_option( 'WPHUBPRO_USER_JWT' );
		$endpoint = get_option( 'WPHUBPRO_ENDPOINT' );
		$project  = get_option( 'WPHUBPRO_PROJECT_ID' );

		if ( empty( $site_id ) || empty( $jwt ) || empty( $endpoint ) || empty( $project ) ) {
			WPHubPro_Bridge_Logger::log_action( get_site_url(), 'sync', 'meta', array(), array( 'skipped' => 'Missing site_id, jwt, endpoint or project_id' ) );
			return false;
		}

		$plugins_meta = self::get_plugins_meta();
		$themes_meta  = self::get_themes_meta();

		$url = untrailingslashit( $endpoint ) . '/functions/sync-site-meta/executions';

		$payload = array(
			'siteId'       => $site_id,
			'plugins_meta' => $plugins_meta,
			'themes_meta'  => $themes_meta,
		);

		$request_body = wp_json_encode( array(
			'body'    => wp_json_encode( $payload ),
			'method'  => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $jwt,
				'Content-Type'  => 'application/json',
			),
		) );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type'         => 'application/json',
					'X-Appwrite-Project'   => $project,
					'X-Appwrite-JWT'       => $jwt,
				),
				'body'    => $request_body,
				'timeout' => 30,
			)
		);

		$code = wp_remote_retrieve_response_code( $response );
		$body_response = wp_remote_retrieve_body( $response );

		if ( is_wp_error( $response ) ) {
			WPHubPro_Bridge_Logger::log_action( get_site_url(), 'sync', 'meta', array(), array( 'error' => $response->get_error_message() ) );
			return false;
		}

		if ( $code < 200 || $code >= 300 ) {
			WPHubPro_Bridge_Logger::log_action( get_site_url(), 'sync', 'meta', array(), array( 'error' => 'HTTP ' . $code, 'body' => substr( $body_response, 0, 200 ) ) );
			return false;
		}

		WPHubPro_Bridge_Logger::log_action( get_site_url(), 'sync', 'meta', array(), array( 'success' => true, 'plugins' => count( $plugins_meta ), 'themes' => count( $themes_meta ) ) );
		return true;
	}

	/**
	 * Get plugins list in meta format (file, name, version, active, update).
	 *
	 * @return array
	 */
	private static function get_plugins_meta() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins   = get_plugins();
		$active_plugins = get_option( 'active_plugins' );
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		$updates = get_site_transient( 'update_plugins' );
		$updates_response = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) ? $updates->response : array();

		$meta = array();
		foreach ( $all_plugins as $file => $data ) {
			$update_version = isset( $updates_response[ $file ] ) && ! empty( $updates_response[ $file ]->new_version )
				? $updates_response[ $file ]->new_version
				: null;
			$meta[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => in_array( $file, (array) $active_plugins, true ),
				'update'  => $update_version,
			);
		}
		return $meta;
	}

	/**
	 * Get themes list in meta format (stylesheet, name, version, active, update).
	 *
	 * @return array
	 */
	private static function get_themes_meta() {
		$all_themes = wp_get_themes();
		$current    = get_stylesheet();
		if ( function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}
		$updates = get_site_transient( 'update_themes' );
		$meta    = array();

		foreach ( $all_themes as $slug => $theme ) {
			$meta[] = array(
				'stylesheet' => $slug,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'active'     => ( $slug === $current ),
				'update'     => isset( $updates->response[ $slug ]['new_version'] ) ? $updates->response[ $slug ]['new_version'] : null,
			);
		}
		return $meta;
	}
}
