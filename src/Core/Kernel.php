<?php
namespace TT\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Shared\Admin\Menu;
use TT\Shared\Frontend\BrandStyles;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Frontend\FrontendAjax;

/**
 * Kernel — the system bootstrap.
 *
 * Responsibilities:
 * 1. Build the container and register core services.
 * 2. Load modules from config.
 * 3. Call register() then boot() across all modules.
 * 4. Initialise shared cross-cutting concerns (menu, brand styles, shortcode, ajax).
 *
 * Contains NO business logic.
 */
class Kernel {

    /** @var self|null */
    private static $instance = null;

    /** @var Container */
    private $container;

    /** @var ModuleRegistry */
    private $registry;

    /** @var bool */
    private $booted = false;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->container = new Container();
        $this->registry  = new ModuleRegistry( $this->container );
    }

    public function boot(): void {
        if ( $this->booted ) return;

        $this->registerCoreServices();

        // Make the shared config service available to static helpers
        /** @var ConfigService $config */
        $config = $this->container->get( 'config' );
        QueryHelpers::setConfigService( $config );

        $this->loadModules();
        $this->registry->registerAll();
        $this->registry->bootAll();

        // Shared cross-cutting concerns
        Menu::init();
        BrandStyles::init( $this->container );
        DashboardShortcode::register();
        FrontendAjax::register();

        // Role capability sync on admin requests
        add_action( 'admin_init', function () {
            /** @var RolesService $roles */
            $roles = $this->container->get( 'roles' );
            $roles->ensureCapabilities();
        });

        $this->booted = true;
    }

    private function registerCoreServices(): void {
        $this->container->bind( 'config', function () {
            return new ConfigService();
        });
        $this->container->bind( 'roles', function () {
            return new RolesService();
        });
    }

    private function loadModules(): void {
        $config_file = TT_PLUGIN_DIR . 'config/modules.php';
        if ( ! file_exists( $config_file ) ) {
            return;
        }
        /** @var array<string,bool|string> $enabled */
        $enabled = require $config_file;
        $classes = [];
        foreach ( $enabled as $class => $is_on ) {
            if ( $is_on ) {
                $classes[] = $class;
            }
        }
        $this->registry->load( $classes );
    }

    public function container(): Container {
        return $this->container;
    }

    public function registry(): ModuleRegistry {
        return $this->registry;
    }
}
