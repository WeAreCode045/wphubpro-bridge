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
		} catch ( \Exception $e ) {
			error_log( '[WPHubPro Bridge] log_action failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Log a single request to the wphubpro/v1 API to option WPHUBPRO_LOG (last 20).
	 *
	 * Entry: time, endpoint, type (GET|POST), code, request, response.
	 *
	 * @param WP_REST_Request $request  Request object.
	 * @param WP_REST_Response|WP_Error $response Response or error.
	 */
	public static function push_api_log( $request, $response ) {
		$route = $request->get_route();
		if ( strpos( $route, 'wphubpro/v1' ) === false ) {
			return;
		}

		$req_data = array(
			'query' => $request->get_query_params(),
			'body'  => $request->get_body_params(),
		);
		if ( empty( $req_data['body'] ) && $request->get_body() ) {
			$parsed = json_decode( $request->get_body(), true );
			$req_data['body'] = is_array( $parsed ) ? $parsed : array( '_raw' => substr( $request->get_body(), 0, 500 ) );
		}

		if ( is_wp_error( $response ) ) {
			$code     = (int) $response->get_error_data( 'status' );
			$res_data = array( 'error' => $response->get_error_message() );
		} else {
			$code     = $response->get_status();
			$res_data = $response->get_data();
		}
		self::cap_size( $req_data );
		self::cap_size( $res_data );

		$entry = array(
			'time'     => gmdate( 'c' ),
			'endpoint' => $route,
			'type'     => $request->get_method(),
			'code'     => $code ? $code : 500,
			'request'  => $req_data,
			'response' => $res_data,
		);

		$log = get_option( 'WPHUBPRO_LOG', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 20 );
		update_option( 'WPHUBPRO_LOG', $log );
	}

	/**
	 * Cap size of logged value to avoid huge options (e.g. full plugin list).
	 *
	 * @param mixed $value Value to cap in place (array/object by reference).
	 */
	private static function cap_size( &$value ) {
		if ( ! is_array( $value ) ) {
			return;
		}
		$n = count( $value );
		if ( $n > 30 ) {
			$value = array(
				'_summary' => 'array',
				'count'   => $n,
				'preview' => array_slice( $value, 0, 5 ),
			);
		}
	}
}
