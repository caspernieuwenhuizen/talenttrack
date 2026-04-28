<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

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
            'club_id'                => 1,
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
        $team_id = (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT team_id FROM {$this->wpdb->prefix}tt_players WHERE id = %d",
            (int) $row['player_id']
        ) );
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

        $ok = $this->wpdb->update( $this->table, $update, [ 'id' => $id ] );
        if ( $ok === false ) return false;

        if (
            isset( $update['actual_return'] ) && $update['actual_return'] !== '' && $update['actual_return'] !== null
            && empty( $existing->actual_return )
        ) {
            $row = (array) $this->find( $id );
            $this->emitEndedEvent( $id, $row );
        }
        return true;
    }

    public function archive( int $id, int $user_id ): bool {
        if ( $id <= 0 ) return false;
        $ok = $this->wpdb->update( $this->table, [
            'archived_at' => current_time( 'mysql' ),
        ], [ 'id' => $id ] );
        return $ok !== false;
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
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
              WHERE player_id = %d {$where}
              ORDER BY started_on DESC, id DESC",
            $player_id
        ) );
        return $rows ?: [];
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
            'injury_started',
            (string) $row['started_on'] . ' 00:00:00',
            $body_part !== '' ? sprintf( 'Injury: %s', $body_part ) : 'Injury started',
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
            'injury_ended',
            (string) $row['actual_return'] . ' 00:00:00',
            'Injury recovered',
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
