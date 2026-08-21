<?php
/**
 * Migration 0219 — share the football-actions catalogue across every
 * methodology set (#2620).
 *
 * Migration 0200 added `methodology_id` to `tt_football_actions` and
 * backfilled every existing row into the club's default set — at that
 * moment JO14-1 Hedel. Migrations 0202 and 0206 then seeded the JO13-1
 * Hedel set's formation, vision, principles, sub-principles, phases and
 * learning goals, but never seeded `tt_football_actions`. On an install
 * where JO13 is the active set, `FootballActionsRepository::listAll()`
 * scopes to `methodology_id = <active> OR methodology_id IS NULL` and
 * matches nothing: the Football actions tab, the goal → action picker
 * and the printed reference card all come up empty.
 *
 * The catalogue is deliberately made SHARED rather than duplicated per
 * set. Two reasons, both structural:
 *
 *   - `tt_football_actions.slug` carries a global `uniq_slug` unique key,
 *     so a per-set copy would need suffixed slugs and a second set of
 *     near-identical rows to maintain.
 *   - `tt_goals.linked_action_id` points at one row id. Duplicating would
 *     split "Aannemen" across two ids, fragmenting goal reporting and
 *     coverage aggregation per set for no gain.
 *
 * A football action ("passen onder druk") is a vocabulary of the game,
 * not of one club's play style. Principles, phases, vision and formations
 * stay per-set; the action catalogue does not.
 *
 * Idempotent by construction: setting an already-NULL column to NULL is a
 * no-op, so re-running changes nothing. Ordering is safe on a fresh
 * install too — 0200 backfills, 0202 seeds JO13, then this unstamps.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;

return new class extends Migration {

    public function getName(): string {
        return '0219_methodology_football_actions_shared';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_football_actions";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // The column only exists on installs that ran 0200. Guard rather
        // than assume, so a partially-migrated install fails soft.
        $has_column = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'methodology_id'",
            $table
        ) );
        if ( $has_column === 0 ) return;

        // Club-scoped rather than a blanket UPDATE: every write in this
        // codebase carries its tenant boundary, and a future multi-tenant
        // install must not have one club's migration touch another's rows.
        $club_ids = (array) $wpdb->get_col( "SELECT DISTINCT club_id FROM {$table}" );
        $cleared  = 0;

        foreach ( $club_ids as $club_id ) {
            $n = $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET methodology_id = NULL
                 WHERE club_id = %d AND methodology_id IS NOT NULL",
                (int) $club_id
            ) );
            if ( is_int( $n ) ) $cleared += $n;
        }

        Logger::info( 'migration.0219.football_actions_shared', [
            'clubs'   => count( $club_ids ),
            'cleared' => $cleared,
        ] );
    }
};
