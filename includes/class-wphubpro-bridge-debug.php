<?php
/**
 * Debug utilities for WPHubPro Bridge.
 *
 * Provides base URL selection for connect redirect.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug feature: domain selection for connect redirect.
 */
class WPHubPro_Bridge_Debug {

	const OPTION_BASE_URL = 'wphubpro_redirect_base_url';
	const DEFAULT_BASE_URL = 'https://wphub.pro';

	/**
	 * Hardcoded list of allowed redirect domains.
	 *
	 * @return array List of domain URLs (strings).
	 */
	private function get_domains_list() {
		return array(
			self::DEFAULT_BASE_URL,
			'https://app.wphub.pro',
			'https://dev.wphub.pro',
		);
	}

	/**
	 * Register REST routes for debug.
	 *
	 * @param string $namespace API namespace.
	 */
	public function register_routes( $namespace ) {
		$manage = function () {
			return current_user_can( 'manage_options' );
		};

		register_rest_route( $namespace, '/debug/domains', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_domains' ),
			'permission_callback' => $manage,
		) );

		register_rest_route( $namespace, '/debug/base-url', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_base_url' ),
			'permission_callback' => $manage,
			'args'                => array(
				'base_url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		) );

		register_rest_route( $namespace, '/debug/base-url', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_base_url' ),
			'permission_callback' => $manage,
		) );
	}

	/**
	 * Get list of allowed redirect domains.
	 *
	 * @return WP_REST_Response
	 */
	public function get_domains() {
		$domains = $this->get_domains_list();
		return rest_ensure_response( array( 'domains' => $domains ) );
	}

	/**
	 * Get current saved base URL.
	 *
	 * @return WP_REST_Response
	 */
	public function get_base_url() {
		$base_url = get_option( self::OPTION_BASE_URL, self::DEFAULT_BASE_URL );
		return rest_ensure_response( array( 'base_url' => $base_url ) );
	}

	/**
	 * Save selected base URL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_base_url( $request ) {
		$base_url = $request->get_param( 'base_url' );
		$base_url = untrailingslashit( $base_url );
		if ( empty( $base_url ) ) {
			return new WP_Error( 'invalid_base_url', 'Invalid base URL', array( 'status' => 400 ) );
		}
		update_option( self::OPTION_BASE_URL, $base_url );
		return rest_ensure_response( array( 'base_url' => $base_url ) );
	}

	/**
	 * Get the base URL to use for connect redirects.
	 *
	 * @return string
	 */
	public static function get_redirect_base_url() {
		return get_option( self::OPTION_BASE_URL, self::DEFAULT_BASE_URL );
	}
}
