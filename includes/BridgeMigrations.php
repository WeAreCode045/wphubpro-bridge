<?php
namespace WPHubPro;

/**
 * One-time migrations run on {@see 'plugins_loaded'}.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema migrations for bridge options.
 */
final class BridgeMigrations {

	/** Bump when adding a new migration step in {@see maybe_run()}. */
	public const SCHEMA_VERSION = 2;

	/** Canonical Appwrite REST base (matches {@see Config::get_api_base_url()} default). */
	public const API_BASE_URL = 'https://appwrite.wphub.pro/v1';

	/**
	 * Run pending migrations once per schema bump.
	 */
	public static function maybe_run() : void {
		$current = (int) get_option( Config::OPTION_DB_VERSION, 0 );
		if ( $current >= self::SCHEMA_VERSION ) {
			return;
		}

		if ( $current < 2 ) {
			self::force_api_base_url();
		}

		update_option( Config::OPTION_DB_VERSION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Force-set API base URL option (delete + add so the row is rewritten).
	 */
	public static function force_api_base_url() : void {
		update_option( Config::OPTION_API_BASE_URL, self::API_BASE_URL );
	}
}
