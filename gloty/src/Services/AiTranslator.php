<?php

namespace Gloty\Services;

/**
 * AiTranslator Class
 * Handles content extraction and AI-powered translation.
 */
class AiTranslator
{
    /**
     * Get translatable strings from a post.
     * 
     * @param int $post_id
     * @return array List of strings with metadata
     */
    public static function get_strings($post_id)
    {
        $post = get_post($post_id);
        if (!$post)
            return [];

        $strings = [];

        // 1. Core Post Title
        $strings[] = [
            'id' => 'post_title',
            'type' => 'core',
            'label' => 'Title',
            'value' => $post->post_title
        ];

        // 2. Core Post Content (if not Elementor)
        $is_elementor = get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder';
        if (!$is_elementor && !empty($post->post_content)) {
            $strings[] = [
                'id' => 'post_content',
                'type' => 'core',
                'label' => 'Content',
                'value' => $post->post_content
            ];
        }

        // 3. Elementor Data
        if ($is_elementor) {
            $elementor_data = get_post_meta($post_id, '_elementor_data', true);
            if (!empty($elementor_data)) {
                $elements = json_decode($elementor_data, true);
                if (is_array($elements)) {
                    $strings = array_merge($strings, self::extract_elementor_strings($elements));
                }
            }
        }

        return $strings;
    }

    /**
     * Recursively extract strings from Elementor elements.
     */
    private static function extract_elementor_strings($elements, &$strings = [])
    {
        foreach ($elements as $element) {
            if (isset($element['settings'])) {
                $translatable_keys = [
                    'title',
                    'title_text',
                    'description_text',
                    'caption',
                    'text',
                    'content',
                    'link_text',
                    'editor',
                    'html',
                    'placeholder',
                    'testimonial_name',
                    'testimonial_content',
                    'testimonial_job',
                    'tab_title',
                    'tab_content',
                    'name',
                    'job',
                    'description',
                    'subtitle',
                    'button_text',
                    'image_caption',
                    'image_alt',
                    'heading',
                    'subheading',
                    'sub_title',
                    'item_title',
                    'item_text',
                    'item_description',
                    'pricing_title',
                    'pricing_description',
                    'pricing_button_text',
                    'testimonial_company',
                    'alert_title',
                    'alert_description',
                    'label',
                    'note',
                    'info',
                    'alt_text'
                ];

                foreach ($element['settings'] as $key => $value) {
                    if (in_array($key, $translatable_keys) && is_string($value) && !empty($value)) {
                        $strings[] = [
                            'id' => $element['id'] . '|' . $key,
                            'type' => 'elementor',
                            'label' => 'Elementor ' . ucfirst($element['widgetType'] ?? 'Widget') . ' (' . $key . ')',
                            'value' => $value
                        ];
                    }

                    // Generic Repeater Detection: Any array of associative arrays
                    if (is_array($value) && !empty($value) && isset($value[0]) && is_array($value[0])) {
                        foreach ($value as $index => $item) {
                            if (!is_array($item))
                                continue;
                            foreach ($item as $sub_key => $sub_value) {
                                if (in_array($sub_key, $translatable_keys) && is_string($sub_value) && !empty($sub_value)) {
                                    $strings[] = [
                                        'id' => $element['id'] . '|' . $key . '|' . $index . '|' . $sub_key,
                                        'type' => 'elementor_list',
                                        'label' => 'Elementor List Item (' . $sub_key . ')',
                                        'value' => $sub_value
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            if (!empty($element['elements'])) {
                self::extract_elementor_strings($element['elements'], $strings);
            }
        }
        return $strings;
    }

    /**
     * Send strings to AI for translation.
     */
    public static function translate($strings, $target_lang)
    {
        $settings = get_option('gloty_settings');
        $engine = $settings['ai_engine'] ?? 'none';
        $api_key = $settings['ai_api_key'] ?? '';

        if ($engine === 'none' || empty($api_key)) {
            return new \WP_Error('ai_disabled', 'AI Engine not configured.');
        }

        $source_lang = \Gloty\Core\Language::get_default_language();

        // Prepare prompt
        $prompt = "You are a professional translator. Translate the following WordPress content from {$source_lang} to {$target_lang}. 
        Return ONLY a JSON array of objects with the keys 'id' and 'translation'. 
        Maintain all HTML tags and placeholders.
        
        Content to translate:
        " . json_encode($strings);

        switch ($engine) {
            case 'gemini':
                return self::call_gemini($prompt, $api_key);
            case 'openai':
                return self::call_openai($prompt, $api_key);
            case 'deepseek':
                return self::call_deepseek($prompt, $api_key);
        }

        return new \WP_Error('invalid_engine', 'Unsupported AI engine.');
    }

    private static function call_gemini($prompt, $api_key)
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $api_key;

        $body = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json'
            ]
        ];

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($body),
            'timeout' => 100
        ]);

        if (is_wp_error($response)) {
            $msg = $response->get_error_message();
            if (strpos($msg, 'timed out') !== false || strpos($msg, 'error 28') !== false) {
                return new \WP_Error('ai_timeout', 'The AI service (Gemini) is taking too long to process this much content. This delay is on their provider end, not your server. Try translating fewer items at a time using the "Selective Translation" arrows.');
            }
            return $response;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($text))
            return new \WP_Error('ai_empty', 'AI returned empty response.');

        $text = self::clean_json_response($text);
        return json_decode($text, true);
    }

    private static function call_openai($prompt, $api_key)
    {
        $url = "https://api.openai.com/v1/chat/completions";

        $body = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a professional translator returning JSON.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'response_format' => ['type' => 'json_object']
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body' => json_encode($body),
            'timeout' => 100
        ]);

        if (is_wp_error($response)) {
            $msg = $response->get_error_message();
            if (strpos($msg, 'timed out') !== false || strpos($msg, 'error 28') !== false) {
                return new \WP_Error('ai_timeout', 'The AI service (OpenAI) is taking too long to respond. This is an external delay on their end. Using the "Copy" arrows for names/titles might speed things up.');
            }
            return $response;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $text = $data['choices'][0]['message']['content'] ?? '';

        $json = json_decode($text, true);
        return $json['results'] ?? $json;
    }

    private static function call_deepseek($prompt, $api_key)
    {
        $url = "https://api.deepseek.com/chat/completions";

        $body = [
            'model' => 'deepseek-chat',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a professional translator returning JSON.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'response_format' => ['type' => 'json_object']
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body' => json_encode($body),
            'timeout' => 100
        ]);

        if (is_wp_error($response)) {
            $msg = $response->get_error_message();
            if (strpos($msg, 'timed out') !== false || strpos($msg, 'error 28') !== false) {
                return new \WP_Error('ai_timeout', 'The AI service (DeepSeek) responded too slowly. This is an external delay. Consider translating in smaller batches for better results.');
            }
            return $response;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $text = $data['choices'][0]['message']['content'] ?? '';

        $json = json_decode($text, true);
        return $json['results'] ?? $json;
    }

    /**
     * Apply translations to a post.
     */
    public static function apply_translations($post_id, $translations)
    {
        $post = get_post($post_id);
        if (!$post)
            return;

        $is_elementor = get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder';
        $elementor_data = $is_elementor ? get_post_meta($post_id, '_elementor_data', true) : '';

        $new_title = $post->post_title;
        $new_content = $post->post_content;
        $new_el_data = $is_elementor ? json_decode($elementor_data, true) : null;

        foreach ($translations as $item) {
            $id = $item['id'];
            $value = $item['translation'];

            if ($id === 'post_title') {
                $new_title = $value;
            } elseif ($id === 'post_content') {
                $new_content = $value;
            } elseif (strpos($id, '|') !== false && $is_elementor) {
                self::update_elementor_value($new_el_data, $id, $value);
            }
        }

        // Update Post
        wp_update_post([
            'ID' => $post_id,
            'post_title' => wp_slash($new_title),
            'post_content' => wp_slash($new_content)
        ]);

        if ($is_elementor && $new_el_data) {
            update_post_meta($post_id, '_elementor_data', wp_slash(json_encode($new_el_data)));
        }
    }

    private static function update_elementor_value(&$elements, $encoded_id, $translation)
    {
        $parts = explode('|', $encoded_id);
        $el_id = $parts[0];
        $key = $parts[1];

        foreach ($elements as &$element) {
            if ($element['id'] === $el_id) {
                if (count($parts) === 2) {
                    $element['settings'][$key] = $translation;
                } elseif (count($parts) === 4) {
                    // List item: id|key|index|sub_key
                    $index = $parts[2];
                    $sub_key = $parts[3];
                    if (isset($element['settings'][$key][$index])) {
                        $element['settings'][$key][$index][$sub_key] = $translation;
                    }
                }
                return true;
            }

            if (!empty($element['elements'])) {
                if (self::update_elementor_value($element['elements'], $encoded_id, $translation)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Clean JSON response from AI (removes markdown code blocks).
     */
    private static function clean_json_response($text)
    {
        $text = trim($text);
        if (strpos($text, '```json') === 0) {
            $text = substr($text, 7);
            if (substr($text, -3) === '```') {
                $text = substr($text, 0, -3);
            }
        } elseif (strpos($text, '```') === 0) {
            $text = substr($text, 3);
            if (substr($text, -3) === '```') {
                $text = substr($text, 0, -3);
            }
        }
        return trim($text);
    }
}
