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
class WPHubPro_Bridge_Health {

	/**
	 * Register REST routes for health.
	 *
	 * @param string $namespace API namespace.
	 */
	public function register_routes( $namespace ) {
		// Placeholder: add health endpoints when needed.
		// e.g. /health, /health/status
	}
}
