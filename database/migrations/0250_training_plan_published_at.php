<?php
/**
 * Migration: 0250_training_plan_published_at
 *
 * #3220 — `published_at` on `tt_training_plans`.
 *
 * The `methodology_delivered` message has shipped since #0066 promising
 * coaches that "the activity plan '{plan_title}' is published", and the
 * product had no concept of publishing one. `src/Modules/Training/` raised
 * no event at all, and no column on any table meant "ready for the coaches
 * to read" — `visibility` is an access scope, not a state.
 *
 * PUBLISHING ANNOUNCES; IT DOES NOT LOCK
 *
 * Migration 0213's own note says plans carry no version chain and are
 * mutable by design, and that stays true. A published plan is still
 * editable, and editing it neither unpublishes it nor re-notifies. The
 * column answers "has the head of development told the coaches about
 * this", which is the only question the message needs answered.
 *
 * NULLABLE, NO BACKFILL
 *
 * Every existing plan comes out unpublished, which is honest: nobody has
 * ever been told about one. Backfilling would either claim an announcement
 * that never happened or, with the trigger live, mail every coach in the
 * academy about every plan ever written.
 *
 * No index — the value is read per plan, never filtered on.
 *
 * Idempotent. The column check makes a re-run a no-op, and an install that
 * has never enabled the Training module has no table to alter.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0250_training_plan_published_at';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_training_plans';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'published_at' ) );
        if ( ! empty( $exists ) ) return;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN published_at DATETIME NULL DEFAULT NULL AFTER visibility" );
    }
};
