<?php
namespace TT\Infrastructure\Evaluations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * A short note per evaluation category (#3949).
 *
 * One row per (evaluation, category), where the category is a main category
 * or a subcategory, rated or not. Notes carry no visibility rule of their
 * own: whoever may read the evaluation reads its notes, so callers gate the
 * evaluation and this repository does not look at the viewer.
 *
 * Writes are partial by category, like the ratings: a category in the
 * submitted map is upserted, a blank clears it, and a category left out is
 * not touched.
 */
class EvalCategoryNotesRepository {

    /** Characters a note may hold. The long write-up belongs in the evaluation's notes. */
    public const MAX_LENGTH = 500;

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_eval_category_notes';
    }

    /**
     * The notes on one evaluation.
     *
     * @return array<int, string> Category id to note.
     */
    public function forEvaluation( int $evaluation_id ): array {
        $all = $this->forEvaluations( [ $evaluation_id ] );
        return $all[ $evaluation_id ] ?? [];
    }

    /**
     * The notes on many evaluations, in one query.
     *
     * @param int[] $evaluation_ids
     * @return array<int, array<int, string>> Evaluation id to (category id to note).
     */
    public function forEvaluations( array $evaluation_ids ): array {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $evaluation_ids ), static fn( int $v ): bool => $v > 0 ) ) );
        if ( empty( $ids ) ) return [];

        $ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT evaluation_id, category_id, note FROM {$this->table()}
              WHERE evaluation_id IN ({$ph}) AND club_id = %d
              ORDER BY evaluation_id ASC, category_id ASC",
            ...array_merge( $ids, [ CurrentClub::id() ] )
        ), ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $note = (string) ( $row['note'] ?? '' );
            if ( $note === '' ) continue;
            $out[ (int) ( $row['evaluation_id'] ?? 0 ) ][ (int) ( $row['category_id'] ?? 0 ) ] = $note;
        }
        return $out;
    }

    /**
     * The notes on many evaluations folded onto their main category: the
     * main category's own note first, then each subcategory's note as
     * "Sub name: note", one per line. For exports, which carry one column
     * per main category.
     *
     * @param int[] $evaluation_ids
     * @return array<int, array<int, string>> Evaluation id to (main category id to text).
     */
    public function perMainForEvaluations( array $evaluation_ids ): array {
        global $wpdb;
        $by_eval = $this->forEvaluations( $evaluation_ids );
        if ( ! $by_eval ) return [];

        $cats = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, parent_id, label FROM {$wpdb->prefix}tt_eval_categories WHERE club_id = %d",
            CurrentClub::id()
        ), ARRAY_A );
        $parent_of = [];
        $label_of  = [];
        foreach ( (array) $cats as $c ) {
            $id               = (int) ( $c['id'] ?? 0 );
            $parent_of[ $id ] = (int) ( $c['parent_id'] ?? 0 );
            $label_of[ $id ]  = EvalCategoriesRepository::displayLabel( (string) ( $c['label'] ?? '' ), $id );
        }

        $out = [];
        foreach ( $by_eval as $eid => $notes ) {
            $mains = [];
            $subs  = [];
            foreach ( $notes as $cid => $note ) {
                $parent = $parent_of[ $cid ] ?? 0;
                if ( $parent > 0 ) {
                    $subs[ $parent ][] = ( $label_of[ $cid ] ?? '' ) . ': ' . $note;
                } else {
                    $mains[ $cid ] = $note;
                }
            }
            foreach ( array_unique( array_merge( array_keys( $mains ), array_keys( $subs ) ) ) as $main_id ) {
                $lines = [];
                if ( isset( $mains[ $main_id ] ) ) $lines[] = $mains[ $main_id ];
                foreach ( $subs[ $main_id ] ?? [] as $line ) $lines[] = $line;
                $out[ $eid ][ (int) $main_id ] = implode( "\n", $lines );
            }
        }
        return $out;
    }

    /**
     * Clean one submitted note: plain text, trimmed. Length is checked by
     * {@see tooLong()} before a write, not silently cut here.
     *
     * @param mixed $value
     */
    public static function clean( $value ): string {
        if ( ! is_scalar( $value ) ) return '';
        return trim( sanitize_textarea_field( (string) $value ) );
    }

    /**
     * The category ids in a submitted map whose note is over the limit.
     *
     * @param array<mixed, mixed> $notes
     * @return int[]
     */
    public static function tooLong( array $notes ): array {
        $out = [];
        foreach ( $notes as $cid => $value ) {
            if ( mb_strlen( self::clean( $value ) ) > self::MAX_LENGTH ) {
                $out[] = (int) $cid;
            }
        }
        return $out;
    }

    /**
     * Write the submitted notes of one evaluation. A blank clears that
     * category's note; a category not in `$notes` is left as it is.
     *
     * @param array<mixed, mixed> $notes Category id to note.
     * @return int Categories written or cleared.
     */
    public function write( int $evaluation_id, array $notes ): int {
        global $wpdb;
        if ( $evaluation_id <= 0 ) return 0;
        $club    = CurrentClub::id();
        $now     = current_time( 'mysql' );
        $written = 0;

        foreach ( $notes as $cid => $value ) {
            $category_id = absint( $cid );
            if ( $category_id <= 0 ) continue;
            $note = self::clean( $value );
            if ( mb_strlen( $note ) > self::MAX_LENGTH ) {
                $note = mb_substr( $note, 0, self::MAX_LENGTH );
            }

            if ( $note === '' ) {
                $n = $wpdb->delete( $this->table(), [
                    'evaluation_id' => $evaluation_id,
                    'category_id'   => $category_id,
                    'club_id'       => $club,
                ] );
                if ( $n !== false ) $written++;
                continue;
            }

            $n = $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$this->table()} (club_id, evaluation_id, category_id, note, created_at, updated_at)
                 VALUES (%d, %d, %d, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE note = VALUES(note), updated_at = VALUES(updated_at)",
                $club,
                $evaluation_id,
                $category_id,
                $note,
                $now,
                $now
            ) );
            if ( $n !== false ) $written++;
        }
        return $written;
    }

    /** Every note on an evaluation, for a delete that bypasses the cascade. */
    public function deleteForEvaluation( int $evaluation_id ): void {
        global $wpdb;
        $wpdb->delete( $this->table(), [ 'evaluation_id' => $evaluation_id, 'club_id' => CurrentClub::id() ] );
    }
}
