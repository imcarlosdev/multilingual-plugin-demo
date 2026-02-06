<?php

namespace Gloty\Admin;

use Gloty\Core\Language;

/**
 * AdminBar Class
 * Adds a language switcher to the WP Admin Bar.
 */
class AdminBar
{
    const COOKIE_NAME = 'gloty_admin_lang';

    public function __construct()
    {
        // Hook to add menu item
        add_action('admin_bar_menu', [$this, 'add_language_switcher'], 100);

        // Hook to handle language switch via URL
        add_action('admin_init', [$this, 'handle_language_switch']);
    }

    /**
     * Get the currently selected admin language.
     * 
     * @return string Language code, 'all', or default language if not set.
     */
    public static function get_current_admin_language()
    {
        // 1. AUTO-SYNC: If we are editing a post, use that post's language
        if (is_admin()) {
            $post_id = isset($_GET['post']) ? (int) $_GET['post'] : (isset($_POST['post_ID']) ? (int) $_POST['post_ID'] : 0);
            if ($post_id > 0) {
                $post_lang = get_post_meta($post_id, '_gloty_language', true);
                if ($post_lang) {
                    return $post_lang;
                }
            }
        }

        if (isset($_COOKIE[self::COOKIE_NAME])) {
            return sanitize_text_field($_COOKIE[self::COOKIE_NAME]);
        }

        return Language::get_default_language();
    }

    public function add_language_switcher($wp_admin_bar)
    {
        if (!is_admin()) {
            return;
        }

        $current_lang = self::get_current_admin_language();
        $active_languages = Language::get_active_languages();

        // Helper to get flag HTML
        $get_flag = function ($lang) {
            $flag_path = GLOTY_PATH . 'assets/flags/4x3/' . $lang . '.svg';
            if (file_exists($flag_path)) {
                $flag_url = GLOTY_URL . 'assets/flags/4x3/' . $lang . '.svg';
                return '<img src="' . esc_url($flag_url) . '" style="width:20px; height:auto; vertical-align:middle; margin-right:5px; border-radius:2px;" alt="' . esc_attr($lang) . '"> ';
            }
            return '';
        };

        // Parent Item
        $icon = $current_lang !== 'all' ? $get_flag($current_lang) : '';
        $wp_admin_bar->add_node([
            'id' => 'gloty_lang',
            'title' => $icon . 'Language: ' . strtoupper($current_lang === 'all' ? 'All' : $current_lang),
            'href' => '#',
            'meta' => ['class' => 'gloty-admin-switcher']
        ]);

        // "All Languages" Option
        $wp_admin_bar->add_node([
            'parent' => 'gloty_lang',
            'id' => 'gloty_lang_all',
            'title' => 'All Languages',
            'href' => add_query_arg('gloty_lang', 'all'),
        ]);

        // Active Languages Options
        foreach ($active_languages as $lang) {
            $wp_admin_bar->add_node([
                'parent' => 'gloty_lang',
                'id' => 'gloty_lang_' . $lang,
                'title' => $get_flag($lang) . strtoupper($lang) . (Language::get_default_language() === $lang ? ' (Default)' : ''),
                'href' => add_query_arg('gloty_lang', $lang),
            ]);
        }
    }

    public function handle_language_switch()
    {
        // 1. Manual switch via URL
        if (isset($_GET['gloty_lang'])) {
            $lang = sanitize_text_field($_GET['gloty_lang']);

            // Validate
            $active_languages = Language::get_active_languages();
            if ($lang !== 'all' && !in_array($lang, $active_languages)) {
                return;
            }

            $this->set_admin_cookie($lang);

            // Redirect to remove param
            $redirect_url = remove_query_arg('gloty_lang');
            wp_redirect($redirect_url);
            exit;
        }

        // 2. Auto-sync persistence: If editing a post, ensure cookie matches
        if (is_admin() && !wp_doing_ajax()) {
            $post_id = isset($_GET['post']) ? (int) $_GET['post'] : (isset($_POST['post_ID']) ? (int) $_POST['post_ID'] : 0);
            if ($post_id > 0) {
                $post_lang = get_post_meta($post_id, '_gloty_language', true);
                $current_cookie = isset($_COOKIE[self::COOKIE_NAME]) ? $_COOKIE[self::COOKIE_NAME] : '';

                if ($post_lang && $post_lang !== $current_cookie) {
                    $this->set_admin_cookie($post_lang);
                }
            }
        }
    }

    /**
     * Set the admin language cookie safely.
     */
    private function set_admin_cookie($lang)
    {
        setcookie(self::COOKIE_NAME, $lang, time() + 30 * DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
        $_COOKIE[self::COOKIE_NAME] = $lang;
    }
}
