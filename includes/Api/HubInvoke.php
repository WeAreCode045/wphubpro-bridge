<?php
/**
 * Hub-invokable PHP handlers (registry only — no arbitrary code execution).
 *
 * POST /wphubpro/v1/hub/invoke runs a named handler registered on the filter
 * `wphubpro_hub_invoke_handlers`. Sites and custom code register callables that
 * receive (array $args, \WP_REST_Request $request) and return array|mixed or \WP_Error.
 *
 * @package WPHubPro
 */

namespace WPHubPro\Api;

use WPHubPro\Config;
use WPHubPro\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST: list and run registered hub invoke handlers.
 */
class HubInvoke {

	/**
	 * Register GET (list keys) and POST (run handler) routes.
	 */
	public static function register_rest_routes(): void {
		$namespace = Config::REST_NAMESPACE;
		$validate  = Config::REST_API_AUTH_PROVIDER;

		register_rest_route(
			$namespace,
			'/hub/invoke',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_list' ),
					'permission_callback' => $validate,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_invoke' ),
					'permission_callback' => $validate,
				),
			)
		);
	}

	/**
	 * Merged handler registry (defaults + filter).
	 *
	 * Filter: `wphubpro_hub_invoke_handlers` — (array $handlers) => array<string, callable>.
	 * Keys must match {@see normalize_handler_key()} (lowercase a–z, 0–9, _, -, max 64).
	 *
	 * @return array<string, callable|array{0: class-string|object, 1: string}>
	 */
	public static function get_registry(): array {
		$defaults = self::default_handlers();

		/**
		 * Add or override hub/invoke handlers.
		 *
		 * @param array<string, callable|array{0: class-string|object, 1: string}> $handlers
		 */
		$merged = apply_filters( 'wphubpro_hub_invoke_handlers', $defaults );

		if ( ! is_array( $merged ) ) {
			return self::normalize_registry_keys( $defaults );
		}

		return self::normalize_registry_keys( $merged );
	}

	/**
	 * Drop invalid keys and normalize to lowercase safe slugs.
	 *
	 * @param array<string, mixed> $handlers Raw handlers map.
	 * @return array<string, callable|array{0: class-string|object, 1: string}>
	 */
	private static function normalize_registry_keys( array $handlers ): array {
		$out = array();
		foreach ( $handlers as $key => $callable ) {
			$norm = self::normalize_handler_key( (string) $key );
			if ( $norm === '' || ! is_callable( $callable ) ) {
				continue;
			}
			$out[ $norm ] = $callable;
		}
		return $out;
	}

	/**
	 * Built-in handlers (diagnostics, cache/DB/comments/reading maintenance).
	 *
	 * @return array<string, callable|array{0: class-string|object, 1: string}>
	 */
	private static function default_handlers(): array {
		return array(
			'ping'                              => array( __CLASS__, 'handler_ping' ),
			'site_summary'                      => array( __CLASS__, 'handler_site_summary' ),
			'maintenance_flush_caches'          => array( HubMaintenance::class, 'handler_flush_caches' ),
			'maintenance_optimize_db'           => array( HubMaintenance::class, 'handler_optimize_db' ),
			'maintenance_purge_spam_comments'   => array( HubMaintenance::class, 'handler_purge_spam_comments' ),
			'reading_search_visibility'         => array( HubMaintenance::class, 'handler_reading_search_visibility' ),
		);
	}

	/**
	 * @param array    $args    JSON `args` object decoded as array.
	 * @param \WP_REST_Request $request Full request.
	 * @return array<string, mixed>
	 */
	public static function handler_ping( array $args, $request ): array {
		return array(
			'ok'   => true,
			'time' => time(),
		);
	}

	/**
	 * @param array    $args    Unused.
	 * @param \WP_REST_Request $request Unused.
	 * @return array<string, mixed>
	 */
	public static function handler_site_summary( array $args, $request ): array {
		return array(
			'wp_version'  => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'site_name'   => get_bloginfo( 'name' ),
			'home_url'    => home_url( '/' ),
		);
	}

	/**
	 * GET: list registered handler keys (no secrets).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_list( $request ) {
		$registry = self::get_registry();
		$keys     = array();
		foreach ( array_keys( $registry ) as $key ) {
			$norm = self::normalize_handler_key( (string) $key );
			if ( $norm !== '' ) {
				$keys[] = $norm;
			}
		}
		$keys = array_values( array_unique( $keys ) );
		sort( $keys, SORT_STRING );

		return rest_ensure_response(
			array(
				'handlers' => $keys,
			)
		);
	}

	/**
	 * POST: run one handler. Body JSON: { "handler": "site_summary", "args": { ... } }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_invoke( $request ) {
		self::maybe_impersonate_from_header();

		$parsed = self::parse_invoke_body( $request );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$handler_key = $parsed['handler'];
		$args        = $parsed['args'];

		$registry = self::get_registry();
		if ( ! isset( $registry[ $handler_key ] ) ) {
			return new \WP_Error(
				'unknown_handler',
				__( 'Unknown handler. Register handlers via wphubpro_hub_invoke_handlers or use a built-in key from GET hub/invoke.', 'wphubpro-bridge' ),
				array( 'status' => 404 )
			);
		}

		$callable = $registry[ $handler_key ];
		if ( ! is_callable( $callable ) ) {
			return new \WP_Error(
				'bad_handler',
				__( 'Handler is not callable.', 'wphubpro-bridge' ),
				array( 'status' => 500 )
			);
		}

		try {
			$result = call_user_func( $callable, $args, $request );
		} catch ( \Throwable $e ) {
			Logger::log_action(
				'invoke',
				'hub/invoke',
				array( 'handler' => $handler_key ),
				array( 'error' => $e->getMessage() )
			);
			return new \WP_Error(
				'handler_exception',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $result ) ) {
			Logger::log_action(
				'invoke',
				'hub/invoke',
				array( 'handler' => $handler_key ),
				array( 'error' => $result->get_error_message() )
			);
			return $result;
		}

		Logger::log_action(
			'invoke',
			'hub/invoke',
			array( 'handler' => $handler_key, 'args' => $args ),
			is_array( $result ) ? $result : array( 'result' => $result )
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'handler' => $handler_key,
				'data'    => $result,
			)
		);
	}

	/**
	 * @return array{handler: string, args: array}|\WP_Error
	 */
	private static function parse_invoke_body( $request ) {
		$body = $request->get_body();
		if ( ! is_string( $body ) || trim( $body ) === '' ) {
			return new \WP_Error(
				'missing_body',
				__( 'JSON body with "handler" is required.', 'wphubpro-bridge' ),
				array( 'status' => 400 )
			);
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return new \WP_Error(
				'invalid_json',
				__( 'Body must be JSON.', 'wphubpro-bridge' ),
				array( 'status' => 400 )
			);
		}

		$handler_raw = isset( $json['handler'] ) ? $json['handler'] : '';
		$handler_key = self::normalize_handler_key( is_string( $handler_raw ) ? $handler_raw : '' );
		if ( $handler_key === '' ) {
			return new \WP_Error(
				'invalid_handler',
				__( 'Invalid or missing handler key (use a–z, 0–9, underscore, hyphen; max 64 chars).', 'wphubpro-bridge' ),
				array( 'status' => 400 )
			);
		}

		$args = isset( $json['args'] ) ? $json['args'] : array();
		if ( is_object( $args ) ) {
			$args = (array) $args;
		}
		if ( ! is_array( $args ) ) {
			$args = array();
		}

		return array(
			'handler' => $handler_key,
			'args'    => $args,
		);
	}

	/**
	 * Normalize registry / request key: lowercase, allowed charset, length.
	 */
	private static function normalize_handler_key( string $key ): string {
		$key = strtolower( trim( $key ) );
		if ( ! preg_match( '/^[a-z0-9_-]{1,64}$/', $key ) ) {
			return '';
		}
		return $key;
	}

	/**
	 * If Hub sent X-WPHub-Admin-Login, run the handler as that user when they are an administrator.
	 */
	private static function maybe_impersonate_from_header(): void {
		$login = isset( $_SERVER['HTTP_X_WPHUB_ADMIN_LOGIN'] )
			? sanitize_user( wp_unslash( $_SERVER['HTTP_X_WPHUB_ADMIN_LOGIN'] ), true )
			: '';
		if ( $login === '' ) {
			return;
		}
		$user = get_user_by( 'login', $login );
		if ( $user && user_can( $user, 'manage_options' ) ) {
			wp_set_current_user( (int) $user->ID );
		}
	}
}
