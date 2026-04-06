<?php
namespace WPHubPro\Health;

use WPHubPro\Logger;

/**
 * Runs WP_Site_Health direct tests and resolves async tests via get_test_* when callable.
 *
 * @package WPHubPro\Health
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteHealthCollector {

	/**
	 * Build the WordPress module from the site health tests.
	 * @return array{id:string,label:string,description:string,source:string,checks:array<int,array>}
	 */
	public static function build_wordpress_module(): array {
		self::bootstrap_dependencies();
		$site_health = \WP_Site_Health::get_instance();
		$tests       = self::get_filtered_tests();

		return self::assemble_module( $site_health, $tests );
	}

	/**
	 * Bootstrap the dependencies for the site health collector.
	 * @return void
	 */
	private static function bootstrap_dependencies(): void {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}

	/**
	 * Get the filtered site health tests.
	 * @return array{direct: array, async: array}
	 */
	private static function get_filtered_tests(): array {
		$tests = \WP_Site_Health::get_tests();
		if ( 'localhost' === preg_replace( '|https?://|', '', get_site_url() ) ) {
			unset( $tests['direct']['https_status'] );
		}

		return $tests;
	}

	/**
	 * Assemble the WordPress module from the site health tests.
	 * @param array{direct: array, async: array} $tests
	 * @return array{id:string,label:string,description:string,source:string,checks:array<int,array>}
	 */
	private static function assemble_module( \WP_Site_Health $site_health, array $tests ): array {
		$module = self::module_shell();
		$direct = self::collect_direct_checks( $site_health, $tests['direct'] ?? array() );
		$async  = self::collect_async_checks( $site_health, $tests['async'] ?? array() );
		$module['checks'] = array_merge( $direct, $async );

		return $module;
	}

	/**
	 * Create a shell for the WordPress module.
	 * @return array{id:string,label:string,description:string,source:string,checks:array}
	 */
	private static function module_shell(): array {
		$id = HealthSnapshot::MODULE_WORDPRESS_SITE_HEALTH;

		return array(
			'id'          => $id,
			'label'       => __( 'WordPress Site Health', 'wphubpro-bridge' ),
			'description' => __( 'Core Site Health checks from WP_Site_Health.', 'wphubpro-bridge' ),
			'source'      => 'wp_site_health',
			'checks'      => array(),
		);
	}

	/**
	 * Collect direct checks from the site health tests.
	 * @return array<int, array>
	 */
	private static function collect_direct_checks( \WP_Site_Health $site_health, array $direct_tests ): array {
		$checks = array();
		foreach ( $direct_tests as $test_key => $test_def ) {
			if ( empty( $test_def['test'] ) || ! is_string( $test_def['test'] ) ) {
				continue;
			}
			$checks[] = self::resolve_direct_check( $site_health, $test_key, $test_def );
		}

		return $checks;
	}

	/**
	 * Run async-listed Site Health tests synchronously when get_test_* exists; else placeholder.
	 *
	 * @return array<int, array>
	 */
	private static function collect_async_checks( \WP_Site_Health $site_health, array $async_tests ): array {
		$checks = array();
		foreach ( $async_tests as $test_key => $test_def ) {
			if ( empty( $test_def['test'] ) || ! is_string( $test_def['test'] ) ) {
				continue;
			}
			$checks[] = self::resolve_async_check( $site_health, $test_key, $test_def );
		}

		return $checks;
	}

	/**
	 * Create an async placeholder check for a test that runs asynchronously.
	 * @return array<int, array>
	 */
	private static function async_placeholder_check( string $test_key, array $test_def ): array {
		$mid   = HealthSnapshot::MODULE_WORDPRESS_SITE_HEALTH;
		$slug  = isset( $test_def['test'] ) && is_string( $test_def['test'] ) ? $test_def['test'] : $test_key;
		$label = is_string( $test_def['label'] ?? null ) ? $test_def['label'] : $test_key;

		return HealthCheckNormalizer::make_item(
			$mid,
			$test_key,
			$slug,
			'async_pending',
			$label,
			'pending',
			null,
			__( 'No synchronous handler for this check; open Site Health in wp-admin to run it.', 'wphubpro-bridge' ),
			array( 'wp_status' => 'pending' )
		);
	}

	/**
	 * Resolve an async-listed check: call get_test_* like direct tests, or placeholder / failure row.
	 *
	 * @return array<string, mixed>
	 */
	private static function resolve_async_check(
		\WP_Site_Health $site_health,
		string $test_key,
		array $test_def
	): array {
		$method   = 'get_test_' . $test_def['test'];
		$callable = array( $site_health, $method );
		if ( ! is_callable( $callable ) ) {
			self::log_uncallable_test( $test_key, $test_def['test'] );

			return self::async_placeholder_check( $test_key, $test_def );
		}

		$mid = HealthSnapshot::MODULE_WORDPRESS_SITE_HEALTH;

		try {
			$raw = call_user_func( $callable );

			return HealthCheckNormalizer::normalize_wp_result( $mid, $test_key, $test_def, $raw, 'async_sync' );
		} catch ( \Throwable $e ) {
			return self::async_check_failed_row( $test_key, $test_def, $e );
		}
	}

	/**
	 * Hub row when an async-listed test throws during synchronous execution.
	 *
	 * @return array<string, mixed>
	 */
	private static function async_check_failed_row( string $test_key, array $test_def, \Throwable $e ): array {
		$mid   = HealthSnapshot::MODULE_WORDPRESS_SITE_HEALTH;
		$slug  = isset( $test_def['test'] ) && is_string( $test_def['test'] ) ? $test_def['test'] : $test_key;
		$label = is_string( $test_def['label'] ?? null ) ? $test_def['label'] : $test_key;

		Logger::log_action(
			'health',
			'site_health_async_test',
			array(
				'test_key'  => $test_key,
				'test_slug' => $slug,
			),
			array(
				'level'   => 'warning',
				'msg'     => 'Async Site Health test threw during sync run',
				'error'   => $e->getMessage(),
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
			)
		);

		return HealthCheckNormalizer::make_item(
			$mid,
			$test_key,
			$slug,
			'async_failed',
			$label,
			'unknown',
			null,
			__( 'Check failed when run outside the Site Health screen.', 'wphubpro-bridge' ),
			array(
				'wp_status' => null,
				'error'     => 'sync_run_exception',
			)
		);
	}

	/**
	 * Resolve a direct check from the site health tests.
	 * @return array<int, array>
	 */
	private static function resolve_direct_check(
		\WP_Site_Health $site_health,
		string $test_key,
		array $test_def
	): array {
		$method   = 'get_test_' . $test_def['test'];
		$callable = array( $site_health, $method );
		if ( ! is_callable( $callable ) ) {
			self::log_uncallable_test( $test_key, $test_def['test'] );

			return self::missing_handler_check( $test_key, $test_def );
		}

		$raw = call_user_func( $callable );
		$mid = HealthSnapshot::MODULE_WORDPRESS_SITE_HEALTH;

		return HealthCheckNormalizer::normalize_wp_result( $mid, $test_key, $test_def, $raw, 'sync' );
	}

	/**
	 * Log a warning when a site health test is not callable.
	 * @return void
	 */
	private static function log_uncallable_test( string $test_key, string $test_slug ): void {
		Logger::log_action(
			'health',
			'site_health_test',
			array(),
			array(
				'level'     => 'warning',
				'msg'       => 'Site Health test not callable',
				'test_key'  => $test_key,
				'test_slug' => $test_slug,
			)
		);
	}

	/**
	 * Create a missing handler check for a test that has no handler.
	 * @return array<int, array>
	 */
	private static function missing_handler_check( string $test_key, array $test_def ): array {
		$mid   = HealthSnapshot::MODULE_WORDPRESS_SITE_HEALTH;
		$label = is_string( $test_def['label'] ?? null ) ? $test_def['label'] : $test_key;

		return HealthCheckNormalizer::make_item(
			$mid,
			$test_key,
			$test_def['test'],
			'sync',
			$label,
			'unknown',
			null,
			__( 'Check could not be run (missing handler).', 'wphubpro-bridge' ),
			array( 'wp_status' => null )
		);
	}
}
