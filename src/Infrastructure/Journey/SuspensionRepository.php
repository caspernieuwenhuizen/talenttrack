<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * SuspensionRepository (#4103) — CRUD on `tt_player_suspensions`.
 *
 * A suspension lasts a number of matches, not until a date (decision 1 on
 * #4103). This class stores what the coach recorded; which matches it
 * covers and when it has been served is derived by `SuspensionService`
 * from the team's own fixture list, so the two never disagree.
 *
 * Side-effects follow the injury record: a create emits
 * `suspension_started` on the journey, and `markServed()` emits
 * `suspension_ended`. Both are keyed on (Journey, suspension, id,
 * event_type), so re-saving never multiplies events. `markUnserved()`
 * removes the ended event again when the serving match is reopened.
 *
 * Rows come back as arrays. `$wpdb` is read with `global` in each method
 * rather than held as a property, which keeps the `literal-string`
 * narrowing `prepare()` needs at PHPStan level 8.
 */
final class SuspensionRepository {

    /** Longest ban the form accepts; a club decision can run a season. */
    public const MAX_MATCHES = 40;

    /**
     * Keys: player_id, started_on (Y-m-d), match_count (1 to MAX_MATCHES),
     * and optionally team_id (defaults to the player's current team),
     * reason_lookup_id and notes. Returns the new id, or 0 when the data
     * does not describe a suspension.
     *
     * @param array<string, mixed> $data
     */
    public function create( array $data ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $player_id   = (int) ( $data['player_id'] ?? 0 );
        $started     = self::cleanDate( (string) ( $data['started_on'] ?? '' ) );
        $match_count = (int) ( $data['match_count'] ?? 0 );
        if ( $player_id <= 0 || $started === '' || $match_count < 1 || $match_count > self::MAX_MATCHES ) return 0;

        $team_id = (int) ( $data['team_id'] ?? 0 );
        if ( $team_id <= 0 ) {
            $team_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT team_id FROM {$p}tt_players WHERE id = %d AND club_id = %d",
                $player_id, CurrentClub::id()
            ) );
        }

        $reason = (int) ( $data['reason_lookup_id'] ?? 0 );
        $notes  = trim( (string) ( $data['notes'] ?? '' ) );
        $now    = current_time( 'mysql' );
        $uid    = get_current_user_id();

        $row = [
            'uuid'             => wp_generate_uuid4(),
            'club_id'          => CurrentClub::id(),
            'player_id'        => $player_id,
            'team_id'          => max( 0, $team_id ),
            'started_on'       => $started,
            'match_count'      => $match_count,
            'reason_lookup_id' => $reason > 0 ? $reason : null,
            'notes'            => $notes !== '' ? $notes : null,
            'created_by'       => $uid > 0 ? $uid : null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];

        if ( $wpdb->insert( "{$p}tt_player_suspensions", $row ) === false ) return 0;
        $id = (int) $wpdb->insert_id;

        $this->emitStartedEvent( $id, $player_id, $started, $match_count, $reason );

        /**
         * #4103 — a suspension was recorded. `SuspensionService` listens, so
         * a suspension recorded after its matches were played is served at
         * once rather than waiting for the next match.
         *
         * @param int $suspension_id
         * @param int $player_id
         */
        do_action( 'tt_player_suspension_recorded', $id, $player_id );

        return $id;
    }

    /**
     * Partial update: an omitted field is left alone (CLAUDE.md §6). Keys:
     * started_on, match_count, reason_lookup_id, notes. Returns false when
     * nothing valid was given or the row does not exist.
     *
     * @param array<string, mixed> $patch
     */
    public function update( int $id, array $patch ): bool {
        global $wpdb;
        if ( $id <= 0 || $this->find( $id ) === null ) return false;

        $update = [];
        if ( array_key_exists( 'started_on', $patch ) ) {
            $date = self::cleanDate( (string) $patch['started_on'] );
            if ( $date === '' ) return false;
            $update['started_on'] = $date;
        }
        if ( array_key_exists( 'match_count', $patch ) ) {
            $n = (int) $patch['match_count'];
            if ( $n < 1 || $n > self::MAX_MATCHES ) return false;
            $update['match_count'] = $n;
        }
        if ( array_key_exists( 'reason_lookup_id', $patch ) ) {
            $r = (int) $patch['reason_lookup_id'];
            $update['reason_lookup_id'] = $r > 0 ? $r : null;
        }
        if ( array_key_exists( 'notes', $patch ) ) {
            $n = trim( (string) $patch['notes'] );
            $update['notes'] = $n !== '' ? $n : null;
        }
        if ( $update === [] ) return false;

        $update['updated_at'] = current_time( 'mysql' );
        return $wpdb->update(
            $wpdb->prefix . 'tt_player_suspensions',
            $update,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        ) !== false;
    }

    /**
     * Record that the last covered match has been completed. Only an open
     * suspension moves; a second call is a no-op.
     */
    public function markServed( int $id, string $served_on ): bool {
        global $wpdb;
        $row = $this->find( $id );
        if ( $row === null || ! empty( $row['served_on'] ) ) return false;

        $date = self::cleanDate( $served_on );
        if ( $date === '' ) return false;

        $ok = $wpdb->update(
            $wpdb->prefix . 'tt_player_suspensions',
            [ 'served_on' => $date, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        if ( $ok === false ) return false;

        $player_id = (int) $row['player_id'];
        EventEmitter::emit(
            $player_id,
            JourneyEventType::SUSPENSION_ENDED,
            $date . ' 00:00:00',
            __( 'Suspension served', 'talenttrack' ),
            [
                'suspension_id' => $id,
                'match_count'   => (int) $row['match_count'],
            ],
            'Journey',
            'suspension',
            $id
        );

        /**
         * #4103 — the last match a suspension covered has been completed.
         *
         * @param int $suspension_id
         * @param int $player_id
         */
        do_action( 'tt_player_suspension_served', $id, $player_id );

        return true;
    }

    /**
     * #4120 — undo `markServed()`: the match that served the suspension was
     * reopened. Clears `served_on` and removes the `suspension_ended` event,
     * so the journey stops saying the ban ended and a later completion
     * writes one fresh event rather than finding the old one. Only a served
     * suspension moves; a second call is a no-op.
     */
    public function markUnserved( int $id ): bool {
        global $wpdb;
        $row = $this->find( $id );
        if ( $row === null || empty( $row['served_on'] ) ) return false;

        $ok = $wpdb->update(
            $wpdb->prefix . 'tt_player_suspensions',
            [ 'served_on' => null, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        if ( $ok === false ) return false;

        $wpdb->delete( $wpdb->prefix . 'tt_player_events', [
            'source_module'      => 'Journey',
            'source_entity_type' => 'suspension',
            'source_entity_id'   => $id,
            'event_type'         => JourneyEventType::SUSPENSION_ENDED,
            'club_id'            => CurrentClub::id(),
        ] );

        $player_id = (int) $row['player_id'];

        /**
         * #4120 — a served suspension is running again because the match
         * that served it was reopened.
         *
         * @param int $suspension_id
         * @param int $player_id
         */
        do_action( 'tt_player_suspension_unserved', $id, $player_id );

        return true;
    }

    public function archive( int $id, int $user_id ): bool {
        global $wpdb;
        if ( $id <= 0 ) return false;
        $ok = $wpdb->update( $wpdb->prefix . 'tt_player_suspensions', [
            'archived_at' => current_time( 'mysql' ),
            'archived_by' => $user_id > 0 ? $user_id : null,
        ], [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        return is_int( $ok ) && $ok > 0;
    }

    /**
     * One suspension that is not in the recycle bin, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find( int $id ): ?array {
        global $wpdb;
        if ( $id <= 0 ) return null;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_player_suspensions WHERE id = %d AND club_id = %d AND trashed_at IS NULL",
            $id, CurrentClub::id()
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForPlayer( int $player_id, bool $include_archived = false ): array {
        global $wpdb;
        if ( $player_id <= 0 ) return [];
        $p     = $wpdb->prefix;
        $where = $include_archived ? '' : 'AND archived_at IS NULL';
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_player_suspensions
              WHERE player_id = %d AND club_id = %d AND trashed_at IS NULL {$where}
              ORDER BY started_on DESC, id DESC",
            $player_id, CurrentClub::id()
        ), ARRAY_A );
        return self::rows( $rows );
    }

    /**
     * Open suspensions (recorded, not archived, not yet served) for a set
     * of players — the rows a planning surface has to ask about.
     *
     * @param array<int, int> $player_ids
     * @return list<array<string, mixed>>
     */
    public function openForPlayers( array $player_ids ): array {
        global $wpdb;
        $ids = self::idList( $player_ids );
        if ( $ids === '' ) return [];

        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_player_suspensions
              WHERE FIND_IN_SET( player_id, %s ) AND club_id = %d
                AND archived_at IS NULL AND trashed_at IS NULL
                AND served_on IS NULL
              ORDER BY started_on ASC, id ASC",
            $ids, CurrentClub::id()
        ), ARRAY_A );
        return self::rows( $rows );
    }

    /**
     * Open suspensions served by one team's matches.
     *
     * @return list<array<string, mixed>>
     */
    public function openForTeam( int $team_id ): array {
        global $wpdb;
        if ( $team_id <= 0 ) return [];
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_player_suspensions
              WHERE team_id = %d AND club_id = %d
                AND archived_at IS NULL AND trashed_at IS NULL
                AND served_on IS NULL
              ORDER BY started_on ASC, id ASC",
            $team_id, CurrentClub::id()
        ), ARRAY_A );
        return self::rows( $rows );
    }

    /**
     * #4120 — served suspensions of one team that a match on `$date` could
     * have served: started on or before it, served on or after it.
     *
     * @return list<array<string, mixed>>
     */
    public function servedForTeamAround( int $team_id, string $date ): array {
        global $wpdb;
        $date = self::cleanDate( $date );
        if ( $team_id <= 0 || $date === '' ) return [];
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_player_suspensions
              WHERE team_id = %d AND club_id = %d
                AND archived_at IS NULL AND trashed_at IS NULL
                AND served_on IS NOT NULL
                AND started_on <= %s AND served_on >= %s
              ORDER BY started_on ASC, id ASC",
            $team_id, CurrentClub::id(), $date, $date
        ), ARRAY_A );
        return self::rows( $rows );
    }

    /**
     * Suspensions across a set of teams, for the squad overview, with the
     * player's name and the team's name joined in.
     *
     * @param array<int, int> $team_ids Empty means every team (academy-wide readers).
     * @param string          $status   open | served | all
     * @return list<array<string, mixed>>
     */
    public function listForTeams( array $team_ids, string $status = 'open' ): array {
        global $wpdb;
        $p   = $wpdb->prefix;
        $ids = self::idList( $team_ids );

        // One literal query per status rather than a WHERE built from
        // pieces, so every branch stays a literal-string for `prepare()`.
        $select = "SELECT s.*, pl.first_name, pl.last_name, t.name AS team_name
                     FROM {$p}tt_player_suspensions s
               INNER JOIN {$p}tt_players pl ON pl.id = s.player_id AND pl.club_id = s.club_id
                LEFT JOIN {$p}tt_teams   t  ON t.id = s.team_id  AND t.club_id = s.club_id
                    WHERE s.club_id = %d
                      AND s.archived_at IS NULL AND s.trashed_at IS NULL
                      AND ( %s = '' OR FIND_IN_SET( s.team_id, %s ) )";

        if ( $status === 'open' ) {
            $sql = $select . ' AND s.served_on IS NULL ORDER BY s.started_on DESC, s.id DESC';
        } elseif ( $status === 'served' ) {
            $sql = $select . ' AND s.served_on IS NOT NULL ORDER BY s.started_on DESC, s.id DESC';
        } else {
            $sql = $select . ' ORDER BY s.started_on DESC, s.id DESC';
        }

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, CurrentClub::id(), $ids, $ids ), ARRAY_A );
        return self::rows( $rows );
    }

    /** The reason's label in the reader's language, or '' when none was given. */
    public static function reasonLabel( int $lookup_id ): string {
        global $wpdb;
        if ( $lookup_id <= 0 ) return '';
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_lookups WHERE id = %d AND lookup_type = %s",
            $lookup_id, 'suspension_reason'
        ) );
        return $row ? LookupTranslator::name( $row ) : '';
    }

    /** Y-m-d, or '' when the input is not a real date. */
    public static function cleanDate( string $value ): string {
        $value = substr( trim( $value ), 0, 10 );
        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) !== 1 ) return '';
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : '';
    }

    private function emitStartedEvent( int $id, int $player_id, string $started, int $count, int $reason_id ): void {
        $reason = self::reasonLabel( $reason_id );

        $summary = $reason !== ''
            /* translators: 1: number of matches, 2: the reason, e.g. "Red card" */
            ? sprintf( _n( 'Suspended for %1$d match: %2$s', 'Suspended for %1$d matches: %2$s', $count, 'talenttrack' ), $count, $reason )
            /* translators: %d: number of matches */
            : sprintf( _n( 'Suspended for %d match', 'Suspended for %d matches', $count, 'talenttrack' ), $count );

        EventEmitter::emit(
            $player_id,
            JourneyEventType::SUSPENSION_STARTED,
            $started . ' 00:00:00',
            $summary,
            [
                'suspension_id' => $id,
                'match_count'   => $count,
                'reason'        => $reason,
            ],
            'Journey',
            'suspension',
            $id
        );
    }

    /** @param array<int, int> $ids */
    private static function idList( array $ids ): string {
        $clean = [];
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 ) $clean[ $id ] = $id;
        }
        return implode( ',', $clean );
    }

    /**
     * @param mixed $rows
     * @return list<array<string, mixed>>
     */
    private static function rows( $rows ): array {
        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            if ( is_array( $row ) ) $out[] = $row;
        }
        return $out;
    }
}
