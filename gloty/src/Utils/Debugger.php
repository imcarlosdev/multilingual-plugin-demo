<?php

namespace Gloty\Utils;

/**
 * Debugger Class
 * Handles internal logging for Gloty.
 */
class Debugger
{
    /**
     * Log a message to gloty-debug.log in wp-content if debug mode is enabled.
     *
     * @param string $message
     * @param mixed $data Optional data to print_r
     */
    public static function log($message, $data = null)
    {
        $settings = get_option('gloty_settings');
        $debug_enabled = isset($settings['debug_mode']) ? $settings['debug_mode'] : 0;

        if (!$debug_enabled) {
            return;
        }

        $log_file = WP_CONTENT_DIR . '/gloty-debug.log';
        $timestamp = current_time('mysql');

        $output = "[{$timestamp}] {$message}";
        if ($data !== null) {
            $output .= " | Data: " . print_r($data, true);
        }
        $output .= "\n";

        error_log($output, 3, $log_file);
    }
}
