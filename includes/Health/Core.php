<?php
namespace WPHubPro\Health;

/**
 * Core site/runtime facts for WPHubPro Bridge (versions, PHP ini, disk).
 *
 * Hub-facing Site Health snapshots live in {@see HealthSnapshot} and {@see SiteHealthCollector}.
 *
 * @package WPHubPro\Health
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core {

	/**
	 * Get site details as array (for sync to Hub wp_meta).
	 *
	 * @return array
	 */
	public static function get_info(): array {
		$server = $_SERVER['SERVER_SOFTWARE'] ?? null;

		return array(
			'wp_version'          => (string) get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'server_software'     => is_string( $server ) ? $server : null,
			'memory_limit'        => (string) ini_get( 'memory_limit' ),
			'max_execution_time'  => (string) ini_get( 'max_execution_time' ),
			'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
			'post_max_size'       => (string) ini_get( 'post_max_size' ),
		);
	}

	/**
	 * @see HealthSnapshot::collect()
	 *
	 * @return array
	 */
	public static function get_health_status() {
		return HealthSnapshot::collect();
	}

	/**
	 * @see HealthSnapshot::append_module()
	 *
	 * @param array $payload From get_health_status().
	 * @param array $module  External module definition.
	 * @return array
	 */
	public static function append_health_module( array $payload, array $module ) {
		return HealthSnapshot::append_module( $payload, $module );
	}

	public static function get_wp_version(): string {
		return (string) get_bloginfo( 'version' );
	}

	public static function get_php_version(): string {
		return PHP_VERSION;
	}

	public static function get_server_software(): ?string {
		$s = $_SERVER['SERVER_SOFTWARE'] ?? null;

		return is_string( $s ) ? $s : null;
	}

	public static function get_memory_limit(): string {
		return (string) ini_get( 'memory_limit' );
	}

	public static function get_max_execution_time(): string {
		return (string) ini_get( 'max_execution_time' );
	}

	public static function get_upload_max_filesize(): string {
		return (string) ini_get( 'upload_max_filesize' );
	}

	public static function get_post_max_size(): string {
		return (string) ini_get( 'post_max_size' );
	}

	public static function get_disk_status(): array {
		$total = disk_total_space( ABSPATH );
		$free  = disk_free_space( ABSPATH );

		return array(
			'total' => false !== $total ? (int) $total : false,
			'free'  => false !== $free ? (int) $free : false,
		);
	}

	private static function tail_debug_log( string $file, int $lines = 5, int $max_bytes = 8192 ): ?array {
		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			return null;
		}

		$size = filesize( $file );
		if ( ! is_numeric( $size ) || $size <= 0 ) {
			return null;
		}
		$read = min( $max_bytes, $size );
		$fp   = @fopen( $file, 'rb' );
		if ( ! $fp ) {
			return null;
		}

		@fseek( $fp, -$read, SEEK_END );
		$chunk = @fread( $fp, $read );
		@fclose( $fp );

		if ( ! is_string( $chunk ) || $chunk === '' ) {
			return null;
		}

		$chunk = str_replace( array( "\r\n", "\r" ), "\n", $chunk );
		$parts = array_values( array_filter( explode( "\n", $chunk ), 'strlen' ) );
		$tail  = array_slice( $parts, -$lines );

		return array(
			'file'  => $file,
			'lines' => $tail,
		);
	}

	private static function client_ip(): string {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && is_string( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && is_string( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );

			return trim( (string) ( $parts[0] ?? '' ) );
		}

		$ra = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

		return is_string( $ra ) ? $ra : 'unknown';
	}

	/**
	 * Get number of installed plugins.
	 *
	 * @return int
	 */
	private function get_plugins_count(): int {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();

		return is_array( $all ) ? (int) count( $all ) : 0;
	}

	/**
	 * Get number of installed themes.
	 *
	 * @return int
	 */
	private function get_themes_count(): int {
		$all = wp_get_themes();

		return is_array( $all ) ? (int) count( $all ) : 0;
	}
}
