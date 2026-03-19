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
    private $base_url = '';
    private $project_id = '';
    private $endpoint = '';

    private static int $connection_timeout = 15;


    public function __construct() {
		$this->base_url   = WPHubPro_Bridge_Config::get_api_base_url();
		$this->api_key    = WPHubPro_Bridge_Config::get_api_key();
		$this->site_id    = WPHubPro_Bridge_Config::get_site_id();
        $this->project_id = WPHubPro_Bridge_Config::get_project_id();
    }

    /**
     * Get the headers for the API request.
     *
     * @return array The headers for the API request.
     */
    protected function get_headers() {
        return array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_key,
            'X-Appwrite-Project' => $this->project_id,
        );
    }

    /**
     * Simple base API class wrapping GET and POST requests for Appwrite endpoints.
     * Uses wp_remote_get and wp_remote_post.
     */
    protected function get(string $endpoint, array $query = []) {
        $this->check_auth();
        $this->endpoint = $endpoint;
        if (empty($endpoint)) {
            throw new ValidationError('Missing endpoint.', 0, null, array('endpoint' => $endpoint));
        }

        $url = untrailingslashit( $this->base_url ) . '/' . $endpoint;
        if (!empty($query) && is_array($query)) {
            $url = add_query_arg($query, $url);
        }

        $headers = $this->get_headers();

        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => self::$connection_timeout,
        ));
        
        if (is_wp_error($response)) {
            throw new RequestError($response->get_error_message(), 0, null, array('url' => $url, 'query' => $query));
            // WPHubPro_Bridge_Logger::log_action(get_site_url(), 'api/get', 'error', array(), array(
            //     'msg'    => $response->get_error_message(),
            //     'url'    => $url,
            //     'query'  => $query,
            // ));
            // return false;
        }
        // return $this->resolve_response($response);
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
    /**
     * Send a POST request to the API.
     *
     * @param string $endpoint The endpoint to send the request to.
     * @param array $body The body of the request.
     * @return array The response from the API.
     * @throws Exception If the endpoint is missing or the request fails.
     */
    protected function post(string $endpoint, array $body = []) {
        $this->endpoint = $endpoint;
        if (empty($this->endpoint)) {
            WPHubPro_Bridge_Logger::log_action($endpoint, 'error', array(), array(
                'msg'      => 'Missing endpoint.',
                'endpoint' => $endpoint,
            ));
            throw new Exception('Missing endpoint.');
        }
        
        $this->check_auth();

        $payload = array_merge($body, ['siteId' => $this->site_id,'site_id' => $this->site_id, 'secret' => $this->api_key]);
        $request_body = wp_json_encode(array(
            'body'    => is_string($payload) ? $payload : wp_json_encode($payload),

        ));

        $url = untrailingslashit( $this->base_url ) . '/' . $endpoint;
        error_log(print_r($url, true));

        $response = wp_remote_post(
            $url,
            array(
                'headers' => $this->get_headers(),
                'body'    => $request_body,
                'timeout' => self::$connection_timeout,
            )
        );
        if (is_wp_error($response)) {
            WPHubPro_Bridge_Logger::log_action($url, 'error', array(), array(
                'msg'    => $response->get_error_message(),
                'url'    => $url,
                'body'   => $body,
            ));
            throw new Exception('Error: ' . $response->get_error_message());
        }
        
        return $this->resolve_response($response);
    }

    /**
     * Check the response from the API.
     *
     * @param int $code The HTTP response code.
     * @return array The response body.
     * @throws RequestError If the response is not a 2xx response.
     */
    protected function resolve_response($response) {
        $code = wp_remote_retrieve_response_code($response);
        $resp_body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            WPHubPro_Bridge_Logger::log_action('api/post', 'http_error', array(), array(
                'msg'   => 'Non-2xx response',
                'code'  => $code,
                'body'  => $resp_body,
                'url'   => untrailingslashit( $this->base_url ) . '/' . $this->endpoint,
            ));
            throw new RequestError('Non-2xx response', $code, null, array('code' => $code, 'body' => $resp_body, 'url' => $this->endpoint));

            // return false;
        }
        $json = json_decode($resp_body, true);
        return $json !== null ? $json : $resp_body;
    }

    /**
     * Check if the site is authenticated.
     *
     * @return bool True if authenticated, false otherwise.
     * @throws Exception If the site is not authenticated.
     */
    private function check_auth() {
        if (empty($this->api_key)) {
            throw new AuthenticationError("Not authenticated: api_key");
        }
        return true;
    }

    // /**
	//  * Send heartbeat to Appwrite.
	//  *
	//  * @return bool True on success, false on failure (logged).
	//  */
	// public static function send_heartbeat() {
		
        
    //     try {
    //         $response = $this->post('/heartbeat');
    //     } catch (Exception $e) {
    //         WPHubPro_Bridge_Logger::log_action(get_site_url(), 'heartbeat', 'error', array(), array(
    //             'msg' => $e->getMessage(),
    //         ));
    //         return false;
    //     }


	// 	// Prefer function domain when configured
		
		
	// 		// // Fallback: executions API
	// 		// if ( empty( $endpoint ) || empty( $project ) ) {
	// 		// 	WPHubPro_Bridge_Logger::log_action( get_site_url(), 'heartbeat', 'meta', array(), array( 'skipped' => 'Missing endpoint or project_id for executions API' ) );
	// 		// 	return false;
	// 		// }
	// 		// $url = untrailingslashit( $endpoint ) . '/functions/site-heartbeat/executions';
	// 		// $request_body = wp_json_encode( array(
	// 		// 	'body'    => wp_json_encode( $payload ),
	// 		// 	'method'  => 'POST',
	// 		// 	'headers' => array(
	// 		// 		'Content-Type' => 'application/json',
	// 		// 	),
	// 		// ) );
	// 		// $response = wp_remote_post(
	// 		// 	$url,
	// 		// 	array(
	// 		// 		'headers' => array(
	// 		// 			'Content-Type'       => 'application/json',
	// 		// 			'X-Appwrite-Project' => $project,
	// 		// 		),
	// 		// 		'body'    => $request_body,
	// 		// 		'timeout' => self::$connection_timeout,
	// 		// 	)
	// 		// );
		

	// 	if ( is_wp_error( $response ) ) {
	// 		update_option( WPHubPro_Bridge_Config::OPTION_STATUS, 'disconnected' );
	// 		WPHubPro_Bridge_Logger::log_action( 'heartbeat', 'meta', array(), array( 'error' => $response->get_error_message() ) );
	// 		return false;
	// 	}

	// 	if ( $code < 200 || $code >= 300 ) {
	// 		update_option( WPHubPro_Bridge_Config::OPTION_STATUS, 'disconnected' );
	// 		WPHubPro_Bridge_Logger::log_action( 'heartbeat', 'meta', array(), array( 'error' => 'HTTP ' . $code, 'body' => substr( $body_response, 0, 200 ), 'site_id' => $site_id ) );
	// 		return false;
	// 	}

	// 	update_option( WPHubPro_Bridge_Config::OPTION_LAST_HEARTBEAT_AT, current_time( 'c' ) );
	// 	update_option( WPHubPro_Bridge_Config::OPTION_STATUS, 'connected' );
	// 	WPHubPro_Bridge_Logger::log_action( 'heartbeat', 'meta', array(), array( 'success' => true, 'site_id' => $site_id ) );
	// 	return true;
	// }
}
