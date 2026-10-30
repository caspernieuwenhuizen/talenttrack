<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * InjuryRepository — CRUD on tt_player_injuries.
 *
 * Side-effects: every successful create + actual_return-set fires a
 * journey event via EventEmitter. Idempotency is enforced by uk_natural
 * on tt_player_events, so re-saving an injury never multiplies events.
 */
final class InjuryRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_player_injuries';
    }

    /**
     * @param array{
     *   player_id:int,
     *   started_on:string,
     *   expected_return?:?string,
     *   actual_return?:?string,
     *   injury_type_lookup_id?:?int,
     *   body_part_lookup_id?:?int,
     *   severity_lookup_id?:?int,
     *   notes?:string,
     * } $data
     */
    public function create( array $data ): int {
        $player_id = (int) ( $data['player_id'] ?? 0 );
        $started   = (string) ( $data['started_on'] ?? '' );
        if ( $player_id <= 0 || $started === '' ) return 0;

        $row = [
            'club_id'                => CurrentClub::id(),
            'player_id'              => $player_id,
            'started_on'             => $started,
            'expected_return'        => isset( $data['expected_return'] ) && $data['expected_return'] !== '' ? (string) $data['expected_return'] : null,
            'actual_return'          => isset( $data['actual_return'] ) && $data['actual_return'] !== '' ? (string) $data['actual_return'] : null,
            'injury_type_lookup_id'  => isset( $data['injury_type_lookup_id'] ) ? (int) $data['injury_type_lookup_id'] : null,
            'body_part_lookup_id'    => isset( $data['body_part_lookup_id'] ) ? (int) $data['body_part_lookup_id'] : null,
            'severity_lookup_id'     => isset( $data['severity_lookup_id'] ) ? (int) $data['severity_lookup_id'] : null,
            'notes'                  => isset( $data['notes'] ) ? (string) $data['notes'] : null,
            'is_recovery_logged'     => ! empty( $data['actual_return'] ) ? 1 : 0,
            'created_by'             => get_current_user_id(),
        ];

        $ok = $this->wpdb->insert( $this->table, $row );
        if ( $ok === false ) return 0;

        $injury_id = (int) $this->wpdb->insert_id;
        $this->emitStartedEvent( $injury_id, $row );
        if ( ! empty( $row['actual_return'] ) ) {
            $this->emitEndedEvent( $injury_id, $row );
        }

        // Workflow hook: notify the head coach via the workflow engine.
        // The trigger row seeded by migration 0037 wires this to the
        // injury_recovery_due template. A future SaaS scheduler can
        // replace the engine without disturbing this hook.
        $team_id = $this->teamIdOf( (int) $row['player_id'] );
        do_action( 'tt_journey_injury_logged', [
            'player_id' => (int) $row['player_id'],
            'team_id'   => $team_id > 0 ? $team_id : null,
            'extras'    => [
                'injury_id'        => $injury_id,
                'expected_return'  => $row['expected_return'] ?? null,
                'started_on'       => $row['started_on'],
            ],
        ] );

        return $injury_id;
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function update( int $id, array $patch ): bool {
        if ( $id <= 0 ) return false;

        $existing = $this->find( $id );
        if ( ! $existing ) return false;

        $allowed = [ 'expected_return', 'actual_return', 'injury_type_lookup_id', 'body_part_lookup_id', 'severity_lookup_id', 'notes', 'archived_at' ];
        $update  = [];
        foreach ( $allowed as $col ) {
            if ( array_key_exists( $col, $patch ) ) {
                $update[ $col ] = $patch[ $col ];
            }
        }
        if ( ! $update ) return false;

        if ( array_key_exists( 'actual_return', $update ) && $update['actual_return'] !== null && $update['actual_return'] !== '' ) {
            $update['is_recovery_logged'] = 1;
        }

        $ok = $this->wpdb->update( $this->table, $update, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        if ( $ok === false ) return false;

        if (
            isset( $update['actual_return'] ) && $update['actual_return'] !== '' && $update['actual_return'] !== null
            && empty( $existing->actual_return )
        ) {
            $row = (array) $this->find( $id );
            $this->emitEndedEvent( $id, $row );
        }

        // The edit's counterpart of `tt_journey_injury_logged`, so a
        // listener that follows an injury (the recovery reminder) hears
        // about a changed expected return the same way it heard about
        // the record being created.
        $player_id = (int) ( $existing->player_id ?? 0 );
        $team_id   = $this->teamIdOf( $player_id );
        do_action( 'tt_journey_injury_updated', [
            'player_id' => $player_id,
            'team_id'   => $team_id > 0 ? $team_id : null,
            'extras'    => [
                'injury_id' => $id,
                'fields'    => array_keys( $update ),
            ],
        ] );

        return true;
    }

    /** The player's team, for the workflow hooks' payload. */
    private function teamIdOf( int $player_id ): int {
        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT team_id FROM {$this->wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $player_id, CurrentClub::id()
        ) );
    }

    /**
     * The one date rule an injury has: nobody is expected back before
     * the day they got hurt. Both ways in ask it here, the wizard and the
     * edit through REST, so the two cannot disagree.
     */
    public static function expectedReturnBeforeStart( string $started_on, ?string $expected_return ): bool {
        if ( $expected_return === null || $expected_return === '' || $started_on === '' ) return false;
        $start  = strtotime( $started_on );
        $expect = strtotime( $expected_return );
        if ( $start === false || $expect === false ) return false;
        return $expect < $start;
    }

    public function archive( int $id, int $user_id ): bool {
        if ( $id <= 0 ) return false;
        $ok = $this->wpdb->update( $this->table, [
            'archived_at' => current_time( 'mysql' ),
        ], [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        return $ok !== false;
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * @return list<object>
     */
    public function listForPlayer( int $player_id, bool $include_archived = false ): array {
        if ( $player_id <= 0 ) return [];
        $where = $include_archived ? '' : 'AND archived_at IS NULL';
        /** @var list<object> $rows */
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE player_id = %d AND club_id = %d {$where}
              ORDER BY started_on DESC, id DESC",
            $player_id, CurrentClub::id()
        ) );
        return $rows ?: [];
    }

    /**
     * The subset of `$player_ids` with an injury that covers `$day` (Y-m-d),
     * as a set keyed by player id: one query for a whole roster. The rule
     * is `InjuryStatus::coversDaySql()`; nothing about the injury but the
     * player id leaves this method.
     *
     * @param list<int> $player_ids
     * @return array<int, true>
     */
    public function coveredSet( array $player_ids, string $day ): array {
        return $this->playerSet( $player_ids, InjuryStatus::coversDaySql(), InjuryStatus::coversDayParams( $day ) );
    }

    /**
     * The subset of `$player_ids` with a record planning should check on
     * `$day` (overdue or incomplete, `InjuryStatus::needsCheckSql()`), as a
     * set keyed by player id. One query; only player ids leave it.
     *
     * @param list<int> $player_ids
     * @return array<int, true>
     */
    public function needsCheckSet( array $player_ids, string $day ): array {
        return $this->playerSet( $player_ids, InjuryStatus::needsCheckSql(), InjuryStatus::needsCheckParams( $day ) );
    }

    /**
     * Every player in the current club with a record whose decision is due
     * on or before `$day` (`InjuryStatus::checkDueSql()`): what the recovery
     * reminder sweep works through. One club-scoped query.
     *
     * @return list<int>
     */
    public function playersWithCheckDue( string $day ): array {
        return array_keys( $this->playerSet( null, InjuryStatus::checkDueSql(), InjuryStatus::checkDueParams( $day ) ) );
    }

    /**
     * The player ids, among `$player_ids` (every player in the club when
     * null), with a record matching `$predicate`.
     *
     * @param list<int>|null $player_ids
     * @param list<string>   $params     Values for the predicate's placeholders.
     * @return array<int, true>
     */
    private function playerSet( ?array $player_ids, string $predicate, array $params ): array {
        $ids = [];
        foreach ( $player_ids ?? [] as $pid ) {
            $pid = (int) $pid;
            if ( $pid > 0 ) $ids[ $pid ] = true;
        }
        if ( $player_ids !== null && $ids === [] ) return [];

        $ids   = array_keys( $ids );
        $where = $player_ids === null
            ? ''
            : 'player_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only; the predicate is a fixed string.
        $rows = $this->wpdb->get_col( $this->wpdb->prepare(
            "SELECT DISTINCT player_id
               FROM {$this->table}
              WHERE {$where} club_id = %d
                AND {$predicate}",
            array_merge( $ids, [ (int) CurrentClub::id() ], $params )
        ) );

        $out = [];
        foreach ( (array) $rows as $pid ) {
            $pid = (int) $pid;
            if ( $pid > 0 ) $out[ $pid ] = true;
        }
        return $out;
    }

    /**
     * #2609 — injuries across a set of teams, for the squad overview.
     *
     * Answers "who is out right now" rather than "what happened to this
     * player", so it joins the player and team through and defaults to
     * open injuries only. An injury is **open** when no actual return has
     * been recorded — that is the single fact the overview is built on,
     * and the reason setting a return date matters.
     *
     * @param list<int>            $team_ids  Empty means every team (academy-wide readers).
     * @param array<string, mixed> $filters   status: open|recovered|all, severity_id, from, to,
     *                                        limit and offset (a page; all rows when no limit)
     * @return list<object>
     */
    public function listForTeams( array $team_ids, array $filters = [] ): array {
        $p = $this->wpdb->prefix;
        [ $where, $args ] = $this->teamsWhere( $team_ids, $filters );

        $page = '';
        $limit = isset( $filters['limit'] ) ? (int) $filters['limit'] : 0;
        if ( $limit > 0 ) {
            $page   = ' LIMIT %d OFFSET %d';
            $args[] = $limit;
            $args[] = max( 0, (int) ( $filters['offset'] ?? 0 ) );
        }

        $sql = "SELECT i.*,
                       pl.first_name, pl.last_name, pl.team_id,
                       t.name AS team_name
                  FROM {$this->table} i
            INNER JOIN {$p}tt_players pl ON pl.id = i.player_id AND pl.club_id = i.club_id
             LEFT JOIN {$p}tt_teams   t  ON t.id = pl.team_id  AND t.club_id = i.club_id
                 WHERE " . implode( ' AND ', $where ) . "
              ORDER BY i.started_on DESC, i.id DESC" . $page;

        /** @var list<object> $rows */
        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$args ) );
        return $rows ?: [];
    }

    /**
     * How many rows `listForTeams()` would return without a page, for a
     * paginated reader.
     *
     * @param list<int>            $team_ids
     * @param array<string, mixed> $filters
     */
    public function countForTeams( array $team_ids, array $filters = [] ): int {
        $p = $this->wpdb->prefix;
        [ $where, $args ] = $this->teamsWhere( $team_ids, $filters );

        $sql = "SELECT COUNT(*)
                  FROM {$this->table} i
            INNER JOIN {$p}tt_players pl ON pl.id = i.player_id AND pl.club_id = i.club_id
                 WHERE " . implode( ' AND ', $where );

        return (int) $this->wpdb->get_var( $this->wpdb->prepare( $sql, ...$args ) );
    }

    /**
     * The overview's WHERE, shared by the list and its count.
     *
     * @param list<int>            $team_ids
     * @param array<string, mixed> $filters
     * @return array{0: list<string>, 1: list<int|string>}
     */
    private function teamsWhere( array $team_ids, array $filters ): array {
        $status = (string) ( $filters['status'] ?? 'open' );

        $where = [ 'i.club_id = %d', 'i.archived_at IS NULL' ];
        $args  = [ CurrentClub::id() ];

        if ( ! empty( $team_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            $where[] = "pl.team_id IN ({$placeholders})";
            foreach ( $team_ids as $tid ) $args[] = (int) $tid;
        }

        if ( $status === 'open' ) {
            $where[] = InjuryStatus::openSql( 'i' );
        } elseif ( $status === 'recovered' ) {
            $where[] = 'NOT ( ' . InjuryStatus::openSql( 'i' ) . ' )';
        }

        if ( ! empty( $filters['severity_id'] ) ) {
            $where[] = 'i.severity_lookup_id = %d';
            $args[]  = (int) $filters['severity_id'];
        }
        if ( ! empty( $filters['from'] ) ) {
            $where[] = 'i.started_on >= %s';
            $args[]  = (string) $filters['from'];
        }
        if ( ! empty( $filters['to'] ) ) {
            $where[] = 'i.started_on <= %s';
            $args[]  = (string) $filters['to'];
        }

        return [ $where, $args ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function emitStartedEvent( int $injury_id, array $row ): void {
        $body_part = $this->lookupName( (int) ( $row['body_part_lookup_id'] ?? 0 ), 'body_part' );
        $severity  = $this->lookupName( (int) ( $row['severity_lookup_id'] ?? 0 ), 'injury_severity' );
        $expected_weeks = 0;
        if ( ! empty( $row['expected_return'] ) ) {
            $delta_days = max( 0, (int) ( ( strtotime( (string) $row['expected_return'] ) - strtotime( (string) $row['started_on'] ) ) / 86400 ) );
            $expected_weeks = (int) ceil( $delta_days / 7 );
        }

        EventEmitter::emit(
            (int) $row['player_id'],
            JourneyEventType::INJURY_STARTED,
            (string) $row['started_on'] . ' 00:00:00',
            /* translators: %s: body part */
            $body_part !== '' ? sprintf( __( 'Injury: %s', 'talenttrack' ), $body_part ) : __( 'Injury started', 'talenttrack' ),
            [
                'injury_id'          => $injury_id,
                'expected_weeks_out' => $expected_weeks,
                'severity_key'       => $severity,
                'body_part'          => $body_part,
            ],
            'Journey',
            'injury',
            $injury_id
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function emitEndedEvent( int $injury_id, array $row ): void {
        $started_at = strtotime( (string) ( $row['started_on'] ?? '' ) );
        $ended_at   = strtotime( (string) ( $row['actual_return'] ?? '' ) );
        $expected   = ! empty( $row['expected_return'] ) ? strtotime( (string) $row['expected_return'] ) : 0;

        $days_out      = $started_at && $ended_at ? max( 0, (int) ( ( $ended_at - $started_at ) / 86400 ) ) : 0;
        $expected_days = $started_at && $expected ? max( 0, (int) ( ( $expected - $started_at ) / 86400 ) ) : 0;

        EventEmitter::emit(
            (int) $row['player_id'],
            JourneyEventType::INJURY_ENDED,
            (string) $row['actual_return'] . ' 00:00:00',
            __( 'Injury recovered', 'talenttrack' ),
            [
                'injury_id'     => $injury_id,
                'days_out'      => $days_out,
                'expected_days' => $expected_days,
            ],
            'Journey',
            'injury_recovery',
            $injury_id
        );
    }

    private function lookupName( int $id, string $type ): string {
        if ( $id <= 0 ) return '';
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT name FROM {$this->wpdb->prefix}tt_lookups WHERE id = %d AND lookup_type = %s",
            $id, $type
        ) );
        return $row ? (string) $row->name : '';
    }
}
