<?php
namespace TT\Infrastructure\Filters;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SavedViewsRegistry (#2448) — maps a saved-views surface key to the
 * capability that gates it.
 *
 * #2385 hardcoded `tt_view_analytics` on all three REST routes, which was
 * fine while saved views existed only on the reports. Once any FilterBar
 * surface can opt in, the gate has to match the surface's own capability —
 * a players-list preset must be gated on the players capability, not on the
 * analytics one.
 *
 * This registry is the single source of truth. The renderer resolves the
 * capability from here rather than taking one from the caller, so the
 * render-time gate and the REST-time gate cannot drift apart: a surface that
 * forgets to register simply renders no strip and is refused by REST
 * (fail-closed), instead of rendering a control whose writes are then
 * rejected.
 *
 * Extend via the `tt_saved_views_registry` filter rather than editing the
 * map, if a module needs to add a surface from outside this file.
 */
final class SavedViewsRegistry {

    /**
     * Surfaces registered at runtime (via `register()`), merged over MAP.
     *
     * @var array<string,string>
     */
    private static array $runtime = [];

    /**
     * view_key => capability.
     *
     * The five report keys below are the surfaces #2385 shipped; their keys
     * are unchanged so presets saved before this refactor keep working.
     *
     * @var array<string,string>
     */
    private const MAP = [
        'attendance_team'        => 'tt_view_analytics',
        'attendance_player'      => 'tt_view_analytics',
        'attendance_leaderboard' => 'tt_view_analytics',
        'minutes_team'           => 'tt_view_analytics',
        'minutes_audit'          => 'tt_view_analytics',
    ];

    /** Register a surface. Later calls win, so a module can override a default. */
    public static function register( string $view_key, string $capability ): void {
        if ( $view_key === '' || $capability === '' ) return;
        self::$runtime[ $view_key ] = $capability;
    }

    /**
     * The full map, filterable.
     *
     * @return array<string,string>
     */
    public static function all(): array {
        /** @var array<string,string> $map */
        $map = apply_filters( 'tt_saved_views_registry', array_merge( self::MAP, self::$runtime ) );
        return is_array( $map ) ? $map : self::MAP;
    }

    /** The capability gating a surface, or null when the key is unknown. */
    public static function capabilityFor( string $view_key ): ?string {
        if ( $view_key === '' ) return null;
        $map = self::all();
        $cap = $map[ $view_key ] ?? null;
        return is_string( $cap ) && $cap !== '' ? $cap : null;
    }

    /** True when the current user may use saved views on this surface. */
    public static function currentUserCan( string $view_key ): bool {
        $cap = self::capabilityFor( $view_key );
        // Unknown surface → refuse. Never fall back to a permissive default:
        // an unregistered key must not become a way to bypass the gate.
        return $cap !== null && current_user_can( $cap );
    }

    /** Reset runtime registrations. Test-support only. */
    public static function resetForTests(): void {
        self::$runtime = [];
    }
}
