<?php
namespace WPHubPro\Health;

/**
 * Normalizes WordPress Site Health test output into hub check rows.
 *
 * @package WPHubPro\Health
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HealthCheckNormalizer {

	/**
	 * @return array{id:string,slug:string,execution:string,label:string,severity:string,category:?string,message:string,meta:array}
	 */
	public static function make_item(
		string $module_id,
		string $test_key,
		string $slug,
		string $execution,
		string $label,
		string $severity,
		?string $category,
		string $message,
		array $meta
	): array {
		return array(
			'id'         => $module_id . '.' . $test_key,
			'module_id'  => $module_id,
			'slug'       => $slug,
			'execution'  => $execution,
			'label'      => $label,
			'severity'   => $severity,
			'category'   => $category,
			'message'    => $message,
			'meta'       => $meta,
		);
	}

	/**
	 * @param mixed $raw Result from WP_Site_Health::get_test_*().
	 */
	public static function normalize_wp_result(
		string $module_id,
		string $test_key,
		array $test_def,
		$raw,
		string $execution
	): array {
		$slug  = isset( $test_def['test'] ) && is_string( $test_def['test'] ) ? $test_def['test'] : $test_key;
		$label = isset( $test_def['label'] ) && is_string( $test_def['label'] ) ? $test_def['label'] : $test_key;

		if ( ! is_array( $raw ) ) {
			return self::unexpected_response_item( $module_id, $test_key, $slug, $execution, $label );
		}

		$wp_status = isset( $raw['status'] ) && is_string( $raw['status'] ) ? $raw['status'] : '';
		$severity  = self::map_wp_status_to_severity( $wp_status );
		$message   = self::description_to_plain_text( isset( $raw['description'] ) ? $raw['description'] : '' );
		$res_label = isset( $raw['label'] ) && is_string( $raw['label'] ) && $raw['label'] !== '' ? $raw['label'] : $label;

		return self::make_item(
			$module_id,
			$test_key,
			$slug,
			$execution,
			$res_label,
			$severity,
			self::badge_category( $raw ),
			$message,
			self::result_meta( $raw, $wp_status )
		);
	}

	public static function unexpected_response_item(
		string $module_id,
		string $test_key,
		string $slug,
		string $execution,
		string $label
	): array {
		return self::make_item(
			$module_id,
			$test_key,
			$slug,
			$execution,
			$label,
			'unknown',
			null,
			__( 'Unexpected response from Site Health.', 'wphubpro-bridge' ),
			array( 'wp_status' => null )
		);
	}

	public static function map_wp_status_to_severity( string $wp_status ): string {
		switch ( $wp_status ) {
			case 'good':
				return 'ok';
			case 'recommended':
				return 'warning';
			case 'critical':
				return 'critical';
			case 'skipped':
				return 'pending';
			default:
				return 'unknown';
		}
	}

	public static function description_to_plain_text( $html ): string {
		if ( ! is_string( $html ) || $html === '' ) {
			return '';
		}
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return is_string( $text ) ? trim( $text ) : '';
	}

	private static function badge_category( array $raw ): ?string {
		if ( ! isset( $raw['badge'] ) || ! is_array( $raw['badge'] ) || ! isset( $raw['badge']['label'] ) ) {
			return null;
		}

		return is_string( $raw['badge']['label'] ) ? $raw['badge']['label'] : null;
	}

	private static function result_meta( array $raw, string $wp_status ): array {
		$color = null;
		if ( isset( $raw['badge']['color'] ) && is_string( $raw['badge']['color'] ) ) {
			$color = $raw['badge']['color'];
		}

		return array_filter(
			array(
				'wp_status'   => $wp_status !== '' ? $wp_status : null,
				'badge_color' => $color,
			)
		);
	}
}
