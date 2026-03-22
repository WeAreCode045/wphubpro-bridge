<?php
/**
 * Registers cron job classes and wires WordPress pseudo-cron.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central scheduler for {@see WPHubPro_Bridge_Cron_Job_Interface} implementations.
 */
class WPHubPro_Bridge_Cron {

	/**
	 * @var string[]
	 */
	private static $jobs = array();

	/** @var bool */
	private static $bootstrapped = false;

	/** @var bool */
	private static $init_done = false;

	/**
	 * Load default jobs, apply filter, register, and bootstrap hooks.
	 *
	 * Idempotent.
	 */
	public static function init() {
		if ( self::$init_done ) {
			return;
		}
		self::$init_done = true;

		$default_jobs = array(
			'WPHubPro_Bridge_Cron_Job_Heartbeat',
		);

		/**
		 * Add or reorder cron job classes (must implement {@see WPHubPro_Bridge_Cron_Job_Interface}).
		 *
		 * @param string[] $job_classes Fully-qualified class names.
		 */
		$jobs = apply_filters( 'wphubpro_bridge_cron_jobs', $default_jobs );

		foreach ( $jobs as $class ) {
			// Legacy: heartbeat job lived on WPHubPro_Bridge_Heartbeat before Cron/Jobs split.
			if ( 'WPHubPro_Bridge_Heartbeat' === $class ) {
				$class = 'WPHubPro_Bridge_Cron_Job_Heartbeat';
			}
			self::register( $class );
		}

		self::bootstrap();
	}

	/**
	 * @param string $job_class Class name.
	 */
	public static function register( $job_class ) {
		if ( ! self::is_valid_job( $job_class ) ) {
			return;
		}
		if ( in_array( $job_class, self::$jobs, true ) ) {
			return;
		}
		self::$jobs[] = $job_class;
	}

	public static function bootstrap() {
		if ( self::$bootstrapped ) {
			self::maybe_schedule_all();
			return;
		}
		self::$bootstrapped = true;

		add_filter( 'cron_schedules', array( __CLASS__, 'merge_schedules' ) );

		foreach ( self::$jobs as $job_class ) {
			add_action( $job_class::get_hook_name(), array( $job_class, 'run' ) );
		}

		self::maybe_schedule_all();
	}

	/**
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function merge_schedules( $schedules ) {
		foreach ( self::$jobs as $job_class ) {
			$slug = $job_class::get_schedule_slug();
			if ( isset( $schedules[ $slug ] ) ) {
				continue;
			}
			$schedules[ $slug ] = array(
				'interval' => $job_class::get_interval_seconds(),
				'display'  => $job_class::get_schedule_label(),
			);
		}
		return $schedules;
	}

	public static function maybe_schedule_all() {
		foreach ( self::$jobs as $job_class ) {
			if ( wp_next_scheduled( $job_class::get_hook_name() ) ) {
				continue;
			}
			if ( ! $job_class::should_schedule() ) {
				continue;
			}
			wp_schedule_event( time(), $job_class::get_schedule_slug(), $job_class::get_hook_name() );
		}
	}

	/**
	 * @param string $job_class Class name.
	 */
	public static function schedule_with_immediate_run( $job_class ) {
		if ( ! self::is_valid_job( $job_class ) ) {
			return;
		}
		wp_clear_scheduled_hook( $job_class::get_hook_name() );
		if ( ! $job_class::should_schedule() ) {
			return;
		}
		$job_class::run();
		wp_schedule_event( time(), $job_class::get_schedule_slug(), $job_class::get_hook_name() );
	}

	/**
	 * @param string $job_class Class name.
	 */
	public static function unschedule( $job_class ) {
		if ( ! self::is_valid_job( $job_class ) ) {
			return;
		}
		wp_clear_scheduled_hook( $job_class::get_hook_name() );
	}

	/**
	 * @param string $job_class Class name.
	 * @return bool
	 */
	private static function is_valid_job( $job_class ) {
		return is_string( $job_class )
			&& class_exists( $job_class )
			&& in_array( WPHubPro_Bridge_Cron_Job_Interface::class, class_implements( $job_class ), true );
	}
}
