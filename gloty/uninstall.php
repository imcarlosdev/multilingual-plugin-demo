<?php
/**
 * Fired when the plugin is uninstalled.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 1. Check if user wants to preserve data
$settings = get_option('gloty_settings');
if (isset($settings['preserve_data']) && $settings['preserve_data'] == 1) {
    // User wants to keep data. Exit without deleting.
    return;
}

// 2. Delete Options
delete_option('gloty_settings');

// 3. Remove Post Meta
// Standard WordPress way to remove all meta keys with a prefix is slightly heavy but necessary.
global $wpdb;
$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '_gloty_%'");
