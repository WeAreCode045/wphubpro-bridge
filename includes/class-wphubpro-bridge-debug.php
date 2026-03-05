<?php
/**
 * Debug utilities for WPHubPro Bridge.
 *
 * Fetches redirect domains from Appwrite and provides base URL selection.
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
	const APPWRITE_SETTINGS_KEY = 'redirect_domains';

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
	 * Get list of allowed redirect domains from Appwrite platform_settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_domains() {
		$domains = $this->fetch_domains_from_appwrite();
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

	/**
	 * Fetch redirect_domains from Appwrite platform_settings.
	 *
	 * @return array List of domain URLs (strings).
	 */
	private function fetch_domains_from_appwrite() {
		$endpoint = getenv( 'APPWRITE_ENDPOINT' );
		$project  = getenv( 'APPWRITE_PROJECT_ID' );
		$key      = getenv( 'APPWRITE_API_KEY' );
		if ( ! $endpoint || ! $project || ! $key ) {
			return $this->get_default_domains();
		}

		$url  = $endpoint . '/databases/platform_db/collections/platform_settings/documents';
		$url .= '?queries[]=' . rawurlencode( 'equal("key","' . self::APPWRITE_SETTINGS_KEY . '")' );

		$opts = array(
			'http' => array(
				'method'  => 'GET',
				'header'  => "X-Appwrite-Project: $project\r\nX-Appwrite-Key: $key\r\nContent-Type: application/json\r\n",
				'timeout' => 10,
			),
		);
		$context = stream_context_create( $opts );
		$result  = @file_get_contents( $url, false, $context );
		if ( ! $result ) {
			return $this->get_default_domains();
		}

		$data = json_decode( $result, true );
		if ( empty( $data['documents'][0]['value'] ) ) {
			return $this->get_default_domains();
		}

		$decoded = json_decode( $data['documents'][0]['value'], true );
		if ( ! is_array( $decoded ) ) {
			return $this->get_default_domains();
		}

		return array_map( 'untrailingslashit', array_values( array_filter( $decoded, 'is_string' ) ) );
	}

	/**
	 * Default domains when Appwrite is unavailable.
	 *
	 * @return array
	 */
	private function get_default_domains() {
		return array(
			self::DEFAULT_BASE_URL,
			'https://app.wphub.pro',
			'https://dev.wphub.pro',
		);
	}
}
