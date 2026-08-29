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

    /**
     * Age groups that have at least one template on this club.
     *
     * @return list<string>
     */
    public function ageGroupsWithTemplates(): array {
        $rows = $this->wpdb->get_col( $this->wpdb->prepare(
            "SELECT DISTINCT age_group FROM {$this->table} WHERE club_id = %d",
            CurrentClub::id()
        ) );
        // `get_col()` is typed as always returning an array, so an
        // `is_array()` guard here is dead code PHPStan reports as such.
        return array_values( array_map( 'strval', $rows ) );
    }

    /**
     * Clone every template of one age group onto another (#2601).
     *
     * Adding an age profile is not enough on its own to make the
     * generator work: the profile supplies the load ceiling, the template
     * supplies the shape of the session, and a missing template blocks
     * the draft one pass later with a different message. Copying the
     * nearest modelled age group's blueprint means an academy that adds
     * U15 gets a working generator from its own ceilings, instead of
     * hitting a second wall it has no surface to fix.
     *
     * A blueprint is a starting point, not a claim about this age group —
     * the numbers that carry the age-safety are the ones the operator
     * typed into the profile, and they are applied over the top of these
     * slots by the rule pipeline.
     *
     * `INSERT IGNORE`-shaped: an md_context the target already has is
     * left alone, so re-running never overwrites an academy's own edits.
     *
     * @return int Number of templates copied.
     */
    public function copyAgeGroup( string $from_age_group, string $to_age_group ): int {
        if ( $from_age_group === '' || $to_age_group === '' || $from_age_group === $to_age_group ) return 0;

        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT md_context, slots_json, total_duration_minutes_target, description_nl
               FROM {$this->table}
              WHERE club_id = %d AND age_group = %s",
            CurrentClub::id(), $from_age_group
        ) );
        if ( ! is_array( $rows ) || $rows === [] ) return 0;

        $copied = 0;
        foreach ( $rows as $row ) {
            $exists = (int) $this->wpdb->get_var( $this->wpdb->prepare(
                "SELECT id FROM {$this->table}
                  WHERE club_id = %d AND age_group = %s AND md_context = %s",
                CurrentClub::id(), $to_age_group, (string) $row->md_context
            ) );
            if ( $exists > 0 ) continue;

            $ok = $this->wpdb->insert(
                $this->table,
                [
                    'club_id'                       => CurrentClub::id(),
                    'uuid'                          => wp_generate_uuid4(),
                    'age_group'                     => $to_age_group,
                    'md_context'                    => (string) $row->md_context,
                    'slots_json'                    => (string) $row->slots_json,
                    'total_duration_minutes_target' => (int) $row->total_duration_minutes_target,
                    'description_nl'                => $row->description_nl !== null ? (string) $row->description_nl : null,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
            );
            if ( $ok ) $copied++;
        }

        return $copied;
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
