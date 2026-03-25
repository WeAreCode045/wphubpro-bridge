<?php
namespace WPHubPro\Health;

/**
 * Core health information for WPHubPro Bridge.
 *
 * Returns core information about the WordPress site.
 *
 * @package WPHubPro\Health
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site details: WordPress version, plugin/theme counts, PHP info.
 */
class Core {

	/**
	 * Get site details as array (for sync to Hub wp_meta).
	 *
	 * @return array
	 */
    public static function get_info() {
        return array(
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
            );
    }

    public static function get_health_status() {
        // 1. Laad de benodigde WordPress files voor Site Health
        if ( ! class_exists( 'WP_Site_Health' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        }
    
        $site_health = \WP_Site_Health::get_instance();
        
        // 2. Verzamel de verschillende onderdelen van de gezondheidscheck
        
        // Systeeminformatie (PHP versie, Server, Database, etc.)
        // $info = $site_health->get_test_directory_sizes(); // Optioneel: mapgroottes
        
        // Voer de standaard tests uit
        // Let op: sommige tests zijn asynchroon (zoals loopback requests)
        $tests = \WP_Site_Health::get_tests();
        
        $results = [
            'status' => 'success',
            'timestamp' => current_time('mysql'),
            'site_info' => $site_health->enqueue_scripts(), // Dit triggert ook interne data verzameling
            'data' => [
                'direct_tests' => [],
                'async_tests'  => []
            ]
        ];
    
        // Voer de directe (synchronous) tests uit
        foreach ( $tests['direct'] as $test_name => $test ) {
            if ( is_callable( $test['test'] ) ) {
                $results['data']['direct_tests'][$test_name] = call_user_func( $test['test'] );
            }
        }
    
        // Voor de async tests kun je in een API vaak alleen de namen/urls teruggeven, 
        // omdat deze normaal via AJAX in de admin worden afgehandeld.
        $results['data']['async_tests'] = array_keys($tests['async']);
    
        return results;
    }

    public static function get_wp_version() {
        return get_bloginfo('version');
    }

    public static function get_php_version() {
        return PHP_VERSION;
    }

    public static function get_server_software() {
        return $_SERVER['SERVER_SOFTWARE'] ?? null;
    }

    public static function get_memory_limit() {
        return ini_get('memory_limit');
    }

    public static function get_max_execution_time() {
        return ini_get('max_execution_time');
    }
    
    public static function get_upload_max_filesize() {
        return ini_get('upload_max_filesize');
    }

    public static function get_post_max_size() {
        return ini_get('post_max_size');
    }

    public static function get_disk_status() {
        return array(
            'total' => disk_total_space(ABSPATH),
            'free' => disk_free_space(ABSPATH),
        );
    }

    private static function tail_debug_log(string $file, int $lines = 5, int $max_bytes = 8192): ?array {
        if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
            return null;
        }

        $size = filesize( $file );
        if ( ! is_numeric( $size ) || $size <= 0 ) {
            return null;
        }
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

    /**
	 * Get number of installed plugins.
	 *
	 * @return int
	 */
	private function get_plugins_count() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		return is_array( $all ) ? count( $all ) : 0;
	}

	/**
	 * Get number of installed themes.
	 *
	 * @return int
	 */
	private function get_themes_count() {
		$all = wp_get_themes();
		return is_array( $all ) ? count( $all ) : 0;
	}

}