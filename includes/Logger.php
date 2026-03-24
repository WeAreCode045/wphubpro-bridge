<?php
namespace WPHUBPRO;

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
class Logger {

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
	public static function log_action($action, $endpoint, $request, $response ) {
		$log_req = is_array( $request ) ? $request : array();
		$log_res = is_array( $response ) ? $response : ( is_object( $response ) ? (array) $response : array() );
		$log_req_copy = json_decode( wp_json_encode( $log_req ), true ) ?: array();
		$log_res_copy = json_decode( wp_json_encode( $log_res ), true ) ?: array();
		self::strip_sensitive_data( $log_req_copy );
		self::strip_sensitive_data( $log_res_copy );
		error_log( '[WPHubPro Bridge] log_action: ' . wp_json_encode( array(
			'action'   => $action,
			'endpoint' => $endpoint,
			'request'  => $log_req_copy,
			'response' => $log_res_copy,
		) ) );
		
		try {
			// Send log action to Platform.
			\WPHUBPRO\Api\ApiLogger::instance()->send_log_action( $action, $endpoint, $request, $response );
		} catch ( \Exception $e ) {
			error_log( '[WPHubPro Bridge] send_log_action failed: ' . $e->getMessage() );
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
		if ( !$route || strpos( $route, 'wphubpro/v1' ) === false || strpos( $route, '/logs' ) !== false ) {
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
			$res_data = is_array( $res_data ) ? $res_data : array();
		}
		self::strip_sensitive_data( $req_data );
		self::strip_sensitive_data( $res_data );
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

		$log = Config::get_log();
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 20 );
		update_option( Config::OPTION_LOG, $log );
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
}
