<?php
namespace WPHubPro\Health;

/**
 * Versioned health snapshot envelope: summary, modules, checks_flat.
 *
 * @package WPHubPro\Health
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HealthSnapshot {

	/** Bump when the envelope or check item shape changes. */
	public const SCHEMA_VERSION = 1;

	/** Module id for WP_Site_Health-derived checks. */
	public const MODULE_WORDPRESS_SITE_HEALTH = 'wordpress_site_health';

	/**
	 * @return array{
	 *   schema_version: int,
	 *   collected_at: string,
	 *   collection_duration_ms: int,
	 *   summary: array,
	 *   modules: array<int, array>,
	 *   checks_flat: array<int, array>
	 * }
	 */
	public static function collect(): array {
		$t0 = microtime( true );
		$wp = SiteHealthCollector::build_wordpress_module();

		return self::finalize_snapshot( array( $wp ), $t0 );
	}

	/**
	 * Append an external module to the snapshot.
	 * @param array $payload From collect().
	 * @param array $module  External module: id, label, optional description/source, checks[].
	 * @return array
	 */
	public static function append_module( array $payload, array $module ): array {
		$normalized = self::normalize_external_module( $module );
		$modules    = isset( $payload['modules'] ) && is_array( $payload['modules'] ) ? $payload['modules'] : array();
		$modules[]  = $normalized;
		$payload['modules']        = $modules;
		$payload['summary']        = self::summarize_modules( $modules );
		$payload['checks_flat']    = self::flatten_checks( $modules );
		$payload['schema_version'] = max( (int) ( $payload['schema_version'] ?? 1 ), self::SCHEMA_VERSION );

		return $payload;
	}

	/**
	 * Finalize the snapshot by summarizing the modules and flattening the checks.
	 * @param array<int, array> $modules
	 * @return array
	 */
	private static function finalize_snapshot( array $modules, float $started_at ): array {
		$summary     = self::summarize_modules( $modules );
		$checks_flat = self::flatten_checks( $modules );

		return array(
			'schema_version'         => self::SCHEMA_VERSION,
			'collected_at'           => gmdate( 'c' ),
			'collection_duration_ms' => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			'summary'                => $summary,
			'modules'                => $modules,
			'checks_flat'            => $checks_flat,
		);
	}

	/**
	 * Summarize the overall health and counts of checks across all modules.
	 * @param array<int, array{checks?:array}> $modules
	 * @return array{overall:string,counts:array<string,int>,total_checks:int}
	 */
	private static function summarize_modules( array $modules ): array {
		$counts = self::count_check_severities( $modules );
		$total  = array_sum( $counts );

		return array(
			'overall'      => self::derive_overall_health( $counts, $total ),
			'counts'       => $counts,
			'total_checks' => $total,
		);
	}

	/**
	 * Count the number of checks by severity.
	 * @param array<int, array{checks?:array}> $modules
	 * @return array<string, int>
	 */
	private static function count_check_severities( array $modules ): array {
		$counts = array(
			'ok'       => 0,
			'warning'  => 0,
			'critical' => 0,
			'pending'  => 0,
			'unknown'  => 0,
		);

		foreach ( $modules as $module ) {
			if ( empty( $module['checks'] ) || ! is_array( $module['checks'] ) ) {
				continue;
			}
			foreach ( $module['checks'] as $check ) {
				$sev = isset( $check['severity'] ) && is_string( $check['severity'] ) ? $check['severity'] : 'unknown';
				if ( ! isset( $counts[ $sev ] ) ) {
					$counts['unknown']++;
					continue;
				}
				$counts[ $sev ]++;
			}
		}

		return $counts;
	}

	/**
	 * @param array<string, int> $counts
	 */
	private static function derive_overall_health( array $counts, int $total ): string {
		if ( $counts['critical'] > 0 ) {
			return 'critical';
		}
		if ( $counts['warning'] > 0 ) {
			return 'warning';
		}
		if ( $counts['unknown'] > 0 ) {
			return 'unknown';
		}
		if ( $counts['pending'] > 0 && $counts['ok'] + $counts['pending'] === $total ) {
			return 'pending';
		}

		return 'ok';
	}

	/**
	 * @param array<int, array> $modules
	 * @return array<int, array>
	 */
	private static function flatten_checks( array $modules ): array {
		$flat = array();
		foreach ( $modules as $module ) {
			if ( empty( $module['checks'] ) || ! is_array( $module['checks'] ) ) {
				continue;
			}
			foreach ( $module['checks'] as $check ) {
				if ( is_array( $check ) ) {
					$flat[] = $check;
				}
			}
		}

		return $flat;
	}

	/**
	 * Normalize an external module into the snapshot envelope format.
	 * @param array $module Raw module from integration code.
	 * @return array{id:string,label:string,description?:string,source?:string,checks:array<int,array>}
	 */
	private static function normalize_external_module( array $module ): array {
		$id  = isset( $module['id'] ) && is_string( $module['id'] ) ? $module['id'] : 'custom';
		$out = self::external_module_shell( $id, $module );
		if ( empty( $module['checks'] ) || ! is_array( $module['checks'] ) ) {
			return $out;
		}
		foreach ( $module['checks'] as $idx => $check ) {
			$row = self::normalize_external_check_row( $id, $idx, $check );
			if ( $row !== null ) {
				$out['checks'][] = $row;
			}
		}

		return $out;
	}

	/**
	 * Create a shell for an external module.
	 * @return array{id:string,label:string,description?:string,source?:string,checks:array}
	 */
	private static function external_module_shell( string $id, array $module ): array {
		$out = array(
			'id'     => $id,
			'label'  => isset( $module['label'] ) && is_string( $module['label'] ) ? $module['label'] : $id,
			'checks' => array(),
		);
		if ( isset( $module['description'] ) && is_string( $module['description'] ) ) {
			$out['description'] = $module['description'];
		}
		if ( isset( $module['source'] ) && is_string( $module['source'] ) ) {
			$out['source'] = $module['source'];
		}

		return $out;
	}

	/**
	 * Normalize an external check row into the snapshot envelope format.
	 * @param mixed $check
	 * @return array<string, mixed>|null
	 */
	private static function normalize_external_check_row( string $module_id, $idx, $check ): ?array {
		if ( ! is_array( $check ) ) {
			return null;
		}
		$slug = isset( $check['slug'] ) && is_string( $check['slug'] ) ? $check['slug'] : 'check_' . (string) $idx;
		$meta = isset( $check['meta'] ) && is_array( $check['meta'] ) ? $check['meta'] : array();

		return array(
			'id'        => isset( $check['id'] ) && is_string( $check['id'] ) ? $check['id'] : $module_id . '.' . $slug,
			'module_id' => $module_id,
			'slug'      => $slug,
			'execution' => isset( $check['execution'] ) && is_string( $check['execution'] ) ? $check['execution'] : 'sync',
			'label'     => isset( $check['label'] ) && is_string( $check['label'] ) ? $check['label'] : $slug,
			'severity'  => isset( $check['severity'] ) && is_string( $check['severity'] ) ? $check['severity'] : 'unknown',
			'category'  => isset( $check['category'] ) && is_string( $check['category'] ) ? $check['category'] : null,
			'message'   => isset( $check['message'] ) && is_string( $check['message'] ) ? $check['message'] : '',
			'meta'      => $meta,
		);
	}
}
