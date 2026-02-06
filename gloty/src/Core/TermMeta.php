<?php

namespace Gloty\Core;

/**
 * TermMeta Class
 * Handles metadata for taxonomy terms (language, original_id).
 */
class TermMeta
{
    const META_KEY_LANG = '_gloty_language';
    const META_KEY_ORIGINAL = '_gloty_original_id';

    /**
     * Get language of a term.
     *
     * @param int $term_id
     * @return string Language code or default.
     */
    public static function get_term_language($term_id)
    {
        $lang = get_term_meta($term_id, self::META_KEY_LANG, true);
        return $lang ? $lang : Language::get_default_language();
    }

    /**
     * Get original ID of a term.
     *
     * @param int $term_id
     * @return int Original Term ID.
     */
    public static function get_original_term_id($term_id)
    {
        $original = get_term_meta($term_id, self::META_KEY_ORIGINAL, true);
        return $original ? (int) $original : (int) $term_id; // Default to self if not set
    }

    /**
     * Set language for a term.
     *
     * @param int $term_id
     * @param string $lang_code
     */
    public static function set_term_language($term_id, $lang_code)
    {
        update_term_meta($term_id, self::META_KEY_LANG, $lang_code);
    }

    /**
     * Set original ID for a term.
     *
     * @param int $term_id
     * @param int $original_id
     */
    public static function set_original_term_id($term_id, $original_id)
    {
        update_term_meta($term_id, self::META_KEY_ORIGINAL, $original_id);
    }
}
