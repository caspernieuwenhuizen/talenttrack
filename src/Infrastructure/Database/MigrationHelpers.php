<?php
namespace TT\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MigrationHelpers — schema-drift-safe ALTER helpers.
 *
 * Our migration system was originally designed around CREATE TABLE IF NOT EXISTS,
 * which is fine for new tables but doesn't help with evolving existing tables.
 * v2.6.2 introduces column-addition migrations; these helpers make them idempotent
 * (safe to re-run) and tolerant of sites whose schema has drifted from a previous
 * version (e.g., a v1.x table that never received v2.x columns).
 */
class MigrationHelpers {

    /**
     * Return true if the column exists on the table.
     */
    public static function columnExists( string $table, string $column ): bool {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SHOW COLUMNS FROM `$table` LIKE %s",
            $column
        );
        $row = $wpdb->get_row( $sql );
        return $row !== null;
    }

    /**
     * Add a column iff missing. Returns true if the column exists afterwards.
     * The $definition argument is the raw SQL fragment (e.g. "BIGINT UNSIGNED NULL").
     * Position clauses ("AFTER column_name") may be appended; if the referenced
     * column doesn't exist yet (e.g. schema drift), we retry without the clause.
     */
    public static function addColumnIfMissing( string $table, string $column, string $definition, string $after = '' ): bool {
        global $wpdb;
        if ( self::columnExists( $table, $column ) ) {
            return true;
        }
        $after_clause = $after !== '' && self::columnExists( $table, $after ) ? " AFTER `$after`" : '';
        $sql = "ALTER TABLE `$table` ADD COLUMN `$column` $definition{$after_clause}";
        $result = $wpdb->query( $sql );
        if ( $result === false ) {
            // Re-raise for the migration runner to log.
            error_log( "[TalentTrack] Failed to add column $column to $table: " . $wpdb->last_error );
            return false;
        }
        return true;
    }

    /**
     * Relax NOT NULL on a column if it is currently NOT NULL. No-op if already nullable.
     */
    public static function makeColumnNullable( string $table, string $column, string $definition ): bool {
        global $wpdb;
        if ( ! self::columnExists( $table, $column ) ) {
            return true; // nothing to do
        }
        $row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $column ) );
        if ( ! $row || ( $row->Null ?? '' ) === 'YES' ) {
            return true;
        }
        $sql = "ALTER TABLE `$table` MODIFY COLUMN `$column` $definition";
        $result = $wpdb->query( $sql );
        if ( $result === false ) {
            error_log( "[TalentTrack] Failed to modify column $column on $table: " . $wpdb->last_error );
            return false;
        }
        return true;
    }

    /**
     * Has the matrix, or an academy, already decided this persona's cell on
     * this entity? A seed top-up that adds rows asks this first and leaves a
     * decided cell exactly as it is.
     *
     * A cell counts as decided when the matrix holds a row for the persona
     * on the entity at any scope or activity, or when
     * `tt_authorization_changelog` records an edit to it after the latest
     * reset or import (either replaces the whole matrix, so an edit made
     * before it no longer stands).
     */
    public static function matrixCellChanged( string $persona, string $entity ): bool {
        global $wpdb;

        $held = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_authorization_matrix WHERE persona = %s AND entity = %s",
            $persona,
            $entity
        ) );
        if ( $held > 0 ) return true;

        $changelog = $wpdb->prefix . 'tt_authorization_changelog';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $changelog ) ) !== $changelog ) {
            return false;
        }

        $since = (int) $wpdb->get_var(
            "SELECT COALESCE( MAX(id), 0 ) FROM {$wpdb->prefix}tt_authorization_changelog WHERE change_type IN ( 'reset', 'bulk_import' )"
        );

        $edits = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_authorization_changelog WHERE persona = %s AND entity = %s AND id > %d",
            $persona,
            $entity,
            $since
        ) );
        return $edits > 0;
    }
}
