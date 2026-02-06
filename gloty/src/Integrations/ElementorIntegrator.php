<?php

namespace Gloty\Integrations;

use Gloty\Core\Language;

/**
 * ElementorIntegrator Class
 * Handles frontend template swapping for Elementor Pro Theme Builder.
 */
class ElementorIntegrator
{
    /**
     * @var bool Flag to prevent infinite loops during template swapping.
     */
    private static $is_swapping = false;

    public function __construct()
    {
        // Only run if Elementor support is enabled
        $settings = get_option('gloty_settings');
        if (!isset($settings['elementor_support']) || !$settings['elementor_support']) {
            return;
        }

        // Hook into Elementor Pro Theme Builder to swap IDs
        add_filter('elementor/theme/get_location_templates/template_id', [$this, 'swap_template_id']);
    }

    /**
     * Swap the template ID with its translation if available.
     *
     * @param int $template_id
     * @return int
     */
    public function swap_template_id($template_id)
    {
        if (!$template_id || self::$is_swapping) {
            return $template_id;
        }

        $current_lang = defined('GLOTY_CURRENT_LANG') ? GLOTY_CURRENT_LANG : Language::get_default_language();
        $default_lang = Language::get_default_language();

        $original_id = get_post_meta($template_id, '_gloty_original_id', true) ?: $template_id;
        $template_lang = get_post_meta($template_id, '_gloty_language', true) ?: $default_lang;

        if ($current_lang === $template_lang) {
            return (int) $template_id;
        }

        // If the current site is the default language, and the template isn't, swap back to original
        if ($current_lang === $default_lang) {
            return (int) $original_id;
        }

        // Find the translation for the current language
        // Use direct SQL for maximum reliability (bypasses all WP_Query filters)
        global $wpdb;
        $sql = $wpdb->prepare("
            SELECT p.ID 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id 
            INNER JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id
            WHERE m1.meta_key = '_gloty_original_id' AND m1.meta_value = %d
            AND m2.meta_key = '_gloty_language' AND m2.meta_value = %s
            AND p.post_status IN ('publish', 'private', 'draft')
            LIMIT 1
        ", $original_id, $current_lang);

        $translated_id = $wpdb->get_var($sql);

        if ($translated_id) {
            return (int) $translated_id;
        }

        return $template_id;
    }
}
