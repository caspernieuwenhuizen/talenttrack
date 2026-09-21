<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerReportAudience (#3876, epic #3871) — who a player report is written
 * for, and what that audience may ever receive.
 *
 * - **internal** — staff. Every block, each gated for the reader as before.
 * - **scout** — an external reader. The allowlist below, with evaluation
 *   scores but never the coach's written notes, and tests at the public level
 *   only. The family allowlist minus goals, which are the academy's own
 *   development work as far as another club is concerned.
 * - **family** (#3955) — the player and their parents. Attendance, playing
 *   time, goals, evaluation scores without the coach's notes, and tests at the
 *   public level. One audience for both: a parent and their child read the
 *   same document, the one the coach chose to share.
 *
 * The internal and scout audiences are **resolved from the reader**, never
 * taken from a request: a scout cannot ask for the internal report. A reader
 * the rules cannot place gets the scout composition — when unsure about
 * exposing a child's data, less (CLAUDE.md §1).
 *
 * Family is never resolved from a reader. A family report exists only as a
 * snapshot a coach shared (`PlayerReportSnapshots::share()`), composed with the
 * coach's view and cut to the allowlist when it is taken. Families do not
 * generate reports.
 *
 * The gate is on the **payload**: a block outside the allowlist is absent
 * from the data, not merely unrendered, so no consumer — REST, screen, PDF,
 * snapshot, emailed link — can show what was never there.
 */
final class PlayerReportAudience {

    public const INTERNAL = 'internal';
    public const SCOUT    = 'scout';
    public const FAMILY   = 'family';

    /** Blocks the scout audience may ever receive, in print order. */
    public const SCOUT_BLOCKS = [
        PlayerReportBlock::LETTERHEAD,
        PlayerReportBlock::RATINGS,
        PlayerReportBlock::ATTENDANCE,
        PlayerReportBlock::MINUTES,
        PlayerReportBlock::TESTS,
    ];

    /**
     * Blocks a family report may ever carry, in print order. Status and
     * potential are staff judgements and never reach a family; nor do talking
     * points, the development plan, staff notes, injuries, the journey,
     * behaviour or the blank notes area.
     */
    public const FAMILY_BLOCKS = [
        PlayerReportBlock::LETTERHEAD,
        PlayerReportBlock::RATINGS,
        PlayerReportBlock::ATTENDANCE,
        PlayerReportBlock::MINUTES,
        PlayerReportBlock::GOALS,
        PlayerReportBlock::TESTS,
    ];

    /** Personas whose reading of a player report is a staff reading. */
    private const STAFF_PERSONAS = [
        'head_of_development', 'academy_admin', 'head_coach', 'assistant_coach',
        'team_manager', 'readonly_observer', 'staff',
    ];

    public static function isValid( string $audience ): bool {
        return in_array( $audience, [ self::INTERNAL, self::SCOUT, self::FAMILY ], true );
    }

    /**
     * Is this an audience outside the staff room? Such an audience reads tests
     * at the public level and evaluation scores without the coach's notes.
     */
    public static function isExternal( string $audience ): bool {
        return self::allowlist( $audience ) !== null;
    }

    /**
     * The blocks this audience may ever receive; null for the internal
     * audience, which is limited only by each block's own gate.
     *
     * @return list<string>|null
     */
    public static function allowlist( string $audience ): ?array {
        if ( $audience === self::SCOUT )  return self::SCOUT_BLOCKS;
        if ( $audience === self::FAMILY ) return self::FAMILY_BLOCKS;
        return null;
    }

    /**
     * The line under the report, on screen and on paper. A staff report says
     * it stays with staff; one shared with the family says who it is for.
     */
    public static function footerText( bool $family ): string {
        return $family
            ? __( 'Shared by the academy with the player and their parents. It covers this player only.', 'talenttrack' )
            : __( 'Confidential — staff only. This report describes a minor\'s development. Do not share it with the player, their parents or anyone outside the coaching staff.', 'talenttrack' );
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
     * staff, the whole allowlist for an external audience.
     *
     * @param list<string> $blocks
     * @return list<string>
     */
    public static function blocksFor( string $audience, array $blocks ): array {
        $allowed = self::allowlist( $audience );
        if ( $allowed === null ) return $blocks;
        if ( $blocks === [] ) return $allowed;

        return array_values( array_filter( $blocks, static fn( string $b ): bool => in_array( $b, $allowed, true ) ) );
    }

    /**
     * Strip from a composed payload what this audience may not receive. For an
     * external audience: any block outside its allowlist, the coach's written
     * notes on each evaluation, and the playing-time comparison with teammates
     * (#3991). The player's own share of the minutes stays; the position
     * group's and the team's averages go, because in a small group an average
     * is another child's minutes — with two goalkeepers, exactly them.
     *
     * @param array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>} $report
     * @return array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>}
     */
    public static function apply( array $report, string $audience ): array {
        $allowed = self::allowlist( $audience );
        if ( $allowed === null ) return $report;

        $report['blocks'] = array_values( array_filter( $report['blocks'], static fn( string $b ): bool => in_array( $b, $allowed, true ) ) );
        $report['data']   = array_intersect_key( $report['data'], array_flip( $report['blocks'] ) );

        if ( isset( $report['data'][ PlayerReportBlock::RATINGS ]['evaluations'] ) && is_array( $report['data'][ PlayerReportBlock::RATINGS ]['evaluations'] ) ) {
            foreach ( $report['data'][ PlayerReportBlock::RATINGS ]['evaluations'] as $i => $evaluation ) {
                if ( is_array( $evaluation ) ) {
                    unset( $evaluation['notes'] );
                    $report['data'][ PlayerReportBlock::RATINGS ]['evaluations'][ $i ] = $evaluation;
                }
            }
        }
        if ( isset( $report['data'][ PlayerReportBlock::MINUTES ] ) && is_array( $report['data'][ PlayerReportBlock::MINUTES ] ) ) {
            unset( $report['data'][ PlayerReportBlock::MINUTES ]['comparison'] );
        }
        return $report;
    }
}
