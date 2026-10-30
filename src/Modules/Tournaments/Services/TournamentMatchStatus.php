<?php
namespace TT\Modules\Tournaments\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TournamentMatchStatus (#4264) — where a tournament fixture is in its
 * lifecycle, derived from its row.
 *
 * Minutes are booked only when a fixture is completed, so a fixture that has
 * its score typed in but was never completed (`result_entered`) still counts
 * for nothing in the totals. The REST serializer and the detail view both ask
 * here, so they cannot disagree about it (CLAUDE.md §4).
 */
final class TournamentMatchStatus {

    public const SCHEDULED      = 'scheduled';
    public const IN_PROGRESS    = 'in_progress';
    public const RESULT_ENTERED = 'result_entered';
    public const COMPLETED      = 'completed';

    /**
     * @param array<string,mixed>|object $match A `tt_tournament_matches` row.
     */
    public static function of( $match ): string {
        $row = is_object( $match ) ? get_object_vars( $match ) : (array) $match;

        if ( ! empty( $row['completed_at'] ) ) return self::COMPLETED;
        if ( ! empty( $row['kicked_off_at'] ) ) return self::IN_PROGRESS;

        // `isset()` excludes null, which is how "no result recorded" reads;
        // a 0-0 is a result.
        if ( isset( $row['our_score'], $row['their_score'] ) ) return self::RESULT_ENTERED;

        return self::SCHEDULED;
    }

    /**
     * Fixtures that have been played, or are being played, but whose minutes
     * are not in the totals yet because nobody completed them.
     *
     * @param array<int, array<string,mixed>|object> $matches
     */
    public static function countNotCompleted( array $matches ): int {
        $n = 0;
        foreach ( $matches as $m ) {
            $status = self::of( $m );
            if ( $status === self::IN_PROGRESS || $status === self::RESULT_ENTERED ) $n++;
        }
        return $n;
    }
}
