<?php
/**
 * Base HTTP client for Bridge → Hub (Appwrite-style) requests.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps GET/POST to the configured Hub API with site auth headers.
 */
class WPHubPro_Bridge_API {
    private $site_secret = '';
    private $site_id = '';
    private $base_url = '';
    private $project_id = '';
    private $endpoint = '';

    private static int $connection_timeout = 15;
    private static string $path_prefix = '/functions';
    private static string $path_postfix = '/executions';


    public function __construct() {
		$this->refresh_config();
    }

	/**
	 * Refresh config from options. Call before each request so we use fresh
	 * site_id/site_secret after save_connection (sync runs on shutdown).
	 */
	protected function refresh_config() {
		$this->base_url    = WPHubPro_Bridge_Config::get_api_base_url();
		$this->site_secret = WPHubPro_Bridge_Config::get_site_secret();
		$this->site_id     = WPHubPro_Bridge_Config::get_site_id();
		$this->project_id  = WPHubPro_Bridge_Config::get_project_id();
	}

    /**
     * Get the headers for the API request.
     * Uses site_secret for Bridge→Hub auth (Bearer token).
     *
     * @return array The headers for the API request.
     */
    protected function get_headers() {
        return array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->site_secret,
            'X-Site-Secret' => $this->site_secret,
            'X-Appwrite-Project' => $this->project_id,
        );
    }

    /**
     * Get the URL for the API request.
     *
     * @param string $endpoint The endpoint to get the URL for.
     * @return string The URL for the API request.
     */
    protected function get_url(string $endpoint) {
        return untrailingslashit( $this->base_url ) . self::$path_prefix . '/' . $endpoint . self::$path_postfix;
    }

    /**
     * Simple base API class wrapping GET and POST requests for Appwrite endpoints.
     * Uses wp_remote_get and wp_remote_post.
     */
    protected function get(string $endpoint, array $query = []) {
        $this->refresh_config();
        $this->check_auth();
        $this->endpoint = $endpoint;
        if (empty($endpoint)) {
            throw new ValidationError('Missing endpoint.', 0, null, array('endpoint' => $endpoint));
        }

        $url = $this->get_url($endpoint);
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
    /**
     * Send a POST request to the API.
     *
     * @param string $endpoint The endpoint to send the request to.
     * @param array $body The body of the request.
     * @return array The response from the API.
     * @throws Exception If the endpoint is missing or the request fails.
     */
    protected function post(string $endpoint, array $body = []) {
        $this->refresh_config();
        $this->endpoint = $endpoint;
        if (empty($this->endpoint)) {
            WPHubPro_Bridge_Logger::log_action($endpoint, 'error', array(), array(
                'msg'      => 'Missing endpoint.',
                'endpoint' => $endpoint,
            ));
            throw new Exception('Missing endpoint.');
        }
        
        $this->check_auth();

        $payload = array_merge($body, array( 'site_id' => $this->site_id, 'secret' => $this->site_secret ));
        $request_body = wp_json_encode(array(
            'body'    => is_string($payload) ? $payload : wp_json_encode($payload),

        ));

        $url = $this->get_url($endpoint);

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
        if ( empty( $this->site_secret ) ) {
            throw new AuthenticationError( 'Not authenticated: site_secret' );
        }
        return true;
    }
}
