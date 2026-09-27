<?php
namespace TT\Modules\Tournaments\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TournamentScoreOwnership (#4055) — is this activity's scoreline the
 * tournament fixture's to change, rather than the activity's?
 *
 * #4021 made `tt_tournament_matches` the single score store for a tournament
 * fixture: the fixture activity's scoreline is derived from `our_score` /
 * `their_score` and the minutes grid renders it read-only. One route was left
 * outside that lane's file boundary and still took the write —
 * `PUT /activities/{id}/result` — so an integration could PATCH an activity
 * score, get a `200`, and find the value gone on the next sync from the
 * fixture. A write that silently does nothing is the shape of a bug report
 * later; refusing it names the real rule instead.
 *
 * Two kinds of activity are covered, for the same reason:
 *
 * - a **fixture** activity, which the planner stamps with
 *   `activity_source_key = tournament` on kick-off. Its result belongs on the
 *   fixture, and the refusal can name the exact route for it.
 * - the **tournament day**, which is a read-only roll-up of its fixtures
 *   (#3857) and a multi-game day besides (#2686) — one scoreline cannot
 *   describe five games, so there is nothing for a write to mean.
 *
 * The answer is a read, never a side effect, and it lives here rather than in
 * the controller so a future front end asking the same question gets the same
 * answer (CLAUDE.md §4).
 */
final class TournamentScoreOwnership {

    /** The activity was promoted from a tournament fixture. */
    public const KIND_FIXTURE = 'fixture';

    /** The activity is the tournament day itself. */
    public const KIND_DAY = 'day';

    /** The `activity_source_key` the planner stamps on a promoted fixture. */
    private const SOURCE = 'tournament';

    /**
     * Who owns this activity's scoreline, or `null` when the activity owns it
     * itself — an ordinary match, a training, or an activity of another club.
     *
     * `route` is what a refusal should point the caller at: the fixture's own
     * PATCH, concrete where the fixture is known and in its template form for
     * a day, which has no single fixture to name.
     *
     * @return array{kind:string, tournament_id:int, match_id:int, route:string}|null
     */
    public static function forActivity( int $activity_id ): ?array {
        if ( $activity_id <= 0 ) return null;

        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT activity_type_key, activity_source_key, tournament_id
               FROM {$p}tt_activities
              WHERE id = %d AND club_id = %d
              LIMIT 1",
            $activity_id,
            (int) CurrentClub::id()
        ) );
        if ( ! is_object( $row ) ) return null;

        $type   = strtolower( (string) ( $row->activity_type_key ?? '' ) );
        $source = strtolower( (string) ( $row->activity_source_key ?? '' ) );

        if ( $type === ActivityTypeKey::TOURNAMENT ) {
            return [
                'kind'          => self::KIND_DAY,
                'tournament_id' => (int) ( $row->tournament_id ?? 0 ),
                'match_id'      => 0,
                'route'         => 'PATCH /tournaments/{id}/matches/{match_id}',
            ];
        }

        if ( $source !== self::SOURCE ) return null;

        // The fixture behind the activity, so the refusal can name the exact
        // route rather than a template the caller has to fill in.
        $fixture = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, tournament_id
               FROM {$p}tt_tournament_matches
              WHERE activity_id = %d AND club_id = %d
              LIMIT 1",
            $activity_id,
            (int) CurrentClub::id()
        ) );

        $tournament_id = (int) ( $fixture->tournament_id ?? ( $row->tournament_id ?? 0 ) );
        $match_id      = (int) ( $fixture->id ?? 0 );

        return [
            'kind'          => self::KIND_FIXTURE,
            'tournament_id' => $tournament_id,
            'match_id'      => $match_id,
            'route'         => $tournament_id > 0 && $match_id > 0
                ? 'PATCH /tournaments/' . $tournament_id . '/matches/' . $match_id
                : 'PATCH /tournaments/{id}/matches/{match_id}',
        ];
    }
}
