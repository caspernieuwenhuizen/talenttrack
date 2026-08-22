<?php
namespace TT\Modules\Alerts;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Contracts\AlertInterface;

/**
 * AlertRegistry (#2631, epic #2629) — the catalogue of alert definitions.
 *
 * Mirrors `WizardRegistry` / `Workflow\TemplateRegistry`: modules register
 * their own definitions through the `tt_register_alerts` filter, so this
 * class stays unaware of which modules exist and a module can ship an alert
 * without anything here changing.
 *
 *     add_filter( 'tt_register_alerts', function ( array $alerts ): array {
 *         $alerts[] = new MyAlert();
 *         return $alerts;
 *     } );
 *
 * Resolution is cached per request. The filter fires once, on first access,
 * rather than on registration, so a module registering late in `init` is
 * still picked up by a sweep later in the request.
 */
final class AlertRegistry {

    /** @var array<string,AlertInterface>|null */
    private static $cache = null;

    /**
     * Every registered definition, keyed by `key()`.
     *
     * A duplicate key is dropped rather than overwriting: two definitions
     * claiming one key would share a `dedupe_key` namespace and silently
     * resolve each other's occurrences every sweep. Losing the second is
     * visible in the diagnostic; interleaved resolution would not be.
     *
     * @return array<string,AlertInterface>
     */
    public static function all(): array {
        if ( self::$cache !== null ) return self::$cache;

        $registered = apply_filters( 'tt_register_alerts', [] );
        $out        = [];

        foreach ( is_array( $registered ) ? $registered : [] as $alert ) {
            if ( ! $alert instanceof AlertInterface ) continue;
            $key = $alert->key();
            if ( $key === '' ) continue;
            if ( isset( $out[ $key ] ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf(
                        '[TalentTrack alerts] duplicate alert key "%s" ignored (%s)',
                        $key,
                        get_class( $alert )
                    ) );
                }
                continue;
            }
            $out[ $key ] = $alert;
        }

        self::$cache = $out;
        return $out;
    }

    public static function find( string $key ): ?AlertInterface {
        $all = self::all();
        return $all[ $key ] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array {
        return array_keys( self::all() );
    }

    /**
     * Definitions belonging to one module slug.
     *
     * @return array<string,AlertInterface>
     */
    public static function forModule( string $module ): array {
        return array_filter(
            self::all(),
            static function ( AlertInterface $a ) use ( $module ): bool {
                return $a->module() === $module;
            }
        );
    }

    /** Drop the per-request cache. Tests and the module toggle use this. */
    public static function flush(): void {
        self::$cache = null;
    }
}
