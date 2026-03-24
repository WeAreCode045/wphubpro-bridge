<?php
namespace WPHUBPRO\Error;

/**
 * Not found error for WPHubPro Bridge.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when a requested resource (plugin, theme, etc.) is not found.
 */
class RequestError extends BaseError {

	/**
	 * @return string
	 */
	protected function get_log_action() {
		return 'request_error';
	}
}
