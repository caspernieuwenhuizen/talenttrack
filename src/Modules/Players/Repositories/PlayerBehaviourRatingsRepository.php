<?php
namespace TT\Modules\Players\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerBehaviourRatingsRepository (#0057 Sprint 1) — `tt_player_behaviour_ratings`.
 *
 * Continuous capture of behaviour scores between evaluation cycles.
 * Feeds the status calculator's behaviour input. Append-only — there's
 * no update path; if a rating was wrong, log a corrective one.
 */
final class PlayerBehaviourRatingsRepository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'tt_player_behaviour_ratings';
    }

    /**
     * @param array{player_id:int,rated_at?:string,rated_by?:int,rating:float,context?:string,notes?:string,related_activity_id?:int} $data
     */
    public function create( array $data ): int {
        global $wpdb;
        $row = [
            'club_id'             => CurrentClub::id(),
            'player_id'           => (int) $data['player_id'],
            'rated_at'            => (string) ( $data['rated_at'] ?? current_time( 'mysql' ) ),
            'rated_by'            => (int) ( $data['rated_by'] ?? get_current_user_id() ),
            'rating'              => (float) $data['rating'],
            'context'             => isset( $data['context'] ) ? (string) $data['context'] : null,
            'notes'               => isset( $data['notes'] ) ? (string) $data['notes'] : null,
            'related_activity_id' => isset( $data['related_activity_id'] ) ? (int) $data['related_activity_id'] : null,
        ];
        $wpdb->insert( $this->table, $row );
        return (int) $wpdb->insert_id;
    }

    /**
     * Most recent ratings for a player, newest first.
     *
     * @return list<object>
     */
    public function listForPlayer( int $player_id, int $limit = 50 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE player_id = %d AND club_id = %d
              ORDER BY rated_at DESC, id DESC
              LIMIT %d",
            $player_id, CurrentClub::id(), $limit
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Average rating in a date window. Returns null when no rows.
     */
    public function averageInWindow( int $player_id, string $from, string $to ): ?float {
        return $this->averagesInWindow( [ $player_id ], $from, $to )[ $player_id ] ?? null;
    }

    /**
     * #4945 — `averageInWindow()` for many players in one statement. A player
     * with no rating in the window is absent.
     *
     * @param list<int> $player_ids
     * @return array<int, float>
     */
    public function averagesInWindow( array $player_ids, string $from, string $to ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ), static fn( int $id ): bool => $id > 0 ) ) );
        if ( $ids === [] ) return [];

        global $wpdb;
        $in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT player_id, AVG(rating) AS avg_rating FROM {$this->table}
              WHERE player_id IN ({$in})
                AND club_id = %d
                AND rated_at >= %s
                AND rated_at <= %s
              GROUP BY player_id",
            ...array_merge( $ids, [ CurrentClub::id(), $from, $to ] )
        ) );
        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $r ) {
            if ( is_object( $r ) && ( $r->avg_rating ?? null ) !== null ) $out[ (int) ( $r->player_id ?? 0 ) ] = (float) $r->avg_rating;
        }
        return $out;
    }
}
