<?php
namespace WPHUBPRO\Theme;

/**
 * REST args and slug parsing for theme management.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme REST request helpers.
 */
class Params {

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function rest_base_args() {
		return array(
			'slug' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Parse theme slug from request (query, body param, or raw body).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string
	 */
	public static function parse_slug_from_request( $request ) {
		$slug = $request->get_param( 'slug' );
		if ( empty( $slug ) ) {
			$body_raw = $request->get_param( 'body' );
			if ( is_string( $body_raw ) ) {
				$decoded = json_decode( $body_raw, true );
				if ( is_array( $decoded ) && ! empty( $decoded['slug'] ) ) {
					return sanitize_text_field( $decoded['slug'] );
				}
			}
			if ( ! empty( $request->get_body() ) ) {
				$decoded = json_decode( $request->get_body(), true );
				if ( is_array( $decoded ) && ! empty( $decoded['slug'] ) ) {
					return sanitize_text_field( $decoded['slug'] );
				}
			}
		}
		return $slug ? sanitize_text_field( $slug ) : '';
	}

	/**
	 * Validate theme slug and that theme exists.
	 *
	 * @param string $slug Theme slug.
	 * @return WP_Error|null
	 */
	public static function validate_theme_slug( $slug ) {
		if ( empty( $slug ) ) {
			return new \WP_Error( 'invalid_theme', 'Invalid or missing theme slug' );
		}
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return new \WP_Error( 'theme_not_found', 'Theme not found: ' . $slug );
		}
		return null;
	}
}
