<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerReportAudience (#3876, epic #3871) — who a player report is written
 * for, and what that audience may ever receive.
 *
 * Two audiences here; parents and players follow in #3955.
 *
 * - **internal** — staff. Every block, each gated for the reader as before.
 * - **scout** — an external reader. The allowlist below, with evaluation
 *   scores but never the coach's written notes, and tests at the public level
 *   only. The external allowlist decided for parents in #3955, minus goals,
 *   which are internal development work.
 *
 * The audience is **resolved from the reader**, never taken from a request: a
 * scout cannot ask for the internal report. A reader the rules cannot place
 * gets the scout composition — when unsure about exposing a child's data, less
 * (CLAUDE.md §1).
 *
 * The gate is on the **payload**: a block outside the allowlist is absent
 * from the data, not merely unrendered, so no consumer — REST, screen, PDF,
 * snapshot, emailed link — can show what was never there.
 */
final class PlayerReportAudience {

    public const INTERNAL = 'internal';
    public const SCOUT    = 'scout';

    /** Blocks the scout audience may ever receive, in print order. */
    public const SCOUT_BLOCKS = [
        PlayerReportBlock::LETTERHEAD,
        PlayerReportBlock::RATINGS,
        PlayerReportBlock::ATTENDANCE,
        PlayerReportBlock::MINUTES,
        PlayerReportBlock::TESTS,
    ];

    /** Personas whose reading of a player report is a staff reading. */
    private const STAFF_PERSONAS = [
        'head_of_development', 'academy_admin', 'head_coach', 'assistant_coach',
        'team_manager', 'readonly_observer', 'staff',
    ];

    public static function isValid( string $audience ): bool {
        return in_array( $audience, [ self::INTERNAL, self::SCOUT ], true );
    }

    /**
     * The audience this reader reads as. A site administrator and anyone
     * holding a staff persona read the internal report; everyone else — a
     * scout, or a reader who reached the report some other way — the scout one.
     */
    public static function forReader( int $user_id ): string {
        if ( $user_id <= 0 ) return self::SCOUT;
        if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'tt_edit_settings' ) ) return self::INTERNAL;

        if ( class_exists( '\\TT\\Modules\\Authorization\\PersonaResolver' ) ) {
            $personas = \TT\Modules\Authorization\PersonaResolver::effectivePersonas( $user_id );
            if ( array_intersect( self::STAFF_PERSONAS, $personas ) !== [] ) return self::INTERNAL;
        }
        return self::SCOUT;
    }

    /**
     * The blocks this audience may receive, out of the ones asked for. An
     * empty request is the audience's own default: the conversation set for
     * staff, the whole allowlist for a scout.
     *
     * @param list<string> $blocks
     * @return list<string>
     */
    public static function blocksFor( string $audience, array $blocks ): array {
        if ( $audience !== self::SCOUT ) return $blocks;
        if ( $blocks === [] ) return self::SCOUT_BLOCKS;

        return array_values( array_filter( $blocks, static fn( string $b ): bool => in_array( $b, self::SCOUT_BLOCKS, true ) ) );
    }

    /**
     * Strip from a composed payload what this audience may not receive. For a
     * scout: any block outside the allowlist, and the coach's written notes on
     * each evaluation.
     *
     * @param array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>} $report
     * @return array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>}
     */
    public static function apply( array $report, string $audience ): array {
        if ( $audience !== self::SCOUT ) return $report;

        $report['blocks'] = array_values( array_filter( $report['blocks'], static fn( string $b ): bool => in_array( $b, self::SCOUT_BLOCKS, true ) ) );
        $report['data']   = array_intersect_key( $report['data'], array_flip( $report['blocks'] ) );

        if ( isset( $report['data'][ PlayerReportBlock::RATINGS ]['evaluations'] ) && is_array( $report['data'][ PlayerReportBlock::RATINGS ]['evaluations'] ) ) {
            foreach ( $report['data'][ PlayerReportBlock::RATINGS ]['evaluations'] as $i => $evaluation ) {
                if ( is_array( $evaluation ) ) {
                    unset( $evaluation['notes'] );
                    $report['data'][ PlayerReportBlock::RATINGS ]['evaluations'][ $i ] = $evaluation;
                }
            }
        }
        return $report;
    }
}
