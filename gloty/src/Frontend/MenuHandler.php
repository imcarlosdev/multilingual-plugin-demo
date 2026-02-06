<?php

namespace Gloty\Frontend;

use Gloty\Core\Language;

/**
 * MenuHandler Class
 * Handles registering language-specific menu locations and swapping them on the frontend.
 */
class MenuHandler
{
    public function __construct()
    {
        // Register new locations (e.g. primary_es)
        // We use 'init' or 'after_setup_theme' with late priority to ensure original theme locations are registered.
        add_action('init', [$this, 'register_language_locations'], 999);

        // Filter the menu arguments to swap the location
        add_filter('wp_nav_menu_args', [$this, 'filter_menu_args']);
    }

    /**
     * Look at registered nav menus and register duplicates for active languages.
     */
    public function register_language_locations()
    {
        $locations = get_registered_nav_menus();
        $languages = Language::get_active_languages(); // Returns ['en', 'es']
        $default_lang = Language::get_default_language();
        $supported = Language::get_supported_languages();

        $new_locations = [];

        foreach ($locations as $location_id => $description) {
            foreach ($languages as $lang_code) {
                if ($lang_code === $default_lang) {
                    continue;
                }

                // Get friendly name
                $lang_data = $supported[$lang_code] ?? null;
                $lang_name = isset($lang_data['name']) ? $lang_data['name'] : strtoupper($lang_code);

                // Create new location ID: primary_es
                // Create new description: Primary Menu (Spanish)
                $new_id = $location_id . '_' . $lang_code;
                $new_desc = $description . ' (' . $lang_name . ')';

                $new_locations[$new_id] = $new_desc;
            }
        }

        if (!empty($new_locations)) {
            register_nav_menus($new_locations);
        }
    }

    /**
     * Intercept menu display and swap location if we are in a different language.
     *
     * @param array $args
     * @return array
     */
    public function filter_menu_args($args)
    {
        if (!defined('GLOTY_CURRENT_LANG')) {
            return $args;
        }

        $current_lang = GLOTY_CURRENT_LANG;
        $default_lang = Language::get_default_language();

        if ($current_lang === $default_lang) {
            return $args;
        }

        // Check if the requested location has a translated counterpart
        $theme_location = $args['theme_location'] ?? '';

        if ($theme_location) {
            $target_location = $theme_location . '_' . $current_lang;

            if (has_nav_menu($target_location)) {
                $args['theme_location'] = $target_location;
                // If a specific menu was set, unset it to allow the location's assigned menu to take over.
                if (isset($args['menu'])) {
                    unset($args['menu']);
                }
            }
        }

        return $args;
    }
}
