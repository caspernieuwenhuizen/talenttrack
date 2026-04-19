<?php
namespace TT\Infrastructure\CustomFields;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CustomValuesRepository — typed data access for tt_custom_values.
 *
 * Polymorphic: scoped by (entity_type, entity_id). Values are upserted
 * per (entity_type, entity_id, field_id) — the table's UNIQUE KEY
 * enforces one value per (entity, field) pair.
 */
class CustomValuesRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_custom_values';
    }

    /**
     * Fetch the raw { field_id => value } map for a given entity.
     *
     * @return array<int, string|null>
     */
    public function getByEntity( string $entity_type, int $entity_id ): array {
        global $wpdb;
        $t = $this->table();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT field_id, value FROM {$t} WHERE entity_type = %s AND entity_id = %d",
            $entity_type, $entity_id
        ) );
        $out = [];
        foreach ( $rows as $r ) {
            $out[ (int) $r->field_id ] = $r->value;
        }
        return $out;
    }

    /**
     * Fetch the { field_key => value } map for a given entity.
     * Convenience for rendering and API responses.
     *
     * @return array<string, mixed>
     */
    public function getByEntityKeyed( string $entity_type, int $entity_id ): array {
        global $wpdb;
        $values_t = $this->table();
        $fields_t = $wpdb->prefix . 'tt_custom_fields';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT f.field_key, f.field_type, v.value
             FROM {$values_t} v
             INNER JOIN {$fields_t} f ON v.field_id = f.id
             WHERE v.entity_type = %s AND v.entity_id = %d AND f.is_active = 1",
            $entity_type, $entity_id
        ) );

        $out = [];
        foreach ( $rows as $r ) {
            $out[ (string) $r->field_key ] = self::castForOutput( (string) $r->field_type, $r->value );
        }
        return $out;
    }

    /**
     * Upsert a single (entity, field) value. Null/empty deletes the row.
     */
    public function upsert( string $entity_type, int $entity_id, int $field_id, ?string $value ): void {
        global $wpdb;
        $t = $this->table();

        // Null or empty string → delete (don't store sentinels).
        if ( $value === null || $value === '' ) {
            $wpdb->delete( $t, [
                'entity_type' => $entity_type,
                'entity_id'   => $entity_id,
                'field_id'    => $field_id,
            ] );
            return;
        }

        // Does a row already exist? Use UNIQUE KEY lookup.
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t} WHERE entity_type = %s AND entity_id = %d AND field_id = %d",
            $entity_type, $entity_id, $field_id
        ) );

        if ( $existing_id ) {
            $wpdb->update( $t, [ 'value' => $value ], [ 'id' => (int) $existing_id ] );
        } else {
            $wpdb->insert( $t, [
                'entity_type' => $entity_type,
                'entity_id'   => $entity_id,
                'field_id'    => $field_id,
                'value'       => $value,
            ] );
        }
    }

    /**
     * Delete all values for an entity. Called when an entity itself is deleted.
     */
    public function deleteForEntity( string $entity_type, int $entity_id ): void {
        global $wpdb;
        $wpdb->delete( $this->table(), [
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
        ] );
    }

    /**
     * Cast a stored string value back to its natural PHP type for output.
     *
     * @return mixed
     */
    private static function castForOutput( string $field_type, ?string $raw ) {
        if ( $raw === null ) return null;
        switch ( $field_type ) {
            case CustomFieldsRepository::TYPE_NUMBER:
                if ( $raw === '' ) return null;
                return is_numeric( $raw ) && strpos( $raw, '.' ) === false
                    ? (int) $raw
                    : (float) $raw;
            case CustomFieldsRepository::TYPE_CHECKBOX:
                return $raw === '1';
            default:
                return (string) $raw;
        }
    }
}
