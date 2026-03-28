<?php
namespace WPHubPro\Api;

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
class Logger extends ApiBase {

	private static $instance = null;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}


	/**
	 * Log an action to the site's action_log in Appwrite.
	 *
	 * Uses JWT and Appwrite SDK. Requires WPHUBPRO_ENDPOINT, WPHUBPRO_PROJECT_ID, WPHUBPRO_USER_JWT.
	 *
	 * @param string $action   Action name (e.g. activate, deactivate, update, list).
	 * @param string $endpoint REST endpoint (e.g. plugins/manage, plugins).
	 * @param array  $request  Request data.
	 * @param mixed  $response Response/result.
	 */
	public static function send_log_action($action, $endpoint, $request, $response ) {
		$log_req = is_array( $request ) ? $request : array();
		$log_res = is_array( $response ) ? $response : ( is_object( $response ) ? (array) $response : array() );
		$log_req_copy = json_decode( wp_json_encode( $log_req ), true ) ?: array();
		$log_res_copy = json_decode( wp_json_encode( $log_res ), true ) ?: array();
		self::strip_sensitive_data( $log_req_copy );
		self::strip_sensitive_data( $log_res_copy );
		error_log( '[WPHubPro Bridge] send_log_action: ' . wp_json_encode( array(
			'action'   => (string) $action,
			'endpoint' => (string) $endpoint,
			'request'  => $log_req_copy,
			'response' => $log_res_copy,
		) ) );

		$req_safe  = is_array( $request ) ? $request : array();
		$res_safe  = is_array( $response ) ? $response : ( is_object( $response ) ? (array) $response : array() );
		self::strip_sensitive_data( $req_safe );
		self::strip_sensitive_data( $res_safe );

		$entry = array(
			'timestamp' => gmdate( 'c' ),
			'action'    => (string) $action,
			'endpoint'  => (string) $endpoint,
			'request'   => $req_safe,
			'response'  => $res_safe,
		);

		try {
			self::instance()->post( 'bridge-site-log-action', $entry );
		} catch ( \Exception $e ) {
			error_log( '[WPHubPro Bridge] log_action: ' . wp_json_encode( array(
				'error'    => $e->getMessage(),
				'action'   => $action,
				'endpoint' => $endpoint,
				'request'  => $log_req_copy,
				'response' => $log_res_copy,
			) ) );
			return false;
		}
		return true;
	}

	/**
	 * Strip api_key, secret and other sensitive data from arrays before storing/sending.
	 * WPHUB_DATA (options, logs) must NEVER contain WPHUBPRO_API_KEY.
	 *
	 * @param array $data Data to sanitize (modified in place).
	 */
	private static function strip_sensitive_data( &$data ) {
		if ( ! is_array( $data ) ) {
			return;
		}
		$sensitive_keys = array( 'api_key', 'apiKey', 'secret', 'WPHUBPRO_API_KEY', 'password', 'jwt', 'X-WPHub-Key' );
		foreach ( $sensitive_keys as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$data[ $key ] = '[REDACTED]';
			}
		}
		foreach ( $data as $k => &$v ) {
			if ( is_array( $v ) ) {
				self::strip_sensitive_data( $v );
			}
		}
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

	/**
	 * Log each wphubpro/v1 request to WPHUBPRO_LOG option (last 20).
	 * Excludes /logs to avoid logging the logs request itself.
	 *
	 * @param WP_REST_Response|WP_Error $response Result to send.
	 * @param WP_REST_Server            $server   Server instance.
	 * @param WP_REST_Request          $request  Request object.
	 * @return WP_REST_Response|WP_Error Unchanged response.
	 */
	public function log_rest_request( $response, $server, $request ) {
		\WPHubPro\Logger::push_api_log( $request, $response );
		return $response;
	}
}
