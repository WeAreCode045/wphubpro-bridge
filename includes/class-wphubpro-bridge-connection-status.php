<?php
/**
 * Connection status for WPHubPro Bridge admin.
 *
 * Fetches site info from Appwrite using JWT (WPHUBPRO_USER_JWT).
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
	 * Uses WPHUBPRO_ENDPOINT, WPHUBPRO_PROJECT_ID, WPHUBPRO_USER_JWT from WordPress options.
	 *
	 * @return array{connected: bool, site_id?: string, username?: string, plan_name?: string, connected_at?: string, action_log?: array, error?: string}
	 */
	public static function fetch() {
		$api_log = get_option( 'WPHUBPRO_LOG', array() );
		if ( ! is_array( $api_log ) ) {
			$api_log = array();
		}

		$api_key = get_option( 'wphubpro_api_key' );
		if ( empty( $api_key ) ) {
			return array( 'connected' => false, 'api_log' => $api_log );
		}

		$jwt      = get_option( 'WPHUBPRO_USER_JWT' );
		$endpoint = get_option( 'WPHUBPRO_ENDPOINT' );
		$project  = get_option( 'WPHUBPRO_PROJECT_ID' );

		if ( empty( $jwt ) || empty( $endpoint ) || empty( $project ) || ! class_exists( 'Appwrite\Client' ) ) {
			return array(
				'connected'         => true,
				'error'            => __( 'Appwrite config not set. Connect from dashboard to save JWT and connection details.', 'wphubpro-bridge' ),
				'api_log'          => $api_log,
				'last_heartbeat_at' => get_option( 'WPHUBPRO_LAST_HEARTBEAT_AT', '' ),
				'heartbeat_reached' => get_option( 'WPHUBPRO_LAST_HEARTBEAT_STATUS', '' ) === 'success',
			);
		}

		$site = self::fetch_site_via_sdk( $endpoint, $project, $jwt );

		if ( ! $site ) {
			return array(
				'connected'         => true,
				'error'            => __( 'Site not found in platform. Connect from the dashboard to link this site.', 'wphubpro-bridge' ),
				'api_log'          => $api_log,
				'last_heartbeat_at' => get_option( 'WPHUBPRO_LAST_HEARTBEAT_AT', '' ),
				'heartbeat_reached' => get_option( 'WPHUBPRO_LAST_HEARTBEAT_STATUS', '' ) === 'success',
			);
		}

		$site_id     = $site['$id'] ?? '';
		$user_id     = self::extract_user_id( $site['user_id'] ?? '' );
		$created_at  = $site['$createdAt'] ?? '';
		$action_log  = isset( $site['action_log'] ) && is_array( $site['action_log'] ) ? array_reverse( $site['action_log'] ) : array();

		// Username: prefer site document, else fetch from platform Account (JWT owner).
		$username = ! empty( $site['username'] ) ? (string) $site['username'] : '';
		if ( empty( $username ) && $user_id ) {
			$username = self::fetch_user_name_via_sdk( $endpoint, $project, $jwt );
		}

		// Plan: fetch from accounts by user_id (connected site owner).
		$plan_name = $user_id ? self::fetch_plan_name_via_sdk( $endpoint, $project, $jwt, $user_id ) : '';

		$last_heartbeat_at = get_option( 'WPHUBPRO_LAST_HEARTBEAT_AT', '' );
		$heartbeat_reached = get_option( 'WPHUBPRO_LAST_HEARTBEAT_STATUS', '' ) === 'success';
		$platform_data = array(
			'site_id'           => $site_id,
			'username'          => $username,
			'plan_name'         => $plan_name,
			'connection_status' => 'connected',
			'connected_at'      => $created_at,
			'last_heartbeat_at' => $last_heartbeat_at,
			'heartbeat_reached' => $heartbeat_reached,
		);
		update_option( 'WPHUBPRO_DATA', $platform_data );

		return array(
			'connected'         => true,
			'site_id'           => $site_id,
			'username'          => $username,
			'plan_name'         => $plan_name,
			'connected_at'      => $created_at,
			'last_heartbeat_at' => $last_heartbeat_at,
			'heartbeat_reached' => $heartbeat_reached,
			'action_log'        => $action_log,
			'api_log'           => $api_log,
		);
	}

	/**
	 * Fetch site document via Appwrite SDK with JWT.
	 *
	 * @param string $endpoint Appwrite endpoint.
	 * @param string $project  Project ID.
	 * @param string $jwt      User JWT.
	 * @return array|null Site document or null.
	 */
	private static function fetch_site_via_sdk( $endpoint, $project, $jwt ) {
		try {
			$client = new \Appwrite\Client();
			$client->setEndpoint( $endpoint )->setProject( $project )->setJWT( $jwt );
			$databases = new \Appwrite\Services\Databases( $client );
			$site_url  = get_site_url();
			$urls      = array( $site_url, untrailingslashit( $site_url ), trailingslashit( $site_url ) );
			foreach ( $urls as $url ) {
				$resp = $databases->listDocuments( 'platform_db', 'sites', array(
					\Appwrite\Query::equal( 'site_url', $url ),
				) );
				if ( ! empty( $resp['documents'][0] ) ) {
					return $resp['documents'][0];
				}
			}
		} catch ( \Exception $e ) {
			return null;
		}
		return null;
	}

	/**
	 * Extract user ID from site document (string or relation object).
	 *
	 * @param mixed $user_id Raw user_id from document.
	 * @return string
	 */
	private static function extract_user_id( $user_id ) {
		if ( is_string( $user_id ) && $user_id !== '' ) {
			return $user_id;
		}
		if ( is_array( $user_id ) && ! empty( $user_id['$id'] ) ) {
			return (string) $user_id['$id'];
		}
		return '';
	}

	/**
	 * Fetch user name via SDK with JWT.
	 * Uses Account.get() (current JWT user) — Users.get() requires admin privileges.
	 *
	 * @param string $endpoint Appwrite endpoint.
	 * @param string $project  Project ID.
	 * @param string $jwt      User JWT.
	 * @return string
	 */
	private static function fetch_user_name_via_sdk( $endpoint, $project, $jwt ) {
		try {
			$client  = new \Appwrite\Client();
			$client->setEndpoint( $endpoint )->setProject( $project )->setJWT( $jwt );
			$account = new \Appwrite\Services\Account( $client );
			$user    = $account->get();
			return $user['name'] ?? $user['email'] ?? '';
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Fetch plan name via SDK with JWT.
	 * Platform may store plan label in current_plan_id (Stripe) or as relation to plans.
	 *
	 * @param string $endpoint Appwrite endpoint.
	 * @param string $project  Project ID.
	 * @param string $jwt      User JWT.
	 * @param string $user_id  User ID.
	 * @return string
	 */
	private static function fetch_plan_name_via_sdk( $endpoint, $project, $jwt, $user_id ) {
		try {
			$client    = new \Appwrite\Client();
			$client->setEndpoint( $endpoint )->setProject( $project )->setJWT( $jwt );
			$databases = new \Appwrite\Services\Databases( $client );
			$accounts  = $databases->listDocuments( 'platform_db', 'accounts', array(
				\Appwrite\Query::equal( 'user_id', $user_id ),
				\Appwrite\Query::limit( 1 ),
			) );
			if ( empty( $accounts['documents'][0] ) ) {
				return '';
			}
			$acc = $accounts['documents'][0];

			// Stripe webhook stores product label directly in current_plan_id.
			$plan_id = $acc['current_plan_id'] ?? null;
			if ( is_string( $plan_id ) && $plan_id !== '' && ! self::looks_like_doc_id( $plan_id ) ) {
				return $plan_id;
			}

			// Resolve from current_plan relation.
			$plan_id = null;
			if ( ! empty( $acc['current_plan'] ) ) {
				$rel = $acc['current_plan'];
				if ( is_array( $rel ) && ! empty( $rel['name'] ) ) {
					return (string) $rel['name'];
				}
				$plan_id = is_string( $rel ) ? $rel : ( $rel['$id'] ?? null );
			}
			if ( ! $plan_id || ! self::looks_like_doc_id( $plan_id ) ) {
				return '';
			}

			$plan = $databases->getDocument( 'platform_db', 'plans', $plan_id );
			return $plan['name'] ?? '';
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Check if string looks like an Appwrite document ID (24 hex chars).
	 *
	 * @param string $id Potential document ID.
	 * @return bool
	 */
	private static function looks_like_doc_id( $id ) {
		return is_string( $id ) && strlen( $id ) === 24 && ctype_xdigit( $id );
	}

}
