<?php
/**
 * Connection status for WPHubPro Bridge admin.
 *
 * Fetches site info from Appwrite to display on the admin page.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches and returns connection state from Appwrite.
 */
class WPHubPro_Bridge_Connection_Status {

	/**
	 * Fetch connection status from Appwrite.
	 *
	 * @return array{connected: bool, site_id?: string, username?: string, plan_name?: string, connected_at?: string, action_log?: array, error?: string}
	 */
	public static function fetch() {
		$api_key = get_option( 'wphubpro_api_key' );
		if ( empty( $api_key ) ) {
			return array( 'connected' => false );
		}

		$endpoint = getenv( 'APPWRITE_ENDPOINT' );
		$project  = getenv( 'APPWRITE_PROJECT_ID' );
		$key      = getenv( 'APPWRITE_API_KEY' );
		if ( ! $endpoint || ! $project || ! $key ) {
			return array(
				'connected' => true,
				'error'     => __( 'Appwrite config (APPWRITE_ENDPOINT, APPWRITE_PROJECT_ID, APPWRITE_API_KEY) not set. Connection state unavailable.', 'wphubpro-bridge' ),
			);
		}

		$site_url = get_site_url();
		$site     = self::fetch_site_by_url( $endpoint, $project, $key, $site_url );
		if ( ! $site ) {
			return array(
				'connected' => true,
				'error'     => __( 'Site not found in platform. Connect from the dashboard to link this site.', 'wphubpro-bridge' ),
			);
		}

		$site_id     = $site['$id'] ?? '';
		$user_id     = $site['user_id'] ?? '';
		$created_at  = $site['$createdAt'] ?? '';
		$action_log  = isset( $site['action_log'] ) && is_array( $site['action_log'] ) ? array_reverse( $site['action_log'] ) : array();

		$username = '';
		$plan_name = '';
		if ( $user_id ) {
			$username = self::fetch_user_name( $endpoint, $project, $key, $user_id );
			$plan_name = self::fetch_plan_name( $endpoint, $project, $key, $user_id );
		}

		return array(
			'connected'    => true,
			'site_id'      => $site_id,
			'username'     => $username,
			'plan_name'    => $plan_name,
			'connected_at' => $created_at,
			'action_log'   => $action_log,
		);
	}

	/**
	 * Fetch site document by site_url.
	 *
	 * @param string $endpoint Appwrite endpoint.
	 * @param string $project  Project ID.
	 * @param string $key      API key.
	 * @param string $site_url Site URL.
	 * @return array|null Site document or null.
	 */
	private static function fetch_site_by_url( $endpoint, $project, $key, $site_url ) {
		$urls_to_try = array( $site_url, untrailingslashit( $site_url ), trailingslashit( $site_url ) );
		foreach ( $urls_to_try as $url ) {
			$query = 'equal("site_url","' . addslashes( $url ) . '")';
			$api_url = $endpoint . '/databases/platform_db/collections/sites/documents?queries[]=' . rawurlencode( $query );
			$opts = array(
				'http' => array(
					'method'  => 'GET',
					'header'  => "X-Appwrite-Project: $project\r\nX-Appwrite-Key: $key\r\nContent-Type: application/json\r\n",
					'timeout' => 10,
				),
			);
			$context = stream_context_create( $opts );
			$result  = @file_get_contents( $api_url, false, $context );
			if ( $result ) {
				$data = json_decode( $result, true );
				if ( ! empty( $data['documents'][0] ) ) {
					return $data['documents'][0];
				}
			}
		}
		return null;
	}

	/**
	 * Fetch user name from Appwrite Users API.
	 *
	 * @param string $endpoint Appwrite endpoint.
	 * @param string $project  Project ID.
	 * @param string $key      API key.
	 * @param string $user_id  User ID.
	 * @return string User name or empty.
	 */
	private static function fetch_user_name( $endpoint, $project, $key, $user_id ) {
		$api_url = $endpoint . '/users/' . urlencode( $user_id );
		$opts = array(
			'http' => array(
				'method'  => 'GET',
				'header'  => "X-Appwrite-Project: $project\r\nX-Appwrite-Key: $key\r\nContent-Type: application/json\r\n",
				'timeout' => 5,
			),
		);
		$context = stream_context_create( $opts );
		$result  = @file_get_contents( $api_url, false, $context );
		if ( $result ) {
			$data = json_decode( $result, true );
			if ( ! empty( $data['name'] ) ) {
				return $data['name'];
			}
			if ( ! empty( $data['email'] ) ) {
				return $data['email'];
			}
		}
		return '';
	}

	/**
	 * Fetch plan name from accounts and plans collections.
	 *
	 * @param string $endpoint Appwrite endpoint.
	 * @param string $project  Project ID.
	 * @param string $key      API key.
	 * @param string $user_id  User ID.
	 * @return string Plan name or empty.
	 */
	private static function fetch_plan_name( $endpoint, $project, $key, $user_id ) {
		$query  = 'equal("user_id","' . addslashes( $user_id ) . '")';
		$api_url = $endpoint . '/databases/platform_db/collections/accounts/documents?queries[]=' . rawurlencode( $query ) . '&limit=1';
		$opts   = array(
			'http' => array(
				'method'  => 'GET',
				'header'  => "X-Appwrite-Project: $project\r\nX-Appwrite-Key: $key\r\nContent-Type: application/json\r\n",
				'timeout' => 5,
			),
		);
		$context = stream_context_create( $opts );
		$result  = @file_get_contents( $api_url, false, $context );
		if ( ! $result ) {
			return '';
		}
		$data = json_decode( $result, true );
		$plan_id = null;
		if ( ! empty( $data['documents'][0]['current_plan_id'] ) ) {
			$plan_id = $data['documents'][0]['current_plan_id'];
		} elseif ( ! empty( $data['documents'][0]['current_plan'] ) ) {
			$plan_id = is_string( $data['documents'][0]['current_plan'] ) ? $data['documents'][0]['current_plan'] : ( $data['documents'][0]['current_plan']['$id'] ?? null );
		}
		if ( ! $plan_id ) {
			return '';
		}
		$plan_url = $endpoint . '/databases/platform_db/collections/plans/documents/' . urlencode( $plan_id );
		$plan_result = @file_get_contents( $plan_url, false, $context );
		if ( $plan_result ) {
			$plan_data = json_decode( $plan_result, true );
			if ( ! empty( $plan_data['name'] ) ) {
				return $plan_data['name'];
			}
		}
		return '';
	}
}
