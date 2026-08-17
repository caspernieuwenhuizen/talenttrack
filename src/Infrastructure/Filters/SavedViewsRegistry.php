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
     * @var array<string,string|array<int,string>>
     */
    private static array $runtime = [];

    /**
     * view_key => capability, or a list of capabilities of which ANY grants
     * access. Several lists gate their own REST endpoint on
     * "view-cap OR edit-cap" (teams, goals), and a user holding only the edit
     * cap can see the list — so a single-capability gate here would refuse
     * saved views to someone who can use the surface.
     *
     * The five report keys below are the surfaces #2385 shipped; their keys
     * are unchanged so presets saved before this refactor keep working. The
     * list surfaces (#2449) each carry the capability their own REST list
     * endpoint is gated on, which is what actually decides whether the user
     * can see the rows a saved view would filter.
     *
     * @var array<string,string|array<int,string>>
     */
    private const MAP = [
        // Reports (#2385).
        'attendance_team'        => 'tt_view_analytics',
        'attendance_player'      => 'tt_view_analytics',
        'attendance_leaderboard' => 'tt_view_analytics',
        'minutes_team'           => 'tt_view_analytics',
        'minutes_audit'          => 'tt_view_analytics',

        // List views (#2449).
        'players-list'      => 'tt_view_players',
        'teams-list'        => [ 'tt_view_teams', 'tt_edit_teams' ],
        'people-list'       => 'tt_view_people',
        'evaluations-list'  => 'tt_view_evaluations',
        'goals-list'        => [ 'tt_view_goals', 'tt_edit_goals' ],
        'tournaments-list'  => 'tt_view_tournaments',
        'holidays-list'     => 'tt_view_holidays',
        'activities-list'   => 'tt_view_activities',
        'audit-log'         => 'tt_view_settings',

        // Standard reports (#2449). All six render through
        // FrontendStandardReportsView::renderPeriodFilterBar() and share the
        // reports capability; the slug keeps each report's views its own.
        'report-player-minutes-played'         => 'tt_view_analytics',
        'report-team-minutes-distribution'     => 'tt_view_analytics',
        'report-team-squad-evaluation-summary' => 'tt_view_analytics',
        'report-season-summary'                => 'tt_view_analytics',
        'report-season-trial-funnel'           => 'tt_view_analytics',
        'report-scout-report-card'             => 'tt_view_analytics',
    ];

    /**
     * Register a surface. Later calls win, so a module can override a default.
     *
     * @param string|array<int,string> $capability one cap, or a list of which any grants access.
     */
    public static function register( string $view_key, $capability ): void {
        if ( $view_key === '' || $capability === '' || $capability === [] ) return;
        self::$runtime[ $view_key ] = $capability;
    }

    /**
     * The full map, filterable.
     *
     * @return array<string,string|array<int,string>>
     */
    public static function all(): array {
        /** @var array<string,string|array<int,string>> $map */
        $map = apply_filters( 'tt_saved_views_registry', array_merge( self::MAP, self::$runtime ) );
        return is_array( $map ) ? $map : self::MAP;
    }

    /**
     * The capabilities gating a surface. Empty when the key is unknown.
     *
     * @return array<int,string>
     */
    public static function capabilitiesFor( string $view_key ): array {
        if ( $view_key === '' ) return [];
        $caps = self::all()[ $view_key ] ?? null;
        if ( is_string( $caps ) ) $caps = [ $caps ];
        if ( ! is_array( $caps ) ) return [];

        return array_values( array_filter(
            array_map( static fn( $c ) => is_string( $c ) ? $c : '', $caps ),
            static fn( string $c ): bool => $c !== ''
        ) );
    }

    /** The first capability gating a surface, or null when the key is unknown. */
    public static function capabilityFor( string $view_key ): ?string {
        return self::capabilitiesFor( $view_key )[0] ?? null;
    }

    /** True when the current user may use saved views on this surface. */
    public static function currentUserCan( string $view_key ): bool {
        $caps = self::capabilitiesFor( $view_key );
        // Unknown surface → refuse. Never fall back to a permissive default:
        // an unregistered key must not become a way to bypass the gate.
        if ( $caps === [] ) return false;

        foreach ( $caps as $cap ) {
            if ( current_user_can( $cap ) ) return true;
        }
        return false;
    }

    /** Reset runtime registrations. Test-support only. */
    public static function resetForTests(): void {
        self::$runtime = [];
    }
}
