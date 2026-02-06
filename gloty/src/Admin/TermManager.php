<?php

namespace Gloty\Admin;

use Gloty\Core\Language;
use Gloty\Core\TermMeta;

/**
 * TermManager Class
 * Adds language controls to Taxonomy Terms (Categories, Tags).
 */
class TermManager
{

    public function __construct()
    {
        // Support built-in taxonomies
        $taxonomies = ['category', 'post_tag'];

        foreach ($taxonomies as $taxonomy) {
            // Add Field
            add_action("{$taxonomy}_add_form_fields", [$this, 'render_add_field']);
            // Edit Field
            add_action("{$taxonomy}_edit_form_fields", [$this, 'render_edit_field']);
            // Save
            // Save
            add_action("created_{$taxonomy}", [$this, 'save_term_meta']);
            add_action("edited_{$taxonomy}", [$this, 'save_term_meta']);
        }

        // Handle duplication request
        add_action('admin_post_gloty_create_term_translation', [$this, 'handle_create_term_translation']);
    }

    /**
     * Render field on "Add New Category" screen.
     */
    public function render_add_field($taxonomy)
    {
        $active_languages = Language::get_active_languages();
        ?>
        <div class="form-field term-gloty-lang-wrap">
            <label for="gloty_language"><?php _e('Language', 'gloty'); ?></label>
            <select name="gloty_language" id="gloty_language">
                <?php
                $admin_lang = AdminBar::get_current_admin_language();
                $default_selection = ($admin_lang && $admin_lang !== 'all' && Language::is_active($admin_lang)) ? $admin_lang : Language::get_default_language();

                foreach ($active_languages as $lang): ?>
                    <option value="<?php echo esc_attr($lang); ?>" <?php selected($lang, $default_selection); ?>>
                        <?php echo esc_html(strtoupper($lang)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p><?php _e('Select the language for this term.', 'gloty'); ?></p>
        </div>
        <?php
    }

    /**
     * Render field on "Edit Category" screen.
     */
    public function render_edit_field($term)
    {
        $active_languages = Language::get_active_languages();
        $current_lang = TermMeta::get_term_language($term->term_id);
        $original_id = TermMeta::get_original_term_id($term->term_id);

        ?>
        <tr class="form-field term-gloty-lang-wrap">
            <th scope="row"><label for="gloty_language"><?php _e('Language', 'gloty'); ?></label></th>
            <td>
                <select name="gloty_language" id="gloty_language">
                    <?php foreach ($active_languages as $lang): ?>
                        <option value="<?php echo esc_attr($lang); ?>" <?php selected($lang, $current_lang); ?>>
                            <?php echo esc_html(strtoupper($lang)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php _e('Select the language for this term.', 'gloty'); ?></p>
            </td>
        </tr>
        <tr class="form-field term-gloty-translations-wrap">
            <th scope="row"><?php _e('Translations', 'gloty'); ?></th>
            <td>
                <ul>
                    <?php
                    foreach ($active_languages as $lang) {
                        if ($lang === $current_lang)
                            continue;

                        // Logic to find translation (Naive implementation)
                        $trans_term = $this->find_translated_term($term->taxonomy, $original_id, $lang);

                        echo '<li><strong>' . strtoupper($lang) . ':</strong> ';
                        if ($trans_term) {
                            echo '<a href="' . esc_url(get_edit_term_link($trans_term->term_id, $term->taxonomy)) . '">' . esc_html($trans_term->name) . '</a>';
                            if ($trans_term->term_id === $original_id) {
                                echo ' <em>(Original)</em>';
                            }
                        } else {
                            // Create Link
                            $create_url = admin_url('admin-post.php?action=gloty_create_term_translation&from=' . $term->term_id . '&to=' . $lang . '&nonce=' . wp_create_nonce('gloty_create_term'));
                            echo '<a href="' . esc_url($create_url) . '">Create +</a>';
                        }
                        echo '</li>';
                    }
                    ?>
                </ul>
                <p class="description">To link terms, ensure they share the same "Original ID". Currently, this is handled by
                    manual assignment logic or import.</p>
                <input type="hidden" name="gloty_original_id" value="<?php echo esc_attr($original_id); ?>">
            </td>
        </tr>
        <?php
    }

    /**
     * Find a translated term.
     */
    private function find_translated_term($taxonomy, $original_id, $lang)
    {
        // 1. Check if the Original Term ITSELF is the one we are looking for.
        // This handles cases where the Original Term doesn't have metadata explicitly set (legacy terms)
        // or simply IS the term for that language.
        $original_lang = TermMeta::get_term_language($original_id);
        if ($original_lang === $lang) {
            $original_term = get_term($original_id, $taxonomy);
            if ($original_term && !is_wp_error($original_term)) {
                return $original_term;
            }
        }

        // 2. Search for other terms linked to this original ID
        $terms = get_terms([
            'taxonomy' => $taxonomy, // 'category' or 'post_tag'
            'hide_empty' => false,
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
            ]
        ]);

        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0];
        }
        return null;
    }

    /**
     * Save term metadata.
     */
    public function save_term_meta($term_id)
    {
        if (isset($_POST['gloty_language'])) {
            $lang = sanitize_text_field($_POST['gloty_language']);
            TermMeta::set_term_language($term_id, $lang);
        } else {
            // Fallback for REST/Gutenberg or Quick Edit where field might be missing
            // Use current AdminBar language if available
            $admin_lang = AdminBar::get_current_admin_language();
            if ($admin_lang && $admin_lang !== 'all') {
                TermMeta::set_term_language($term_id, $admin_lang);
            } else {
                // Default to default language
                TermMeta::set_term_language($term_id, Language::get_default_language());
            }
        }

        // Validate if we are in Add or Edit mode regarding Original ID
        // On creation, if not specified, it is self.
        // On edit, we preserve what is there unless changed (hidden field).

        $existing_original = TermMeta::get_original_term_id($term_id);

        if (isset($_POST['gloty_original_id']) && !empty($_POST['gloty_original_id'])) {
            // If manually linking (advanced usage)
            TermMeta::set_original_term_id($term_id, intval($_POST['gloty_original_id']));
        } elseif ($existing_original === $term_id || !$existing_original) {
            // It's a new term or wasn't linked.
            // Check if we are "Adding" and maybe we want to link it?
            // For now, default to self.
            if (!get_term_meta($term_id, '_gloty_original_id', true)) {
                TermMeta::set_original_term_id($term_id, $term_id);
            }
        }
    }

    /**
     * Handle the admin-post action to create a term translation.
     */
    public function handle_create_term_translation()
    {
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'gloty_create_term')) {
            wp_die('Security check failed');
        }

        $from_id = intval($_GET['from']);
        $to_lang = sanitize_text_field($_GET['to']);

        $source_term = get_term($from_id);
        if (!$source_term || is_wp_error($source_term)) {
            wp_die('Source term not found.');
        }

        $taxonomy = $source_term->taxonomy;

        // Prepare new term data
        // We append language code to name to differentiate, user can edit later.
        $new_name = $source_term->name . ' (' . strtoupper($to_lang) . ')';
        $new_slug = $source_term->slug . '-' . $to_lang;

        $args = [
            'slug' => $new_slug,
            'description' => $source_term->description,
            'parent' => 0 // Handling parent hierarchy duplication is complex, skip for now.
        ];

        $inserted = wp_insert_term($new_name, $taxonomy, $args);

        if (is_wp_error($inserted)) {
            // If it already exists, maybe try to link it? 
            // For now, die with error.
            if (isset($inserted->error_data['term_exists'])) {
                $existing_id = $inserted->error_data['term_exists'];
                // Optional: We *could* just link it if it's not linked?
                // But let's stick to safe "Error" for now to avoid accidental mess.
                wp_die('Term already exists: ' . $inserted->get_error_message());
            }
            wp_die($inserted->get_error_message());
        }

        $new_term_id = $inserted['term_id'];

        // Link metadata
        $original_id = TermMeta::get_original_term_id($from_id);

        // Ensure source has original ID if it was missing (self)
        if (!$original_id || $original_id == $from_id) { // Wait, logic above says get_original returns self if missing.
            // But we might need to explicitly save it if it wasn't saved before.
            if (!get_term_meta($from_id, '_gloty_original_id', true)) {
                TermMeta::set_original_term_id($from_id, $from_id);
                $original_id = $from_id;
            }
        }

        TermMeta::set_term_language($new_term_id, $to_lang);
        TermMeta::set_original_term_id($new_term_id, $original_id);

        // Redirect to edit screen of new term
        wp_redirect(get_edit_term_link($new_term_id, $taxonomy, $source_term->post_type)); // post_type arg is tricky for generic tax, but optional.
        exit;
    }
}
