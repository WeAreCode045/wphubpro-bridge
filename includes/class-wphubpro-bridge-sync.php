<?php
/**
 * Sync plugins_meta and themes_meta to Appwrite sites collection.
 *
 * Called after plugin/theme actions (activate, deactivate, update, install, uninstall).
 * Pushes data to sync-site-meta Appwrite Function via JWT.
 *
 * @package WPHubPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync plugins and themes meta to Appwrite.
 */
class WPHubPro_Bridge_Sync extends WPHubPro_Bridge_API {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Register hooks for plugin/theme changes (WP Admin or REST).
	 */
	public static function init() {
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_or_theme_change' ), 10, 0 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_or_theme_change' ), 10, 0 );
		add_action( 'switch_theme', array( __CLASS__, 'on_plugin_or_theme_change' ), 10, 0 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_complete' ), 10, 2 );
	}

	/**
	 * Callback for plugin/theme change hooks. Schedules async sync to avoid blocking.
	 */
	public static function on_plugin_or_theme_change() {
		// Defer to avoid blocking; runs after request when safe.
		add_action( 'shutdown', array( self::$instance, 'sync_meta_to_appwrite' ), 5 );
	}

	/**
	 * Callback for upgrader_process_complete (install, update).
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Options (type: 'plugin'|'theme', action: 'install'|'update').
	 */
	public static function on_upgrader_complete( $upgrader, $options ) {
		$type = isset( $options['type'] ) ? $options['type'] : '';
		if ( $type === 'plugin' || $type === 'theme' ) {
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
		error_log(print_r($plugins_meta, true));
		$themes_meta  = self::get_themes_meta();
		$wp_meta      = class_exists( 'WPHubPro_Bridge_Details' ) ? WPHubPro_Bridge_Details::get_wp_meta_array() : array();

		$payload = array(
			'plugins_meta' => $plugins_meta,
			'themes_meta'  => $themes_meta,
			'wp_meta'      => $wp_meta,
		);

		try {
			$this->post( 'functions/sync-site-meta/executions', $payload );
		} catch ( Exception $e ) {
			WPHubPro_Bridge_Logger::log_action( 'sync', 'meta', array(), array( 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString() ) );
			return false;
		}
		

		WPHubPro_Bridge_Logger::log_action( 'sync', 'meta', array(), array( 'success' => true, 'plugins' => count( $plugins_meta ), 'themes' => count( $themes_meta ), 'wp_meta' => ! empty( $wp_meta ) ) );
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
		$active_plugins = WPHubPro_Bridge_Config::get_active_plugins();
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
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => in_array( $file, (array) $active_plugins, true ),
				'update'  => $update_version,
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

		foreach ( $all_themes as $slug => $theme ) {
			$meta[] = array(
				'stylesheet' => $slug,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'active'     => ( $slug === $current ),
				'update'     => isset( $updates->response[ $slug ]['new_version'] ) ? $updates->response[ $slug ]['new_version'] : null,
			);
		}
		return $meta;
	}
}
