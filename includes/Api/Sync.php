<?php
namespace WPHubPro\Api;

use WPHubPro\Config;
use WPHubPro\Helper;
use WPHubPro\Logger;

/**
 * Sync plugins_meta, themes_meta, and wp_meta to Appwrite sites collection.
 *
 * Called after plugin/theme/core changes (activate, deactivate, update, install, uninstall, WP version update).
 * Pushes data via manage-sites (`sync_site_meta`). Bridge uses site_secret for auth (Bridge→Hub).
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync plugins and themes meta to Appwrite.
 *
 * Static bootstrap: {@see init()} → {@see add_hooks()}. Deferred shutdown sync is registered from {@see schedule_sync()}.
 */
class Sync extends ApiBase {

	private static $instance = null;

	/** @var bool True if sync already scheduled for this request (avoids double sync on shutdown). */
	private static $sync_scheduled = false;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks for plugin/theme/core changes (WP Admin or REST).
	 */
	public static function init() {
		self::instance()->add_hooks();
	}

	/**
	 * Register WordPress hooks.
	 */
	private static function add_hooks() {
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_or_theme_change' ), 10, 0 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_or_theme_change' ), 10, 0 );
		add_action( 'deleted_plugin', array( __CLASS__, 'on_plugin_or_theme_change' ), 10, 0 );
		add_action( 'switch_theme', array( __CLASS__, 'on_plugin_or_theme_change' ), 10, 0 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_complete' ), 10, 2 );
	}

	/**
	 * Schedule sync to run at shutdown. Idempotent: only one shutdown hook per request.
	 * Call from {@see \WPHubPro\Api\Connect\ConnectionService} (save-connection), Plugins, Themes, or on_plugin_or_theme_change.
	 */
	public static function schedule_sync() {
		if ( self::$sync_scheduled ) {
			return;
		}
		self::$sync_scheduled = true;
		// Deferred per-request hook (not part of add_hooks() lifecycle registration).
		add_action( 'shutdown', array( self::instance(), 'sync_meta_to_appwrite' ), 5 );
	}

	/**
	 * Callback for plugin/theme change hooks. Schedules async sync to avoid blocking.
	 */
	public static function on_plugin_or_theme_change() {
		self::schedule_sync();
	}

	/**
	 * Callback for upgrader_process_complete (install, update).
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Options (type: 'plugin'|'theme'|'core', action: 'install'|'update').
	 */
	public static function on_upgrader_complete( $upgrader, $options ) {
		$type = isset( $options['type'] ) ? (string) $options['type'] : '';
		if ( $type === 'plugin' || $type === 'theme' || $type === 'core' ) {
			self::on_plugin_or_theme_change();
		}
	}

	/**
	 * Sync plugins_meta and themes_meta to Appwrite.
	 *
	 * Fetches current plugins and themes, formats them, and POSTs to the manage-sites function.
	 *
	 * @return bool True on success, false on failure (logged).
	 */
	public function sync_meta_to_appwrite() {
		$plugins_meta = Helper::get_plugins_meta();
		$themes_meta  = Helper::get_themes_meta();
		$wp_meta      = Helper::get_wp_meta_array();

		$payload = array(
			'plugins_meta' => $plugins_meta,
			'themes_meta'  => $themes_meta,
			'wp_meta'      => $wp_meta,
		);

		try {
			$this->post(
				Config::MANAGE_SITES_FUNCTION_ID,
				array_merge( $payload, array( 'action' => 'sync_site_meta' ) )
			);
		} catch ( \Exception $e ) {
			Logger::log_action( 'sync', 'meta', array(), array( 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString() ) );
			return false;
		}
		

		Logger::log_action( 'sync', 'meta', array(), array( 'success' => true, 'plugins' => (int) count( $plugins_meta ), 'themes' => (int) count( $themes_meta ), 'wp_meta' => ! empty( $wp_meta ) ) );
		return true;
	}

	
}
