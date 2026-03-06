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
		$api_key = get_option( 'wphubpro_api_key' );
		if ( empty( $api_key ) ) {
			return array( 'connected' => false );
		}

		$jwt      = get_option( 'WPHUBPRO_USER_JWT' );
		$endpoint = get_option( 'WPHUBPRO_ENDPOINT' );
		$project  = get_option( 'WPHUBPRO_PROJECT_ID' );

		if ( empty( $jwt ) || empty( $endpoint ) || empty( $project ) || ! class_exists( 'Appwrite\Client' ) ) {
			return array(
				'connected' => true,
				'error'     => __( 'Appwrite config not set. Connect from dashboard to save JWT and connection details.', 'wphubpro-bridge' ),
			);
		}

		$site = self::fetch_site_via_sdk( $endpoint, $project, $jwt );

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

		$username  = '';
		$plan_name = '';
		if ( $user_id ) {
			$username  = self::fetch_user_name_via_sdk( $endpoint, $project, $jwt, $user_id );
			$plan_name = self::fetch_plan_name_via_sdk( $endpoint, $project, $jwt, $user_id );
		}

		$platform_data = array(
			'site_id'           => $site_id,
			'username'          => $username,
			'plan_name'         => $plan_name,
			'connection_status' => 'connected',
			'connected_at'      => $created_at,
		);
		update_option( 'WPHUBPRO_DATA', $platform_data );

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
		} catch ( Exception $e ) {
			return null;
		}
		return null;
	}

	/**
	 * Fetch user name via SDK with JWT.
	 *
	 * @param string $endpoint Appwrite endpoint.
	 * @param string $project  Project ID.
	 * @param string $jwt      User JWT.
	 * @param string $user_id  User ID.
	 * @return string
	 */
	private static function fetch_user_name_via_sdk( $endpoint, $project, $jwt, $user_id ) {
		try {
			$client = new \Appwrite\Client();
			$client->setEndpoint( $endpoint )->setProject( $project )->setJWT( $jwt );
			$users  = new \Appwrite\Services\Users( $client );
			$user   = $users->get( $user_id );
			return $user['name'] ?? $user['email'] ?? '';
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Fetch plan name via SDK with JWT.
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
			$plan_id = $accounts['documents'][0]['current_plan_id'] ?? null;
			if ( ! $plan_id && ! empty( $accounts['documents'][0]['current_plan'] ) ) {
				$plan_id = is_string( $accounts['documents'][0]['current_plan'] )
					? $accounts['documents'][0]['current_plan']
					: ( $accounts['documents'][0]['current_plan']['$id'] ?? null );
			}
			if ( ! $plan_id ) {
				return '';
			}
			$plan = $databases->getDocument( 'platform_db', 'plans', $plan_id );
			return $plan['name'] ?? '';
		} catch ( Exception $e ) {
			return '';
		}
	}

}
