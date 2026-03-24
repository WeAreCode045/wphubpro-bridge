<?php
namespace WPHUBPRO\Plugin;

/**
 * REST argument definitions and plugin/slug parsing for plugin management.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse plugin parameters from REST requests and REST route args.
 */
class Params {

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function rest_base_args() {
		return array(
			'plugin' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'slug'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function rest_update_args() {
		return array_merge( self::rest_base_args(), array(
			'zip_url' => array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			'zip_base64' => array(
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) {
					return is_string( $v ) ? $v : '';
				},
			),
		) );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function rest_version_args() {
		return array_merge( self::rest_base_args(), array(
			'version' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		) );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function rest_zip_args() {
		return self::rest_update_args();
	}

	/**
	 * Parse plugin and slug from request (query, body param, or raw body).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array{plugin: string, slug: string}
	 */
	public static function parse_from_request( $request ) {
		$plugin = $request->get_param( 'plugin' );
		$slug   = $request->get_param( 'slug' );
		if ( empty( $plugin ) || empty( $slug ) ) {
			$body_raw = $request->get_param( 'body' );
			if ( is_string( $body_raw ) ) {
				$decoded = json_decode( $body_raw, true );
				if ( is_array( $decoded ) ) {
					if ( empty( $plugin ) && ! empty( $decoded['plugin'] ) ) {
						$plugin = sanitize_text_field( $decoded['plugin'] );
					}
					if ( empty( $slug ) && ! empty( $decoded['slug'] ) ) {
						$slug = sanitize_text_field( $decoded['slug'] );
					}
				}
			}
			if ( ( empty( $plugin ) || empty( $slug ) ) && ! empty( $request->get_body() ) ) {
				$decoded = json_decode( $request->get_body(), true );
				if ( is_array( $decoded ) ) {
					if ( empty( $plugin ) && ! empty( $decoded['plugin'] ) ) {
						$plugin = sanitize_text_field( $decoded['plugin'] );
					}
					if ( empty( $slug ) && ! empty( $decoded['slug'] ) ) {
						$slug = sanitize_text_field( $decoded['slug'] );
					}
				}
			}
		}
		if ( ! empty( $plugin ) && strpos( $plugin, '/' ) === false && strpos( $plugin, '-' ) !== false ) {
			$plugin = self::normalize_plugin_to_path( $plugin );
		}
		return array( 'plugin' => $plugin ?: '', 'slug' => $slug ?: '' );
	}

	/**
	 * Convert slug-style plugin (e.g. elementor-elementor.php) to path format (elementor/elementor.php).
	 *
	 * @param string $plugin Plugin identifier (slug or path).
	 * @return string
	 */
	public static function normalize_plugin_to_path( $plugin ) {
		if ( empty( $plugin ) || strpos( $plugin, '/' ) !== false ) {
			return $plugin;
		}
		$base = preg_replace( '/\.php$/i', '', $plugin );
		if ( strpos( $base, '-' ) !== false ) {
			$base = str_replace( '-', '/', $base );
			return $base . '.php';
		}
		return $plugin;
	}

	/**
	 * Validate plugin file path format.
	 *
	 * @param string $plugin Plugin file path.
	 * @return WP_Error|null WP_Error on failure, null on success.
	 */
	public static function validate_plugin_file( $plugin ) {
		if ( empty( $plugin ) || strpos( $plugin, '/' ) === false ) {
			return new \WP_Error( 'invalid_plugin', 'Invalid or missing plugin param: expected plugin file path (e.g. akismet/akismet.php)' );
		}
		return null;
	}

	/**
	 * Resolve plugin file from slug.
	 *
	 * @param string $slug Plugin slug.
	 * @return string|null Plugin file path or null.
	 */
	public static function resolve_plugin_file( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();
		foreach ( $all_plugins as $file => $data ) {
			if ( strpos( $file, $slug ) !== false ) {
				return $file;
			}
		}
		return null;
	}
}
