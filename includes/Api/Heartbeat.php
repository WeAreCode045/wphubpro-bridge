<?php
namespace WPHubPro\Api;

use WPHubPro\Config;
use WPHubPro\Cron\Job\Heartbeat as CronHeartbeatJob;
use WPHubPro\Cron\Scheduler;
use WPHubPro\Logger;

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
 * Domain logic for heartbeat (HTTP + options). Cron wiring: {@see Scheduler} and {@see CronHeartbeatJob}.
 */
class Heartbeat extends ApiBase {

	private static $instance = null;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Legacy entry point: delegates to {@see Scheduler::init()}.
	 */
	public static function init() {
		Scheduler::init();
	}

	/**
	 * Send heartbeat to Appwrite site-heartbeat function.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function send_heartbeat(): bool {
		try {
			self::instance()->post( 'site-heartbeat');
		} catch ( \Exception $e ) {
			Logger::log_action( 'heartbeat', 'error', array(), array(
				'msg' => $e->getMessage(),
			) );
			return false;
		}

		update_option( Config::OPTION_LAST_HEARTBEAT_AT, (string) current_time( 'c' ) );
		update_option( Config::OPTION_STATUS, 'connected' );
		return true;
	}

	/**
	 * Schedule heartbeat (call after save-connection).
	 */
	public static function schedule() {
		Scheduler::schedule_with_immediate_run( CronHeartbeatJob::class );
	}

	/**
	 * Unschedule heartbeat (call on disconnect).
	 */
	public static function unschedule() {
		Scheduler::unschedule( CronHeartbeatJob::class );
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
