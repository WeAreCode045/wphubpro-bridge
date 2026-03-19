<?php
/**
 * Authentication error for WPHubPro Bridge.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when API key, JWT or other auth fails.
 */
class AuthenticationError extends BaseError {

	/**
	 * @return string
	 */
	protected function get_log_action() {
		return 'auth_error';
	}
}
