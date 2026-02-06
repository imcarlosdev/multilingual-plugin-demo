<?php

namespace Gloty\Admin;

use Gloty\Core\Language;

/**
 * SettingsPage Class
 * Manages the plugin parameters screen.
 */
class SettingsPage
{

    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Add settings link to plugin list
        if (defined('GLOTY_BASENAME')) {
            add_filter('plugin_action_links_' . GLOTY_BASENAME, [$this, 'add_settings_link']);
        }
    }

    public function add_admin_menu()
    {
        add_options_page(
            'Gloty Settings',
            'Gloty Multilingual',
            'manage_options',
            'gloty',
            [$this, 'render_page']
        );
    }

    /**
     * Add settings link to the plugin entry in the plugins list.
     *
     * @param array $links
     * @return array
     */
    public function add_settings_link($links)
    {
        $settings_link = '<a href="options-general.php?page=gloty">' . __('Settings', 'gloty') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function register_settings()
    {
        register_setting('gloty_settings_group', 'gloty_settings');

        add_settings_section(
            'gloty_general_section',
            'General Settings',
            null,
            'gloty'
        );

        add_settings_field(
            'default_language',
            'Default Language',
            [$this, 'render_default_language_field'],
            'gloty',
            'gloty_general_section'
        );

        add_settings_field(
            'active_languages',
            'Active Languages',
            [$this, 'render_active_languages_field'],
            'gloty',
            'gloty_general_section'
        );

        add_settings_field(
            'preserve_data',
            'Data Persistence',
            [$this, 'render_preserve_data_field'],
            'gloty',
            'gloty_general_section'
        );

        add_settings_field(
            'elementor_support',
            'Elementor Compatibility',
            [$this, 'render_elementor_support_field'],
            'gloty',
            'gloty_general_section'
        );

        add_settings_field(
            'debug_mode',
            'Debug Mode',
            [$this, 'render_debug_mode_field'],
            'gloty',
            'gloty_general_section'
        );

        add_settings_section(
            'gloty_ai_section',
            'AI Auto-Translation',
            null,
            'gloty'
        );

        add_settings_field(
            'ai_engine',
            'AI Engine',
            [$this, 'render_ai_engine_field'],
            'gloty',
            'gloty_ai_section'
        );

        add_settings_field(
            'ai_api_key',
            'API Key',
            [$this, 'render_ai_api_key_field'],
            'gloty',
            'gloty_ai_section'
        );
    }

    public function render_page()
    {
        ?>
        <div class="wrap">
            <h1>Gloty Multilingual Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('gloty_settings_group');
                do_settings_sections('gloty');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_default_language_field()
    {
        $settings = get_option('gloty_settings');
        $default = $settings['default_language'] ?? 'en';
        $supported = Language::get_supported_languages();
        ?>
        <select name="gloty_settings[default_language]">
            <?php foreach ($supported as $code => $details): ?>
                <option value="<?php echo esc_attr($code); ?>" <?php selected($default, $code); ?>>
                    <?php echo esc_html($details['name']); ?> (<?php echo esc_html($code); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">The main language of your site (e.g., /).</p>
        <?php
    }

    public function render_active_languages_field()
    {
        $settings = get_option('gloty_settings');
        $active = $settings['active_languages'] ?? ['en'];
        $supported = Language::get_supported_languages();

        echo '<fieldset>';
        foreach ($supported as $code => $details) {
            ?>
            <label style="display:block;">
                <input type="checkbox" name="gloty_settings[active_languages][]" value="<?php echo esc_attr($code); ?>" <?php checked(in_array($code, $active)); ?>>
                <?php echo esc_html($details['name']); ?>
            </label>
            <?php
        }
        echo '</fieldset>';
        echo '<p class="description">Select all languages you want to enable.</p>';
    }

    public function render_preserve_data_field()
    {
        $settings = get_option('gloty_settings');
        $preserve = isset($settings['preserve_data']) ? $settings['preserve_data'] : 0;
        ?>
        <label>
            <input type="checkbox" name="gloty_settings[preserve_data]" value="1" <?php checked(1, $preserve); ?>>
            Minimize data loss risks by preserving translations and settings when uninstalling.
        </label>
        <p class="description">If unchecked, all Gloty data (translations, links, settings) will be <strong>permanently
                deleted</strong> upon uninstall.</p>
        <?php
    }

    public function render_elementor_support_field()
    {
        $settings = get_option('gloty_settings');
        $support = isset($settings['elementor_support']) ? $settings['elementor_support'] : 0;
        ?>
        <label>
            <input type="checkbox" name="gloty_settings[elementor_support]" value="1" <?php checked(1, $support); ?>>
            Enable advanced multilingual support for Elementor (Templates, Headers, Footers).
        </label>
        <p class="description">
            This enables translation management for <code>elementor_library</code> and automatic template swapping on the
            frontend.<br>
            <a href="edit.php?post_type=elementor_library" style="font-weight: bold; margin-top: 5px; display: inline-block;">→
                Go to Elementor Templates List</a>
        </p>
        <?php
    }

    public function render_debug_mode_field()
    {
        $settings = get_option('gloty_settings');
        $debug = isset($settings['debug_mode']) ? $settings['debug_mode'] : 0;
        ?>
        <label>
            <input type="checkbox" name="gloty_settings[debug_mode]" value="1" <?php checked(1, $debug); ?>>
            Enable Gloty Debugging.
        </label>
        <p class="description">Writes internal logs to <code>/wp-content/gloty-debug.log</code>. Useful for troubleshooting
            routing and duplication issues.</p>
        <?php
    }

    public function render_ai_engine_field()
    {
        $settings = get_option('gloty_settings');
        $engine = $settings['ai_engine'] ?? 'none';
        ?>
        <select name="gloty_settings[ai_engine]">
            <option value="none" <?php selected($engine, 'none'); ?>>Disabled</option>
            <option value="gemini" <?php selected($engine, 'gemini'); ?>>Google Gemini</option>
            <option value="openai" <?php selected($engine, 'openai'); ?>>ChatGPT (OpenAI)</option>
            <option value="deepseek" <?php selected($engine, 'deepseek'); ?>>DeepSeek</option>
        </select>
        <p class="description">Select the AI engine to power automatic translations.</p>
        <?php
    }

    public function render_ai_api_key_field()
    {
        $settings = get_option('gloty_settings');
        $key = $settings['ai_api_key'] ?? '';
        ?>
        <input type="password" name="gloty_settings[ai_api_key]" value="<?php echo esc_attr($key); ?>" class="regular-text">
        <p class="description">Enter the API key for your selected AI engine.</p>
        <?php
    }
}
