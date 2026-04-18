<?php
namespace TT\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\Audit\AuditSubscriber;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Database\MigrationRunner;
use TT\Infrastructure\Environment\EnvironmentService;
use TT\Infrastructure\FeatureToggles\FeatureToggleService;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Shared\Admin\Menu;
use TT\Shared\Frontend\BrandStyles;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Frontend\FrontendAjax;

/**
 * Kernel — the system bootstrap.
 *
 * Phase 3 changes: registers EnvironmentService, Logger, FeatureToggleService,
 * and AuditService in the container, and wires AuditSubscriber to hook
 * existing TT actions for audit recording.
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

        // Apply pending migrations before anything else.
        ( new MigrationRunner() )->run();

        $this->registerCoreServices();

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

        // Phase 3: wire audit subscriber to existing action hooks.
        /** @var AuditSubscriber $subscriber */
        $subscriber = $this->container->get( 'audit.subscriber' );
        $subscriber->register();

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

        // Phase 3 additions
        $this->container->bind( 'environment', function () {
            return new EnvironmentService();
        });
        $this->container->bind( 'logger', function ( Container $c ) {
            /** @var EnvironmentService $env */
            $env = $c->get( 'environment' );
            return new Logger( $env );
        });
        $this->container->bind( 'toggles', function ( Container $c ) {
            /** @var ConfigService $config */
            $config = $c->get( 'config' );
            return new FeatureToggleService( $config );
        });
        $this->container->bind( 'audit', function ( Container $c ) {
            /** @var FeatureToggleService $toggles */
            $toggles = $c->get( 'toggles' );
            /** @var Logger $logger */
            $logger = $c->get( 'logger' );
            return new AuditService( $toggles, $logger );
        });
        $this->container->bind( 'audit.subscriber', function ( Container $c ) {
            /** @var AuditService $audit */
            $audit = $c->get( 'audit' );
            return new AuditSubscriber( $audit );
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
