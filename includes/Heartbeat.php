<?php
namespace WPHubPro;
use WPHubPro\Config;
/**
 * Incoming REST routes for heartbeat (e.g. platform poke to verify the bridge is reachable).
 *
 * @package WPHubPro
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Registers heartbeat-related REST routes (inbound only).
 */
class Heartbeat {
	/**
	 * REST path segment under {@see Config::REST_NAMESPACE}.
	 *
	 * @var string
	 */
	private static $path = '/heartbeat/';
	/**
	 * Register heartbeat REST routes.
	 */
	public function register_rest_routes() {
		$namespace = Config::REST_NAMESPACE;
		register_rest_route( $namespace, self::$path . 'poke', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( $this, 'handle_poke' ),
		) );
	}
	/**
	 * Heartbeat poke (platform can call to verify bridge is reachable).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_poke( $request ) {
		return rest_ensure_response( array( 'success' => true, 'poked' => true ) );
	}
}