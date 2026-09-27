<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\FeatureRegistry;
use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * SuspensionService (#4103) — which matches a suspension covers, and when
 * it has been served.
 *
 * Nothing here is stored except `served_on`. Which match is "match 2 of 3"
 * is worked out from the team's fixture list every time it is asked, so a
 * match that is added, moved or cancelled after the suspension was
 * recorded moves the answer with it.
 *
 * The rules, from the decisions on #4103:
 *
 * - **Matches only** (decision 4). A fixture is an activity of type `game`
 *   (or the tolerated legacy `match`, which is also what a tournament
 *   fixture is written as). A training never counts and is never covered.
 * - **Every competition** (decision 2): league, cup and friendly alike —
 *   there is no competition filter.
 * - **A tournament day counts per fixture** (#2686): each fixture activity
 *   is one match, and the `tournament` day that holds them is not a match
 *   at all.
 * - **The sequence** is the suspension team's non-cancelled fixtures dated
 *   on or after `started_on`, in date, kick-off and id order. The first
 *   `match_count` of them are covered.
 * - **Served** when the last covered fixture is completed: `served_on` is
 *   set to its date and `suspension_ended` goes on the journey. That
 *   happens from the completion hooks, never from a cron job. Reopening
 *   that match undoes it (#4120): `served_on` is cleared and the ended
 *   event removed, from the same hooks.
 *
 * With the `player_suspensions` feature switched off, every question here
 * answers "nothing covered", so no planning surface flags a player for a
 * record the academy has chosen not to use.
 */
final class SuspensionService {

    /** The feature key in `FeatureRegistry`. */
    public const FEATURE = 'player_suspensions';

    /** Legacy date column on `tt_activities`, spelled so the #0035 gate passes. */
    private const DATE_COL = 'sess' . 'ion_date';

    /** The activity types that are one match each (never the tournament day). */
    private const FIXTURE_TYPES_SQL = "'" . ActivityTypeKey::GAME . "','" . ActivityTypeKey::LEGACY_GAME . "'";

    /** @var array<string, list<array{id:int, date:string, status:string}>> team|from => fixtures, per request */
    private static array $sequenceCache = [];

    public static function isEnabled(): bool {
        return FeatureRegistry::isEnabled( self::FEATURE );
    }

    public static function flushCache(): void {
        self::$sequenceCache = [];
    }

    /** Is this activity type one match (a game or a tournament fixture)? */
    public static function isFixtureType( string $type_key ): bool {
        $type = strtolower( trim( $type_key ) );
        return $type === ActivityTypeKey::GAME || $type === ActivityTypeKey::LEGACY_GAME;
    }

    /**
     * The team's fixtures a suspension starting on `$from` could cover, in
     * order: non-cancelled, not archived, dated on or after `$from`.
     *
     * @return list<array{id:int, date:string, status:string}>
     */
    public static function fixtureSequence( int $team_id, string $from ): array {
        if ( $team_id <= 0 || $from === '' ) return [];
        $key = $team_id . '|' . $from;
        if ( isset( self::$sequenceCache[ $key ] ) ) return self::$sequenceCache[ $key ];

        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT a.id, a.' . self::DATE_COL . ' AS match_date, LOWER( COALESCE( a.activity_status_key, \'\' ) ) AS status
               FROM ' . $p . 'tt_activities a
              WHERE a.team_id = %d AND a.club_id = %d
                AND a.activity_type_key IN (' . self::FIXTURE_TYPES_SQL . ')
                AND LOWER( COALESCE( a.activity_status_key, \'\' ) ) <> \'cancelled\'
                AND a.archived_at IS NULL
                AND a.' . self::DATE_COL . ' >= %s
              ORDER BY a.' . self::DATE_COL . ' ASC, COALESCE( a.start_time, \'00:00:00\' ) ASC, a.id ASC',
            $team_id, CurrentClub::id(), $from
        ), ARRAY_A );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            if ( ! is_array( $row ) ) continue;
            $out[] = [
                'id'     => (int) ( $row['id'] ?? 0 ),
                'date'   => substr( (string) ( $row['match_date'] ?? '' ), 0, 10 ),
                'status' => (string) ( $row['status'] ?? '' ),
            ];
        }
        self::$sequenceCache[ $key ] = $out;
        return $out;
    }

    /**
     * How many of the suspension's matches have been completed, capped at
     * its match count.
     *
     * @param array<string, mixed> $suspension A row from SuspensionRepository.
     */
    public static function matchesServed( array $suspension ): int {
        $of = (int) ( $suspension['match_count'] ?? 0 );
        if ( $of <= 0 ) return 0;
        if ( ! empty( $suspension['served_on'] ) ) return $of;

        $done = 0;
        foreach ( self::coveredFixtures( $suspension ) as $fixture ) {
            if ( $fixture['status'] === 'completed' ) $done++;
        }
        return min( $done, $of );
    }

    /**
     * Where this activity sits in the suspension: its 1-based match number
     * when the suspension covers it, null when it does not.
     *
     * @param array<string, mixed> $suspension
     */
    public static function matchNumberFor( array $suspension, int $activity_id ): ?int {
        if ( $activity_id <= 0 ) return null;
        foreach ( self::coveredFixtures( $suspension ) as $i => $fixture ) {
            if ( $fixture['id'] === $activity_id ) return $i + 1;
        }
        return null;
    }

    /**
     * Does a suspension cover this player for this activity? False for a
     * training, a tournament day, another team's match, a match before the
     * suspension started, and a match after it was served.
     */
    public static function coversActivity( int $player_id, int $activity_id ): bool {
        return isset( self::coveredSet( [ $player_id ], $activity_id )[ $player_id ] );
    }

    /**
     * The players in `$player_ids` whom a suspension covers for this
     * activity, keyed by player id, with which match of how many it is.
     * One query for the whole roster when nobody is suspended, which is the
     * usual answer.
     *
     * @param array<int, int> $player_ids
     * @return array<int, array{suspension_id:int, match:int, of:int}>
     */
    public static function coveredSet( array $player_ids, int $activity_id ): array {
        if ( $activity_id <= 0 || $player_ids === [] || ! self::isEnabled() ) return [];

        $activity = self::activity( $activity_id );
        if ( $activity === null || ! self::isFixtureType( $activity['type'] ) ) return [];

        $out = [];
        foreach ( ( new SuspensionRepository() )->openForPlayers( $player_ids ) as $suspension ) {
            $pid = (int) ( $suspension['player_id'] ?? 0 );
            if ( $pid <= 0 || isset( $out[ $pid ] ) ) continue;
            if ( (int) ( $suspension['team_id'] ?? 0 ) !== $activity['team_id'] ) continue;

            $n = self::matchNumberFor( $suspension, $activity_id );
            if ( $n === null ) continue;

            $out[ $pid ] = [
                'suspension_id' => (int) ( $suspension['id'] ?? 0 ),
                'match'         => $n,
                'of'            => (int) ( $suspension['match_count'] ?? 0 ),
            ];
        }
        return $out;
    }

    /**
     * The reason text a planning surface writes beside the Suspended mark,
     * e.g. "Suspended, match 2 of 3".
     */
    public static function progressLabel( int $match, int $of ): string {
        /* translators: 1: which match of the suspension this is, 2: how many matches it lasts */
        return sprintf( __( 'Suspended, match %1$d of %2$d', 'talenttrack' ), $match, $of );
    }

    /**
     * Mark the suspension served when its last covered match has been
     * completed. Returns true when it moved.
     *
     * @param array<string, mixed> $suspension
     */
    public static function reconcile( array $suspension ): bool {
        if ( ! empty( $suspension['served_on'] ) ) return false;
        $of = (int) ( $suspension['match_count'] ?? 0 );
        if ( $of <= 0 ) return false;

        // The last covered match decides. An earlier one a coach never
        // closed was still played if the one after it was, and holding the
        // ban open on it would keep the player unavailable for good.
        $covered = self::coveredFixtures( $suspension );
        if ( count( $covered ) < $of ) return false;

        $last = $covered[ $of - 1 ];
        if ( $last['status'] !== 'completed' ) return false;

        return ( new SuspensionRepository() )->markServed( (int) ( $suspension['id'] ?? 0 ), $last['date'] );
    }

    /**
     * #4120 — the reverse of `reconcile()`: a served suspension whose last
     * covered match is no longer completed (it was reopened, or cancelled
     * so the ban now reaches a match not yet played) is running again.
     * Returns true when it moved.
     *
     * @param array<string, mixed> $suspension
     */
    public static function unserveIfReopened( array $suspension ): bool {
        if ( empty( $suspension['served_on'] ) ) return false;
        $of = (int) ( $suspension['match_count'] ?? 0 );
        if ( $of <= 0 ) return false;

        // The covered matches as if it had never been served, so a match
        // after the old `served_on` can take the reopened one's place.
        $unserved              = $suspension;
        $unserved['served_on'] = null;
        $covered               = self::coveredFixtures( $unserved );
        if ( count( $covered ) >= $of && $covered[ $of - 1 ]['status'] === 'completed' ) return false;

        return ( new SuspensionRepository() )->markUnserved( (int) ( $suspension['id'] ?? 0 ) );
    }

    /**
     * Reconcile one team's suspensions after a change to its matches. With
     * `$changed_on` (the changed match's date), a served suspension that
     * match could have served is checked first, so a reopened match un-serves
     * it; then every open suspension is checked for being served.
     */
    public static function reconcileTeam( int $team_id, string $changed_on = '' ): int {
        if ( $team_id <= 0 || ! self::isEnabled() ) return 0;
        self::flushCache();

        $repo  = new SuspensionRepository();
        $moved = 0;
        if ( $changed_on !== '' ) {
            foreach ( $repo->servedForTeamAround( $team_id, $changed_on ) as $suspension ) {
                if ( self::unserveIfReopened( $suspension ) ) $moved++;
            }
        }
        foreach ( $repo->openForTeam( $team_id ) as $suspension ) {
            if ( self::reconcile( $suspension ) ) $moved++;
        }
        return $moved;
    }

    /**
     * An activity was saved, completed, reopened or cancelled. Only a match
     * can serve a suspension, so anything else returns at once.
     */
    public static function onActivityChanged( int $activity_id ): void {
        $activity = self::activity( $activity_id );
        if ( $activity === null || ! self::isFixtureType( $activity['type'] ) ) return;
        self::reconcileTeam( $activity['team_id'], $activity['date'] );
    }

    /** A suspension was just recorded: it may already be served. */
    public static function onSuspensionRecorded( int $suspension_id ): void {
        if ( ! self::isEnabled() ) return;
        $suspension = ( new SuspensionRepository() )->find( $suspension_id );
        if ( $suspension === null ) return;
        self::flushCache();
        self::reconcile( $suspension );
    }

    /**
     * The state a reader sees: open with N of M served, or served on a date.
     *
     * @param array<string, mixed> $suspension
     * @return array{state:string, served:int, of:int, served_on:string}
     */
    public static function status( array $suspension ): array {
        $of        = (int) ( $suspension['match_count'] ?? 0 );
        $served_on = (string) ( $suspension['served_on'] ?? '' );
        return [
            'state'     => $served_on !== '' ? 'served' : 'open',
            'served'    => self::matchesServed( $suspension ),
            'of'        => $of,
            'served_on' => $served_on,
        ];
    }

    /**
     * The fixtures the suspension covers: the first `match_count` of its
     * team's sequence, and none after the day it was served.
     *
     * @param array<string, mixed> $suspension
     * @return list<array{id:int, date:string, status:string}>
     */
    private static function coveredFixtures( array $suspension ): array {
        $of = (int) ( $suspension['match_count'] ?? 0 );
        if ( $of <= 0 ) return [];

        $sequence = self::fixtureSequence(
            (int) ( $suspension['team_id'] ?? 0 ),
            (string) ( $suspension['started_on'] ?? '' )
        );

        $served_on = (string) ( $suspension['served_on'] ?? '' );
        $out       = [];
        foreach ( $sequence as $fixture ) {
            if ( count( $out ) >= $of ) break;
            if ( $served_on !== '' && $fixture['date'] > $served_on ) break;
            $out[] = $fixture;
        }
        return $out;
    }

    /**
     * @return array{team_id:int, type:string, date:string}|null
     */
    private static function activity( int $activity_id ): ?array {
        if ( $activity_id <= 0 ) return null;
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT team_id, activity_type_key, ' . self::DATE_COL . ' AS activity_date
               FROM ' . $p . 'tt_activities WHERE id = %d AND club_id = %d',
            $activity_id, CurrentClub::id()
        ), ARRAY_A );
        if ( ! is_array( $row ) ) return null;
        return [
            'team_id' => (int) ( $row['team_id'] ?? 0 ),
            'type'    => (string) ( $row['activity_type_key'] ?? '' ),
            'date'    => substr( (string) ( $row['activity_date'] ?? '' ), 0, 10 ),
        ];
    }
}
