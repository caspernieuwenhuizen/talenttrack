<?php
namespace TT\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ModuleRegistry — discovers, registers, and boots modules.
 *
 * Module list comes from /config/modules.php (returns an array of class names).
 * Each class must implement ModuleInterface.
 */
class ModuleRegistry {

    /** @var ModuleInterface[] */
    private $modules = [];

    /** @var Container */
    private $container;

    public function __construct( Container $container ) {
        $this->container = $container;
    }

    /**
     * Load module classes from config.
     *
     * @param string[] $module_classes Fully-qualified class names.
     */
    public function load( array $module_classes ): void {
        foreach ( $module_classes as $class ) {
            if ( ! class_exists( $class ) ) {
                continue;
            }
            $module = new $class();
            if ( ! $module instanceof ModuleInterface ) {
                continue;
            }
            $this->modules[ $module->getName() ] = $module;
        }
    }

    /**
     * Call register() on every loaded module.
     */
    public function registerAll(): void {
        foreach ( $this->modules as $module ) {
            $module->register( $this->container );
        }
    }

    /**
     * Call boot() on every loaded module.
     */
    public function bootAll(): void {
        foreach ( $this->modules as $module ) {
            $module->boot( $this->container );
        }
    }

    /**
     * @return ModuleInterface[]
     */
    public function all(): array {
        return $this->modules;
    }
}
