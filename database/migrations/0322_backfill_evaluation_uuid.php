<?php
/**
 * Migration 0322 — every evaluation gets its uuid (#4985).
 *
 * `tt_evaluations.uuid` is the evaluation's portable identity (CLAUDE.md
 * §4). Migration 0038 added the column and filled it once, but the insert
 * paths did not set it, so every evaluation written since has none. The
 * insert paths set it now; this fills the ones that are empty today.
 *
 * Data only. It walks the rows whose uuid is NULL or empty in batches of
 * 500 and gives each a fresh `wp_generate_uuid4()`. Rows that have one are
 * never touched, so a second run writes nothing. A write the database
 * refuses throws, and so do rows still empty when the walk ends, so the
 * runner records the migration as failed and it runs again next time.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const BATCH = 500;

    public function getName(): string {
        return '0322_backfill_evaluation_uuid';
    }

    public function up(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_evaluations";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_evaluations is missing' );
            return;
        }
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'uuid' ) ) === null ) {
            $this->report( 0, 'tt_evaluations has no uuid column' );
            return;
        }

        $filled = 0;
        $first  = true;
        while ( true ) {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM `$table` WHERE uuid IS NULL OR uuid = '' ORDER BY id LIMIT %d",
                self::BATCH
            ) );
            if ( empty( $ids ) ) {
                if ( $first ) {
                    $this->report( 0, 'every evaluation already has a uuid' );
                    return;
                }
                break;
            }
            $first = false;

            // `exec()` throws on a refused write, so the runner records the
            // migration as failed rather than applied with rows left empty.
            $in_batch = 0;
            foreach ( $ids as $id ) {
                $in_batch += $this->exec( $wpdb->prepare(
                    "UPDATE `$table` SET uuid = %s WHERE id = %d AND ( uuid IS NULL OR uuid = '' )",
                    wp_generate_uuid4(),
                    (int) $id
                ) );
            }
            $filled += $in_batch;
            if ( $in_batch === 0 ) break;
        }

        $left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table` WHERE uuid IS NULL OR uuid = ''" );
        if ( $left > 0 ) {
            throw new \RuntimeException( sprintf( '0322: %d evaluation(s) still have no uuid after the backfill', $left ) );
        }

        $this->report( $filled, 'evaluations given a uuid' );
    }
};
