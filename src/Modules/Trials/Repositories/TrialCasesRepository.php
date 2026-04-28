<?php
namespace TT\Modules\Trials\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

class TrialCasesRepository {

    public const STATUS_OPEN     = 'open';
    public const STATUS_EXTENDED = 'extended';
    public const STATUS_DECIDED  = 'decided';
    public const STATUS_ARCHIVED = 'archived';

    public const DECISION_ADMIT         = 'admit';
    public const DECISION_DENY_FINAL    = 'deny_final';
    public const DECISION_DENY_ENCOURAGE = 'deny_encouragement';

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_trial_cases';
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d", $id
        ) );
        return $row ?: null;
    }

    public function findOpenForPlayer( int $player_id ): ?object {
        if ( $player_id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE player_id = %d AND status IN ('open','extended')
             ORDER BY id DESC LIMIT 1",
            $player_id
        ) );
        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $filters
     * @return object[]
     */
    public function search( array $filters = [] ): array {
        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = (string) $filters['status'];
        }
        if ( ! empty( $filters['track_id'] ) ) {
            $where[]  = 'track_id = %d';
            $params[] = (int) $filters['track_id'];
        }
        if ( ! empty( $filters['decision'] ) ) {
            $where[]  = 'decision = %s';
            $params[] = (string) $filters['decision'];
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'end_date >= %s';
            $params[] = (string) $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'start_date <= %s';
            $params[] = (string) $filters['date_to'];
        }
        if ( empty( $filters['include_archived'] ) ) {
            $where[] = 'archived_at IS NULL';
        }

        $sql = "SELECT * FROM {$this->table}
                WHERE " . implode( ' AND ', $where ) . "
                ORDER BY start_date DESC, id DESC";

        if ( $params ) {
            /** @var string $prepared */
            $prepared = $this->wpdb->prepare( $sql, ...$params );
            $rows = $this->wpdb->get_results( $prepared );
        } else {
            $rows = $this->wpdb->get_results( $sql );
        }
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create( array $data ): int {
        $insert = [
            'player_id'   => (int) ( $data['player_id'] ?? 0 ),
            'track_id'    => (int) ( $data['track_id'] ?? 0 ),
            'start_date'  => (string) ( $data['start_date'] ?? gmdate( 'Y-m-d' ) ),
            'end_date'    => (string) ( $data['end_date'] ?? gmdate( 'Y-m-d' ) ),
            'status'      => self::STATUS_OPEN,
            'notes'       => $data['notes'] ?? null,
            'created_by'  => (int) ( $data['created_by'] ?? get_current_user_id() ),
            'uuid'        => wp_generate_uuid4(),
        ];
        $ok = $this->wpdb->insert( $this->table, $insert );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    /**
     * @param array<string,mixed> $patch
     */
    public function update( int $id, array $patch ): bool {
        if ( $id <= 0 || ! $patch ) return false;
        $allowed = [
            'track_id', 'start_date', 'end_date', 'status', 'extension_count',
            'decision', 'decision_made_at', 'decision_made_by', 'decision_notes',
            'strengths_summary', 'growth_areas',
            'inputs_released_at', 'inputs_released_by',
            'acceptance_slip_returned_at', 'acceptance_slip_returned_by',
            'notes', 'archived_at', 'archived_by',
        ];
        $clean = array_intersect_key( $patch, array_flip( $allowed ) );
        if ( ! $clean ) return false;
        return (bool) $this->wpdb->update( $this->table, $clean, [ 'id' => $id ] );
    }

    public function archive( int $id, int $user_id ): bool {
        return $this->update( $id, [
            'archived_at' => current_time( 'mysql', true ),
            'archived_by' => $user_id,
            'status'      => self::STATUS_ARCHIVED,
        ] );
    }

    public function recordDecision( int $id, string $decision, int $user_id, string $notes, ?string $strengths = null, ?string $growth = null ): bool {
        if ( ! in_array( $decision, [ self::DECISION_ADMIT, self::DECISION_DENY_FINAL, self::DECISION_DENY_ENCOURAGE ], true ) ) {
            return false;
        }
        return $this->update( $id, [
            'decision'         => $decision,
            'decision_made_at' => current_time( 'mysql', true ),
            'decision_made_by' => $user_id,
            'decision_notes'   => $notes,
            'strengths_summary' => $strengths,
            'growth_areas'     => $growth,
            'status'           => self::STATUS_DECIDED,
        ] );
    }

    public function releaseInputs( int $case_id, int $user_id ): bool {
        return $this->update( $case_id, [
            'inputs_released_at' => current_time( 'mysql', true ),
            'inputs_released_by' => $user_id,
        ] );
    }

    public function markAcceptanceReceived( int $case_id, int $user_id ): bool {
        return $this->update( $case_id, [
            'acceptance_slip_returned_at' => current_time( 'mysql', true ),
            'acceptance_slip_returned_by' => $user_id,
        ] );
    }

    /** @return object[] */
    public function listEndingBetween( string $from_date, string $to_date ): array {
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status IN ('open','extended')
               AND end_date BETWEEN %s AND %s
               AND archived_at IS NULL",
            $from_date, $to_date
        ) );
        return is_array( $rows ) ? $rows : [];
    }
}
