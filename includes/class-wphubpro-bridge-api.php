<?php
/**
 * Site health for WPHubPro Bridge.
 *
 * Placeholder for site health checks (WordPress Site Health, status, etc.).
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site health feature (placeholder).
 */
class WPHubPro_Bridge_API {
    private $api_key = '';
    private $site_id = '';
    private $secret = '';
    private $baseurl = '';
    private $project = '';
    

    public function __construct() {
        $this->site_id       = get_option( 'WPHUBPRO_SITE_ID' );
		$this->base_url       = get_option( 'WPHUBPRO_ENDPOINT' );
		$this->project       = get_option( 'WPHUBPRO_PROJECT_ID' );
        $this->api_key       = get_option( 'WPHUBPRO_API_KEY' );
    }

    /**
     * Simple base API class wrapping GET and POST requests for Appwrite endpoints.
     * Uses wp_remote_get and wp_remote_post.
     */
    public static function get($endpoint, $query = array(), $headers = array()) {
        
        if (empty($endpoint)) {
            WPHubPro_Bridge_Logger::log_action(get_site_url(), 'api/get', 'error', array(), array(
                'msg'      => 'Missing endpoint.',
                'endpoint' => $endpoint,
            ));
            return false;
        }
        $url = $this->base_url . $endpoint;
        if (!empty($query) && is_array($query)) {
            $url = add_query_arg($query, $url);
        }

        $default_headers = array(
            'Accept' => 'application/json',
        );
        $headers = array_merge($default_headers, $headers);

        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 15,
        ));
        if (is_wp_error($response)) {
            WPHubPro_Bridge_Logger::log_action(get_site_url(), 'api/get', 'error', array(), array(
                'msg'    => $response->get_error_message(),
                'url'    => $url,
                'query'  => $query,
            ));
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            WPHubPro_Bridge_Logger::log_action(get_site_url(), 'api/get', 'http_error', array(), array(
                'msg'   => 'Non-2xx response',
                'code'  => $code,
                'body'  => $body,
                'url'   => $url,
                'query' => $query,
            ));
            return false;
        }
        $json = json_decode($body, true);
        return $json !== null ? $json : $body;
    }

    public static function post($endpoint, $body = array(), $headers = array()) {
        if (empty($endpoint)) {
            WPHubPro_Bridge_Logger::log_action(get_site_url(), 'api/post', 'error', array(), array(
                'msg'      => 'Missing endpoint.',
                'endpoint' => $endpoint,
            ));
            return false;
        }
        $default_headers = array(
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        );
        $headers = array_merge($default_headers, $headers);

        $args = array(
            'headers' => $headers,
            'body'    => is_string($body) ? $body : wp_json_encode($body),
            'timeout' => 15,
        );
        $response = wp_remote_post($endpoint, $args);
        if (is_wp_error($response)) {
            WPHubPro_Bridge_Logger::log_action(get_site_url(), 'api/post', 'error', array(), array(
                'msg'    => $response->get_error_message(),
                'url'    => $endpoint,
                'body'   => $body,
            ));
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        $resp_body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            WPHubPro_Bridge_Logger::log_action(get_site_url(), 'api/post', 'http_error', array(), array(
                'msg'   => 'Non-2xx response',
                'code'  => $code,
                'body'  => $resp_body,
                'url'   => $endpoint,
            ));
            return false;
        }
        $json = json_decode($resp_body, true);
        return $json !== null ? $json : $resp_body;
    }

    /**
     * Check if the site is authenticated.
     *
     * @return bool True if authenticated, false otherwise.
     */
    private function check_auth() {
        if (empty($this->site_id) || empty($this->secret)) {
            return false;
        }
        return true;
    }

    /**
	 * Send heartbeat to Appwrite.
	 *
	 * @return bool True on success, false on failure (logged).
	 */
	public static function send_healthcheck() {
		$site_id       = get_option( 'WPHUBPRO_SITE_ID' );
		$secret        = get_option( 'WPHUBPRO_API_KEY' );
		$endpoint      = get_option( 'WPHUBPRO_ENDPOINT' );
		$project       = get_option( 'WPHUBPRO_PROJECT_ID' );
		$heartbeat_url = get_option( 'WPHUBPRO_HEARTBEAT_URL', '' );

		if($this->check_auth()) {
            throw new Exception('Not authenticated');
        }

		$payload = array(
			'siteId'  => $site_id,
			'site_id' => $site_id,
			'secret'  => $secret,
		);

		// Prefer function domain when configured
		if ( ! empty( $heartbeat_url ) ) {
			$url = untrailingslashit( $heartbeat_url );
			$response = wp_remote_post(
				$url,
				array(
					'headers' => array(
						'Content-Type' => 'application/json',
					),
					'body'    => wp_json_encode( $payload ),
					'timeout' => 15,
				)
			);
		} else {
			// Fallback: executions API
			if ( empty( $endpoint ) || empty( $project ) ) {
				WPHubPro_Bridge_Logger::log_action( get_site_url(), 'heartbeat', 'meta', array(), array( 'skipped' => 'Missing endpoint or project_id for executions API' ) );
				return false;
			}
			$url = untrailingslashit( $endpoint ) . '/functions/site-heartbeat/executions';
			$request_body = wp_json_encode( array(
				'body'    => wp_json_encode( $payload ),
				'method'  => 'POST',
				'headers' => array(
					'Content-Type' => 'application/json',
				),
			) );
			$response = wp_remote_post(
				$url,
				array(
					'headers' => array(
						'Content-Type'       => 'application/json',
						'X-Appwrite-Project' => $project,
					),
					'body'    => $request_body,
					'timeout' => 15,
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body_response = wp_remote_retrieve_body( $response );

		if ( is_wp_error( $response ) ) {
			update_option( 'wphub_status', 'disconnected' );
			WPHubPro_Bridge_Logger::log_action( get_site_url(), 'heartbeat', 'meta', array(), array( 'error' => $response->get_error_message() ) );
			return false;
		}

		if ( $code < 200 || $code >= 300 ) {
			update_option( 'wphub_status', 'disconnected' );
			WPHubPro_Bridge_Logger::log_action( get_site_url(), 'heartbeat', 'meta', array(), array( 'error' => 'HTTP ' . $code, 'body' => substr( $body_response, 0, 200 ), 'site_id' => $site_id ) );
			return false;
		}

		update_option( 'WPHUBPRO_LAST_HEARTBEAT_AT', current_time( 'c' ) );
		update_option( 'wphub_status', 'connected' );
		WPHubPro_Bridge_Logger::log_action( get_site_url(), 'heartbeat', 'meta', array(), array( 'success' => true, 'site_id' => $site_id ) );
		return true;
	}
}
