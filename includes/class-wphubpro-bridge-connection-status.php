<?php
/**
 * Simple connection status for WPHubPro Bridge admin.
 * Returns wphub_status from options (connected/disconnected). No Appwrite fetch.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns local connection state from options.
 */
class WPHubPro_Bridge_Connection_Status {

	/**
	 * Fetch connection status from local options.
	 *
	 * @return array{connected: bool, last_heartbeat_at?: string, site_id?: string}
	 */
	public static function fetch() {
		$site_id    = WPHubPro_Bridge_Config::get_site_id();
		$status     = WPHubPro_Bridge_Config::get_status();
		$connected  = $status === 'connected' && ! empty( $site_id );

		return array(
			'connected'         => $connected,
			'last_heartbeat_at' => WPHubPro_Bridge_Config::get_last_heartbeat_at(),
			'site_id'           => $site_id ?: '',
		);
	}
}
