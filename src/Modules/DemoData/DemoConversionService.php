<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * DemoConversionService (#1272 PR2) — orchestrates the demo→production
 * conversion: per-batch delete OR per-batch promote-to-production.
 *
 * Two operations per batch:
 *   - **delete** — `DemoDataCleaner::wipeData(null, $batch_id)` removes
 *     the entity rows AND their `tt_demo_tags` rows. The dependency
 *     cascade in `DATA_ORDER` is preserved.
 *   - **promote** — DELETE FROM `tt_demo_tags` WHERE batch_id = X.
 *     The entity rows survive as plain production rows; they stop
 *     being scoped by demo-mode queries because they're no longer
 *     tagged.
 *
 * Audit-log entry per service call carries the per-batch counts so
 * the operator can verify what cleaned up after the fact.
 */
final class DemoConversionService {

    /** #1295 — entity_type → (table, id_column) for per-record overrides. */
    private const TABLE_MAP = [
        'team'       => [ 'tt_teams',       'id' ],
        'player'     => [ 'tt_players',     'id' ],
        'person'     => [ 'tt_people',      'id' ],
        'activity'   => [ 'tt_activities',  'id' ],
        'evaluation' => [ 'tt_evaluations', 'id' ],
        'goal'       => [ 'tt_goals',       'id' ],
    ];

    /**
     * Run a conversion.
     *
     * @param string[] $delete_batches  Batch ids to delete entirely (rows + tags).
     * @param string[] $promote_batches Batch ids to promote to production (rows stay; tags removed).
     * @param array<string, array<int, string>> $per_record_overrides #1295 — optional
     *   per-record exceptions shaped `[ entity_type => [ id => 'delete'|'promote' ] ]`.
     *   The cascade walks the per-batch decisions first, then applies overrides as a
     *   final pass:
     *     - `delete` on a row whose batch was promoted → delete the row + its
     *       `tt_demo_tags` entry.
     *     - `promote` on a row whose batch was deleted → already-deleted; the
     *       intent there is "skip the delete", so the override is processed
     *       BEFORE the per-batch delete loop instead (see below).
     * @return array{
     *   deleted_per_batch:  array<string, array<string,int>>,
     *   promoted_per_batch: array<string, int>,
     *   per_record_overrides_applied: array<string, array<int, string>>,
     * }
     */
    public function run( array $delete_batches, array $promote_batches, array $per_record_overrides = [] ): array {
        $delete_batches  = array_values( array_unique( array_filter( $delete_batches, 'is_string' ) ) );
        $promote_batches = array_values( array_unique( array_filter( $promote_batches, 'is_string' ) ) );

        // #1295 — sanitise overrides shape.
        $clean_overrides = [];
        foreach ( $per_record_overrides as $entity_type => $ids ) {
            if ( ! isset( self::TABLE_MAP[ $entity_type ] ) ) continue;
            if ( ! is_array( $ids ) ) continue;
            foreach ( $ids as $id => $action ) {
                $id     = (int) $id;
                $action = is_string( $action ) ? $action : '';
                if ( $id <= 0 ) continue;
                if ( $action !== 'delete' && $action !== 'promote' ) continue;
                $clean_overrides[ $entity_type ][ $id ] = $action;
            }
        }

        // #1295 — pre-pass: detach `promote` overrides from delete batches.
        // Removing the demo tag BEFORE the batch delete makes the row
        // invisible to `DemoBatchRegistry::allEntityIds()` so the batch
        // delete leaves it alone — net effect "rescue this row from a
        // batch-wide delete". Skipped tag rows are tracked separately
        // so they don't trigger the post-pass delete logic.
        $rescued_ids_per_type = [];
        if ( ! empty( $clean_overrides ) && ! empty( $delete_batches ) ) {
            foreach ( $clean_overrides as $entity_type => $ids ) {
                foreach ( $ids as $id => $action ) {
                    if ( $action !== 'promote' ) continue;
                    $batch_id = self::tagBatchId( $entity_type, $id );
                    if ( $batch_id === null ) continue;
                    if ( ! in_array( $batch_id, $delete_batches, true ) ) continue;
                    self::deleteTag( $entity_type, $id );
                    $rescued_ids_per_type[ $entity_type ][] = $id;
                }
            }
        }

        $deleted_per_batch  = [];
        $promoted_per_batch = [];

        foreach ( $delete_batches as $batch_id ) {
            if ( $batch_id === '' ) continue;
            $deleted_per_batch[ $batch_id ] = DemoDataCleaner::wipeData( null, $batch_id );
        }

        foreach ( $promote_batches as $batch_id ) {
            if ( $batch_id === '' ) continue;
            $promoted_per_batch[ $batch_id ] = self::promoteBatch( $batch_id );
        }

        // #1295 — post-pass: apply `delete` overrides against promoted
        // batches. The batch's tags are already gone (promoteBatch ran
        // above), so we delete the entity row directly and skip the
        // tag delete (no tag left). Done after the batch loops so we
        // don't fight with `DemoDataCleaner::wipeData()`.
        $applied_overrides = [];
        if ( ! empty( $clean_overrides ) ) {
            foreach ( $clean_overrides as $entity_type => $ids ) {
                foreach ( $ids as $id => $action ) {
                    if ( $action === 'promote' ) {
                        if ( ! empty( $rescued_ids_per_type[ $entity_type ] )
                          && in_array( $id, $rescued_ids_per_type[ $entity_type ], true ) ) {
                            $applied_overrides[ $entity_type ][ $id ] = 'promote-rescued';
                        }
                        // promote on a batch that was already promoted is a no-op.
                        continue;
                    }
                    if ( $action !== 'delete' ) continue;

                    // delete on a row whose batch was already deleted: the
                    // batch wipe will have removed the row + tag; nothing
                    // to do. We still record the intent for the audit log.
                    $batch_id = self::tagBatchId( $entity_type, $id );
                    if ( $batch_id === null ) {
                        // Either already wiped by batch delete, or never
                        // tagged. Mark applied so audit log shows intent.
                        $applied_overrides[ $entity_type ][ $id ] = 'delete-noop';
                        continue;
                    }
                    if ( in_array( $batch_id, $delete_batches, true ) ) {
                        // already swept by the batch delete loop
                        $applied_overrides[ $entity_type ][ $id ] = 'delete-noop';
                        continue;
                    }
                    // The row's batch was promoted (tags gone) OR not selected
                    // at all. Either way delete the row directly. The tag is
                    // either gone (promoted) or still there (unselected batch);
                    // sweep it.
                    self::deleteEntityRow( $entity_type, $id );
                    self::deleteTag( $entity_type, $id );
                    $applied_overrides[ $entity_type ][ $id ] = 'delete';
                }
            }
        }

        $summary = [
            'deleted_per_batch'  => $deleted_per_batch,
            'promoted_per_batch' => $promoted_per_batch,
            'per_record_overrides_applied' => $applied_overrides,
        ];

        Logger::info( 'demo.conversion.run', [
            'club_id'  => CurrentClub::id(),
            'by_user'  => get_current_user_id(),
            'summary'  => $summary,
            // #1295 — full per-record override map (incl. inert entries
            // not reflected in `summary['per_record_overrides_applied']`
            // because the cascade was a no-op).
            'per_record_overrides' => $clean_overrides,
        ] );

        // #1272 PR3 — terminal state. Once converted, demo mode is
        // permanently disabled on this install: the toggle UI is
        // locked, `tagIfActive` no-ops via `effective() !== ON`, and
        // a re-run of this service is a no-op (every batch is gone).
        DemoMode::markConverted();
        Logger::info( 'demo.converted_to_production', [
            'club_id'      => CurrentClub::id(),
            'by_user'      => get_current_user_id(),
            'converted_at' => DemoMode::convertedAt(),
        ] );

        return $summary;
    }

    /**
     * Promote a batch: drop its `tt_demo_tags` rows so the entity rows
     * stop being demo-scoped. Returns count of tag rows removed.
     */
    private static function promoteBatch( string $batch_id ): int {
        global $wpdb;
        $tag_table = $wpdb->prefix . 'tt_demo_tags';
        $n = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$tag_table} WHERE batch_id = %s",
            $batch_id
        ) );
        return (int) $n;
    }

    /**
     * #1295 — Look up the batch_id of a single tagged entity, scoped
     * to the current club. Returns `null` when no tag exists (e.g.
     * the row was never tagged or has already been swept).
     */
    private static function tagBatchId( string $entity_type, int $entity_id ): ?string {
        global $wpdb;
        $tag_table = $wpdb->prefix . 'tt_demo_tags';
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT batch_id FROM {$tag_table}
              WHERE club_id = %d AND entity_type = %s AND entity_id = %d
              LIMIT 1",
            CurrentClub::id(), $entity_type, $entity_id
        ) );
        return is_string( $val ) ? $val : null;
    }

    /**
     * #1295 — Drop the single tt_demo_tags row matching the entity.
     * Idempotent; no-op when no row matches.
     */
    private static function deleteTag( string $entity_type, int $entity_id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_demo_tags', [
            'club_id'     => CurrentClub::id(),
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
        ] );
    }

    /**
     * #1295 — Delete the underlying entity row for a per-record
     * `delete` override. Scoped to the current club. Idempotent.
     */
    private static function deleteEntityRow( string $entity_type, int $entity_id ): void {
        if ( ! isset( self::TABLE_MAP[ $entity_type ] ) ) return;
        global $wpdb;
        [ $table, $id_col ] = self::TABLE_MAP[ $entity_type ];
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}{$table} WHERE {$id_col} = %d AND club_id = %d",
            $entity_id, CurrentClub::id()
        ) );
    }
}
