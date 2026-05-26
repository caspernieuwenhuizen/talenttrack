<?php
namespace TT\Modules\Vct\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * VctSessionTemplatesRepository — slot definitions per (age × MD context).
 * Seed lands via migration 0125. Operator-editable post-MVP through the
 * Phase 2 configuration tile.
 */
class VctSessionTemplatesRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_vct_session_templates';
    }

    /**
     * Returns the template for the given (age, md_context). Falls back
     * to the `NONE` md_context template when no exact match exists
     * (covers the U10/U11 no-MD-logic path, and any future age × MD
     * combination not explicitly seeded).
     *
     * @return array{id:int, age_group:string, md_context:string, slots:list<array<string,mixed>>, total_duration_minutes_target:int}|null
     */
    public function findFor( string $age_group, string $md_context ): ?array {
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT id, age_group, md_context, slots_json, total_duration_minutes_target
               FROM {$this->table}
              WHERE club_id = %d AND age_group = %s AND md_context = %s
              LIMIT 1",
            CurrentClub::id(), $age_group, $md_context
        ) );
        if ( ! $row && $md_context !== 'NONE' ) {
            return $this->findFor( $age_group, 'NONE' );
        }
        if ( ! $row ) return null;
        return $this->hydrate( $row );
    }

    /** @param object $row */
    private function hydrate( $row ): array {
        $slots = json_decode( (string) $row->slots_json, true );
        return [
            'id'                            => (int)    $row->id,
            'age_group'                     => (string) $row->age_group,
            'md_context'                    => (string) $row->md_context,
            'slots'                         => is_array( $slots ) ? $slots : [],
            'total_duration_minutes_target' => (int)    $row->total_duration_minutes_target,
        ];
    }
}
