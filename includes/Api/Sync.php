<?php
namespace WPHubPro\Api;

use WPHubPro\Config;
use WPHubPro\Helper;
use WPHubPro\Logger;

/**
 * Sync plugins_meta, themes_meta, and wp_meta to Appwrite sites collection.
 *
 * Called after plugin/theme/core changes (activate, deactivate, update, install, uninstall, WP version update).
 * Pushes data to sync-site-meta Appwrite Function. Bridge uses site_secret for auth (Bridge→Hub).
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
	 * Call from Connect, Plugins, Themes, or on_plugin_or_theme_change.
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
	 * Fetches current plugins and themes, formats them, and POSTs to sync-site-meta function.
	 *
	 * @return bool True on success, false on failure (logged).
	 */
	public function sync_meta_to_appwrite() {
		$plugins_meta = self::get_plugins_meta();
		$themes_meta  = self::get_themes_meta();
		$wp_meta      = Helper::get_wp_meta_array();

		$payload = array(
			'plugins_meta' => $plugins_meta,
			'themes_meta'  => $themes_meta,
			'wp_meta'      => $wp_meta,
		);

		try {
			$this->post( 'sync-site-meta', $payload );
		} catch ( \Exception $e ) {
			Logger::log_action( 'sync', 'meta', array(), array( 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString() ) );
			return false;
		}
		

		Logger::log_action( 'sync', 'meta', array(), array( 'success' => true, 'plugins' => (int) count( $plugins_meta ), 'themes' => (int) count( $themes_meta ), 'wp_meta' => ! empty( $wp_meta ) ) );
		return true;
	}

	/**
	 * Get plugins list in meta format (file, name, version, active, update).
	 *
	 * @return array
	 */
	private static function get_plugins_meta() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins   = get_plugins();
		$active_plugins = Config::get_active_plugins();
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		$updates = get_site_transient( 'update_plugins' );
		$updates_response = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) ? $updates->response : array();

		$meta = array();
		foreach ( $all_plugins as $file => $data ) {
			$update_version = isset( $updates_response[ $file ] ) && ! empty( $updates_response[ $file ]->new_version )
				? $updates_response[ $file ]->new_version
				: null;
			$meta[] = array(
				'file'    => (string) $file,
				'name'    => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'active'  => in_array( $file, (array) $active_plugins, true ),
				'update'  => null !== $update_version ? (string) $update_version : null,
			);
		}
		return $meta;
	}

	/**
	 * Get themes list in meta format (stylesheet, name, version, active, update).
	 *
	 * @return array
	 */
	private static function get_themes_meta() {
		$all_themes = wp_get_themes();
		$current    = get_stylesheet();
		if ( function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}
		$updates = get_site_transient( 'update_themes' );
		$meta    = array();
		$resp    = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) )
			? $updates->response
			: array();

		foreach ( $all_themes as $slug => $theme ) {
			$upd = null;
			if ( isset( $resp[ $slug ]['new_version'] ) ) {
				$upd = (string) $resp[ $slug ]['new_version'];
			}
			$meta[] = array(
				'stylesheet' => (string) $slug,
				'name'       => (string) $theme->get( 'Name' ),
				'version'    => (string) $theme->get( 'Version' ),
				'active'     => ( (string) $slug === (string) $current ),
				'update'     => $upd,
			);
		}
		return $meta;
	}
}
