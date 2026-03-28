<?php
namespace WPHubPro\Cron\Job;

use WPHubPro\Api\Heartbeat as ApiHeartbeat;
use WPHubPro\Config;
use WPHubPro\Cron\JobInterface;
use WPHubPro\Cron\Scheduler;
/**
 * WP-Cron job: send bridge heartbeat to the platform.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduling metadata + tick; delegates work to {@see ApiHeartbeat::send_heartbeat()}.
 */
class Heartbeat implements JobInterface {

	public static function get_hook_name(): string {
		return 'wphubpro_bridge_heartbeat';
	}

	public static function get_schedule_slug(): string {
		return Scheduler::JOB_SLUG_MINUTE;
	}

	public static function get_interval_seconds(): int {
		return Scheduler::JOB_INTERVAL_MINUTE;
	}

	public static function get_schedule_label(): string {
		return __( 'Every minute', 'wphubpro-bridge' );
	}

	public static function should_schedule(): bool {
		$site_id = Config::get_site_id();
		$secret  = Config::get_site_secret();
		return ! empty( $site_id ) && ! empty( $secret );
	}

	public static function run(): void {
		ApiHeartbeat::send_heartbeat();
	}
}
