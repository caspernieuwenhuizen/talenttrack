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

    /**
     * Run a conversion.
     *
     * @param string[] $delete_batches  Batch ids to delete entirely (rows + tags).
     * @param string[] $promote_batches Batch ids to promote to production (rows stay; tags removed).
     * @return array{
     *   deleted_per_batch:  array<string, array<string,int>>,
     *   promoted_per_batch: array<string, int>,
     * }
     */
    public function run( array $delete_batches, array $promote_batches ): array {
        $delete_batches  = array_values( array_unique( array_filter( $delete_batches, 'is_string' ) ) );
        $promote_batches = array_values( array_unique( array_filter( $promote_batches, 'is_string' ) ) );

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

        $summary = [
            'deleted_per_batch'  => $deleted_per_batch,
            'promoted_per_batch' => $promoted_per_batch,
        ];

        Logger::info( 'demo.conversion.run', [
            'club_id'  => CurrentClub::id(),
            'by_user'  => get_current_user_id(),
            'summary'  => $summary,
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
}
