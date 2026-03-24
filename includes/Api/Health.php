<?php
namespace WPHubPro\Api;

use WPHubPro\Config;
use WPHubPro\Cron\Job\Health as CronHealthJob;
use WPHubPro\Cron\Scheduler;
use WPHubPro\Logger;

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
class Health extends Api_Base {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function get_health_status() {
        $t0 = microtime(true);

        $safe_mode = file_exists(WPHUBPRO_BRIDGE_ABSPATH . '/.wphubpro_safe_mode');

        // Writable checks
        $paths = [
            'wp_content' => WP_CONTENT_DIR,
            'plugins'    => WP_PLUGIN_DIR,
            'backups'    => WP_CONTENT_DIR . '/upgrade-backups',
			'logs'       => WP_DEBUG_LOG,
        ];
        $writable = [];
        foreach ($paths as $k => $p) {
            $writable[$k] = [
                'path' => $p,
                'exists' => file_exists($p),
                'is_writable' => @is_writable($p),
            ];
        }

        // Theme info
        $theme = wp_get_theme();
        $theme_info = [
            'name'    => (string)$theme->get('Name'),
            'version' => (string)$theme->get('Version'),
            'stylesheet' => (string)$theme->get_stylesheet(),
            'template' => (string)$theme->get_template(),
        ];

        // Plugin info
        $updater_plugin_version = defined('WPHUBPRO_BRIDGE_VERSION') ? WPHUBPRO_BRIDGE_VERSION : null;
        $mu_recovery_present = file_exists(WPHUBPRO_BRIDGE_ABSPATH . '/wphubpro-bridge-recovery.php');

        // WooCommerce checks (best-effort, zonder fatals)
        $woo = self::get_woo_status();

        // Disk space
        $disk = self::get_disk_status();

        // Last update attempt (jij kunt dit tijdens update flow zelf zetten)
        $last_update = Config::get_last_update();

        // Backups summary (optioneel, beperkt tot max slugs)
        $backups = self::summarize_backups(WP_CONTENT_DIR . '/upgrade-backups', 10);

        // Debug tail (klein, optioneel)
        $debug_tail = self::tail_debug_log(WP_DEBUG_LOG, 5, 8192);

        $duration_ms = (int) round((microtime(true) - $t0) * 1000);

        $payload = [
            'timestamp_utc' => gmdate('c'),
            'response_time_ms' => $duration_ms,

            'site' => [
                'home_url' => home_url('/'),
                'site_url' => site_url('/'),
                'is_ssl' => is_ssl(),
                'client_ip' => self::client_ip(),
            ],

            'runtime' => [
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
            ],

            'state' => [
                'safe_mode' => $safe_mode,
                'mu_recovery_present' => $mu_recovery_present,
                'updater_plugin_version' => $updater_plugin_version,
                'theme' => $theme_info,
            ],

            'writable' => $writable,
            'disk' => $disk,
            'woocommerce' => $woo,
            'backups' => $backups,

            'last_update_attempt' => $last_update,
            'debug_log_tail' => $debug_tail,
        ];

        return $payload;
    }

    /**
     * POST current health snapshot to the Hub (used by WP-Cron and manual triggers).
     *
     * @return array|bool Response data on success, false on handled failure.
     */
    public static function send_health_status() {
        try {
            return self::instance()->post( 'site-health', self::get_health_status() );
        } catch ( \Exception $e ) {
            Logger::log_action( 'health', 'error', array(), array(
                'msg' => $e->getMessage(),
            ) );
            return false;
        }
    }

    /**
     * Unschedule health cron (call on disconnect).
     */
    public static function unschedule() {
        Scheduler::unschedule( CronHealthJob::class );
    }

    /**
     * Schedule health push with an immediate run (e.g. after save-connection).
     */
    public static function schedule() {
        Scheduler::schedule_with_immediate_run( CronHealthJob::class );
    }

    private static function get_woo_status(): array {
        $active = false;
        $version = null;

        // is_plugin_active vereist includes/plugin.php
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active = is_plugin_active('woocommerce/woocommerce.php');

        if ($active && defined('WC_VERSION')) {
            $version = WC_VERSION;
        } elseif ($active) {
            // fallback: try to read plugin header
            $plugin_file = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
            if (file_exists($plugin_file) && function_exists('get_plugin_data')) {
                $data = get_plugin_data($plugin_file, false, false);
                $version = $data['Version'] ?? null;
            }
        }

        // Check pages exist (best-effort)
        $checkout_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('checkout') : null;
        $cart_url     = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('cart') : null;

        return [
            'active' => (bool)$active,
            'version' => $version,
            'checkout_url' => $checkout_url ?: null,
            'cart_url' => $cart_url ?: null,
        ];
    }

    private static function get_disk_status(): array {
        $path = ABSPATH;
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        return [
            'path' => $path,
            'free_bytes' => is_numeric($free) ? (int)$free : null,
            'total_bytes' => is_numeric($total) ? (int)$total : null,
        ];
    }

    private static function summarize_backups(string $base_dir, int $max_slugs = 10): array {
        if ( ! is_dir( $base_dir ) ) {
            return array( 'present' => false, 'base' => $base_dir, 'slugs' => array() );
        }

        $slugs = array();
        $dirs  = glob( $base_dir . '/*', GLOB_ONLYDIR ) ?: array();
        $dirs  = array_slice( $dirs, 0, $max_slugs );

        foreach ( $dirs as $slug_dir ) {
            $slug      = basename( $slug_dir );
            $snapshots = glob( $slug_dir . '/*', GLOB_ONLYDIR ) ?: array();
            rsort( $snapshots );

            $slugs[] = array(
                'slug'   => $slug,
                'count'  => count( $snapshots ),
                'latest' => $snapshots[0] ?? null,
            );
        }

        return array(
            'present' => true,
            'base'    => $base_dir,
            'slugs'   => $slugs,
        );
    }

    private static function tail_debug_log(string $file, int $lines = 5, int $max_bytes = 8192): ?array {
        if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
            return null;
        }

        $size = filesize( $file );
        $read = min( $max_bytes, $size );
        $fp = @fopen( $file, 'rb' );
        if ( ! $fp ) {
            return null;
        }

        @fseek( $fp, -$read, SEEK_END );
        $chunk = @fread( $fp, $read );
        @fclose( $fp );

        if ( ! is_string( $chunk ) || $chunk === '' ) {
            return null;
        }

        $chunk = str_replace( array( "\r\n", "\r" ), "\n", $chunk );
        $parts = array_values( array_filter( explode( "\n", $chunk ), 'strlen' ) );
        $tail  = array_slice( $parts, -$lines );

        return array(
            'file'  => $file,
            'lines' => $tail,
        );
    }

    private static function client_ip(): string {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
