<?php
namespace TT\Infrastructure\Evaluations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CategoryWeightsRepository — data access for tt_category_weights.
 *
 * Sprint v2.13.0. Each row is a (age_group_id, main_category_id, weight)
 * triple where weight is an integer percentage. A fully-configured
 * age group has four rows (one per main category) summing to 100.
 *
 * Equal-fallback: when an age group has no rows (or a partial set), the
 * caller uses equalWeightsForMains() to synthesize percentages that
 * divide evenly across the active mains. The repository never stores
 * fallback rows — they materialize only at compute time. This keeps
 * "configured vs. fallback" distinguishable without sentinel values.
 */
class CategoryWeightsRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_category_weights';
    }

    // Reads

    /**
     * Weights for a specific age group, keyed by main_category_id.
     * Returns empty array when no rows are configured — callers should
     * apply equal-fallback in that case.
     *
     * @return array<int, int>  main_category_id => weight (percent)
     */
    public function getForAgeGroup( int $age_group_id ): array {
        global $wpdb;
        if ( $age_group_id <= 0 ) return [];
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT main_category_id, weight FROM {$this->table()} WHERE age_group_id = %d",
            $age_group_id
        ) );
        if ( ! is_array( $rows ) ) return [];
        $out = [];
        foreach ( $rows as $r ) {
            $out[ (int) $r->main_category_id ] = (int) $r->weight;
        }
        return $out;
    }

    /**
     * Batched fetch for many age groups at once — used by the list-column
     * render path in EvaluationsPage to avoid N+1.
     *
     * @param int[] $age_group_ids
     * @return array<int, array<int, int>>  age_group_id => [main_id => weight]
     */
    public function getForAgeGroups( array $age_group_ids ): array {
        global $wpdb;
        $out = [];
        $clean = array_values( array_unique( array_filter( array_map( 'intval', $age_group_ids ), fn( $v ) => $v > 0 ) ) );
        if ( empty( $clean ) ) return $out;

        $placeholders = implode( ',', array_fill( 0, count( $clean ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT age_group_id, main_category_id, weight FROM {$this->table()} WHERE age_group_id IN ($placeholders)",
            ...$clean
        ) );
        if ( ! is_array( $rows ) ) return $out;
        foreach ( $rows as $r ) {
            $out[ (int) $r->age_group_id ][ (int) $r->main_category_id ] = (int) $r->weight;
        }
        return $out;
    }

    // Writes

    /**
     * Replace the weights for an age group in a single transaction-like
     * operation. The caller has already validated that $weights sums to
     * 100; this method takes that on trust. Uses DELETE + INSERT pattern
     * rather than ON DUPLICATE KEY UPDATE to keep the set exactly equal
     * to what was passed in (drops stale main_category_ids implicitly).
     *
     * @param array<int, int> $weights  main_category_id => weight
     */
    public function saveForAgeGroup( int $age_group_id, array $weights ): bool {
        global $wpdb;
        if ( $age_group_id <= 0 ) return false;
        if ( empty( $weights ) ) return false;

        $wpdb->delete( $this->table(), [ 'age_group_id' => $age_group_id ], [ '%d' ] );
        foreach ( $weights as $main_id => $w ) {
            $main_id = (int) $main_id;
            $w       = (int) $w;
            if ( $main_id <= 0 || $w < 0 || $w > 100 ) continue;
            $ok = $wpdb->insert( $this->table(), [
                'age_group_id'     => $age_group_id,
                'main_category_id' => $main_id,
                'weight'           => $w,
            ], [ '%d', '%d', '%d' ] );
            if ( $ok === false ) return false;
        }
        return true;
    }

    public function deleteForAgeGroup( int $age_group_id ): bool {
        global $wpdb;
        if ( $age_group_id <= 0 ) return false;
        return $wpdb->delete( $this->table(), [ 'age_group_id' => $age_group_id ], [ '%d' ] ) !== false;
    }

    // Fallback

    /**
     * Equal weights across the given main category IDs, summing to 100
     * as closely as integer math permits. 4 mains → (25, 25, 25, 25).
     * 3 mains → (34, 33, 33). 5 mains → (20, 20, 20, 20, 20). The
     * remainder goes on the first main so the sum always equals exactly
     * 100.
     *
     * @param int[] $main_ids
     * @return array<int, int>  main_id => weight
     */
    public static function equalWeightsForMains( array $main_ids ): array {
        $clean = array_values( array_unique( array_filter( array_map( 'intval', $main_ids ), fn( $v ) => $v > 0 ) ) );
        $count = count( $clean );
        if ( $count === 0 ) return [];

        $base      = intdiv( 100, $count );
        $remainder = 100 - ( $base * $count );

        $out = [];
        foreach ( $clean as $i => $mid ) {
            $out[ $mid ] = $base + ( $i === 0 ? $remainder : 0 );
        }
        return $out;
    }

    /**
     * Validation helper for admin save. Returns null on success, or a
     * human-readable error string naming the current total.
     *
     * @param array<int, int> $weights
     */
    public static function validateSumsTo100( array $weights ): ?int {
        $sum = 0;
        foreach ( $weights as $w ) {
            $sum += (int) $w;
        }
        return $sum === 100 ? null : $sum;
    }
}
