<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * DemoBatchRegistry — thin wrapper around tt_demo_tags.
 *
 * Every generated entity is tagged with (batch_id, entity_type, entity_id)
 * plus optional extra_json (archetype for players, persistent:true for users,
 * seed for audit). All generators accept a registry instance and call
 * tag() after each insert.
 */
class DemoBatchRegistry {

    private string $batch_id;

    public function __construct( string $batch_id ) {
        $this->batch_id = $batch_id;
    }

    public function batchId(): string {
        return $this->batch_id;
    }

    /**
     * @param array<string,mixed> $extra
     */
    public function tag( string $entity_type, int $entity_id, array $extra = [] ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_demo_tags", [
            'club_id'     => CurrentClub::id(),
            'batch_id'    => $this->batch_id,
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'extra_json'  => $extra ? (string) wp_json_encode( $extra ) : null,
        ] );
    }

    /**
     * All entity ids tagged with the given type. Optionally narrowed
     * to a specific `batch_id` for the v3.95.1+ per-batch wipe scope
     * (#0080 Wave B2). Empty / null `$batch_id` returns the all-batches
     * set (the historical behaviour, kept for back-compat callers).
     *
     * @return int[]
     */
    public static function allEntityIds( string $entity_type, ?string $batch_id = null ): array {
        global $wpdb;
        if ( $batch_id !== null && $batch_id !== '' ) {
            $rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT entity_id FROM {$wpdb->prefix}tt_demo_tags
                 WHERE entity_type = %s AND club_id = %d AND batch_id = %s",
                $entity_type, CurrentClub::id(), $batch_id
            ) );
        } else {
            $rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT entity_id FROM {$wpdb->prefix}tt_demo_tags
                 WHERE entity_type = %s AND club_id = %d",
                $entity_type, CurrentClub::id()
            ) );
        }
        return array_map( 'intval', (array) $rows );
    }

    /**
     * #0080 Wave B2 — listBatches() helper for the wipe-form Batch
     * dropdown. Returns one row per distinct `batch_id` with the
     * batch's first-seen timestamp + the count of tagged entities.
     * Most-recent batch first.
     *
     * @return array<int, array{batch_id:string, created_at:string, tag_count:int}>
     */
    public static function listBatches(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT batch_id,
                    MIN(created_at) AS created_at,
                    COUNT(*)        AS tag_count
               FROM {$wpdb->prefix}tt_demo_tags
              WHERE club_id = %d
              GROUP BY batch_id
              ORDER BY MIN(created_at) DESC",
            CurrentClub::id()
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Entity ids tagged with the given type in this batch.
     *
     * @return int[]
     */
    public function entityIds( string $entity_type ): array {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT entity_id FROM {$wpdb->prefix}tt_demo_tags
             WHERE batch_id = %s AND entity_type = %s AND club_id = %d",
            $this->batch_id,
            $entity_type,
            CurrentClub::id()
        ) );
        return array_map( 'intval', (array) $rows );
    }

    /**
     * Ids of entities tagged `persistent: true` in extra_json (the
     * demo user set survives data wipes — see spec, Rich set of 36).
     *
     * @return int[]
     */
    public static function persistentEntityIds( string $entity_type ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT entity_id, extra_json FROM {$wpdb->prefix}tt_demo_tags
             WHERE entity_type = %s AND club_id = %d",
            $entity_type,
            CurrentClub::id()
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $extra = $r->extra_json ? json_decode( (string) $r->extra_json, true ) : [];
            if ( is_array( $extra ) && ! empty( $extra['persistent'] ) ) {
                $out[] = (int) $r->entity_id;
            }
        }
        return $out;
    }
}
