<?php
namespace TT\Modules\StaffDevelopment\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

class StaffMentorshipsRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_staff_mentorships';
    }

    /** @return object[] */
    public function listForMentor( int $mentor_person_id, bool $active_only = true ): array {
        if ( $mentor_person_id <= 0 ) return [];
        $sql = "SELECT * FROM {$this->table} WHERE mentor_person_id = %d AND club_id = %d";
        if ( $active_only ) $sql .= " AND ended_on IS NULL";
        $sql .= " ORDER BY started_on DESC";
        return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $mentor_person_id, CurrentClub::id() ) ) ?: [];
    }

    /** @return object[] */
    public function listForMentee( int $mentee_person_id, bool $active_only = true ): array {
        if ( $mentee_person_id <= 0 ) return [];
        $sql = "SELECT * FROM {$this->table} WHERE mentee_person_id = %d AND club_id = %d";
        if ( $active_only ) $sql .= " AND ended_on IS NULL";
        $sql .= " ORDER BY started_on DESC";
        return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $mentee_person_id, CurrentClub::id() ) ) ?: [];
    }

    public function create( int $mentor_person_id, int $mentee_person_id, ?string $started_on = null ): int {
        if ( $mentor_person_id <= 0 || $mentee_person_id <= 0 || $mentor_person_id === $mentee_person_id ) return 0;
        $ok = $this->wpdb->insert( $this->table, [
            'club_id'          => CurrentClub::id(),
            'mentor_person_id' => $mentor_person_id,
            'mentee_person_id' => $mentee_person_id,
            'started_on'       => $started_on ?: gmdate( 'Y-m-d' ),
            'created_by'       => get_current_user_id(),
        ] );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    public function end( int $id, ?string $ended_on = null ): bool {
        if ( $id <= 0 ) return false;
        return (bool) $this->wpdb->update(
            $this->table,
            [ 'ended_on' => $ended_on ?: gmdate( 'Y-m-d' ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    public function delete( int $id ): bool {
        if ( $id <= 0 ) return false;
        return (bool) $this->wpdb->delete(
            $this->table,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    public function isMentorOf( int $mentor_person_id, int $mentee_person_id ): bool {
        if ( $mentor_person_id <= 0 || $mentee_person_id <= 0 ) return false;
        $row = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT 1 FROM {$this->table}
              WHERE mentor_person_id = %d AND mentee_person_id = %d
                AND ended_on IS NULL AND club_id = %d
              LIMIT 1",
            $mentor_person_id, $mentee_person_id, CurrentClub::id()
        ) );
        return $row !== null;
    }
}
