<?php

namespace Gloty;

use Gloty\Core\Router;
use Gloty\Core\Language;
use Gloty\Admin\SettingsPage;
use Gloty\Admin\MetaBox;
use Gloty\Frontend\LanguageSwitcher;
use Gloty\Frontend\SeoManager;
use Gloty\Frontend\MenuHandler;
use Gloty\Services\MenuManager;
use Gloty\Admin\TermManager;
use Gloty\Admin\AdminBar;
use Gloty\Admin\PostFilter;
use Gloty\Admin\TermFilter;
use Gloty\Integrations\ElementorIntegrator;

/**
 * Main Plugin Class (Singleton)
 */
class Gloty
{

    /**
     * @var Gloty|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return Gloty
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct()
    {
        $this->init_modules();
    }

    /**
     * Initialize all plugin modules.
     */
    private function init_modules()
    {
        // Core Modules
        new Language(); // Initialize language handling
        new Router();   // Initialize routing rules

        // Frontend
        if (!is_admin()) {
            new LanguageSwitcher();
            new SeoManager();
        }

        // Global (Frontend + Admin + REST)
        new MenuHandler();
        new TermManager();
        new TermFilter();
        new ElementorIntegrator();

        // Admin Modules
        if (is_admin()) {
            new SettingsPage();
            new MetaBox();
            new AdminBar();
            new PostFilter();
        }
    }
}
