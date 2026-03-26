<?php
namespace WPHubPro;

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
class ConnectionStatus {

    /**
	 * Instance of the class.
	 * @var ConnectionStatus|null
	 */
	private static $instance = null;

	/**
	 * Get the instance of the class.
	 *
	 * @return ConnectionStatus
	 */
	public static function instance() : self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

    /**
     * Register REST routes.
     *
     * @return void
     */
    public function register_rest_routes() : void {
        // Connection status (admin only)
		register_rest_route( Config::REST_NAMESPACE, '/connection-status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_connection_status' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );
    }

    /**
     * Handle connection status request.
     *
     * @return WP_REST_Response
     */
    public function handle_connection_status() : WP_REST_Response {
        return rest_ensure_response( self::get_connection_status() );
    }
    
	/**
	 * Compare two semantic versions. Returns 1 if a > b, -1 if a < b, 0 if equal.
	 *
	 * @param string $a Version string (e.g. 2.2.28).
	 * @param string $b Version string.
	 * @return int
	 */
	private static function compare_versions( string $a, string $b ) : int {
		$pa = array_map( 'intval', explode( '.', $a ) );
		$pb = array_map( 'intval', explode( '.', $b ) );
		$len = max( count( $pa ), count( $pb ) );
		for ( $i = 0; $i < $len; $i++ ) {
			$va = $pa[ $i ] ?? 0;
			$vb = $pb[ $i ] ?? 0;
			if ( $va > $vb ) {
				return 1;
			}
			if ( $va < $vb ) {
				return -1;
			}
		}
		return 0;
	}

	/**
	 * Fetch connection status from local options, plus bridge version.
	 * Latest version is fetched from WPHub only when user clicks "Check for updates".
	 *
	 * @return array{connected: bool, last_heartbeat_at?: string, site_id?: string, bridge_version?: string, latest_version?: string, update_available?: bool}
	 */
	public static function get_connection_status() : array {
		$site_id   = Config::get_site_id();
		$status    = Config::get_status();
		$connected = $status === 'connected' && ! empty( $site_id );

		$plugin_data = Config::get_bridge_plugin_data();
		$installed   = $plugin_data['installed'];
		$latest      = $plugin_data['latest'];
		$update_available = ! empty( $latest ) && ! empty( $installed ) && self::compare_versions( $latest, $installed ) > 0;

		return array(
			'connected'         => $connected,
			'last_heartbeat_at' => Config::get_last_heartbeat_at(),
			'site_id'           => $site_id ?: '',
			'bridge_version'    => $installed,
			'latest_version'    => $latest,
			'update_available'  => $update_available,
		);
	}
}
