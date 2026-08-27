<?php
namespace TT\Modules\Import;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ImportBatchRegistry (#2956) — where a real import records its rows.
 *
 * The counterpart to `DemoBatchRegistry`. Same job, different tables:
 * `tt_import_batches` / `tt_import_tags` instead of `tt_demo_tags`. That
 * separation is the whole safety property — `DemoDataCleaner` resolves
 * what to delete from `tt_demo_tags`, so a club's real squad recorded here
 * is simply not reachable by it. See migration 0238.
 *
 * The batch row is created lazily on the first tag, so an import that
 * validates but writes nothing leaves no empty batch behind.
 */
final class ImportBatchRegistry implements ImportTagSink {

    private string $batch_key;
    private string $source_filename;
    private ?int $batch_id = null;

    public function __construct( string $batch_key, string $source_filename = '' ) {
        $this->batch_key       = $batch_key;
        $this->source_filename = $source_filename;
    }

    public function batchId(): string {
        return $this->batch_key;
    }

    /**
     * @param array<string,mixed> $extra
     */
    public function tag( string $entity_type, int $entity_id, array $extra = [] ): void {
        global $wpdb;

        $this->ensureBatch();

        $wpdb->insert( "{$wpdb->prefix}tt_import_tags", [
            'club_id'     => CurrentClub::id(),
            'batch_key'   => $this->batch_key,
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'extra_json'  => $extra ? (string) wp_json_encode( $extra ) : null,
        ] );
    }

    /**
     * Stamp the per-entity counts once the import is done, so the history
     * can list what arrived without counting tag rows every time.
     *
     * @param array<string,int> $counts
     */
    public function recordCounts( array $counts ): void {
        global $wpdb;

        $this->ensureBatch();
        if ( $this->batch_id === null ) return;

        $wpdb->update(
            "{$wpdb->prefix}tt_import_batches",
            [ 'counts_json' => (string) wp_json_encode( $counts ) ],
            [ 'id' => $this->batch_id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * Entity ids of one type in this batch.
     *
     * @return list<int>
     */
    public function entityIds( string $entity_type ): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT entity_id FROM {$wpdb->prefix}tt_import_tags
              WHERE batch_key = %s AND entity_type = %s AND club_id = %d",
            $this->batch_key, $entity_type, CurrentClub::id()
        ) );
        return array_map( 'intval', is_array( $ids ) ? $ids : [] );
    }

    /**
     * Ids of one type across every real import batch, or one batch.
     *
     * @return list<int>
     */
    public static function allEntityIds( string $entity_type, ?string $batch_key = null ): array {
        global $wpdb;

        if ( $batch_key !== null && $batch_key !== '' ) {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT entity_id FROM {$wpdb->prefix}tt_import_tags
                  WHERE entity_type = %s AND club_id = %d AND batch_key = %s",
                $entity_type, CurrentClub::id(), $batch_key
            ) );
        } else {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT entity_id FROM {$wpdb->prefix}tt_import_tags
                  WHERE entity_type = %s AND club_id = %d",
                $entity_type, CurrentClub::id()
            ) );
        }

        return array_map( 'intval', is_array( $ids ) ? $ids : [] );
    }

    /**
     * Every real import batch, most recent first.
     *
     * @return list<array{id:int, uuid:string, batch_key:string, source_filename:string, counts:array<string,int>, created_at:string, created_by:int}>
     */
    public static function listBatches(): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, uuid, batch_key, source_filename, counts_json, created_at, created_by
               FROM {$wpdb->prefix}tt_import_batches
              WHERE club_id = %d
              ORDER BY created_at DESC, id DESC",
            CurrentClub::id()
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $r ) {
            $counts = json_decode( (string) ( $r->counts_json ?? '' ), true );
            $out[] = [
                'id'              => (int) $r->id,
                'uuid'            => (string) $r->uuid,
                'batch_key'       => (string) $r->batch_key,
                'source_filename' => (string) $r->source_filename,
                'counts'          => is_array( $counts ) ? $counts : [],
                'created_at'      => (string) $r->created_at,
                'created_by'      => (int) $r->created_by,
            ];
        }
        return $out;
    }

    /** Create the batch row on first use. */
    private function ensureBatch(): void {
        global $wpdb;

        if ( $this->batch_id !== null ) return;

        $table   = "{$wpdb->prefix}tt_import_batches";
        $club_id = CurrentClub::id();

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE batch_key = %s AND club_id = %d",
            $this->batch_key, $club_id
        ) );
        if ( $existing ) {
            $this->batch_id = (int) $existing;
            return;
        }

        $wpdb->insert( $table, [
            'uuid'            => wp_generate_uuid4(),
            'club_id'         => $club_id,
            'batch_key'       => $this->batch_key,
            'source_filename' => $this->source_filename,
            'created_by'      => get_current_user_id() ?: null,
        ] );

        $this->batch_id = (int) $wpdb->insert_id;
    }
}
