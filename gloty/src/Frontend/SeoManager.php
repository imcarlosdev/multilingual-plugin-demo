<?php

namespace Gloty\Frontend;

use Gloty\Core\Language;

/**
 * SeoManager Class
 * Handles hreflang tags and standard SEO/Metatag filters.
 */
class SeoManager
{

    public function __construct()
    {
        add_action('wp_head', [$this, 'output_hreflang_tags']);

        // Yoast SEO integration (example)
        add_filter('wpseo_canonical', [$this, 'filter_canonical']);
    }

    public function output_hreflang_tags()
    {
        $post_id = 0;

        if (is_singular()) {
            $post_id = get_the_ID();
        } elseif (is_home() && 'page' === get_option('show_on_front')) {
            // Blog Page
            $post_id = get_queried_object_id();
        }

        if (!$post_id) {
            return;
        }

        $original_id = get_post_meta($post_id, '_gloty_original_id', true) ?: $post_id;

        // Get all translations of this content
        $translations = $this->get_all_translations($original_id);

        foreach ($translations as $lang => $trans_id) {
            echo '<link rel="alternate" hreflang="' . esc_attr($lang) . '" href="' . esc_url(get_permalink($trans_id)) . '" />' . "\n";
        }
    }

    private function get_all_translations($original_id)
    {
        // Query all linked posts
        // Note: This matches the logic in MetaBox/LanguageSwitcher. 
        // In a Production plugin, this should be in a centralized "TranslationRepository".

        $translations = [];

        // Add original itself
        $orig_lang = get_post_meta($original_id, '_gloty_language', true) ?: Language::get_default_language();
        $translations[$orig_lang] = $original_id;

        $args = [
            'post_type' => get_post_type($original_id), // Assuming same post type
            'meta_query' => [
                [
                    'key' => '_gloty_original_id',
                    'value' => $original_id
                ]
            ],
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];

        $query = new \WP_Query($args);
        foreach ($query->posts as $p_id) {
            $lang = get_post_meta($p_id, '_gloty_language', true);
            if ($lang) {
                $translations[$lang] = $p_id;
            }
        }

        return $translations;
    }

    public function filter_canonical($canonical)
    {
        // Yoast usually handles canonical well, but if we are on a translated page,
        // we must ensure the canonical points to SELF, not the original.
        // Since we are using separate posts, Yoast should automatically use the current post's permalink.
        // So explicit filtering might not be needed unless Yoast gets confused by our rewrite rules.
        return $canonical;
    }
}
