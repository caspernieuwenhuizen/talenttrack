<?php
namespace TT\Modules\Vct\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * VctCoachingPointsRepository — translatable child of `tt_vct_exercises`.
 *
 * Canonical Dutch text lives in `tt_translations` keyed on
 * `(table='tt_vct_coaching_points', record_id, locale, column='text')`
 * — matches the lookup-translation pattern (avoids the JSON-trapped-
 * translation anti-pattern called out in #902).
 *
 * The catalogue seed (separate VCT-8 issue) populates both tables; this
 * repository surfaces the resolved text for a given exercise + locale.
 */
class VctCoachingPointsRepository {

    private \wpdb $wpdb;
    private string $table;
    private string $translations_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb               = $wpdb;
        $this->table              = $wpdb->prefix . 'tt_vct_coaching_points';
        $this->translations_table = $wpdb->prefix . 'tt_translations';
    }

    /**
     * Return coaching-point codes + resolved text for $exercise_id in
     * $locale. Falls back to the row's `code` when no translation exists
     * for the requested locale (mirrors the lookup translator fallback).
     *
     * @return list<array{id:int, sequence:int, code:string, text:string}>
     */
    public function listForExercise( int $exercise_id, string $locale ): array {
        if ( $exercise_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT cp.id, cp.sequence, cp.code,
                    COALESCE(t.value, cp.code) AS text
               FROM {$this->table} cp
               LEFT JOIN {$this->translations_table} t
                 ON t.club_id     = cp.club_id
                AND t.entity_type = 'vct_coaching_point'
                AND t.entity_id   = cp.id
                AND t.field       = 'text'
                AND t.locale      = %s
              WHERE cp.club_id = %d AND cp.exercise_id = %d AND cp.archived_at IS NULL
              ORDER BY cp.sequence ASC",
            $locale, CurrentClub::id(), $exercise_id
        ) );
        if ( ! is_array( $rows ) ) return [];
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'id'       => (int) $r->id,
                'sequence' => (int) $r->sequence,
                'code'     => (string) $r->code,
                'text'     => (string) $r->text,
            ];
        }
        return $out;
    }
}
