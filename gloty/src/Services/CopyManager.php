<?php

namespace Gloty\Services;

/**
 * CopyManager Class
 * Handles post duplication for translations.
 */
class CopyManager
{

    /**
     * Duplicate a post to a target language.
     *
     * @param int $post_id Original Post ID
     * @param string $target_lang Target Language Code
     * @return int|WP_Error New Post ID or Error
     */
    public static function create_translation($post_id, $target_lang)
    {
        $post = get_post($post_id);
        if (!$post) {
            return new \WP_Error('invalid_post', 'Post not found.');
        }

        // 1. Prepare post data
        $post_data = [
            'post_author' => get_current_user_id(),
            'post_date' => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', 1),
            'post_content' => $post->post_content,
            'post_title' => $post->post_title . ' (' . strtoupper($target_lang) . ')', // Suffix for clarity
            'post_status' => 'draft', // Safety first
            'post_type' => $post->post_type,
            'post_name' => $post->post_name . '-' . $target_lang, // Unique slug
            'post_parent' => $post->post_parent, // Warning: Parent needs to be translated too? Complexity. For now, keep logic simple.
        ];

        // 2. Insert Post
        $new_id = wp_insert_post($post_data);

        if (is_wp_error($new_id)) {
            return $new_id;
        }

        // 3. Link Translations
        // If the original already has a "Group ID" or "Original ID", use that.
        // Simplified Logic: The first post is the "Original".

        $original_id = get_post_meta($post_id, '_gloty_original_id', true);
        if (!$original_id) {
            // The post being copied IS the original (or at least we treat it so)
            $original_id = $post_id;
            // Mark the source as original to itself just to be sure
            update_post_meta($post_id, '_gloty_original_id', $post_id);
            update_post_meta($post_id, '_gloty_language', \Gloty\Core\Language::get_default_language()); // Assume default if missing
        }

        update_post_meta($new_id, '_gloty_original_id', $original_id);
        update_post_meta($new_id, '_gloty_language', $target_lang);

        // 4. Duplicate Meta
        $meta = get_post_meta($post_id);
        foreach ($meta as $key => $values) {
            // Skip Gloty internals and WP internals
            if (strpos($key, '_gloty_') === 0)
                continue;

            foreach ($values as $value) {
                // IMPORTANT: failed get_post_meta() without strict key returns raw strings, even if serialized.
                // We must unserialize first, otherwise add_post_meta() will double-serialize it.
                $value = maybe_unserialize($value);

                // Elementor compatibility: Meta values need to be slashed (JSON or arrays) as Elementor uses wp_slash() on save
                if (strpos($key, '_elementor_') === 0) {
                    // Special case for _elementor_data: it must be slashed JSON string
                    if ($key === '_elementor_data' && is_string($value)) {
                        $value = wp_slash($value);
                    } elseif (is_array($value) || is_object($value)) {
                        $value = wp_slash($value);
                    }
                }
                add_post_meta($new_id, $key, $value);
            }
        }

        // 5. Force Elementor Builder Mode
        if ($post->post_type === 'elementor_library') {
            update_post_meta($new_id, '_elementor_edit_mode', 'builder');
        }

        // 6. Duplicate Taxonomies (Crucial for Elementor Template Types: Header, Footer, etc.)
        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($post_id, $taxonomy);
            if (!is_wp_error($terms) && !empty($terms)) {
                $term_ids = wp_list_pluck($terms, 'term_id');
                wp_set_object_terms($new_id, $term_ids, $taxonomy);
            }
        }

        // Special check for Elementor Library Type (Sometimes missed by get_object_taxonomies)
        $el_terms = wp_get_object_terms($post_id, 'elementor_library_type');
        if (!is_wp_error($el_terms) && !empty($el_terms)) {
            $el_term_ids = wp_list_pluck($el_terms, 'term_id');
            wp_set_object_terms($new_id, $el_term_ids, 'elementor_library_type');
        }

        // 7. Explicitly ensure _elementor_template_type is synced if it's a taxonomy term
        // Some versions of Elementor use a term, others use meta. We do both.
        $template_type = get_post_meta($post_id, '_elementor_template_type', true);
        if ($template_type) {
            update_post_meta($new_id, '_elementor_template_type', $template_type);
        }

        return $new_id;
    }
}
