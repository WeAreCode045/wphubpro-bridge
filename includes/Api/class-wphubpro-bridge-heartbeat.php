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
 * Domain logic for heartbeat (HTTP + options). Cron wiring: {@see WPHubPro_Bridge_Cron} and {@see WPHubPro_Bridge_Cron_Job_Heartbeat}.
 */
class WPHubPro_Bridge_Heartbeat extends WPHubPro_Bridge_API {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Legacy entry point: delegates to {@see WPHubPro_Bridge_Cron::init()}.
	 */
	public static function init() {
		WPHubPro_Bridge_Cron::init();
	}

	/**
	 * Send heartbeat to Appwrite site-heartbeat function.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function send_heartbeat() {
		try {
			self::instance()->post( 'site-heartbeat', array( 'site_id' => WPHubPro_Bridge_Config::get_site_id(), 'secret' => WPHubPro_Bridge_Config::get_site_secret() ) );
		} catch ( Exception $e ) {
			WPHubPro_Bridge_Logger::log_action( 'heartbeat', 'error', array(), array(
				'msg' => $e->getMessage(),
			) );
			return false;
		}

		update_option( WPHubPro_Bridge_Config::OPTION_LAST_HEARTBEAT_AT, current_time( 'c' ) );
		update_option( WPHubPro_Bridge_Config::OPTION_STATUS, 'connected' );
		return true;
	}

	/**
	 * Schedule heartbeat (call after save-connection).
	 */
	public static function schedule() {
		WPHubPro_Bridge_Cron::schedule_with_immediate_run( 'WPHubPro_Bridge_Cron_Job_Heartbeat' );
	}

	/**
	 * Unschedule heartbeat (call on disconnect).
	 */
	public static function unschedule() {
		WPHubPro_Bridge_Cron::unschedule( 'WPHubPro_Bridge_Cron_Job_Heartbeat' );
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
