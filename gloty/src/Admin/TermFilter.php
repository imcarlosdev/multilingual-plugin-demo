<?php

namespace Gloty\Admin;

use Gloty\Core\Language;

/**
 * TermFilter Class
 * Filters taxonomy term lists in Admin based on selected language.
 */
class TermFilter
{
    public function __construct()
    {
        // 'parse_term_query' is a good place to intercept term queries.
        // It runs for both get_terms() and the admin list table.
        add_action('parse_term_query', [$this, 'filter_term_query']);
    }

    public function filter_term_query($query)
    {
        // Detect context
        $is_rest = defined('REST_REQUEST') && REST_REQUEST;
        $is_admin = is_admin();

        // Debug Log
        // error_log("Gloty Filter: is_rest=" . ($is_rest?1:0) . " is_admin=" . ($is_admin?1:0));

        if (!$is_rest && !$is_admin) {
            return;
        }

        // Whitelist: Do not filter menus. We want all menus available for assignment to locations.
        $taxonomies = $query->query_vars['taxonomy'] ?? [];
        if (in_array('nav_menu', (array) $taxonomies)) {
            return;
        }

        // If admin, check screen to avoid running on irrelevant pages (like settings)
        // We want it on: edit-tags.php, post.php, post-new.php (classic editor / quick edit)
        if ($is_admin && !$is_rest && function_exists('get_current_screen')) {
            $screen = get_current_screen();
            // Core screens that list terms: edit-tags, post, page?
            // Actually, for Classic Editor metaboxes, it might call get_terms.
            // Let's whitelist screens where we know we want filtering.
            $allowed_bases = ['edit-tags', 'post'];
            if (!$screen || !in_array($screen->base, $allowed_bases)) {

                // Allow AJAX requests (e.g., searching for a category in metabox)
                if (!wp_doing_ajax()) {
                    return;
                }
            }
        }

        // Get Current Selected Language
        $current_lang = AdminBar::get_current_admin_language();

        // If 'all' is selected, do not filter
        if ($current_lang === 'all') {
            return;
        }

        // Check if filters are suppressed (internal requests)
        if (isset($query->query_vars['suppress_filters']) && $query->query_vars['suppress_filters']) {
            return;
        }

        // Get existing meta query
        $meta_query = $query->query_vars['meta_query'];
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
                    'compare' => 'NOT EXISTS' // Use NOT EXISTS to find terms with no language set
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

        // Set the meta query back
        $query->query_vars['meta_query'] = $meta_query;
    }
}
