<?php
namespace TT\Modules\TeamDevelopment\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerAttributesRepository (#1912) — the structured player-attribute
 * model behind the reworked chemistry engine.
 *
 * Two tables: a catalogue of attribute definitions (`tt_player_attribute_defs`,
 * groups physical/technical/tactical/mental/behaviour/development, each
 * 0–100) and per-player values (`tt_player_attribute_values`). Stateless,
 * club-scoped; catalogue reads exclude archived defs.
 */
class PlayerAttributesRepository {

    /**
     * The active attribute catalogue, ordered, with localised labels.
     *
     * @return array<int, object>
     */
    public function listDefs(): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, attr_group, attr_key, label, min_value, max_value, sort_order
               FROM {$p}tt_player_attribute_defs
              WHERE club_id = %d AND archived_at IS NULL AND is_active = 1
              ORDER BY sort_order ASC, id ASC",
            CurrentClub::id()
        ) );
        if ( ! is_array( $rows ) ) return [];

        foreach ( $rows as $row ) {
            $row->label_localised = $this->localiseLabel( (int) $row->id, (string) $row->label );
        }
        return $rows;
    }

    /**
     * A player's full attribute set as a grouped view:
     *   [ group => [ { def_id, attr_key, label, value (int|null), min, max } ] ]
     * Every catalogue attribute appears (value null when unrecorded), so the
     * editor and the engine see a complete, stable shape.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function forPlayer( int $player_id ): array {
        if ( $player_id <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        $defs   = $this->listDefs();
        $values = [];
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT attribute_def_id, value FROM {$p}tt_player_attribute_values
              WHERE player_id = %d AND club_id = %d",
            $player_id, CurrentClub::id()
        ) );
        foreach ( (array) $rows as $r ) {
            $values[ (int) $r->attribute_def_id ] = $r->value !== null ? (int) $r->value : null;
        }

        $grouped = [];
        foreach ( $defs as $def ) {
            $did   = (int) $def->id;
            $group = (string) $def->attr_group;
            $grouped[ $group ][] = [
                'def_id'   => $did,
                'attr_key' => (string) $def->attr_key,
                'label'    => (string) $def->label_localised,
                'value'    => $values[ $did ] ?? null,
                'min'      => (int) $def->min_value,
                'max'      => (int) $def->max_value,
            ];
        }
        return $grouped;
    }

    /**
     * Set (or clear) a player's value for one attribute. Clamped to the
     * attribute's min/max. A null/blank value clears it.
     */
    public function upsertValue( int $player_id, int $def_id, ?int $value ): bool {
        if ( $player_id <= 0 || $def_id <= 0 ) return false;
        global $wpdb;
        $p = $wpdb->prefix;

        $def = $wpdb->get_row( $wpdb->prepare(
            "SELECT min_value, max_value FROM {$p}tt_player_attribute_defs
              WHERE id = %d AND club_id = %d AND archived_at IS NULL",
            $def_id, CurrentClub::id()
        ) );
        if ( ! $def ) return false;

        if ( $value !== null ) {
            $value = (int) max( (int) $def->min_value, min( (int) $def->max_value, $value ) );
        }

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_player_attribute_values
              WHERE player_id = %d AND attribute_def_id = %d AND club_id = %d LIMIT 1",
            $player_id, $def_id, CurrentClub::id()
        ) );

        if ( $existing > 0 ) {
            return false !== $wpdb->update(
                "{$p}tt_player_attribute_values",
                [ 'value' => $value, 'updated_at' => current_time( 'mysql', true ) ],
                [ 'id' => $existing, 'club_id' => CurrentClub::id() ]
            );
        }

        return false !== $wpdb->insert( "{$p}tt_player_attribute_values", [
            'club_id'          => CurrentClub::id(),
            'uuid'             => wp_generate_uuid4(),
            'player_id'        => $player_id,
            'attribute_def_id' => $def_id,
            'value'            => $value,
            'recorded_at'      => current_time( 'mysql', true ),
            'recorded_by'      => get_current_user_id() ?: null,
        ] );
    }

    private function localiseLabel( int $def_id, string $fallback ): string {
        // Defs carry their translations under entity_type
        // 'player_attribute_def' / field 'label' (seeded in migration 0178).
        global $wpdb;
        $p      = $wpdb->prefix;
        $locale = get_locale();
        if ( $locale === '' ) return $fallback;

        $val = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT value FROM {$p}tt_translations
              WHERE entity_type = 'player_attribute_def' AND entity_id = %d
                AND field = 'label' AND locale = %s AND club_id = %d
              LIMIT 1",
            $def_id, $locale, CurrentClub::id()
        ) );
        return $val !== '' ? $val : $fallback;
    }
}
