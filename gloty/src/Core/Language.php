<?php

namespace Gloty\Core;

/**
 * Language Class
 * Handles active languages, default language, and validations.
 */
class Language
{

    /**
     * Get the default language code.
     *
     * @return string
     */
    public static function get_default_language()
    {
        $settings = get_option('gloty_settings');
        return isset($settings['default_language']) && !empty($settings['default_language'])
            ? $settings['default_language']
            : 'en';
    }

    /**
     * Get all active languages (including default).
     *
     * @return array Array of language codes (e.g., ['en', 'es', 'fr'])
     */
    public static function get_active_languages()
    {
        $settings = get_option('gloty_settings');
        $active = isset($settings['active_languages']) && is_array($settings['active_languages'])
            ? $settings['active_languages']
            : ['en'];

        // Ensure default is always active
        $default = self::get_default_language();
        if (!in_array($default, $active)) {
            array_unshift($active, $default);
        }

        return array_unique($active);
    }

    /**
     * Check if a language code is active.
     *
     * @param string $code
     * @return bool
     */
    public static function is_active($code)
    {
        return in_array($code, self::get_active_languages());
    }

    /**
     * Get list of supported languages (Common list).
     * Expanded list can be added later or fetched from a standard library.
     *
     * @return array
     */
    public static function get_supported_languages()
    {
        return [
            'en' => ['name' => 'English', 'locale' => 'en_US'],
            'es' => ['name' => 'Español', 'locale' => 'es_ES'],
            'fr' => ['name' => 'Français', 'locale' => 'fr_FR'],
            'de' => ['name' => 'Deutsch', 'locale' => 'de_DE'],
            'it' => ['name' => 'Italiano', 'locale' => 'it_IT'],
            'pt' => ['name' => 'Português', 'locale' => 'pt_BR'], // Assuming BR for PT based on common usage
            'ru' => ['name' => 'Russian', 'locale' => 'ru_RU'],
            'ja' => ['name' => 'Japanese', 'locale' => 'ja'],
            'zh' => ['name' => 'Chinese', 'locale' => 'zh_CN'],
            'nl' => ['name' => 'Dutch', 'locale' => 'nl_NL'],
            'pl' => ['name' => 'Polish', 'locale' => 'pl_PL'],
            'tr' => ['name' => 'Turkish', 'locale' => 'tr_TR']
        ];
    }

    /**
     * Get locale for a specific code
     */
    public static function get_locale($code)
    {
        $all = self::get_supported_languages();
        if (isset($all[$code]['locale'])) {
            return $all[$code]['locale'];
        }
        return $code . '_' . strtoupper($code); // Fallback
    }
}
