<?php
/**
 * Migration 0135 — Blueprint assignment UNIQUE constraint includes
 * club_id (#1054).
 *
 * Migration 0129 set the UNIQUE constraint on
 * `tt_team_blueprint_assignments` as `(blueprint_id, slot_label, tier)`
 * — no `club_id`. Combined with the `setAssignment` SELECT that
 * filters by club_id, this produced the #1054 silent-fail mode: if
 * any stale row existed for the same `(blueprint_id, slot_label,
 * tier)` but a different `club_id`, the SELECT missed it, the INSERT
 * collided on the UNIQUE, and the failure was swallowed (the calling
 * code didn't check the wpdb return value).
 *
 * The repository-side silent-fail is fixed in v4.15.7 by checking the
 * return value and surfacing a 500. This migration closes the
 * UNDERLYING collision by extending the UNIQUE to include `club_id`,
 * so the constraint matches how the repo logically scopes
 * assignments. SaaS-readiness: when CurrentClub::id() returns
 * something other than 1, the constraint stays correct.
 *
 * Idempotent: DROP INDEX IF EXISTS then ADD. Safe to re-run.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0135_blueprint_assignment_uniq_with_club';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = $p . 'tt_team_blueprint_assignments';

        // Drop the old narrower UNIQUE if present. MySQL syntax:
        // information_schema lookup to avoid touching a non-existent
        // index on freshly-created installs.
        $has_old = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(1) FROM information_schema.STATISTICS
              WHERE table_schema = DATABASE()
                AND table_name   = %s
                AND index_name   = %s",
            $table, 'uniq_slot_tier'
        ) );
        if ( $has_old > 0 ) {
            $wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_slot_tier" );
        }

        $has_new = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(1) FROM information_schema.STATISTICS
              WHERE table_schema = DATABASE()
                AND table_name   = %s
                AND index_name   = %s",
            $table, 'uniq_slot_tier_club'
        ) );
        if ( $has_new === 0 ) {
            $wpdb->query( "ALTER TABLE {$table}
                ADD UNIQUE KEY uniq_slot_tier_club (club_id, blueprint_id, slot_label, tier)" );
        }
    }

    public function down(): void {
        // Forward-only. Reverting risks duplicate-row insertion on a
        // shared (blueprint_id, slot_label, tier) across club_ids if
        // the data layer regresses. Operators who need to roll back
        // should restore from backup.
    }
};
