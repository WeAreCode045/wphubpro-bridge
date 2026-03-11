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
		$site_id = get_option( 'WPHUBPRO_SITE_ID' );
		$status  = get_option( 'wphub_status', 'disconnected' );
		$connected = $status === 'connected' && ! empty( $site_id );

		return array(
			'connected'         => $connected,
			'last_heartbeat_at' => get_option( 'WPHUBPRO_LAST_HEARTBEAT_AT', '' ),
			'site_id'           => $site_id ?: '',
		);
	}
}
