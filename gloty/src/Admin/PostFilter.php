<?php

namespace Gloty\Admin;

use Gloty\Core\Language;

/**
 * PostFilter Class
 * Filters post lists in Admin based on selected language.
 */
class PostFilter
{
    public function __construct()
    {
        add_action('pre_get_posts', [$this, 'filter_query']);
    }

    public function filter_query($query)
    {
        // Only run on admin, main query, and valid post types
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        // Check if we are on a list screen (edit.php)
        $screen = get_current_screen();
        if (!$screen || 'edit' !== $screen->base) {
            return;
        }

        // Validate Post Type
        $post_type = $screen->post_type;
        $allowed = ['post', 'page'];

        $settings = get_option('gloty_settings');
        if (isset($settings['elementor_support']) && $settings['elementor_support']) {
            $allowed[] = 'elementor_library';
        }

        if (!in_array($post_type, $allowed)) {
            return;
        }

        // Get Current Selected Language
        $current_lang = AdminBar::get_current_admin_language();

        // If 'all' is selected, do not filter
        if ($current_lang === 'all') {
            return;
        }

        // Modify Query
        $meta_query = $query->get('meta_query');
        if (!is_array($meta_query)) {
            $meta_query = [];
        }

        if ($current_lang === Language::get_default_language()) {
            // SHOW: Items with this language OR items without any language (Legacy/Original)
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key' => '_gloty_language',
                    'value' => $current_lang,
                    'compare' => '='
                ],
                [
                    'key' => '_gloty_language',
                    'compare' => 'NOT EXISTS'
                ]
            ];
        } else {
            // SHOW: Only items strictly assigned to this language
            $meta_query[] = [
                'key' => '_gloty_language',
                'value' => $current_lang,
                'compare' => '='
            ];
        }

        $query->set('meta_query', $meta_query);
    }
}
