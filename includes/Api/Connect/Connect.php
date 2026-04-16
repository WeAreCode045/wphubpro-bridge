<?php
namespace WPHubPro\Api\Connect;

/**
 * Connect & site linking for WPHubPro Bridge.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Facade for connect REST registration; delegates to {@see ConnectController}.
 */
class Connect {

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

	private function __construct() {
	}

	/**
	 * Register REST routes for connect, disconnect, save-connection, redirect settings, and token exchange.
	 */
	public function register_rest_routes(): void {
		ConnectController::instance()->register_rest_routes();
	}
}
