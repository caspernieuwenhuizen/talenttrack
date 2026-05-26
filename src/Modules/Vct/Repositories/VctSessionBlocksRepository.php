<?php
namespace TT\Modules\Vct\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * VctSessionBlocksRepository — filled slots per VCT session.
 *
 * Child of `tt_vct_sessions`. Cleanup on session delete is handled
 * inside `VctSessionsRepository::delete()` in a transaction (no DB
 * CASCADE per codebase convention).
 *
 * The FK column on the table is `vct_session_id` (disambiguated from
 * the legacy training-session FK token banned under the #0035
 * no-regression linter — see migration 0122 header).
 */
class VctSessionBlocksRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_vct_session_blocks';
    }

    /**
     * Replace the full block set for a session — delete-then-insert.
     * Used by `RulesEngine::compose()` when persisting a newly
     * generated session.
     *
     * @param list<array<string,mixed>> $blocks
     */
    public function replaceForSession( int $vct_session_id, array $blocks ): bool {
        if ( $vct_session_id <= 0 ) return false;
        $club_id = CurrentClub::id();

        $this->wpdb->delete( $this->table, [
            'club_id'        => $club_id,
            'vct_session_id' => $vct_session_id,
        ] );

        foreach ( $blocks as $b ) {
            $ok = $this->wpdb->insert( $this->table, [
                'club_id'                       => $club_id,
                'vct_session_id'                => $vct_session_id,
                'sequence'                      => (int) ( $b['sequence'] ?? 0 ),
                'slot_category'                 => (string) ( $b['slot_category'] ?? '' ),
                'exercise_id'                   => isset( $b['exercise_id'] ) && (int) $b['exercise_id'] > 0 ? (int) $b['exercise_id'] : null,
                'custom_label'                  => isset( $b['custom_label'] ) ? (string) $b['custom_label'] : null,
                'duration_minutes'              => (int) ( $b['duration_minutes'] ?? 0 ),
                'intensity_band'                => (int) ( $b['intensity_band'] ?? 0 ),
                'coaching_point_override_codes' => isset( $b['coaching_point_override_codes'] )
                    ? wp_json_encode( $b['coaching_point_override_codes'] )
                    : null,
            ] );
            if ( $ok === false ) return false;
        }
        return true;
    }

    /**
     * Update a single block — used by PATCH /vct/sessions/{id} when a
     * coach swaps one block's exercise. RulesEngine::validate() runs
     * after the swap to recompute load + warnings.
     *
     * @param array<string,mixed> $patch
     */
    public function updateBlock( int $block_id, array $patch ): bool {
        $allowed = [
            'exercise_id', 'custom_label', 'duration_minutes', 'intensity_band',
            'coaching_point_override_codes',
        ];
        $set = [];
        foreach ( $allowed as $key ) {
            if ( array_key_exists( $key, $patch ) ) {
                $value = $patch[ $key ];
                if ( $key === 'coaching_point_override_codes' && is_array( $value ) ) {
                    $value = wp_json_encode( $value );
                }
                $set[ $key ] = $value;
            }
        }
        if ( ! $set ) return true;
        $ok = $this->wpdb->update(
            $this->table,
            $set,
            [ 'id' => $block_id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForSession( int $vct_session_id ): array {
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE club_id = %d AND vct_session_id = %d
              ORDER BY sequence ASC",
            CurrentClub::id(), $vct_session_id
        ) );
        if ( ! is_array( $rows ) ) return [];
        $out = [];
        foreach ( $rows as $r ) {
            $overrides = json_decode( (string) ( $r->coaching_point_override_codes ?? '' ), true );
            $out[] = [
                'id'                            => (int) $r->id,
                'sequence'                      => (int) $r->sequence,
                'slot_category'                 => (string) $r->slot_category,
                'exercise_id'                   => $r->exercise_id !== null ? (int) $r->exercise_id : null,
                'custom_label'                  => $r->custom_label !== null ? (string) $r->custom_label : null,
                'duration_minutes'              => (int) $r->duration_minutes,
                'intensity_band'                => (int) $r->intensity_band,
                'coaching_point_override_codes' => is_array( $overrides ) ? $overrides : [],
            ];
        }
        return $out;
    }
}
