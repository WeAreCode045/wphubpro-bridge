<?php
namespace WPHUBPRO\Cron\Job;

use WPHUBPRO\Cron\JobInterface;

/**
 * WP-Cron job: send bridge heartbeat to the platform.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduling metadata + tick; delegates work to {@see \WPHUBPRO\Api\Heartbeat::send_heartbeat()}.
 */
class Heartbeat implements JobInterface {

	public static function get_hook_name(): string {
		return 'wphubpro_bridge_heartbeat';
	}

	public static function get_schedule_slug(): string {
		return 'wphubpro_minute';
	}

	public static function get_interval_seconds(): int {
		return 60;
	}

	public static function get_schedule_label(): string {
		return __( 'Every minute', 'wphubpro-bridge' );
	}

	public static function should_schedule(): bool {
		$site_id = \WPHUBPRO\Config::get_site_id();
		$secret  = \WPHUBPRO\Config::get_site_secret();
		return ! empty( $site_id ) && ! empty( $secret );
	}

	public static function run(): void {
		\WPHUBPRO\Api\Heartbeat::send_heartbeat();
	}
}
