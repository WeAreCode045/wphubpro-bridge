<?php
/**
 * Appwrite action logger for WPHubPro Bridge.
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
	 * @param string $site_url Site URL.
	 * @param string $action   Action name (e.g. activate, deactivate, update).
	 * @param string $endpoint REST endpoint (e.g. plugins/manage).
	 * @param array  $request  Request data.
	 * @param mixed  $response Response/result.
	 */
	public static function log_action( $site_url, $action, $endpoint, $request, $response ) {
		// Debug: log alle actions naar PHP error_log
		error_log( '[WPHubPro Bridge] log_action: ' . wp_json_encode( array(
			'action'   => $action,
			'endpoint' => $endpoint,
			'request'  => $request,
			'response' => $response,
		) ) );

		$appwrite_endpoint = getenv( 'APPWRITE_ENDPOINT' );
		$appwrite_project  = getenv( 'APPWRITE_PROJECT_ID' );
		$appwrite_key      = getenv( 'APPWRITE_API_KEY' );
		if ( ! $appwrite_endpoint || ! $appwrite_project || ! $appwrite_key ) {
			return;
		}

		$site_id = null;
		$sites_url = $appwrite_endpoint . "/v1/databases/platform_db/collections/sites/documents?queries[]=equal('site_url','" . urlencode( $site_url ) . "')";
		$opts = array(
			'http' => array(
				'method'  => 'GET',
				'header'  => "X-Appwrite-Project: $appwrite_project\r\nX-Appwrite-Key: $appwrite_key\r\nContent-Type: application/json\r\n",
			),
		);
		$context  = stream_context_create( $opts );
		$result   = @file_get_contents( $sites_url, false, $context );
		if ( $result ) {
			$data = json_decode( $result, true );
			if ( ! empty( $data['documents'][0]['$id'] ) ) {
				$site_id = $data['documents'][0]['$id'];
			}
		}
		if ( ! $site_id ) {
			return;
		}

		$doc_url = $appwrite_endpoint . "/v1/databases/platform_db/collections/sites/documents/$site_id";
		$opts['http']['method'] = 'GET';
		$context = stream_context_create( $opts );
		$doc     = @file_get_contents( $doc_url, false, $context );
		$action_log = array();
		if ( $doc ) {
			$doc_data = json_decode( $doc, true );
			if ( ! empty( $doc_data['action_log'] ) && is_array( $doc_data['action_log'] ) ) {
				$action_log = $doc_data['action_log'];
			}
		}

		$entry = array(
			'timestamp' => date( 'c' ),
			'action'    => $action,
			'endpoint'  => $endpoint,
			'request'   => $request,
			'response'  => $response,
		);
		$action_log[] = $entry;

		$opts['http']['method']  = 'PATCH';
		$opts['http']['content'] = wp_json_encode( array( 'action_log' => $action_log ) );
		$context = stream_context_create( $opts );
		@file_get_contents( $doc_url, false, $context );
	}
}
