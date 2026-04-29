<?php
namespace TT\Modules\Trials\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

class TrialCaseStaffRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_trial_case_staff';
    }

    /**
     * @return object[]
     */
    public function listForCase( int $case_id, bool $active_only = true ): array {
        if ( $case_id <= 0 ) return [];
        $sql = "SELECT * FROM {$this->table} WHERE case_id = %d AND club_id = %d";
        if ( $active_only ) {
            $sql .= " AND unassigned_at IS NULL";
        }
        $sql .= " ORDER BY assigned_at ASC";
        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $case_id, CurrentClub::id() ) );
        return is_array( $rows ) ? $rows : [];
    }

    public function isAssigned( int $case_id, int $user_id ): bool {
        $row = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE case_id = %d AND user_id = %d AND club_id = %d AND unassigned_at IS NULL
             LIMIT 1",
            $case_id, $user_id, CurrentClub::id()
        ) );
        return ! empty( $row );
    }

    public function assign( int $case_id, int $user_id, ?string $role_label = null ): int {
        if ( $case_id <= 0 || $user_id <= 0 ) return 0;
        $existing = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE case_id = %d AND user_id = %d AND club_id = %d LIMIT 1",
            $case_id, $user_id, CurrentClub::id()
        ) );
        if ( $existing ) {
            $this->wpdb->update( $this->table, [
                'unassigned_at' => null,
                'role_label'    => $role_label,
            ], [ 'id' => (int) $existing->id, 'club_id' => CurrentClub::id() ] );
            return (int) $existing->id;
        }
        $ok = $this->wpdb->insert( $this->table, [
            'club_id'    => CurrentClub::id(),
            'case_id'    => $case_id,
            'user_id'    => $user_id,
            'role_label' => $role_label,
        ] );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    public function unassign( int $case_id, int $user_id ): bool {
        return (bool) $this->wpdb->update( $this->table,
            [ 'unassigned_at' => current_time( 'mysql', true ) ],
            [ 'case_id' => $case_id, 'user_id' => $user_id, 'club_id' => CurrentClub::id() ]
        );
    }
}
