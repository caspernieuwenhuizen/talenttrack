<?php
namespace TT\Modules\StaffDevelopment\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

class StaffPdpRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_staff_pdp';
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        return $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) ) ?: null;
    }

    public function findForPersonSeason( int $person_id, ?int $season_id ): ?object {
        if ( $person_id <= 0 ) return null;
        if ( $season_id === null ) {
            $row = $this->wpdb->get_row( $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                  WHERE person_id = %d AND season_id IS NULL AND club_id = %d",
                $person_id, CurrentClub::id()
            ) );
        } else {
            $row = $this->wpdb->get_row( $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                  WHERE person_id = %d AND season_id = %d AND club_id = %d",
                $person_id, $season_id, CurrentClub::id()
            ) );
        }
        return $row ?: null;
    }

    public function upsert( int $person_id, ?int $season_id, array $data, int $reviewer_user_id ): int {
        if ( $person_id <= 0 ) return 0;
        $existing = $this->findForPersonSeason( $person_id, $season_id );

        $allowed = [ 'strengths', 'development_areas', 'actions_next_quarter', 'narrative' ];
        $row     = array_intersect_key( $data, array_flip( $allowed ) );
        $row['last_reviewed_at'] = current_time( 'mysql' );
        $row['last_reviewed_by'] = $reviewer_user_id;

        if ( $existing ) {
            $this->wpdb->update(
                $this->table,
                $row,
                [ 'id' => (int) $existing->id, 'club_id' => CurrentClub::id() ]
            );
            return (int) $existing->id;
        }

        $row['club_id']   = CurrentClub::id();
        $row['uuid']      = wp_generate_uuid4();
        $row['person_id'] = $person_id;
        $row['season_id'] = $season_id;
        $ok = $this->wpdb->insert( $this->table, $row );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }
}
