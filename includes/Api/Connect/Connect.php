<?php
namespace WPHubPro\Api\Connect;

/**
 * Connect & site linking for WPHubPro Bridge.
 *
 * The Hub Appwrite function {@see \WPHubPro\Config::MANAGE_SITES_FUNCTION_ID} (`manage-sites`) handles site rows and can
 * proxy `connect_site` → {@see ConnectController::register_rest_routes()} `POST …/save-connection` on this site
 * (flat JSON: optional nested `body` merged with top-level fields, then `api_key` + optional `username`; see
 * {@see ConnectionService::save_connection_from_request()}). Bridge REST auth uses `X-WPHub-Key` / {@see \WPHubPro\Auth\Auth::validate_api_key()}.
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
	 *
 * `save-connection` persists Hub metadata ({@see ConnectionService::save_connection_from_request()}) and schedules
 * meta sync ({@see \WPHubPro\Api\Sync::schedule_sync()}). Browser-originated saves also schedule
 * {@see ConnectExecution::invoke_connect_site()} (manage-sites `connect_site`), skipped when the request carries
 * `X-WPHub-Execution: manage-sites` from that execution.
	 */
	public function register_rest_routes(): void {
		ConnectController::instance()->register_rest_routes();
	}
}
