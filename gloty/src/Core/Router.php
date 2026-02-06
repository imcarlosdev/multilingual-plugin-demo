<?php

namespace Gloty\Core;

use Gloty\Core\Language;
use Gloty\Utils\Debugger;

/**
 * Router Class
 * Handles Subdirectory URL routing, Language Detection, and 404 enforcement.
 */
class Router
{
    /**
     * @var string The original, unmodified REQUEST_URI.
     */
    private $request_uri_original = '';

    public function __construct()
    {
        // Detect language early
        add_action('init', [$this, 'detect_language'], 1);

        // Modify query vars early
        add_filter('request', [$this, 'filter_request']);

        // Filter queries to match language (meta query)
        add_action('pre_get_posts', [$this, 'filter_query']);

        // Filter home URL to append language
        add_filter('home_url', [$this, 'filter_home_url'], 10, 2);

        // Filter Permalinks
        add_filter('post_link', [$this, 'filter_permalink'], 10, 2);
        add_filter('page_link', [$this, 'filter_permalink'], 10, 2);
        add_filter('post_type_link', [$this, 'filter_permalink'], 10, 2);
        add_filter('preview_post_link', [$this, 'filter_permalink'], 10, 2);

        // Filter HTML Attributes (lang="es-ES")
        add_filter('language_attributes', [$this, 'filter_language_attributes']);

        // Filter Pagination Links (Fix double-subdirectory issue)
        add_filter('get_pagenum_link', [$this, 'filter_pagenum_link']);

        // Disable Canonical Redirects for translated pages to prevent loops
        add_filter('redirect_canonical', [$this, 'disable_canonical_redirect']);

        // Custom Canonical Enforcement (SEO)
        add_action('template_redirect', [$this, 'enforce_canonical_url']);
    }

    /**
     * Fix pagination links in subdirectory installations.
     * Prevents /es/~sub/es/~sub/ loop caused by WP's internal logic.
     */
    public function filter_pagenum_link($result)
    {
        if (!defined('GLOTY_CURRENT_LANG')) {
            return $result;
        }

        $lang = GLOTY_CURRENT_LANG;
        $default = Language::get_default_language();

        if ($lang !== $default) {
            $site_path = parse_url(site_url(), PHP_URL_PATH);
            // If site path is just '/', nothing to duplicate.
            if ($site_path && $site_path !== '/') {
                $site_path = rtrim($site_path, '/');

                // We look for pattern: / LANG / SITE_PATH /
                // e.g. /es/~glotywp/
                $double_stack = '/' . $lang . $site_path . '/';
                $correction = '/' . $lang . '/';

                // If found, replace with single instance
                if (strpos($result, $double_stack) !== false) {
                    $result = str_replace($double_stack, $correction, $result);
                }
            }
        }
        return $result;
    }

    /**
     * Detect current language from URL and define constant.
     * Handles 404 if language is inactive.
     */
    public function detect_language()
    {
        // Store original URI for canonical check later
        $this->request_uri_original = $_SERVER['REQUEST_URI'] ?? '';

        if (defined('GLOTY_CURRENT_LANG')) {
            return;
        }

        // Simulating usage of $_SERVER['REQUEST_URI']
        // We need to parse the path relative to the site root.
        $request_uri = $_SERVER['REQUEST_URI'];

        // Remove query string
        $path = parse_url($request_uri, PHP_URL_PATH);

        // Get site path (if WP is in subdirectory)
        $site_path = parse_url(site_url(), PHP_URL_PATH);
        if (!$site_path) {
            $site_path = '/';
        }
        // Normalize site path to remove trailing slash for consistent concatenation
        $site_path_trimmed = rtrim($site_path, '/');

        // Create working path for analysis
        // If site is /~glotywp and request is /~glotywp/es/foo
        // We want /es/foo to check for lang

        // Strip site path from the start of path if present
        if ($site_path_trimmed && $site_path_trimmed !== '/' && strpos((string) $path, (string) $site_path_trimmed) === 0) {
            $path = substr((string) $path, strlen((string) $site_path_trimmed));
        }

        $path = trim($path, '/');
        $segments = explode('/', $path);
        $first_segment = $segments[0] ?? '';

        $supported = Language::get_supported_languages(); // Just to check validity of format/existence

        // Logic:
        // 1. Is 1st segment a valid language code?
        // 2. Is it active?

        if ($first_segment && array_key_exists($first_segment, $supported)) {
            // It IS a language code
            if (Language::is_active($first_segment)) {

                // --- HARDENED TRAILING SLASH ENFORCEMENT ---
                // Force trailing slash for consistency (SEO) and to prevent WP canonical confusion.

                $current_uri_full = $_SERVER['REQUEST_URI'] ?? '';
                $current_path = parse_url($current_uri_full, PHP_URL_PATH) ?: '';

                // 1. SAFETY: Exclude Admin, API, and System files
                if (
                    !is_admin() &&
                    !(function_exists('is_customize_preview') && is_customize_preview()) &&
                    !$this->is_elementor_context() &&
                    strpos((string) $current_path, '/wp-admin') === false &&
                    strpos((string) $current_path, '/wp-json') === false &&
                    strpos((string) $current_path, 'wp-login.php') === false &&
                    strpos((string) $current_path, 'xmlrpc.php') === false
                ) {
                    // 2. CHECK: Only if missing trailing slash
                    if (substr($current_path, -1) !== '/') {

                        // 3. CHECK: Does it match our Site + Language prefix?
                        // $site_path_trimmed e.g. /~glotywp
                        // $first_segment e.g. es
                        $prefix = $site_path_trimmed . '/' . $first_segment;

                        if (strpos((string) $current_path, (string) $prefix) === 0) {

                            // 4. CHECK: Is it a file? (Has extension)
                            $segments_check = explode('/', $current_path);
                            $last_segment = end($segments_check);

                            if (strpos($last_segment, '.') === false) {

                                // 5. REDIRECT: Reconstruct URL with / and Query String
                                // Use home_url() + relative path to ensure protocol/host correctness
                                // $path variable (from line ~75/78) is 'es' or 'es/foo' (trimmed)

                                // We use $path (which is relative to site root) directly
                                $redirect_to = home_url($path . '/');

                                // Append Query String if present
                                $query_string = parse_url($current_uri_full, PHP_URL_QUERY);
                                if ($query_string) {
                                    $redirect_to = rtrim($redirect_to, '/') . '/?' . $query_string;
                                }

                                wp_redirect($redirect_to, 301);
                                exit;
                            }
                        }
                    }
                }

                // Active language
                define('GLOTY_CURRENT_LANG', $first_segment);

                // Modify $_SERVER['REQUEST_URI'] to trick WP
                // We construct the pattern to match the language prefix
                // If site is root, pattern is ^/es
                // If site is /sub, pattern is ^/sub/es

                $pattern = '#^' . preg_quote($site_path_trimmed . '/' . $first_segment, '#') . '#';

                // We replace with site path (e.g. /sub or empty string if root was / but we use / for safety on root? No, we trimmed.)
                // If we strip /es, we want /sub/foo.
                // Replace ^/sub/es with /sub

                $replacement = $site_path_trimmed;

                // Special case: if site_path was just /, replacement is empty string?
                // Request: /es/foo -> Pattern ^/es -> Replace with "" -> /foo. Correct.

                $old_uri = $_SERVER['REQUEST_URI'];
                $_SERVER['REQUEST_URI'] = preg_replace($pattern, $replacement, $_SERVER['REQUEST_URI'], 1);


                // Ensure we have at least a slash
                if (empty($_SERVER['REQUEST_URI'])) {
                    $_SERVER['REQUEST_URI'] = '/';
                }

            } else {
                // Inactive language -> Force 404 (Skip if Elementor context)
                if (!$this->is_elementor_context()) {
                    define('GLOTY_FORCE_404', true);
                }
            }
        } else {
            // No language code found, assume Default Language
            define('GLOTY_CURRENT_LANG', Language::get_default_language());
        }
    }

    /**
     * Inspect and modify query variables before query is run.
     * Use this for Slug Swapping.
     */
    public function filter_request($vars)
    {
        if (is_admin() || (function_exists('is_customize_preview') && is_customize_preview()) || !defined('GLOTY_CURRENT_LANG') || $this->is_elementor_context()) {
            return $vars;
        }



        // --- SUBDIRECTORY COLLISION FIX ---
        // When using %category%/%postname% permalinks in a subdirectory (e.g. /~glotywp/),
        // WordPress often mistakes the subdirectory for the category segment.
        if (isset($vars['category_name'])) {
            $site_path = parse_url(site_url(), PHP_URL_PATH);
            // $site_path is usually /~glotywp/ or /
            $bad_cat = trim($site_path, '/');

            if ($bad_cat && $vars['category_name'] === $bad_cat) {
                // Removing the collision
                unset($vars['category_name']);
                // Also clean up dependent vars if they were set by this collision
                if (isset($vars['category']))
                    unset($vars['category']);
                if (isset($vars['cat']))
                    unset($vars['cat']);
            }
        }

        $current_lang = GLOTY_CURRENT_LANG;
        if ($current_lang === Language::get_default_language()) {
            return $vars;
        }

        // --- ROOT / HOMEPAGE CHECK ---
        // Verify if we are at the effective root after rewrite
        $request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $site_path = parse_url(site_url(), PHP_URL_PATH) ?: '/';

        // Normalize
        $request_path_trim = rtrim($request_path, '/');
        $site_path_trim = rtrim($site_path, '/');

        if ($request_path_trim === $site_path_trim) {
            // We are at Homepage!
            $front_page_id = (int) get_option('page_on_front');
            if ($front_page_id > 0) {
                $trans_id = $this->get_translated_post_id($front_page_id, $current_lang);
                if ($trans_id && $trans_id != $front_page_id) {
                    $vars['page_id'] = $trans_id;
                    $vars['post_type'] = 'page';
                    return $vars;
                }
            }
        }

        // Check for name or pagename
        $slug = isset($vars['name']) ? $vars['name'] : (isset($vars['pagename']) ? $vars['pagename'] : '');

        // --- MANUAL PAGINATION PARSING ---
        // If WP didn't recognize /page/2/ for a Page, $slug might be broken or empty,
        // or vars might be interpreted wrongly.
        // We check the REQUEST_URI directly for /page/N/ pattern relative to our handled path.

        $paged_manually_found = 0;

        // request_path_trim is e.g. /~glotywp/blog-espanol/page/2 or /blog-espanol/page/2
        // We need to identify if "page/N" is at the end.
        if (preg_match('#/page/([0-9]+)/?$#', $request_path_trim, $matches)) {
            $paged_manually_found = (int) $matches[1];
            // Remove /page/N from the path to identify the slug
            // But we need the slug relative to the logic below.

            // Standard generic fallback:
            // If vars['name'] is not set, we might try to extract slug from URI.
            // But let's look at the existing $slug logic.
        }

        // If we found pagination but no slug (because WP failed to parse the rule for /page/N),
        // we try to extract the slug manually from the end of the URL (before /page/).
        if ((!$slug || $slug === 'page') && $paged_manually_found) {
            // Heuristic: remove /page/N and see what's left.
            // $site_path_trim might be /~glotywp
            // request URI: /~glotywp/blog-espanol/page/2

            // Strip /page/N
            $reduced_path = preg_replace('#/page/[0-9]+/?$#', '', $request_path_trim);
            $reduced_path = rtrim($reduced_path, '/');

            // Extract last segment
            $parts = explode('/', $reduced_path);
            $possible_slug = end($parts);

            if ($possible_slug && $possible_slug !== $site_path_trim) {
                $slug = $possible_slug;
            }
        }

        // Fallback: WP often thinks unknown slugs are attachments
        if (!$slug && isset($vars['attachment'])) {
            $slug = $vars['attachment'];
        }

        if ($slug) {
            global $wpdb;
            $sql = $wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_status = 'publish' LIMIT 1", $slug);
            $found_id = $wpdb->get_var($sql);



            if ($found_id) {
                // --- BLOG ARCHIVE FIX (Optimized) ---
                // We perform the check HERE, once we know we have a valid Post ID.
                $posts_page_id = (int) get_option('page_for_posts');
                if ($posts_page_id > 0) {
                    // Is this ID the translation of the posts page?
                    // We check if the found ID's Original ID matches the Posts Page's Original ID (robust check)
                    // Or simply: get translation of Posts Page and compare.

                    $trans_posts_id = $this->get_translated_post_id($posts_page_id, $current_lang);

                    if ((int) $found_id === (int) $trans_posts_id) {
                        // IT IS THE BLOG PAGE!
                        define('GLOTY_IS_BLOG_PAGE', true);

                        // We must NOT return empty vars, or WP thinks it's likely the front page.
                        // We want the "Home" (Blog) template.
                        // Setting post_type = 'post' helps.
                        $vars['post_type'] = 'post';

                        // Fix Pagination:
                        if ($paged_manually_found) {
                            $vars['paged'] = $paged_manually_found;
                        } elseif (isset($vars['page'])) {
                            $vars['paged'] = $vars['page'];
                            unset($vars['page']);
                        }

                        unset($vars['pagename']);
                        unset($vars['name']);
                        unset($vars['page_id']);

                        // FIX: Unset category vars that might be incorrectly parsed from subdirectory (e.g. ~glotywp)
                        unset($vars['category_name']);
                        unset($vars['category']);
                        unset($vars['cat']);

                        return $vars;
                    }
                }

                $found_lang = get_post_meta($found_id, '_gloty_language', true) ?: Language::get_default_language();




                // Case 1: Wrong Language (Mismatch)
                if ($found_lang !== $current_lang) {
                    // Mismatch: We found a post, but it's not in the language we want.
                    // Find the translation.
                    $original_id = get_post_meta($found_id, '_gloty_original_id', true) ?: $found_id;

                    $args = [
                        'post_type' => 'any',
                        'meta_query' => [
                            'relation' => 'AND',
                            ['key' => '_gloty_original_id', 'value' => $original_id],
                            ['key' => '_gloty_language', 'value' => $current_lang]
                        ],
                        'fields' => 'ids',
                        'posts_per_page' => 1
                    ];

                    $trans_query = new \WP_Query($args);
                    if ($trans_query->have_posts()) {
                        $trans_id = $trans_query->posts[0];
                        $trans_post = get_post($trans_id);



                        // Swap the slug in the vars
                        if (isset($vars['name'])) {
                            $vars['name'] = $trans_post->post_name;
                        } elseif (isset($vars['pagename'])) {
                            $vars['pagename'] = $trans_post->post_name;
                        } else {
                            if ($trans_post->post_type === 'page') {
                                $vars['pagename'] = $trans_post->post_name;
                            } else {
                                $vars['name'] = $trans_post->post_name;
                            }
                        }

                        if (isset($vars['attachment'])) {
                            unset($vars['attachment']);
                        }
                    }
                }
                // Case 2: Language Matches
                else {
                    // The slug matches a post in the correct language.
                    // However, WP might have misidentified it (e.g. as a 'post' instead of 'page', or 'attachment').
                    // We must enforce the correct type.

                    $current_post = get_post($found_id);
                    if ($current_post) {
                        if ($current_post->post_type === 'page') {
                            $vars['pagename'] = $current_post->post_name;
                            unset($vars['name']);
                            $vars['post_type'] = 'page';
                            $vars['page_id'] = $found_id; // Explicit safety
                        } elseif ($current_post->post_type === 'post') {
                            $vars['name'] = $current_post->post_name;
                            $vars['post_type'] = 'post';
                        }
                        // Clean up attachment if it was wrongly guessed
                        if (isset($vars['attachment'])) {
                            unset($vars['attachment']);
                        }
                    }
                }
            }
        }

        return $vars;
    }

    /**
     * Disable canonical redirect for non-default languages.
     * WordPress doesn't understand our virtual URL structure and tries to "fix" it, causing loops.
     */
    public function disable_canonical_redirect($redirect_url)
    {
        if (defined('GLOTY_CURRENT_LANG')) {
            $current_lang = GLOTY_CURRENT_LANG;
            $default_lang = Language::get_default_language();

            // Only disable if we are strictly in a translated context
            if ($current_lang !== $default_lang) {
                return false;
            }
        }
        return $redirect_url;
    }

    /**
     * Filter main query to fetch posts only in current language.
     * 
     * @param \WP_Query $query
     */
    public function filter_query($query)
    {
        if (defined('GLOTY_FORCE_404') && GLOTY_FORCE_404) {
            $query->set_404();
            status_header(404);
            return;
        }

        // Only frontend and main query
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        // --- BLOG ARCHIVE FIX ---
        if (defined('GLOTY_IS_BLOG_PAGE') && GLOTY_IS_BLOG_PAGE) {
            $query->is_home = true;
            $query->is_posts_page = true;
            // Force reset of page-specific flags
            $query->is_page = false;
            $query->is_singular = false;
            $query->is_single = false;
            $query->is_attachment = false;
            $query->is_404 = false;

            $query->set('page_id', '');
            $query->set('pagename', '');
            $query->set('name', '');
            $query->set('attachment', '');
            $query->set('post_type', 'post');
        }

        $current_lang = defined('GLOTY_CURRENT_LANG') ? GLOTY_CURRENT_LANG : Language::get_default_language();

        // Create Meta Query
        // If current lang is DEFAULT, we want posts that are explicitly Default OR have NO language set (legacy)
        $meta_query = $query->get('meta_query');
        if (!is_array($meta_query)) {
            $meta_query = [];
        }

        if ($current_lang === Language::get_default_language()) {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key' => '_gloty_language',
                    'value' => $current_lang,
                    'compare' => '='
                ],
                [
                    'key' => '_gloty_language',
                    'compare' => 'NOT EXISTS'
                ]
            ];
        } else {
            // Non-default: Strict check
            $meta_query[] = [
                'key' => '_gloty_language',
                'value' => $current_lang,
                'compare' => '='
            ];
        }

        $query->set('meta_query', $meta_query);
    }

    /**
     * Filter home_url to add language segment.
     */
    public function filter_home_url($url, $path)
    {
        if (is_admin() || (function_exists('is_customize_preview') && is_customize_preview()) || !defined('GLOTY_CURRENT_LANG')) {
            return $url;
        }

        // --- HARDENED: Prevent /es/es/ ---
        // If the URL or path already contains the language segment, skip filtering.
        $lang = GLOTY_CURRENT_LANG;
        $default = Language::get_default_language();

        if ($lang === $default) {
            return $url;
        }

        // Check if URL already has /es/ after the base
        // WARNING: Using home_url() here causes infinite recursion!
        // We use get_option('home') instead which is filter-free.
        $site_home = get_option('home');
        $site_path = parse_url($site_home, PHP_URL_PATH) ?: '';
        $site_path_trimmed = rtrim($site_path, '/');
        $prefix = $site_path_trimmed . '/' . $lang . '/';

        if (strpos(parse_url($url, PHP_URL_PATH), $prefix) !== false) {
            return $url;
        }

        if ($lang !== $default) {
            // Provide basic home_url filtering 
            // If path is empty, we must ensure we append lang

            // Prevent infinite recursion by removing filter before calling home_url()
            remove_filter('home_url', [$this, 'filter_home_url'], 10);
            $home = home_url();
            add_filter('home_url', [$this, 'filter_home_url'], 10, 2);

            $filtered = str_replace($home, $home . '/' . $lang, $url);

            // Ensure trailing slash if it's just the language root (e.g., /es -> /es/)
            $site_path = parse_url(get_option('home'), PHP_URL_PATH) ?: '';
            $site_path_trimmed = rtrim($site_path, '/');
            $expected_root = $site_path_trimmed . '/' . $lang;

            if (parse_url($filtered, PHP_URL_PATH) === $expected_root) {
                $filtered = trailingslashit($filtered);
            }

            return $filtered;
        }

        return $url;
    }

    /**
     * Filter permalinks to inject language code for translated posts.
     */
    public function filter_permalink($url, $post)
    {
        $post = get_post($post);
        if (!$post) {
            return $url;
        }

        $target_lang = get_post_meta($post->ID, '_gloty_language', true) ?: Language::get_default_language();
        $default_lang = Language::get_default_language();
        $current_lang = defined('GLOTY_CURRENT_LANG') ? GLOTY_CURRENT_LANG : $default_lang;

        // Get the CLEAN home URL (without our filter)
        remove_filter('home_url', [$this, 'filter_home_url'], 10);
        $clean_home = home_url();
        add_filter('home_url', [$this, 'filter_home_url'], 10, 2);

        // We need to know the relative path of the post (slug/structure).
        // Since $url coming in might be polluted by filter_home_url (e.g., contains /es/ even if post is English),
        // we strive to strip the base to get the path.

        // Calculate the base that WP likely used
        $used_base = $clean_home;
        if ($current_lang !== $default_lang && strpos($url, $clean_home . '/' . $current_lang) === 0) {
            $used_base = $clean_home . '/' . $current_lang;
        }

        // Get relative path (e.g. /sample-page/)
        $path = str_replace($used_base, '', $url);
        $path = ltrim($path, '/');

        // --- HOMEPAGE PERMALINK FIX ---
        // If this post IS the translated homepage, ensuring the permalink is just /es/
        $front_page_id = (int) get_option('page_on_front');
        if ($front_page_id > 0 && (int) $post->ID === $this->get_translated_post_id($front_page_id, $target_lang)) {
            if ($target_lang !== $default_lang) {
                return $clean_home . '/' . $target_lang . '/';
            }
        }

        // Rebuild URL
        if ($target_lang !== $default_lang) {
            return $clean_home . '/' . $target_lang . '/' . $path;
        } else {
            return $clean_home . '/' . $path;
        }
    }

    /**
     * Helper to find translated post ID.
     */
    private function get_translated_post_id($original_id, $target_lang)
    {
        $original_of_original = get_post_meta($original_id, '_gloty_original_id', true) ?: $original_id;

        $args = [
            'post_type' => 'any',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_gloty_original_id', 'value' => $original_of_original],
                ['key' => '_gloty_language', 'value' => $target_lang]
            ],
            'fields' => 'ids',
            'posts_per_page' => 1
        ];

        $query = new \WP_Query($args);
        if ($query->have_posts()) {
            return $query->posts[0];
        }
        return false;
    }

    /**
     * Filter the language attributes for the <html> tag.
     * Replaces default locale (e.g. en-US) with current active language locale.
     */
    public function filter_language_attributes($output)
    {
        if (defined('GLOTY_CURRENT_LANG')) {
            $lang_code = GLOTY_CURRENT_LANG;
            $locale = Language::get_locale($lang_code);

            // Standardize locale format: es_ES -> es-ES for HTML attribute
            $locale_dash = str_replace('_', '-', $locale);

            // Replace lang="xx-XX" if it exists, or inject it
            // WP usually outputs: lang="en-US"

            if (preg_match('/lang="([^"]+)"/', $output, $matches)) {
                $output = str_replace('lang="' . $matches[1] . '"', 'lang="' . $locale_dash . '"', $output);
            } else {
                $output .= ' lang="' . $locale_dash . '"';
            }
        }
        return $output;
    }

    /**
     * Enforce Canonical URLs for SEO.
     * Redirects to the correct URL if the requested one is functional but incorrect (e.g. wrong category).
     */
    public function enforce_canonical_url()
    {
        // 1. SAFEGUARDS: Do not redirect in these contexts
        if (
            is_admin() ||
            is_preview() ||
            (function_exists('is_customize_preview') && is_customize_preview()) ||
            (defined('REST_REQUEST') && REST_REQUEST) ||
            (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) ||
            wp_doing_ajax() ||
            is_trackback() ||
            is_search() ||
            !isset($_SERVER['REQUEST_METHOD']) ||
            $_SERVER['REQUEST_METHOD'] !== 'GET'
        ) {
            return;
        }

        // 2. CONTEXT: Only handle singular posts/pages for now
        if (!is_singular()) {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }

        // 3. GET CANONICAL: Use Gloty-aware permalink
        $canonical_url = get_permalink($post_id);
        if (!$canonical_url) {
            return;
        }

        // 4. RECONSTRUCT REQUESTED URL (Original)
        // We use the stored original URI to compare against the canonical
        $protocol = is_ssl() ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $requested_url = $protocol . $host . $this->request_uri_original;

        // Normalize both for comparison (remove trailing slashes and query strings for the base check)
        $requested_base = strtok($requested_url, '?') ?: $requested_url;
        $canonical_base = strtok($canonical_url, '?') ?: $canonical_url;

        // Ensure we are working with strings for untrailingslashit
        $requested_base = (string) $requested_base;
        $canonical_base = (string) $canonical_base;

        // If bases differ, or if there's a slash mismatch
        if (untrailingslashit($requested_base) !== untrailingslashit($canonical_base) || (strpos($requested_base, '/') !== false && substr($requested_base, -1) !== substr($canonical_base, -1))) {

            // 5. REDIRECT: Append query string if it exists in requested
            $query_string = parse_url($requested_url, PHP_URL_QUERY);
            if ($query_string) {
                $canonical_url = rtrim($canonical_base, '/') . '/' . '?' . $query_string;
            }

            wp_redirect($canonical_url, 301);
            exit;
        }
    }

    /**
     * Check if the current request is within an Elementor Editor or Preview context.
     */
    private function is_elementor_context()
    {
        // 1. Editor Mode (GET or POST action)
        $action = $_REQUEST['action'] ?? '';
        if ($action === 'elementor' || $action === 'elementor_ajax') {
            return true;
        }

        // 2. Preview Mode
        if (isset($_REQUEST['elementor-preview']) || isset($_REQUEST['preview_id'])) {
            return true;
        }

        // 3. REST API for Elementor Editor
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'wp-json/elementor/v1') !== false) {
            return true;
        }

        return false;
    }
}
