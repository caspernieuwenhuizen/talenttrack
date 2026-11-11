<?php
/**
 * Migration 0327 — a snapshot can be of a Report Studio report (#5021, epic #4564).
 *
 * A Studio report about one player or one team is frozen into the snapshot
 * table that subject already has, not into a third one: a parent's "shared
 * with me" list, the section notes and the "readable forever" rule then cover
 * both kinds of snapshot through the same code.
 *
 *   - `report_id`         — the Studio report it was taken from;
 *   - `report_version_id` — the published version it was taken of.
 *
 * Both nullable, and no backfill: every existing row is a player report or a
 * monthly report snapshot and keeps NULL. An index on `report_id` lets a
 * report list its own snapshots without reading every row of the subject.
 *
 * Additive and idempotent through `addColumnIfMissing` (never `dbDelta` on a
 * pre-existing table, per 0196). Run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0327_report_snapshots_studio_report';
    }

    public function up(): void {
        global $wpdb;

        foreach ( [ 'tt_player_report_snapshots', 'tt_team_report_snapshots' ] as $name ) {
            $table = $wpdb->prefix . $name;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                continue;
            }

            $columns = [
                [ 'report_id',         'BIGINT UNSIGNED NULL DEFAULT NULL', 'notes_json' ],
                [ 'report_version_id', 'BIGINT UNSIGNED NULL DEFAULT NULL', 'report_id' ],
            ];
            foreach ( $columns as [ $column, $definition, $after ] ) {
                if ( ! MigrationHelpers::addColumnIfMissing( $table, $column, $definition, $after ) ) {
                    throw new \RuntimeException( "0327: failed to add {$column} to {$name}" );
                }
            }

            // `ADD KEY` is not idempotent; the guard 0254 uses.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $exists = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'idx_report' ) );
            if ( empty( $exists ) ) {
                $this->exec( "ALTER TABLE {$table} ADD KEY idx_report (club_id, report_id)" );
            }
        }
    }
};
