<?php
/**
 * WP-Cron job: send bridge heartbeat to the platform.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduling metadata + tick; delegates work to {@see WPHubPro_Bridge_Heartbeat::send_heartbeat()}.
 */
class WPHubPro_Bridge_Cron_Job_Heartbeat implements WPHubPro_Bridge_Cron_Job_Interface {

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
		$site_id = WPHubPro_Bridge_Config::get_site_id();
		$secret  = WPHubPro_Bridge_Config::get_site_secret();
		return ! empty( $site_id ) && ! empty( $secret );
	}

	public static function run(): void {
		WPHubPro_Bridge_Heartbeat::send_heartbeat();
	}
}
