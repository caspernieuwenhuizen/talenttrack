<?php
/**
 * Migration 0007 — Custom fields positioning column.
 *
 * Sprint 1H (v2.11.0) — adds tt_custom_fields.insert_after to support
 * arbitrary field positioning (inserting a custom field between two
 * native fields on the entity's edit form). Existing rows get NULL,
 * which correctly means "append at end" — exactly where they appeared
 * before positioning existed, so no behavioural change for old fields.
 *
 * Explicit ALTER TABLE (not dbDelta) per the v2.10.1 learning that
 * dbDelta's ADD COLUMN behavior is unreliable on some hosts when the
 * surrounding CREATE TABLE also changes indexes in the same pass.
 *
 * Idempotent. Non-destructive. Safe to re-run.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0007_custom_fields_positioning';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $this->addColumnIfMissing(
            "{$p}tt_custom_fields",
            'insert_after',
            'VARCHAR(64) DEFAULT NULL',
            'is_active'
        );

        // Index on (entity_type, insert_after) speeds up the slot lookup
        // performed on every form render. Idempotent-safe: we check first.
        if ( ! $this->indexExists( "{$p}tt_custom_fields", 'idx_insert_after' ) ) {
            $wpdb->query( "ALTER TABLE {$p}tt_custom_fields ADD KEY idx_insert_after (entity_type, insert_after)" );
        }
    }

    /* ═══ inline helpers ═══ */

    private function columnExists( string $table, string $column ): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $column ) );
        return $row !== null;
    }

    private function indexExists( string $table, string $index ): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM `$table` WHERE Key_name = %s", $index ) );
        return $row !== null;
    }

    private function addColumnIfMissing( string $table, string $column, string $definition, string $after = '' ): bool {
        global $wpdb;
        if ( $this->columnExists( $table, $column ) ) {
            return true;
        }
        $after_clause = $after !== '' && $this->columnExists( $table, $after ) ? " AFTER `$after`" : '';
        return $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `$column` $definition{$after_clause}" ) !== false;
    }
};
