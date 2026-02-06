<?php

namespace Gloty\Core;

/**
 * Installer Class
 * Handles plugin activation and deactivation tasks.
 */
class Installer
{

    /**
     * Run upon plugin activation.
     */
    public static function activate()
    {
        // 1. Create default options if they don't exist
        if (!get_option('gloty_settings')) {
            $defaults = [
                'default_language' => 'en',
                'active_languages' => ['en'],
            ];
            update_option('gloty_settings', $defaults);
        }

        // 2. Flush rewrite rules to ensure our custom routes work immediately
        // Note: In a real scenario, we might want to register the rules here first before flushing.
        // For now, we rely on the main plugin load to register them, but since activation happens *before* the next load,
        // we might need to manually trigger rule registration here or just flush. 
        // Best practice: Register rules, then flush.

        // We will implement the actual rule registration in Router logic, 
        // but for activation, we simply flush.
        flush_rewrite_rules();
    }

    /**
     * Run upon plugin deactivation.
     */
    public static function deactivate()
    {
        // Flush rewrite rules to remove our custom rules
        flush_rewrite_rules();
    }
}
