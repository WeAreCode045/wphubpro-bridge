<?php
namespace WPHUBPRO\Api;

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
 * Domain logic for heartbeat (HTTP + options). Cron wiring: {@see \WPHUBPRO\Cron\Scheduler} and {@see \WPHUBPRO\Cron\Job\Heartbeat}.
 */
class Heartbeat extends API {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Legacy entry point: delegates to {@see \WPHUBPRO\Cron\Scheduler::init()}.
	 */
	public static function init() {
		\WPHUBPRO\Cron\Scheduler::init();
	}

	/**
	 * Send heartbeat to Appwrite site-heartbeat function.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function send_heartbeat() {
		try {
			self::instance()->post( 'site-heartbeat');
		} catch ( \Exception $e ) {
			\WPHUBPRO\Logger::log_action( 'heartbeat', 'error', array(), array(
				'msg' => $e->getMessage(),
			) );
			return false;
		}

		update_option( \WPHUBPRO\Config::OPTION_LAST_HEARTBEAT_AT, current_time( 'c' ) );
		update_option( \WPHUBPRO\Config::OPTION_STATUS, 'connected' );
		return true;
	}

	/**
	 * Schedule heartbeat (call after save-connection).
	 */
	public static function schedule() {
		\WPHUBPRO\Cron\Scheduler::schedule_with_immediate_run( \WPHUBPRO\Cron\Job\Heartbeat::class );
	}

	/**
	 * Unschedule heartbeat (call on disconnect).
	 */
	public static function unschedule() {
		\WPHUBPRO\Cron\Scheduler::unschedule( \WPHUBPRO\Cron\Job\Heartbeat::class );
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
