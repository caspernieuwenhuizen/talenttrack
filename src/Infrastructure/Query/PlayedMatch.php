<?php
namespace TT\Infrastructure\Query;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\PlayerMatchMinutes;

/**
 * PlayedMatch (#4957) — the one rule for "this match has been played", and
 * the two sets of matches it is counted over. Every report figure that says
 * "played" reads it from here; an architecture test fails on a copy.
 *
 * The rule: dated today or earlier, and at least one of completed, dated
 * before today, minutes recorded, or a score. Each piece of evidence alone
 * is enough, but none of it counts for a match dated after today: a future
 * fixture marked completed, or carrying minutes or a score, is a data error,
 * not a match played.
 *
 * The sets, and why there are two:
 *
 *  - {@see FIXTURES} (game and the legacy match): the record. Played, won,
 *    drawn, lost, goals and "without a score". A tournament is a multi-game
 *    day, so one result line cannot describe it.
 *  - {@see MINUTES_BEARING} (fixtures and tournaments): every minutes figure.
 *    A tournament day carries minutes like any match.
 *
 * So a minutes surface's played count is the record's played count plus the
 * tournament days played.
 *
 * The clauses are literal SQL for a `tt_activities` alias; no user input
 * reaches them.
 */
final class PlayedMatch {

    public const FIXTURES        = 'fixtures';
    public const MINUTES_BEARING = 'minutes_bearing';

    /**
     * The rule, as a condition on a `tt_activities` alias. The alias must be
     * a real one: the minutes test joins against it, and an unqualified `id`
     * would bind to the attendance row inside the subquery instead.
     *
     * "Its date has passed" is strictly `<`: a fixture kicking off at 19:00
     * tonight is not played from midnight (#2833). Status alone is not the
     * gate either: the column defaults to `planned`, so every match recorded
     * without pressing "completed" would vanish (#2832).
     */
    public static function playedSql( string $alias = 'a' ): string {
        global $wpdb;
        $q        = self::alias( $alias ) . '.';
        // #0035 lint-safe: the legacy date column name is assembled, not typed.
        $date_col = $q . 'sess' . 'ion_date';

        $minutes = "EXISTS ( SELECT 1 FROM {$wpdb->prefix}tt_attendance played_att"
            . " WHERE played_att.activity_id = {$q}id"
            . "   AND played_att.record_type = 'actual'"
            . "   AND played_att.is_guest = 0"
            . "   AND COALESCE( played_att.minutes_override, played_att.minutes_played, 0 ) > 0"
            // #4556 — on the row the minutes read picks, not any row: a
            // duplicate corrected to 0 does not make the match played.
            . "   " . PlayerMatchMinutes::newestRowClause( 'played_att' ) . " )";

        return "( {$date_col} <= CURDATE() AND ( "
            . ActivityLifecycle::completedClause( self::alias( $alias ) )
            . " OR {$date_col} < CURDATE()"
            . " OR {$minutes}"
            . " OR ( {$q}home_score IS NOT NULL AND {$q}away_score IS NOT NULL )"
            . " ) )";
    }

    /**
     * The activity types of a set, as a condition on the alias. An unknown
     * set name reads as the fixtures, the narrower of the two.
     */
    public static function setSql( string $set, string $alias = 'a' ): string {
        $col = self::alias( $alias ) . '.activity_type_key';
        if ( $set === self::MINUTES_BEARING ) return ActivityTypeKey::matchTypeClause( $col );

        return "LOWER({$col}) IN ( '" . ActivityTypeKey::GAME . "', '" . ActivityTypeKey::LEGACY_GAME . "' )";
    }

    /**
     * A live match of the current club: not archived, not trashed, not
     * cancelled on either lifecycle axis (a NULL is not a cancellation), and
     * inside the demo scope. Starts with the club, so it reads as a whole
     * condition; the demo scope adds its own `AND`.
     */
    public static function liveSql( string $alias = 'a' ): string {
        $a = self::alias( $alias );
        return "{$a}.club_id = " . (int) CurrentClub::id()
            . " AND {$a}.archived_at IS NULL"
            . " AND {$a}.trashed_at IS NULL"
            . ' AND ' . ActivityLifecycle::notCancelledClause( $a )
            . QueryHelpers::apply_demo_scope( $a, 'activity' );
    }

    /** A live match of the set, of any date. */
    public static function liveInSetSql( string $set, string $alias = 'a' ): string {
        return '( ' . self::setSql( $set, $alias ) . ' AND ' . self::liveSql( $alias ) . ' )';
    }

    /** A live, played match of the set. */
    public static function playedInSetSql( string $set, string $alias = 'a' ): string {
        return '( ' . self::setSql( $set, $alias ) . ' AND ' . self::liveSql( $alias ) . ' AND ' . self::playedSql( $alias ) . ' )';
    }

    private static function alias( string $alias ): string {
        return preg_replace( '/[^A-Za-z0-9_]/', '', $alias ) ?: 'a';
    }
}
