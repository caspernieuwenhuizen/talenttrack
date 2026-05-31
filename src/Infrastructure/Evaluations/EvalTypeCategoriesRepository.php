<?php
namespace TT\Infrastructure\Evaluations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * EvalTypeCategoriesRepository (#819) — join table for the
 * per-eval-type category filter.
 *
 * Empty mapping for a type = "all active categories" (back-compat
 * with pre-#819 behaviour).
 */
class EvalTypeCategoriesRepository {

    /**
     * Category IDs configured for a given eval_type. Empty array =
     * fall back to "all active categories".
     *
     * @return int[]
     */
    public function categoryIdsFor( int $eval_type_id ): array {
        if ( $eval_type_id <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT eval_category_id FROM {$p}tt_eval_type_categories
              WHERE eval_type_id = %d AND club_id = %d
              ORDER BY eval_category_id ASC",
            $eval_type_id, CurrentClub::id()
        ) );
        return array_map( 'intval', (array) $rows );
    }

    /**
     * Replace the mapping for a single eval_type. Passing an empty
     * array clears the mapping (= "all" fallback).
     *
     * @param int[] $category_ids
     */
    public function setCategoriesFor( int $eval_type_id, array $category_ids ): bool {
        if ( $eval_type_id <= 0 ) return false;
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        $wpdb->delete( "{$p}tt_eval_type_categories", [
            'eval_type_id' => $eval_type_id,
            'club_id'      => $club_id,
        ] );

        foreach ( array_unique( array_map( 'intval', $category_ids ) ) as $cid ) {
            if ( $cid <= 0 ) continue;
            $wpdb->insert( "{$p}tt_eval_type_categories", [
                'club_id'          => $club_id,
                'eval_type_id'     => $eval_type_id,
                'eval_category_id' => $cid,
            ] );
        }
        return true;
    }

    /**
     * Bulk lookup — `eval_type_id => [category_id, …]` map. Used by
     * the admin matrix to render the configured state in one shot.
     *
     * @return array<int, int[]>
     */
    public function allMappings(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT eval_type_id, eval_category_id
               FROM {$p}tt_eval_type_categories
              WHERE club_id = %d",
            CurrentClub::id()
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->eval_type_id ][] = (int) $r->eval_category_id;
        }
        return $out;
    }
}
