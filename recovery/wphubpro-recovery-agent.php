<?php
/**
 * Plugin Name: WPHubPro Recovery Agent
 * Description: Herstel- en monitoringsagent voor WPHubPro platform.
 * Version: 1.1.0
 */

if (!defined('ABSPATH')) exit;

class WPHubProRecoveryAgent {

    private $log_file;

    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/wphubpro-fatal-error.json';

        // Vang fatale fouten op (registreer dit zo vroeg mogelijk)
        register_shutdown_function([$this, 'capture_fatal_error']);

        $this->add_hooks();
    }

    /**
     * Register WordPress hooks.
     */
    private function add_hooks() {
        add_action('plugins_loaded', [$this, 'handle_platform_request'], 1);
    }

    public function handle_platform_request() {
        if (!isset($_SERVER['HTTP_AUTHORIZATION'])) return;

        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth_header, 'Bearer ') !== 0) return;

        $token = substr($auth_header, 7);
        
        // Haal de gedeelde sleutel uit de WP database (Config when bridge loaded, else get_option for mu-plugin)
        $shared_secret = class_exists( \WPHUBPRO\Config::class ) ? \WPHUBPRO\Config::get_api_key() : get_option( 'WPHUBPRO_API_KEY', '' );
        if ( ! $shared_secret ) return;

        $payload = $this->validate_jwt($token, $shared_secret);

        if ($payload) {
            $this->execute_action($payload);
        }
    }

    private function validate_jwt($token, $secret) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return false;

        list($header64, $payload64, $signature64) = $parts;

        // Valideer Signature met HMAC SHA256
        $signature = $this->base64_url_decode($signature64);
        $expected_sig = hash_hmac('sha256', "$header64.$payload64", $secret, true);

        if (!hash_equals($signature, $expected_sig)) return false;

        $payload = json_decode($this->base64_url_decode($payload64), true);

        // Check expiration (exp)
        if (isset($payload['exp']) && $payload['exp'] < time()) return false;

        return $payload;
    }

    private function execute_action($payload) {
        $action = $payload['action'] ?? '';

        switch ($action) {
            case 'get_status':
                wp_send_json_success([
                    'status' => 'online',
                    'php_version' => PHP_VERSION,
                    'wp_version' => get_bloginfo('version')
                ]);
                break;

            case 'get_error_log':
                if (file_exists($this->log_file)) {
                    $log_data = json_decode(file_get_contents($this->log_file), true);
                    wp_send_json_success($log_data);
                }
                wp_send_json_error('Geen fatal error gevonden.');
                break;

            case 'rollback_plugin':
                $slug = sanitize_text_field($payload['plugin_slug']);
                $plugin_dir = WP_PLUGIN_DIR . '/' . $slug;
                if (!file_exists($plugin_dir)) {
                    wp_send_json_error("Plugin map niet gevonden.");
                    break;
                }
                $disabled_dir = WP_PLUGIN_DIR . '/.disabled';
                if (!is_dir($disabled_dir)) {
                    if (!wp_mkdir_p($disabled_dir)) {
                        wp_send_json_error("Kon .disabled map niet aanmaken.");
                        break;
                    }
                }
                $dest = $disabled_dir . '/' . $slug;
                if (file_exists($dest)) {
                    $dest .= '_' . time();
                }
                if (rename($plugin_dir, $dest)) {
                    if (file_exists($this->log_file)) {
                        $log_content = file_get_contents($this->log_file);
                        $error_log_path = $dest . '/error.log';
                        $header = '[' . gmdate('Y-m-d H:i:s') . '] WPHubPro rollback - fatal error data:' . "\n";
                        file_put_contents($error_log_path, $header . $log_content . "\n", LOCK_EX);
                        @unlink($this->log_file);
                    }
                    wp_send_json_success("Plugin $slug is verplaatst naar .disabled.");
                } else {
                    wp_send_json_error("Kon plugin niet verplaatsen.");
                }
                break;
        }
        exit;
    }

    public function capture_fatal_error() {
        $error = error_get_last();
        $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        
        if ($error && in_array($error['type'], $fatal_types)) {
            $data = [
                'timestamp' => time(),
                'message'   => $error['message'],
                'file'      => $error['file'],
                'line'      => $error['line'],
                'type'      => $error['type']
            ];
            file_put_contents($this->log_file, json_encode($data));
        }
    }

    private function base64_url_decode($data) {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
}

new WPHubProRecoveryAgent();