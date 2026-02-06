<?php
/**
 * Plugin Name: Gloty Multilingual
 * Plugin URI:  https://develus.com
 * Description: A lightweight, architecture-focused multilingual plugin for WordPress using subdirectory routing.
 * Version:     1.0.0
 * Author:      Develus
 * Author URI:  https://develus.com
 * License:     GPL-2.0+
 * Text Domain: gloty
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. Load Autoloader
require_once plugin_dir_path(__FILE__) . 'autoload.php';

// 2. Define Plugin Constants
define('GLOTY_VERSION', '1.0.0');
define('GLOTY_PATH', plugin_dir_path(__FILE__));
define('GLOTY_URL', plugin_dir_url(__FILE__));
define('GLOTY_BASENAME', plugin_basename(__FILE__));

// 3. Initialize Plugin
if (class_exists('Gloty\Gloty')) {
    // Hooking into 'plugins_loaded' is a standard safe practice
    add_action('plugins_loaded', ['Gloty\Gloty', 'get_instance']);
}

// 4. Activation/Deactivation Hooks
register_activation_hook(__FILE__, ['Gloty\Core\Installer', 'activate']);
register_deactivation_hook(__FILE__, ['Gloty\Core\Installer', 'deactivate']);
