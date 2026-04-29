<?php
namespace TT\Modules\Threads;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Threads\Domain\ThreadTypeAdapter;

/**
 * ThreadTypeRegistry — maps `thread_type` slug to its adapter (#0028).
 *
 * Modules call register('goal', new GoalThreadAdapter()) from boot().
 * v1 wires the goal adapter from this module itself; follow-up PRs
 * for #0017 / #0014 / #0044 add their own adapters from their own
 * module boot().
 */
final class ThreadTypeRegistry {

    /** @var array<string, ThreadTypeAdapter> */
    private static array $adapters = [];

    public static function register( string $type, ThreadTypeAdapter $adapter ): void {
        $type = sanitize_key( $type );
        if ( $type === '' ) return;
        self::$adapters[ $type ] = $adapter;
    }

    public static function get( string $type ): ?ThreadTypeAdapter {
        return self::$adapters[ sanitize_key( $type ) ] ?? null;
    }

    /** @return list<string> */
    public static function known(): array {
        return array_keys( self::$adapters );
    }

    public static function clear(): void {
        self::$adapters = [];
    }
}
