<?php
/**
 * Migration 0039 — `tt_config` becomes tenant-scoped (#0052 PR-A).
 *
 * Two coordinated changes:
 *
 * 1. Schema reshape on `tt_config`:
 *      - Add `club_id INT UNSIGNED NOT NULL DEFAULT 1` column.
 *      - Drop existing `PRIMARY KEY (config_key)`.
 *      - Add new `PRIMARY KEY (club_id, config_key)`.
 *
 *    Existing rows pick up `club_id = 1` from the column default. Code
 *    that reads `tt_config` already filters by `config_key`; this
 *    migration is invisible at runtime today because `CurrentClub::id()`
 *    returns `1`. Tomorrow when SaaS migration lands, `(club_id,
 *    config_key)` is the natural per-tenant scope.
 *
 * 2. Tenant-scoped `wp_options` move into `tt_config`:
 *      - tt_trial_acceptance_club_address
 *      - tt_trial_acceptance_response_days
 *      - tt_trial_admittance_include_acceptance_slip
 *
 *    Each option's value is copied to `tt_config` keyed by
 *    `(1, <option_name>)`. The `wp_options` row is left in place so a
 *    rollback is a one-line code change; cleanup of the wp_options rows
 *    is a separate follow-up.
 *
 *    `tt_installed_version` stays in wp_options (install-global).
 *    `tt_wizard_*_<slug>` analytics counters stay in wp_options for now
 *    (per-club analytics is a separate refactor; documented as a known
 *    gap in `docs/access-control.md` § Known SaaS-readiness gaps).
 *
 * Idempotent: column add gated on SHOW COLUMNS, primary-key swap gated
 * on inspecting the existing key, wp_options copy gated on `INSERT
 * IGNORE` against the composite PK.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0039_tt_config_tenancy';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_config";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            // tt_config doesn't exist on this install — nothing to do.
            return;
        }

        $this->reshapePrimaryKey( $table );
        $this->migrateTenantScopedOptions();
    }

    private function reshapePrimaryKey( string $table ): void {
        global $wpdb;

        $has_club_id = $wpdb->get_row( $wpdb->prepare(
            "SHOW COLUMNS FROM `$table` LIKE %s",
            'club_id'
        ) );

        if ( ! $has_club_id ) {
            $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `club_id` INT UNSIGNED NOT NULL DEFAULT 1 FIRST" );
        }

        // Inspect the current primary key. If it's already (club_id,
        // config_key) we're done; otherwise drop + recreate.
        $pk_columns = $wpdb->get_col( $wpdb->prepare(
            "SELECT COLUMN_NAME
               FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = %s
                AND INDEX_NAME = 'PRIMARY'
              ORDER BY SEQ_IN_INDEX ASC",
            $table
        ) );

        $current = implode( ',', array_map( 'strtolower', (array) $pk_columns ) );
        if ( $current === 'club_id,config_key' ) {
            return; // already migrated
        }

        // Drop existing PK then add the composite one. Wrapped in a
        // transaction-like best-effort: if the drop succeeds but the add
        // fails, the table is left without a PK, which `tt_config` can
        // tolerate (config_key still has unique-content semantics
        // application-side); a re-run will recreate the composite PK
        // because $current will read as empty.
        if ( $current !== '' ) {
            $wpdb->query( "ALTER TABLE `$table` DROP PRIMARY KEY" );
        }
        $wpdb->query( "ALTER TABLE `$table` ADD PRIMARY KEY (`club_id`, `config_key`)" );
    }

    private function migrateTenantScopedOptions(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_config';

        $tenant_scoped = [
            'tt_trial_acceptance_club_address',
            'tt_trial_acceptance_response_days',
            'tt_trial_admittance_include_acceptance_slip',
        ];

        foreach ( $tenant_scoped as $option_name ) {
            $value = get_option( $option_name, null );
            if ( $value === null ) continue;

            // Coerce to a storable string. tt_config stores LONGTEXT.
            $stored = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );

            // INSERT IGNORE so re-running is a no-op when the row is
            // already there from a previous migration pass. Composite PK
            // (club_id, config_key) carries the uniqueness.
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO `$table` (club_id, config_key, config_value) VALUES (%d, %s, %s)",
                1, $option_name, $stored
            ) );
        }
    }
};
