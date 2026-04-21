<?php
namespace WPHubPro\Api\Connect;

use WPHubPro\Auth\Auth;
use WPHubPro\Config;

/**
 * REST API routes for site connect, disconnect, save-connection, and redirect settings.
 *
 * `POST …/save-connection` follows the same JSON contract as `manage-sites` `connect_site`
 * (Hub→WordPress): merged fields + `api_key` + optional `username`; `X-WPHub-Key` auth.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers connect-related REST routes and delegates handlers to {@see ConnectionService}.
 */
class ConnectController {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register REST routes for connect, disconnect, save-connection, and redirect settings.
	 */
	public function register_rest_routes(): void {
		Auth::init();

		$namespace = Config::REST_NAMESPACE;

		register_rest_route(
			$namespace,
			'/connect',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_connect' ),
				'permission_callback' => array( $this, 'permission_manage_options' ),
			)
		);

		register_rest_route(
			$namespace,
			'/exchange-token',
			array(
				'methods'             => 'GET',
				'callback'            => array( Auth::class, 'handle_exchange_token' ),
				'permission_callback' => array( Auth::class, 'validate_exchange_token_permission' ),
				'args'                => array(
					'connect_token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_disconnect' ),
				'permission_callback' => array( $this, 'permission_manage_options' ),
			)
		);

		register_rest_route(
			$namespace,
			'/connect/redirect-settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_redirect_settings' ),
				'permission_callback' => array( $this, 'permission_manage_options' ),
			)
		);

		register_rest_route(
			$namespace,
			'/connect/redirect-settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_redirect_settings' ),
				'permission_callback' => array( $this, 'permission_manage_options' ),
				'args'                => array(
					'use_default' => array(
						'required' => true,
						'type'     => 'boolean',
					),
					'custom_url'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/save-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_save_connection' ),
				'permission_callback' => array( Auth::class, 'validate_api_key' ),
				'args'                => array(
					'api_key'           => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'bridge_secret'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'site_secret'       => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'encrypted_api_key' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'endpoint'          => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'project_id'        => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'site_id'           => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'username'          => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'heartbeat_url'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'body'              => array(
						'required' => false,
						'type'     => 'object',
					),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public function permission_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @return array{success: bool}
	 */
	public function handle_disconnect(): array {
		return ConnectionService::disconnect_local();
	}

	/**
	 * @param \WP_REST_Request $request Request body aligned with manage-sites `connect_site` (see {@see ConnectionService::save_connection_from_request()}).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_save_connection( \WP_REST_Request $request ) {
		return ConnectionService::save_connection_from_request( $request );
	}

	/**
	 * @return array{redirect: string}
	 */
	public function handle_connect(): array {
		return ConnectionService::build_connect_redirect_response();
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function get_redirect_settings(): \WP_REST_Response {
		return ConnectionService::get_redirect_settings_response();
	}

	/**
	 * @param \WP_REST_Request $request Request with use_default and optional custom_url.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_redirect_settings( \WP_REST_Request $request ) {
		return ConnectionService::save_redirect_settings_from_request( $request );
	}
}
