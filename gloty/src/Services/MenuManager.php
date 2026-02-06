<?php

namespace Gloty\Services;

use Gloty\Core\Language;

/**
 * MenuManager Class
 * Handles manual menu duplication.
 */
class MenuManager
{

    public function __construct()
    {
        add_action('admin_init', [$this, 'handle_menu_duplication']);
        add_action('admin_notices', [$this, 'admin_notices']);
        // We could add a UI button in 'nav-menus.php' via js or hooks,
        // but for "Architecture-focused" MVC, let's keep it simple: 
        // A utility page or parameters in our Settings page for now, 
        // OR hooked into the existing Menu admin page if possible.
        // Spec #14: "Provide a 'Duplicate Menu to Language X' function in the Appearance > Menus panel."
        add_action('admin_footer-nav-menus.php', [$this, 'add_duplication_ui']);
    }

    public function add_duplication_ui()
    {
        $languages = Language::get_active_languages();
        ?>
        <script>
            jQuery(document).ready(function ($) {
                $('.manage-menus').append('<h3 style="margin-top:20px;">Gloty Duplication</h3>');
                <?php foreach ($languages as $lang): ?>
                    $('.manage-menus').append('<a href="<?php echo admin_url('nav-menus.php?action=gloty_duplicate_menu&lang=' . $lang); ?>&menu=' + $('#menu').val() + '" class="button">Duplicate to <?php echo strtoupper($lang); ?></a> ');
                <?php endforeach; ?>
            });
        </script>
        <?php
    }

    public function handle_menu_duplication()
    {
        if (isset($_GET['action']) && $_GET['action'] === 'gloty_duplicate_menu' && isset($_GET['lang']) && isset($_GET['menu'])) {
            $menu_id = intval($_GET['menu']);
            $target_lang = sanitize_text_field($_GET['lang']);

            $menu_object = wp_get_nav_menu_object($menu_id);

            if (!$menu_object) {
                return;
            }

            // Create new menu
            $new_menu_name = $menu_object->name . ' (' . strtoupper($target_lang) . ')';
            $new_menu_id = wp_create_nav_menu($new_menu_name);

            if (is_wp_error($new_menu_id)) {
                add_settings_error('gloty_notices', 'gloty_menu_error', $new_menu_id->get_error_message(), 'error');
                return;
            }

            // Get items
            $items = wp_get_nav_menu_items($menu_id);
            foreach ($items as $item) {
                wp_update_nav_menu_item($new_menu_id, 0, [
                    'menu-item-title' => $item->title . ' [' . strtoupper($target_lang) . ']',
                    'menu-item-url' => $item->url, // Note: User must manually update links or we could try to find translations map
                    'menu-item-status' => 'publish'
                ]);
            }

            wp_redirect(admin_url('nav-menus.php?menu=' . $new_menu_id));
            exit;
        }
    }

    public function admin_notices()
    {
        settings_errors('gloty_notices');
    }
}
