<?php

namespace Gloty\Admin;

use Gloty\Core\Language;
use Gloty\Services\CopyManager;

/**
 * MetaBox Class
 * Adds translation controls to the post editor.
 */
class MetaBox
{

    public function __construct()
    {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('admin_post_gloty_create_translation', [$this, 'handle_create_translation']);
        add_action('save_post', [$this, 'save_language_meta']);
        add_action('wp_insert_post', [$this, 'set_initial_language'], 10, 3);
        // Also run on save_post to capture late updates (REST API) - Wait, save_post runs BEFORE REST terms update.
        // We need specific REST hooks.
        add_action('rest_after_insert_post', [$this, 'handle_rest_insert'], 10, 3);
        add_action('rest_after_insert_page', [$this, 'handle_rest_insert'], 10, 3);

        // Keep save_post for Classic Editor / Quick Edit
        add_action('save_post', [$this, 'set_initial_language'], 20, 3);

        // AJAX Handlers for AI Translation
        add_action('wp_ajax_gloty_ai_get_strings', [$this, 'ajax_get_strings']);
        add_action('wp_ajax_gloty_ai_translate', [$this, 'ajax_translate']);
        add_action('wp_ajax_gloty_ai_save', [$this, 'ajax_save_translation']);

        // Enqueue Assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets($hook)
    {
        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }

        $assets_dir = dirname(dirname(__DIR__)) . '/assets/';
        $css_ver = file_exists($assets_dir . 'css/admin-ai.css') ? filemtime($assets_dir . 'css/admin-ai.css') : '1.0.0';
        $js_ver = file_exists($assets_dir . 'js/admin-ai.js') ? filemtime($assets_dir . 'js/admin-ai.js') : '1.0.0';

        wp_enqueue_style('gloty-admin-ai', plugin_dir_url(dirname(__DIR__)) . 'assets/css/admin-ai.css', [], $css_ver);
        wp_enqueue_script('gloty-admin-ai', plugin_dir_url(dirname(__DIR__)) . 'assets/js/admin-ai.js', ['jquery'], $js_ver, true);

        wp_localize_script('gloty-admin-ai', 'glotyAi', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gloty_ai_nonce'),
            'loading_msg' => 'Translating content...',
            'error_msg' => 'Error: Suggest trying individual fields if the AI continues to timeout.'
        ]);
    }

    public function handle_rest_insert($post, $request, $creating)
    {
        $this->set_initial_language($post->ID, $post, true);
    }

    public function add_meta_box()
    {
        $screens = ['post', 'page'];

        // Dynamic Elementor Support
        $settings = get_option('gloty_settings');
        if (isset($settings['elementor_support']) && $settings['elementor_support']) {
            $screens[] = 'elementor_library';
        }

        foreach ($screens as $screen) {
            add_meta_box(
                'gloty_translations',
                'Gloty Translations',
                [$this, 'render_meta_box'],
                $screen,
                'side',
                'high'
            );
        }
    }

    public function render_meta_box($post)
    {
        $active_languages = Language::get_active_languages();
        $current_lang = get_post_meta($post->ID, '_gloty_language', true);

        // If no language set, assume default or admin selected language
        if (!$current_lang) {
            $admin_lang = AdminBar::get_current_admin_language();
            if ($admin_lang && $admin_lang !== 'all' && Language::is_active($admin_lang)) {
                $current_lang = $admin_lang;
            } else {
                $current_lang = Language::get_default_language();
            }
        }

        // Manual Language Switcher
        echo '<p><strong>Language:</strong></p>';
        echo '<select name="gloty_language" style="width:100%; margin-bottom:10px;">';
        foreach ($active_languages as $lang) {
            echo '<option value="' . esc_attr($lang) . '" ' . selected($lang, $current_lang, false) . '>' . strtoupper($lang) . '</option>';
        }
        echo '</select>';

        echo '<hr>';
        echo '<p><strong>Translations:</strong></p>';
        echo '<ul>';

        // Find Original ID
        $original_id = get_post_meta($post->ID, '_gloty_original_id', true);
        if (!$original_id) {
            $original_id = $post->ID; // This is the original
        }

        // 1. If we are on a translation, show "Translate from Original"
        $settings = get_option('gloty_settings');
        if ($original_id !== $post->ID && isset($settings['ai_engine']) && $settings['ai_engine'] !== 'none') {
            $original_lang = get_post_meta($original_id, '_gloty_language', true) ?: Language::get_default_language();
            echo '<div style="margin: 10px 0; padding: 10px; background: #f0f6fb; border: 1px solid #c3d9e9; border-radius: 4px; text-align: center;">';
            echo '<p style="margin: 0 0 8px 0; font-size: 12px;">Content is currently in <strong>' . strtoupper($original_lang) . '</strong>?</p>';
            echo '<button type="button" class="button button-primary gloty-ai-translate" style="width:100%;" 
                    data-post-id="' . $original_id . '" 
                    data-target-id="' . $post->ID . '" 
                    data-lang="' . esc_attr($current_lang) . '">
                    Auto-Translate 🤖
                  </button>';
            echo '</div>';
            echo '<hr>';
        }

        foreach ($active_languages as $lang) {
            if ($lang === $current_lang)
                continue;

            // Find translation for this lang linked to the same original OR being the original
            // Query: post_meta original_id = $original_id AND language = $lang
            // Optimization: In a real plugin, we might cache these links.

            $args = [
                'post_type' => $post->post_type,
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => '_gloty_original_id',
                        'value' => $original_id
                    ],
                    [
                        'key' => '_gloty_language',
                        'value' => $lang
                    ]
                ],
                'fields' => 'ids'
            ];

            // Edge case: if the sought language IS the language of the original post
            // The "Original" matches the lang we are looking for
            // The Original Post might not have _gloty_original_id set if it's new, OR it points to itself.

            $query = new \WP_Query($args);
            $translation_id = 0;

            if ($query->have_posts()) {
                $translation_id = $query->posts[0];
            } elseif ($lang === get_post_meta($original_id, '_gloty_language', true)) {
                $translation_id = $original_id;
            }

            echo '<li>';
            echo strtoupper($lang) . ': ';

            if ($translation_id) {
                $edit_link = get_edit_post_link($translation_id);
                echo '<a href="' . esc_url($edit_link) . '">Edit ✏️</a>';

                // AI Translation Option
                $settings = get_option('gloty_settings');
                if (isset($settings['ai_engine']) && $settings['ai_engine'] !== 'none') {
                    echo ' | <a href="#" class="gloty-ai-translate" data-post-id="' . $post->ID . '" data-target-id="' . $translation_id . '" data-lang="' . esc_attr($lang) . '">AI 🤖</a>';
                }
            } else {
                // Create Link
                $create_url = admin_url('admin-post.php?action=gloty_create_translation&from=' . $post->ID . '&to=' . $lang . '&nonce=' . wp_create_nonce('gloty_create'));
                echo '<a href="' . esc_url($create_url) . '">Create +</a>';
            }
            echo '</li>';
        }
        echo '</ul>';

        // Modal Container (Hidden)
        echo '<div id="gloty-ai-modal" style="display:none;"></div>';
    }

    public function handle_create_translation()
    {
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'gloty_create')) {
            wp_die('Security check failed');
        }

        $from_id = intval($_GET['from']);
        $to_lang = sanitize_text_field($_GET['to']);

        $new_id = CopyManager::create_translation($from_id, $to_lang);

        if (is_wp_error($new_id)) {
            wp_die($new_id->get_error_message());
        }

        // Redirect to the new post
        wp_redirect(get_edit_post_link($new_id, 'raw'));
        exit;
    }

    public function save_language_meta($post_id)
    {
        // Simple save handler if we allowed changing language in a dropdown (we don't right now, but good practice)
        if (isset($_POST['gloty_language'])) {
            update_post_meta($post_id, '_gloty_language', sanitize_text_field($_POST['gloty_language']));

            // Ensure original ID is set if missing
            if (!get_post_meta($post_id, '_gloty_original_id', true)) {
                update_post_meta($post_id, '_gloty_original_id', $post_id);
            }
        }
    }

    /**
     * Set initial language for auto-drafts and fix default category.
     */
    public function set_initial_language($post_id, $post, $update)
    {
        $allowed_types = ['post', 'page'];

        // Dynamic Elementor Support
        $settings = get_option('gloty_settings');
        if (isset($settings['elementor_support']) && $settings['elementor_support']) {
            $allowed_types[] = 'elementor_library';
        }

        // Only care about allowed types
        if (!in_array($post->post_type, $allowed_types)) {
            return;
        }

        // 1. Set Language for Auto-Drafts (Preview usage)
        $current_lang = get_post_meta($post_id, '_gloty_language', true);
        if (!$current_lang) {
            $admin_lang = AdminBar::get_current_admin_language();
            if ($admin_lang && $admin_lang !== 'all' && Language::is_active($admin_lang)) {
                update_post_meta($post_id, '_gloty_language', $admin_lang);
                $current_lang = $admin_lang;
            } else {
                update_post_meta($post_id, '_gloty_language', Language::get_default_language());
                $current_lang = Language::get_default_language();
            }
        }

        // Ensure Original ID
        if (!get_post_meta($post_id, '_gloty_original_id', true)) {
            update_post_meta($post_id, '_gloty_original_id', $post_id);
        }

        // 2. Fix Categories for Mismatched Languages (Scan and Swap)
        if ($post->post_type === 'post') {
            // Force clean cache before reading
            clean_object_term_cache($post_id, 'post');

            // Must suppress filters because TermFilter might hide the 'wrong language' category we are trying to find!
            $post_cats = wp_get_object_terms($post_id, 'category', ['fields' => 'ids', 'suppress_filters' => true]);
            $new_cats = [];
            $changed = false;

            if (empty($post_cats)) {
                // Case: Gutenberg sends empty categories (Draft). WP Core hasn't applied default yet.
                // We preemptively apply the TRANSLATED default category.

                $default_cat_id = (int) get_option('default_category', 1);
                $original_term_id = get_term_meta($default_cat_id, '_gloty_original_id', true) ?: $default_cat_id;

                if ($current_lang !== Language::get_default_language()) {
                    $args = [
                        'taxonomy' => 'category',
                        'meta_query' => [
                            'relation' => 'AND',
                            ['key' => '_gloty_original_id', 'value' => $original_term_id],
                            ['key' => '_gloty_language', 'value' => $current_lang]
                        ],
                        'fields' => 'ids',
                        'hide_empty' => false
                    ];
                    $terms = get_terms($args);

                    if (!empty($terms) && !is_wp_error($terms)) {
                        $translated_def_id = $terms[0];
                        $new_cats[] = $translated_def_id;
                        $changed = true;
                    }
                }
            } else {
                foreach ($post_cats as $cat_id) {
                    $cat_lang = get_term_meta($cat_id, '_gloty_language', true) ?: Language::get_default_language();

                    if ($cat_lang === $current_lang) {
                        $new_cats[] = $cat_id;
                    } else {
                        // Mismatch: Try to find translation
                        $original_term_id = get_term_meta($cat_id, '_gloty_original_id', true) ?: $cat_id;

                        $args = [
                            'taxonomy' => 'category',
                            'meta_query' => [
                                'relation' => 'AND',
                                ['key' => '_gloty_original_id', 'value' => $original_term_id],
                                ['key' => '_gloty_language', 'value' => $current_lang]
                            ],
                            'fields' => 'ids',
                            'hide_empty' => false
                        ];

                        $terms = get_terms($args);
                        if (!empty($terms) && !is_wp_error($terms)) {
                            $new_cats[] = $terms[0];
                            $changed = true;
                        } else {
                            $new_cats[] = $cat_id;
                        }
                    }
                }
            }

            if ($changed) {
                // Ensure unique
                $new_cats = array_unique($new_cats);

                // Update
                wp_set_post_categories($post_id, $new_cats);

                // Clear cache
                clean_post_cache($post_id);
                clean_object_term_cache($post_id, 'post'); // Ensure standard WP function clears it
            } else {
            }
        }
    }

    /**
     * AJAX: Get strings for a post.
     */
    public function ajax_get_strings()
    {
        check_ajax_referer('gloty_ai_nonce', 'nonce');
        $post_id = intval($_POST['post_id']);

        $strings = \Gloty\Services\AiTranslator::get_strings($post_id);
        wp_send_json_success($strings);
    }

    /**
     * AJAX: Translate strings using AI.
     */
    public function ajax_translate()
    {
        check_ajax_referer('gloty_ai_nonce', 'nonce');
        $strings = stripslashes_deep($_POST['strings']); // Array of {id, value}
        $target_lang = sanitize_text_field($_POST['target_lang']);

        $translations = \Gloty\Services\AiTranslator::translate($strings, $target_lang);

        if (is_wp_error($translations)) {
            wp_send_json_error($translations->get_error_message());
        }

        wp_send_json_success($translations);
    }

    /**
     * AJAX: Save translations to the target post.
     */
    public function ajax_save_translation()
    {
        check_ajax_referer('gloty_ai_nonce', 'nonce');
        $target_id = intval($_POST['target_id']);
        $translations = stripslashes_deep($_POST['translations']); // Array of {id, translation}

        \Gloty\Services\AiTranslator::apply_translations($target_id, $translations);
        wp_send_json_success('Saved successfully.');
    }
}
