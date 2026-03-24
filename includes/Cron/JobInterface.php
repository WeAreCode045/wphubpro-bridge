<?php
namespace WPHUBPRO\Cron;

/**
 * Contract for a recurring WP-Cron job (static job class).
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implement on a small class under includes/Cron/Job/; keep domain logic in feature classes.
 */
interface JobInterface {

	public static function get_hook_name(): string;

	public static function get_schedule_slug(): string;

	public static function get_interval_seconds(): int;

	public static function get_schedule_label(): string;

	public static function should_schedule(): bool;

	public static function run(): void;
}
