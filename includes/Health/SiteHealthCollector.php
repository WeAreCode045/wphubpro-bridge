<?php
namespace WPHubPro\Health;

use WPHubPro\Logger;

/**
 * Runs WP_Site_Health direct tests and async placeholders into one module payload.
 *
 * @package WPHubPro\Health
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteHealthCollector {

	/**
	 * @return array{id:string,label:string,description:string,source:string,checks:array<int,array>}
	 */
	public static function build_wordpress_module(): array {
		self::bootstrap_dependencies();
		$site_health = \WP_Site_Health::get_instance();
		$tests       = self::get_filtered_tests();

		return self::assemble_module( $site_health, $tests );
	}

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
	 * @param array{direct: array, async: array} $tests
	 * @return array{id:string,label:string,description:string,source:string,checks:array<int,array>}
	 */
	private static function assemble_module( \WP_Site_Health $site_health, array $tests ): array {
		$module = self::module_shell();
		$direct = self::collect_direct_checks( $site_health, $tests['direct'] ?? array() );
		$async  = self::collect_async_placeholders( $tests['async'] ?? array() );
		$module['checks'] = array_merge( $direct, $async );

		return $module;
	}

	/**
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
	 * @return array<int, array>
	 */
	private static function collect_async_placeholders( array $async_tests ): array {
		$checks = array();
		foreach ( $async_tests as $test_key => $test_def ) {
			$checks[] = self::async_placeholder_check( $test_key, $test_def );
		}

		return $checks;
	}

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
			__( 'This check runs asynchronously in wp-admin only; result not available via REST.', 'wphubpro-bridge' ),
			array( 'wp_status' => 'pending' )
		);
	}

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
