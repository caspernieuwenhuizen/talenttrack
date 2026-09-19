<?php
namespace TT\Modules\Trials\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

class TrialStaffInputsRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_trial_case_staff_inputs';
    }

    public function findForCaseUser( int $case_id, int $user_id ): ?object {
        if ( $case_id <= 0 || $user_id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE case_id = %d AND user_id = %d AND club_id = %d LIMIT 1",
            $case_id, $user_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * @return object[]
     */
    public function listForCase( int $case_id, bool $submitted_only = false ): array {
        if ( $case_id <= 0 ) return [];
        $sql = "SELECT * FROM {$this->table} WHERE case_id = %d AND club_id = %d";
        if ( $submitted_only ) {
            $sql .= " AND submitted_at IS NOT NULL";
        }
        $sql .= " ORDER BY submitted_at ASC, created_at ASC";
        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $case_id, CurrentClub::id() ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Save a draft input. Only the keys present in `$data` are written
     * (#3606, #3612): a rating-only save used to erase the notes, a bare
     * submit erased both, and every save nulled the category ratings, which
     * neither the form nor the API ever sends. A key present with null
     * clears that column; `updated_at` is always stamped.
     *
     * @param array<string,mixed> $data
     */
    public function upsertDraft( int $case_id, int $user_id, array $data ): int {
        $existing = $this->findForCaseUser( $case_id, $user_id );

        $payload = [ 'updated_at' => current_time( 'mysql', true ) ];
        if ( array_key_exists( 'category_ratings', $data ) ) {
            $payload['category_ratings_json'] = $data['category_ratings'] === null ? null : wp_json_encode( $data['category_ratings'] );
        }
        if ( array_key_exists( 'overall_rating', $data ) ) {
            $payload['overall_rating'] = $data['overall_rating'] === null ? null : (float) $data['overall_rating'];
        }
        if ( array_key_exists( 'free_text_notes', $data ) ) {
            $payload['free_text_notes'] = $data['free_text_notes'];
        }

        if ( $existing ) {
            $this->wpdb->update( $this->table, $payload, [ 'id' => (int) $existing->id, 'club_id' => CurrentClub::id() ] );
            return (int) $existing->id;
        }

        $payload['club_id'] = CurrentClub::id();
        $payload['case_id'] = $case_id;
        $payload['user_id'] = $user_id;
        $ok = $this->wpdb->insert( $this->table, $payload );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    public function submit( int $case_id, int $user_id ): bool {
        $existing = $this->findForCaseUser( $case_id, $user_id );
        if ( ! $existing ) return false;
        return (bool) $this->wpdb->update( $this->table,
            [ 'submitted_at' => current_time( 'mysql', true ) ],
            [ 'id' => (int) $existing->id, 'club_id' => CurrentClub::id() ]
        );
    }

    public function release( int $case_id, int $user_id ): int {
        return (int) $this->wpdb->query( $this->wpdb->prepare(
            "UPDATE {$this->table}
                SET released_at = %s, released_by = %d
              WHERE case_id = %d AND club_id = %d AND submitted_at IS NOT NULL AND released_at IS NULL",
            current_time( 'mysql', true ), $user_id, $case_id, CurrentClub::id()
        ) );
    }

    /**
     * Apply visibility rules: HoD sees everything, assigned staff see
     * only their own input + released-others-after-release.
     *
     * @return object[]
     */
    public function listVisibleForUser( int $case_id, int $viewer_id, bool $is_manager ): array {
        $all = $this->listForCase( $case_id );
        if ( $is_manager ) return $all;

        $visible = [];
        foreach ( $all as $row ) {
            if ( (int) $row->user_id === $viewer_id ) { $visible[] = $row; continue; }
            if ( $row->released_at && $row->submitted_at ) { $visible[] = $row; }
        }
        return $visible;
    }
}
