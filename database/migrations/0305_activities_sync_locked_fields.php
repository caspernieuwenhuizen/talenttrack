<?php
/**
 * Migration 0305 — `sync_locked_fields` on `tt_activities` (#4485).
 *
 * A field group a person edits on a Spond-sourced activity is locked for
 * that activity, and the Spond sync stops writing it. The column holds a
 * JSON list of the locked groups (`title`, `date`, `times`, `location`,
 * see `SyncLockedFields`); NULL means nothing is locked.
 *
 * Additive + idempotent through MigrationHelpers::addColumnIfMissing;
 * forward-only; run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0305_activities_sync_locked_fields';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_activities';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_activities missing' );
            return;
        }

        if ( ! MigrationHelpers::addColumnIfMissing( $table, 'sync_locked_fields', 'VARCHAR(255) NULL DEFAULT NULL', 'external_id' ) ) {
            throw new \RuntimeException( 'Migration statement failed: adding tt_activities.sync_locked_fields — ' . $wpdb->last_error );
        }
    }
};
