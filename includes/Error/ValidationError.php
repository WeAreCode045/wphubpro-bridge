<?php
namespace WPHubPro\Error;

/**
 * Validation error for WPHubPro Bridge.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when request parameters or input validation fails.
 */
class ValidationError extends BaseError {

	/**
	 * @return string
	 */
	protected function get_log_action() {
		return 'validation_error';
	}
}
