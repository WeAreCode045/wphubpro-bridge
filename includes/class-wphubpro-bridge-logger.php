<?php
/**
 * Appwrite action logger for WPHubPro Bridge.
 *
 * Uses WPHUBPRO_ENDPOINT, WPHUBPRO_PROJECT_ID, WPHUBPRO_USER_JWT.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs actions to Appwrite for audit trail.
 */
class WPHubPro_Bridge_Logger {

	/**
	 * Log an action to the site's action_log in Appwrite.
	 *
	 * Uses JWT and Appwrite SDK. Requires WPHUBPRO_ENDPOINT, WPHUBPRO_PROJECT_ID, WPHUBPRO_USER_JWT.
	 *
	 * @param string $site_url Site URL.
	 * @param string $action   Action name (e.g. activate, deactivate, update, list).
	 * @param string $endpoint REST endpoint (e.g. plugins/manage, plugins).
	 * @param array  $request  Request data.
	 * @param mixed  $response Response/result.
	 */
	public static function log_action( $site_url, $action, $endpoint, $request, $response ) {
		error_log( '[WPHubPro Bridge] log_action: ' . wp_json_encode( array(
			'action'   => $action,
			'endpoint' => $endpoint,
			'request'  => $request,
			'response' => $response,
		) ) );

		$appwrite_endpoint = get_option( 'WPHUBPRO_ENDPOINT' );
		$appwrite_project  = get_option( 'WPHUBPRO_PROJECT_ID' );
		$appwrite_jwt      = get_option( 'WPHUBPRO_USER_JWT' );

		if ( ! $appwrite_endpoint || ! $appwrite_project || ! $appwrite_jwt || ! class_exists( 'Appwrite\Client' ) ) {
			return;
		}

		try {
			$client    = new \Appwrite\Client();
			$client->setEndpoint( $appwrite_endpoint )->setProject( $appwrite_project )->setJWT( $appwrite_jwt );
			$databases = new \Appwrite\Services\Databases( $client );

			$urls = array( $site_url, untrailingslashit( $site_url ), trailingslashit( $site_url ) );
			$site = null;
			foreach ( $urls as $url ) {
				$resp = $databases->listDocuments( 'platform_db', 'sites', array(
					\Appwrite\Query::equal( 'site_url', $url ),
					\Appwrite\Query::limit( 1 ),
				) );
				if ( ! empty( $resp['documents'][0] ) ) {
					$site = $resp['documents'][0];
					break;
				}
			}
			if ( ! $site ) {
				return;
			}

			$site_id    = $site['$id'];
			$action_log = isset( $site['action_log'] ) && is_array( $site['action_log'] ) ? $site['action_log'] : array();

			$entry = array(
				'timestamp' => gmdate( 'c' ),
				'action'    => $action,
				'endpoint'  => $endpoint,
				'request'   => $request,
				'response'  => $response,
			);
			$action_log[] = $entry;

			$databases->updateDocument(
				'platform_db',
				'sites',
				$site_id,
				array( 'action_log' => $action_log )
			);
		} catch ( Exception $e ) {
			error_log( '[WPHubPro Bridge] log_action failed: ' . $e->getMessage() );
		}
	}
}
