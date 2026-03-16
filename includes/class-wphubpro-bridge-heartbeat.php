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
class WPHubPro_Bridge_Heartbeat {

	const CRON_HOOK = 'wphubpro_bridge_heartbeat';
	const CRON_INTERVAL = 60; // seconds

	/**
	 * Register cron and send heartbeat.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'send_heartbeat' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );

		// Schedule on init if not already scheduled
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$site_id = get_option( 'WPHUBPRO_SITE_ID' );
			$secret  = get_option( 'WPHUBPRO_API_KEY' );
			if ( ! empty( $site_id ) && ! empty( $secret ) ) {
				wp_schedule_event( time(), 'wphubpro_minute', self::CRON_HOOK );
			}
		}
	}

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
	 * Send heartbeat to Appwrite.
	 *
	 * @return bool True on success, false on failure (logged).
	 */
	public static function send_heartbeat() {
		$site_id       = get_option( 'WPHUBPRO_SITE_ID' );
		$secret        = get_option( 'WPHUBPRO_API_KEY' );
		$endpoint      = get_option( 'WPHUBPRO_ENDPOINT' );
		$project       = get_option( 'WPHUBPRO_PROJECT_ID' );
		$heartbeat_url = get_option( 'WPHUBPRO_HEARTBEAT_URL', '' );

		if ( empty( $site_id ) || empty( $secret ) ) {
			WPHubPro_Bridge_Logger::log_action( get_site_url(), 'heartbeat', 'meta', array(), array(
				'skipped'      => 'Missing site_id or secret',
				'has_site_id'  => ! empty( $site_id ),
			) );
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return false;
		}

		$payload = array(
			'siteId'  => $site_id,
			'site_id' => $site_id,
			'secret'  => $secret,
		);

		// Prefer function domain when configured
		if ( ! empty( $heartbeat_url ) ) {
			$url = untrailingslashit( $heartbeat_url );
			$response = wp_remote_post(
				$url,
				array(
					'headers' => array(
						'Content-Type' => 'application/json',
					),
					'body'    => wp_json_encode( $payload ),
					'timeout' => 15,
				)
			);
		} else {
			// Fallback: executions API
			if ( empty( $endpoint ) || empty( $project ) ) {
				WPHubPro_Bridge_Logger::log_action( get_site_url(), 'heartbeat', 'meta', array(), array( 'skipped' => 'Missing endpoint or project_id for executions API' ) );
				return false;
			}
			$url = untrailingslashit( $endpoint ) . '/functions/site-heartbeat/executions';
			$request_body = wp_json_encode( array(
				'body'    => wp_json_encode( $payload ),
				'method'  => 'POST',
				'headers' => array(
					'Content-Type' => 'application/json',
				),
			) );
			$response = wp_remote_post(
				$url,
				array(
					'headers' => array(
						'Content-Type'       => 'application/json',
						'X-Appwrite-Project' => $project,
					),
					'body'    => $request_body,
					'timeout' => 15,
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body_response = wp_remote_retrieve_body( $response );

		if ( is_wp_error( $response ) ) {
			update_option( 'wphub_status', 'disconnected' );
			WPHubPro_Bridge_Logger::log_action( get_site_url(), 'heartbeat', 'meta', array(), array( 'error' => $response->get_error_message() ) );
			return false;
		}

		if ( $code < 200 || $code >= 300 ) {
			update_option( 'wphub_status', 'disconnected' );
			WPHubPro_Bridge_Logger::log_action( get_site_url(), 'heartbeat', 'meta', array(), array( 'error' => 'HTTP ' . $code, 'body' => substr( $body_response, 0, 200 ), 'site_id' => $site_id ) );
			return false;
		}

		update_option( 'WPHUBPRO_LAST_HEARTBEAT_AT', current_time( 'c' ) );
		update_option( 'wphub_status', 'connected' );
		WPHubPro_Bridge_Logger::log_action( get_site_url(), 'heartbeat', 'meta', array(), array( 'success' => true, 'site_id' => $site_id ) );
		return true;
	}

	/**
	 * Schedule heartbeat (call after save-connection).
	 * Sends first heartbeat immediately, then schedules cron every minute.
	 */
	public static function schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$site_id = get_option( 'WPHUBPRO_SITE_ID' );
		$secret  = get_option( 'WPHUBPRO_API_KEY' );
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
