<?php
namespace TT\Modules\StaffDevelopment\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

class StaffEvaluationsRepository {

    public const KIND_SELF     = 'self';
    public const KIND_TOP_DOWN = 'top_down';

    private \wpdb $wpdb;
    private string $table;
    private string $ratings_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb          = $wpdb;
        $this->table         = $wpdb->prefix . 'tt_staff_evaluations';
        $this->ratings_table = $wpdb->prefix . 'tt_staff_eval_ratings';
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        return $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) ) ?: null;
    }

    /** @return object[] */
    public function listForPerson( int $person_id ): array {
        if ( $person_id <= 0 ) return [];
        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE person_id = %d AND club_id = %d AND archived_at IS NULL
              ORDER BY eval_date DESC, id DESC",
            $person_id, CurrentClub::id()
        ) ) ?: [];
    }

    /** @return object[] */
    public function listRatings( int $evaluation_id ): array {
        if ( $evaluation_id <= 0 ) return [];
        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->ratings_table} WHERE evaluation_id = %d ORDER BY id ASC",
            $evaluation_id
        ) ) ?: [];
    }

    public function create( array $data, array $ratings = [] ): int {
        $row = [
            'club_id'          => CurrentClub::id(),
            'person_id'        => (int) ( $data['person_id'] ?? 0 ),
            'reviewer_user_id' => (int) ( $data['reviewer_user_id'] ?? get_current_user_id() ),
            'review_kind'      => (string) ( $data['review_kind'] ?? self::KIND_SELF ),
            'season_id'        => isset( $data['season_id'] ) ? (int) $data['season_id'] : null,
            'eval_date'        => (string) ( $data['eval_date'] ?? gmdate( 'Y-m-d' ) ),
            'notes'            => (string) ( $data['notes'] ?? '' ),
        ];
        $ok = $this->wpdb->insert( $this->table, $row );
        if ( ! $ok ) return 0;
        $eval_id = (int) $this->wpdb->insert_id;

        foreach ( $ratings as $r ) {
            $cat = (int) ( $r['category_id'] ?? 0 );
            if ( $cat <= 0 ) continue;
            $this->wpdb->insert( $this->ratings_table, [
                'evaluation_id' => $eval_id,
                'category_id'   => $cat,
                'rating'        => (float) ( $r['rating'] ?? 0 ),
                'comment'       => (string) ( $r['comment'] ?? '' ),
            ] );
        }
        return $eval_id;
    }

    public function update( int $id, array $data ): bool {
        if ( $id <= 0 ) return false;
        $allowed = [ 'review_kind', 'season_id', 'eval_date', 'notes', 'archived_at', 'archived_by' ];
        $row = array_intersect_key( $data, array_flip( $allowed ) );
        if ( ! $row ) return false;
        return (bool) $this->wpdb->update(
            $this->table,
            $row,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    public function archive( int $id, int $archived_by ): bool {
        return $this->update( $id, [
            'archived_at' => current_time( 'mysql' ),
            'archived_by' => $archived_by,
        ] );
    }
}
