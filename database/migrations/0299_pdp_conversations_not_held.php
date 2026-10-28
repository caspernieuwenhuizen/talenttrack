<?php
/**
 * Migration 0299 — `not_held_at` + `not_held_reason` on
 * `tt_pdp_conversations` (#4298).
 *
 * A PDP cycle's talks run in sequence and only the active one takes content
 * (#2041). The active talk is the first without a coach sign-off, so a talk
 * that never took place, because of illness, a cancelled week or a change
 * of coach, kept the cycle on it for good, the end-of-season talk included.
 *
 * A talk is now held (`conducted_at`), not held (`not_held_at`, with the
 * reason the coach gave) or still to come. A not-held talk stays on the file
 * and moves the cycle on to the next one.
 *
 * UTC, like the other `*_at` columns on the table. NULL means the talk has
 * not been marked not held. Additive + idempotent through
 * MigrationHelpers::addColumnIfMissing; forward-only; run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0299_pdp_conversations_not_held';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_pdp_conversations';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing( $table, 'not_held_at', 'DATETIME DEFAULT NULL', 'coach_signoff_at' );
        MigrationHelpers::addColumnIfMissing( $table, 'not_held_reason', 'VARCHAR(255) DEFAULT NULL', 'not_held_at' );
    }
};
