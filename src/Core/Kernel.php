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
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\CapabilityAliases;
use TT\Infrastructure\Security\RolesService;
use TT\Shared\Admin\Menu;
use TT\Shared\Frontend\BrandStyles;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Frontend\FrontendAccessControl;

/**
 * Kernel — the system bootstrap.
 *
 * v2.8.0: Registers AuthorizationService cache invalidators during boot so
 * per-request authorization caches stay consistent when team assignments
 * or person-user links change mid-request.
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

        // v3.0.0: soft-alias legacy caps to the new granular caps. Must
        // register BEFORE any cap check fires anywhere in the plugin.
        // The user_has_cap filter it installs is cheap (a dozen array
        // lookups per cap resolve) and idempotent.
        CapabilityAliases::init();

        ( new MigrationRunner() )->run();

        $this->registerCoreServices();

        /** @var ConfigService $config */
        $config = $this->container->get( 'config' );
        QueryHelpers::setConfigService( $config );

        // Register AuthorizationService cache invalidator hooks early so
        // any write happening during this request (e.g. staff assignment
        // from admin-post.php flows) correctly invalidates the cache.
        AuthorizationService::registerCacheInvalidators();

        $this->loadModules();
        $this->registry->registerAll();
        $this->registry->bootAll();

        Menu::init();
        BrandStyles::init( $this->container );
        DashboardShortcode::register();
        \TT\Shared\Frontend\FlashMessages::init();
        // #0019 Sprint 6 — one-time admin notice announcing the
        // frontend-first migration. Dismissed per-user via meta.
        \TT\Shared\Admin\UpgradeNotice::init();

        /** @var AuditSubscriber $subscriber */
        $subscriber = $this->container->get( 'audit.subscriber' );
        $subscriber->register();

        /** @var FrontendAccessControl $access */
        $access = $this->container->get( 'frontend.access' );
        $access->register();

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
        $this->container->bind( 'frontend.access', function ( Container $c ) {
            /** @var ConfigService $config */
            $config = $c->get( 'config' );
            return new FrontendAccessControl( $config );
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
            if ( ! $is_on ) continue;
            // #0033 Sprint 5 — runtime module-toggle layer. Modules
            // disabled via the Modules admin tab don't load. Core
            // modules (Auth, Configuration, Authorization) are
            // always-on regardless of the table row.
            if ( ! ModuleRegistry::isEnabled( (string) $class ) ) continue;
            $classes[] = $class;
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
