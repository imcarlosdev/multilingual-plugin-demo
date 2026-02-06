<?php

namespace Gloty\Frontend;

use Gloty\Core\Language;
use Gloty\Core\TermMeta;

/**
 * LanguageSwitcher Class
 * Renders the language selector.
 */
class LanguageSwitcher
{

    public function __construct()
    {
        add_shortcode('gloty_switcher', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Enqueue switcher styles.
     */
    public function enqueue_styles()
    {
        wp_enqueue_style(
            'gloty-frontend',
            GLOTY_URL . 'assets/css/gloty-frontend.css',
            [],
            GLOTY_VERSION
        );
    }

    /**
     * Enqueue switcher scripts.
     */
    public function enqueue_scripts()
    {
        wp_enqueue_script(
            'gloty-frontend',
            GLOTY_URL . 'assets/js/gloty-frontend.js',
            [],
            GLOTY_VERSION,
            true
        );
    }

    /**
     * Render the switcher HTML.
     */
    public function render_shortcode()
    {
        $active_languages = Language::get_active_languages();
        $current_lang = defined('GLOTY_CURRENT_LANG') ? GLOTY_CURRENT_LANG : Language::get_default_language();

        // 1. Pre-calculate available URLs
        $available_urls = [];
        foreach ($active_languages as $lang) {
            $url = $this->get_translation_url($lang);
            if ($url !== null) {
                $available_urls[$lang] = $url;
            }
        }

        // 2. Return empty string if no other languages are available
        // If count <= 1, it means only the current language exists for this view.
        if (count($available_urls) <= 1) {
            return '';
        }

        $supported = Language::get_supported_languages();

        // Output buffering
        ob_start();
        ?>
        <div class="gloty-language-switcher-container">
            <div class="gloty-switcher-trigger">
                <span class="gloty-globe-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="2" y1="12" x2="22" y2="12"></line>
                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z">
                        </path>
                    </svg>
                </span>
                <span class="gloty-current-label"><?php echo strtoupper($current_lang); ?></span>
            </div>
            <ul class="gloty-switcher-dropdown">
                <?php foreach ($available_urls as $lang => $url):
                    $label = isset($supported[$lang]['name']) ? $supported[$lang]['name'] : strtoupper($lang);
                    ?>
                    <li class="<?php echo ($lang === $current_lang) ? 'is-active' : ''; ?>">
                        <a href="<?php echo esc_url($url); ?>">
                            <?php echo esc_html($label); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get URL for a specific language for the current context.
     *
     * @param string $target_lang
     * @return string
     */
    public function get_translation_url($target_lang)
    {
        $default_lang = Language::get_default_language();

        // 1. IS SINGULAR (Page, Post)
        if (is_singular()) {
            $post_id = get_the_ID();
            $original_id = get_post_meta($post_id, '_gloty_original_id', true);

            if (!$original_id) {
                // If this post doesn't have an original ID, assumes it IS the original
                $original_id = $post_id;
            }

            // Check if this post is already in the target lang
            $current_post_lang = get_post_meta($post_id, '_gloty_language', true) ?: $default_lang;
            if ($current_post_lang === $target_lang) {
                return get_permalink($post_id);
            }

            // Find translation
            $args = [
                'post_type' => get_post_type($post_id),
                'meta_query' => [
                    'relation' => 'AND',
                    ['key' => '_gloty_original_id', 'value' => $original_id],
                    ['key' => '_gloty_language', 'value' => $target_lang]
                ],
                'fields' => 'ids',
                'posts_per_page' => 1
            ];

            $query = new \WP_Query($args);
            if ($query->have_posts()) {
                // Translation found!
                // get_permalink() will run through Router::filter_permalink => correct URL
                return get_permalink($query->posts[0]);
            } else {
                // Check if the Original ID itself is the target language (e.g. we are on a translation, looking for original)
                $orig_lang = get_post_meta($original_id, '_gloty_language', true) ?: $default_lang;
                if ($orig_lang === $target_lang) {
                    return get_permalink($original_id);
                }

                // Strict Mode: No translation exists for this page.
                // User Request: "show only the options available"
                // Return NULL to indicate unavailability
                return null;
            }
        }

        // 2. IS TERM ARCHIVE (Category, Tag, Taxonomy)
        // Moved ABOVE is_home() because is_home() can be true on category pages in some setups
        if (is_category() || is_tag() || is_tax()) {
            $object = get_queried_object();

            // Fallback: If get_queried_object() is null (seen in debug logs), try to recover term
            if (!$object || !($object instanceof \WP_Term)) {
                if (is_category()) {
                    $cat_id = get_query_var('cat');
                    if ($cat_id) {
                        $object = get_term($cat_id, 'category');
                    } else {
                        $cat_name = get_query_var('category_name');
                        if ($cat_name)
                            $object = get_term_by('slug', $cat_name, 'category');
                    }
                } elseif (is_tag()) {
                    $tag_id = get_query_var('tag_id');
                    if ($tag_id) {
                        $object = get_term($tag_id, 'post_tag');
                    } else {
                        $tag_slug = get_query_var('tag');
                        if ($tag_slug)
                            $object = get_term_by('slug', $tag_slug, 'post_tag');
                    }
                } elseif (is_tax()) {
                    $term_slug = get_query_var('term');
                    $tax_name = get_query_var('taxonomy');
                    if ($term_slug && $tax_name) {
                        $object = get_term_by('slug', $term_slug, $tax_name);
                    }
                }
            }

            if ($object instanceof \WP_Term) {
                $term_id = $object->term_id;
                $taxonomy = $object->taxonomy;
                $slug = $object->slug;

                $original_id = TermMeta::get_original_term_id($term_id);
                $current_term_lang = TermMeta::get_term_language($term_id);

                // Self link if language matches
                if ($current_term_lang === $target_lang) {
                    return get_term_link($object);
                }

                // Check if Original IS the target
                $orig_lang = TermMeta::get_term_language($original_id);
                if ($orig_lang === $target_lang) {
                    return get_term_link($original_id, $taxonomy);
                }

                // Find translation
                $terms = get_terms([
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                    'meta_query' => [
                        'relation' => 'AND',
                        ['key' => '_gloty_original_id', 'value' => $original_id],
                        ['key' => '_gloty_language', 'value' => $target_lang]
                    ],
                    'number' => 1
                ]);

                if (!empty($terms) && !is_wp_error($terms)) {
                    // Check if array or object (get_terms returns array of objects by default)
                    $term_obj = is_array($terms) ? $terms[0] : $terms;
                    return get_term_link($term_obj);
                }

                return null;
            }
        }

        // 3. IS HOME / FRONT PAGE
        // If we are on the homepage, we should always allow switching
        if (is_front_page() || is_home()) {
            // Handle "Posts Page" (Blog) specifically if it's separate from Front Page
            if (is_home() && 'page' === get_option('show_on_front')) {
                $posts_page_id = (int) get_option('page_for_posts');
                if ($posts_page_id > 0) {
                    // Find translation of the posts page
                    // Logic similar to singular lookup


                    // 1. Get Original ID of current posts page (if we are on the translated one)
                    // Because of our Router fix, is_home() is true on the translated page.
                    // But get_queried_object_id() might return the translated ID.

                    $current_obj_id = get_queried_object_id();
                    // If we are on the default blog page, current_obj_id is posts_page_id.
                    // If we are on translated blog page, current_obj_id is the translated page ID.

                    $original_id = get_post_meta($current_obj_id, '_gloty_original_id', true);
                    if (!$original_id) {
                        $original_id = $current_obj_id;
                    }

                    // Check if target lang is this current page
                    $current_post_lang = get_post_meta($current_obj_id, '_gloty_language', true) ?: $default_lang;
                    if ($current_post_lang === $target_lang) {
                        // We are already here? No, this function builds URLs for OTHER languages.
                        // But if we generated the list, we want self-link.
                        return get_permalink($current_obj_id);
                    }

                    // Check if Original is the target
                    $orig_lang = get_post_meta($original_id, '_gloty_language', true) ?: $default_lang;
                    if ($orig_lang === $target_lang) {
                        return get_permalink($original_id);
                    }

                    // Find translation
                    $args = [
                        'post_type' => 'page', // Blog holder is a page
                        'meta_query' => [
                            'relation' => 'AND',
                            ['key' => '_gloty_original_id', 'value' => $original_id],
                            ['key' => '_gloty_language', 'value' => $target_lang]
                        ],
                        'fields' => 'ids',
                        'posts_per_page' => 1
                    ];

                    $query = new \WP_Query($args);
                    if ($query->have_posts()) {
                        return get_permalink($query->posts[0]);
                    }

                    // If no translation for Blog Page found, fall back to Root? 
                    // Or return null? 
                    // User wants "Smart", effectively hiding it if not exists.
                    // But usually main blog should exist. If not, return null.
                    return null;
                }
            }

            if ($target_lang === $default_lang) {
                return site_url('/');
            } else {
                return site_url('/' . $target_lang . '/');
            }
        }

        // 4. Fallback (Archives, 404, etc) -> Link to Language Root
        // We use site_url to avoid Router::filter_home_url appending the CURRENT lang
        if ($target_lang === $default_lang) {
            return site_url('/');
        } else {
            return site_url('/' . $target_lang . '/');
        }
    }
}
