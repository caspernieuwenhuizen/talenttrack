<?php
namespace TT\Modules\Translations\Cache;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * SourceMetaRepository — `tt_translation_source_meta` data access (#0025).
 *
 * Stores the detected source language for each (entity_type, entity_id,
 * field_name) triple so re-reads of unchanged text don't re-detect.
 * `source_hash` is recorded so we can spot a content-changed write
 * even if the caller forgets to invalidate explicitly.
 */
final class SourceMetaRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_translation_source_meta';
    }

    public function find( string $entity_type, int $entity_id, string $field_name ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE entity_type = %s AND entity_id = %d AND field_name = %s AND club_id = %d LIMIT 1",
            $entity_type, $entity_id, $field_name, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    public function upsert(
        string $entity_type,
        int $entity_id,
        string $field_name,
        string $source_hash,
        string $detected_lang,
        float $confidence
    ): void {
        global $wpdb;
        $existing = $this->find( $entity_type, $entity_id, $field_name );
        $payload  = [
            'club_id'              => CurrentClub::id(),
            'entity_type'          => $entity_type,
            'entity_id'            => $entity_id,
            'field_name'           => $field_name,
            'source_hash'          => $source_hash,
            'detected_lang'        => $detected_lang,
            'detection_confidence' => max( 0, min( 1.0, $confidence ) ),
            'last_detected_at'     => current_time( 'mysql', true ),
        ];
        if ( $existing ) {
            $wpdb->update( $this->table(), $payload, [ 'id' => (int) $existing->id, 'club_id' => CurrentClub::id() ] );
        } else {
            $wpdb->insert( $this->table(), $payload );
        }
    }

    public function deleteFor( string $entity_type, int $entity_id, ?string $field_name = null ): int {
        global $wpdb;
        $where = [ 'entity_type' => $entity_type, 'entity_id' => $entity_id, 'club_id' => CurrentClub::id() ];
        if ( $field_name !== null ) $where['field_name'] = $field_name;
        return (int) $wpdb->delete( $this->table(), $where );
    }

    public function truncate(): int {
        global $wpdb;
        return (int) $wpdb->query( "TRUNCATE TABLE {$this->table()}" );
    }
}
