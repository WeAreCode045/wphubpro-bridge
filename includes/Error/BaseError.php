<?php
namespace WPHubPro\Error;

use WPHubPro\Logger;

/**
 * Base exception for WPHubPro Bridge that logs via Logger.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base error class. On construction, logs the error via Logger::log_action().
 */
class BaseError extends \Exception {

	/**
	 * @param string           $message     Exception message.
	 * @param int              $code        Exception code.
	 * @param \Throwable|null $previous    Previous throwable.
	 * @param array          $log_context Optional context for log_action: site_url, action, endpoint, request.
	 */
	public function __construct( $message = '', $code = 0, $previous = null, array $log_context = array() ) {
		parent::__construct( $message, $code, $previous );

		$action   = isset( $log_context['action'] ) ? $log_context['action'] : $this->get_log_action();
		$endpoint = isset( $log_context['endpoint'] ) ? $log_context['endpoint'] : '';
		$request  = isset( $log_context['request'] ) && is_array( $log_context['request'] ) ? $log_context['request'] : array();
		$response = array(
			'error' => $this->getMessage(),
			'code'  => $this->getCode(),
			'type'  => static::class,
		);

		if ( class_exists( Logger::class ) ) {
			Logger::log_action( $action, $endpoint, $request, $response );
		}
	}

	/**
	 * Action name used when logging. Override in child classes.
	 *
	 * @return string
	 */
	protected function get_log_action() {
		return 'error';
	}
}
