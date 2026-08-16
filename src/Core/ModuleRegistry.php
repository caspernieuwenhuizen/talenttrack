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
     * Call register() on every loaded module that is currently enabled.
     * Disabled modules (per ModuleRegistry::isEnabled) skip the register
     * step entirely — their hooks, REST routes, and admin pages stay
     * dark until the toggle flips back on. Always-on core modules
     * register regardless.
     */
    public function registerAll(): void {
        foreach ( $this->modules as $module ) {
            if ( ! self::isEnabled( get_class( $module ) ) ) continue;
            $module->register( $this->container );
        }
    }

    /**
     * Call boot() on every loaded module that is currently enabled.
     */
    public function bootAll(): void {
        foreach ( $this->modules as $module ) {
            if ( ! self::isEnabled( get_class( $module ) ) ) continue;
            $module->boot( $this->container );
        }
    }

    /**
     * @return ModuleInterface[]
     */
    public function all(): array {
        return $this->modules;
    }

    // #0033 Sprint 5 — runtime module state

    /**
     * Modules that cannot be disabled. Toggle button renders inert
     * with a tooltip explaining "Core module — cannot be disabled."
     *
     * @var list<string>
     */
    private const ALWAYS_ON_MODULES = [
        'TT\\Modules\\Auth\\AuthModule',
        'TT\\Modules\\Configuration\\ConfigurationModule',
        'TT\\Modules\\Authorization\\AuthorizationModule',
    ];

    /** @var array<string, bool>|null per-request cache */
    private static $stateCache = null;

    /** @var array<string, bool>|null #2409 per-request under-development cache */
    private static $devStateCache = null;

    public static function isAlwaysOn( string $module_class ): bool {
        return in_array( ltrim( $module_class, '\\' ), self::ALWAYS_ON_MODULES, true );
    }

    /**
     * Reads `tt_module_state` to decide whether a module should boot.
     * Always-on core modules return true regardless of row state.
     * Unknown modules (not yet seeded) default to true.
     */
    public static function isEnabled( string $module_class ): bool {
        if ( self::isAlwaysOn( $module_class ) ) return true;
        $state = self::loadStateCache();
        if ( ! array_key_exists( $module_class, $state ) ) return true;
        return (bool) $state[ $module_class ];
    }

    /**
     * Persist a new enabled state for the module. Drops the cache
     * so subsequent requests pick up the change.
     */
    public static function setEnabled( string $module_class, bool $enabled, ?int $actor_user_id = null ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_module_state";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        // Core modules cannot be disabled.
        if ( self::isAlwaysOn( $module_class ) && ! $enabled ) return;

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE module_class = %s",
            $module_class
        ) );
        $row = [
            'enabled'    => $enabled ? 1 : 0,
            'updated_at' => current_time( 'mysql' ),
            'updated_by' => $actor_user_id !== null ? $actor_user_id : get_current_user_id(),
        ];
        if ( $existing > 0 ) {
            $wpdb->update( $table, $row, [ 'module_class' => $module_class ] );
        } else {
            $row['module_class'] = $module_class;
            $wpdb->insert( $table, $row );
        }
        self::$stateCache = null;
    }

    /**
     * #2409 — is the module marked "under development"? Cosmetic and
     * independent of `enabled`: the module stays fully live, the flag only
     * drives the informational pill on its views and the badge on its
     * dashboard tiles. Unknown modules are never under development.
     */
    public static function isUnderDevelopment( string $module_class ): bool {
        $dev = self::loadDevStateCache();
        return $dev[ ltrim( $module_class, '\\' ) ] ?? ( $dev[ $module_class ] ?? false );
    }

    /**
     * #2409 — persist the under-development flag.
     *
     * Two deliberate differences from setEnabled(): an always-on core module
     * CAN be flagged (the flag gates nothing, so there is no core surface to
     * protect), and `enabled` is preserved on INSERT from the module's
     * currently-resolved state rather than the column default — otherwise
     * flagging a module that is off would silently switch it on.
     */
    public static function setUnderDevelopment( string $module_class, bool $flag, ?int $actor_user_id = null ): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_module_state";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE module_class = %s",
            $module_class
        ) );
        $row = [
            'under_development' => $flag ? 1 : 0,
            'updated_at'        => current_time( 'mysql' ),
            'updated_by'        => $actor_user_id !== null ? $actor_user_id : get_current_user_id(),
        ];
        if ( $existing > 0 ) {
            $wpdb->update( $table, $row, [ 'module_class' => $module_class ] );
        } else {
            $row['module_class'] = $module_class;
            $row['enabled']      = self::isEnabled( $module_class ) ? 1 : 0;
            $wpdb->insert( $table, $row );
        }
        self::$stateCache    = null;
        self::$devStateCache = null;
    }

    /**
     * @return list<array{class:string, enabled:bool, always_on:bool}>
     *         Every class declared in `config/modules.php`, with its
     *         current state and whether it's always-on.
     */
    public static function allWithState(): array {
        $config_file = defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR . 'config/modules.php' : '';
        if ( $config_file === '' || ! is_readable( $config_file ) ) return [];
        $declared = require $config_file;
        if ( ! is_array( $declared ) ) return [];
        $state = self::loadStateCache();
        $out = [];
        foreach ( $declared as $class => $_default ) {
            $out[] = [
                'class'             => $class,
                'enabled'           => self::isEnabled( $class ),
                'always_on'         => self::isAlwaysOn( $class ),
                // #2409 — cosmetic flag, independent of enabled.
                'under_development' => self::isUnderDevelopment( $class ),
            ];
        }
        return $out;
    }

    /**
     * @return array<string, bool>
     */
    private static function loadStateCache(): array {
        if ( self::$stateCache !== null ) return self::$stateCache;
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_module_state";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            self::$stateCache    = [];
            self::$devStateCache = [];
            return self::$stateCache;
        }
        // #2409 — read enabled + under_development in one pass. The
        // under_development column arrives with migration 0209; during the
        // narrow upgrade window before it runs the wider SELECT errors, so
        // fall back to the enabled-only shape and treat dev as all-false.
        $rows = $wpdb->get_results( "SELECT module_class, enabled, under_development FROM {$table}" );
        if ( $rows === null ) {
            $rows = $wpdb->get_results( "SELECT module_class, enabled FROM {$table}" );
        }
        $out = [];
        $dev = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $out[ (string) $r->module_class ] = (bool) $r->enabled;
                $dev[ (string) $r->module_class ] = (bool) ( $r->under_development ?? 0 );
            }
        }
        self::$stateCache    = $out;
        self::$devStateCache = $dev;
        return $out;
    }

    /**
     * @return array<string, bool> module_class => under_development
     */
    private static function loadDevStateCache(): array {
        if ( self::$devStateCache === null ) self::loadStateCache();
        return self::$devStateCache ?? [];
    }
}
