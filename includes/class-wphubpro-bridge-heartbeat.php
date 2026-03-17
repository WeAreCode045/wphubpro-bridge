<?php
/**
 * Heartbeat: Bridge sends heartbeat to Appwrite every minute.
 * Updates sites.heartbeat_updated_at and bridge_status. On success: wphub_status=connected. On failure: wphub_status=disconnected.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends heartbeat to Appwrite site-heartbeat function.
 */
class WPHubPro_Bridge_Heartbea extends WPHubPro_Bridge_API {


		/**
	 * Add 1-minute cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_cron_interval( $schedules ) {
		$schedules['wphubpro_minute'] = array(
			'interval' => self::CRON_INTERVAL,
			'display'   => __( 'Every minute', 'wphubpro-bridge' ),
		);
		return $schedules;
	}

	/**
	 * Send heartbeat to Appwrite site-heartbeat function.
	 *
	 * @return bool True on success, false on failure.
	 * @throws Exception If the heartbeat fails.
	 */
	public static function send_heartbeat() {
		
		
        try {
            $response = parent::post('/heartbeat');
        } catch (Exception $e) {
            WPHubPro_Bridge_Logger::log_action(get_site_url(), 'heartbeat', 'error', array(), array(
                'msg' => $e->getMessage(),
            ));
            return false;
        }


		// Prefer function domain when configured
		
		
			// // Fallback: executions API
			// if ( empty( $endpoint ) || empty( $project ) ) {
			// 	WPHubPro_Bridge_Logger::log_action( get_site_url(), 'heartbeat', 'meta', array(), array( 'skipped' => 'Missing endpoint or project_id for executions API' ) );
			// 	return false;
			// }
			// $url = untrailingslashit( $endpoint ) . '/functions/site-heartbeat/executions';
			// $request_body = wp_json_encode( array(
			// 	'body'    => wp_json_encode( $payload ),
			// 	'method'  => 'POST',
			// 	'headers' => array(
			// 		'Content-Type' => 'application/json',
			// 	),
			// ) );
			// $response = wp_remote_post(
			// 	$url,
			// 	array(
			// 		'headers' => array(
			// 			'Content-Type'       => 'application/json',
			// 			'X-Appwrite-Project' => $project,
			// 		),
			// 		'body'    => $request_body,
			// 		'timeout' => self::$connection_timeout,
			// 	)
			// );
		

		if ( is_wp_error( $response ) ) {
			update_option( WPHubPro_Bridge_Config::OPTION_STATUS, 'disconnected' );
			WPHubPro_Bridge_Logger::log_action( 'heartbeat', 'meta', array(), array( 'error' => $response->get_error_message() ) );
			return false;
		}

		if ( $code < 200 || $code >= 300 ) {
			update_option( WPHubPro_Bridge_Config::OPTION_STATUS, 'disconnected' );
			WPHubPro_Bridge_Logger::log_action( 'heartbeat', 'meta', array(), array( 'error' => 'HTTP ' . $code, 'body' => substr( $body_response, 0, 200 ), 'site_id' => $site_id ) );
			return false;
		}

		update_option( WPHubPro_Bridge_Config::OPTION_LAST_HEARTBEAT_AT, current_time( 'c' ) );
		update_option( WPHubPro_Bridge_Config::OPTION_STATUS, 'connected' );
		WPHubPro_Bridge_Logger::log_action( 'heartbeat', 'meta', array(), array( 'success' => true, 'site_id' => $site_id ) );
		return true;
	}

	/**
	 * Schedule heartbeat (call after save-connection).
	 * Sends first heartbeat immediately, then schedules cron every minute.
	 */
	public static function schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$site_id = WPHubPro_Bridge_Config::get_site_id();
		$secret  = WPHubPro_Bridge_Config::get_api_key();
		if ( ! empty( $site_id ) && ! empty( $secret ) ) {
			self::send_heartbeat();
			wp_schedule_event( time(), 'wphubpro_minute', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule heartbeat (call on disconnect).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Handle heartbeat/poke REST request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function handle_poke( $request ) {
		return rest_ensure_response( array( 'success' => true, 'poked' => true ) );
	}
}
