<?php
/**
 * Migration 0057 — New Evaluation wizard infrastructure (#0072).
 *
 * Three pieces:
 *
 * 1. `tt_eval_categories.meta JSON` column (NULL by default). Mirrors the
 *    pattern `tt_lookups.meta` already uses; populated by the wizard
 *    surface's "Quick rate" checkbox + the seed below.
 *
 *    Also `tt_evaluations.activity_id BIGINT NULL` — the activity-first
 *    flow stamps this so reports + the player journey can join on it.
 *    NULL on player-first / ad-hoc evaluations.
 *
 * 2. Seed `meta.quick_rate = true` on the four conventional eval
 *    categories (Technical / Tactical / Physical / Mental — matched by
 *    `category_key` or label-prefix). Other categories remain in the
 *    deep-rate panel until a club flips them via the eval-categories
 *    admin page.
 *
 * 3. Seed `meta.rateable = false` on the well-known non-rateable
 *    activity-type lookup rows: `clinic`, `methodology`, `team_meeting`.
 *    All other activity_type rows are left unmodified — the read helper
 *    `QueryHelpers::isActivityTypeRateable()` defaults to `true` for
 *    unmarked rows so existing data stays rateable on upgrade.
 *
 * 4. New `tt_wizard_drafts` table (multi-tenant via `club_id`). Backs
 *    the persistent half of `WizardState`'s split-store extension —
 *    drafts that survive across browsers / devices / 14-day TTL.
 *
 * Idempotent.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0057_new_evaluation_wizard';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        // 1a. tt_eval_categories.meta column
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'meta'",
            "{$p}tt_eval_categories"
        ) );
        if ( $exists === null ) {
            $wpdb->query( "ALTER TABLE {$p}tt_eval_categories ADD COLUMN meta TEXT NULL DEFAULT NULL AFTER is_system" );
        }

        // 1b. tt_evaluations.activity_id column — the link the wizard's
        // activity-first path populates. NULL = ad-hoc / player-first.
        $exists_aid = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'activity_id'",
            "{$p}tt_evaluations"
        ) );
        if ( $exists_aid === null ) {
            $wpdb->query( "ALTER TABLE {$p}tt_evaluations ADD COLUMN activity_id BIGINT UNSIGNED DEFAULT NULL AFTER eval_type_id" );
            @$wpdb->query( "ALTER TABLE {$p}tt_evaluations ADD KEY idx_activity_id (activity_id)" );
        }

        // 2. Seed meta.quick_rate on the four conventional categories.
        // Match by lowercase prefix of `category_key` OR `label` so seed
        // works against any naming convention (technical, technique, …).
        $quick_keys = [ 'technical', 'tactical', 'physical', 'mental' ];
        $rows = $wpdb->get_results(
            "SELECT id, category_key, label, meta FROM {$p}tt_eval_categories WHERE parent_id IS NULL"
        );
        foreach ( (array) $rows as $row ) {
            $needle = strtolower( (string) ( $row->category_key ?? '' ) . ' ' . (string) ( $row->label ?? '' ) );
            $matches = false;
            foreach ( $quick_keys as $k ) {
                if ( strpos( $needle, $k ) !== false ) { $matches = true; break; }
            }
            if ( ! $matches ) continue;

            $existing = is_string( $row->meta ) && $row->meta !== '' ? json_decode( $row->meta, true ) : [];
            if ( ! is_array( $existing ) ) $existing = [];
            if ( ! empty( $existing['quick_rate'] ) ) continue; // already seeded

            $existing['quick_rate'] = true;
            $wpdb->update( "{$p}tt_eval_categories", [
                'meta' => wp_json_encode( $existing ),
            ], [ 'id' => (int) $row->id ] );
        }

        // 3. Seed meta.rateable=false on well-known non-rateable activity types.
        $non_rateable = [ 'clinic', 'methodology', 'team_meeting' ];
        foreach ( $non_rateable as $type_name ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, meta FROM {$p}tt_lookups WHERE lookup_type = 'activity_type' AND name = %s",
                $type_name
            ) );
            if ( ! $row ) continue;
            $existing = is_string( $row->meta ) && $row->meta !== '' ? json_decode( $row->meta, true ) : [];
            if ( ! is_array( $existing ) ) $existing = [];
            if ( array_key_exists( 'rateable', $existing ) && $existing['rateable'] === false ) continue;
            $existing['rateable'] = false;
            $wpdb->update( "{$p}tt_lookups", [
                'meta' => wp_json_encode( $existing ),
            ], [ 'id' => (int) $row->id ] );
        }

        // 4. tt_wizard_drafts table
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE IF NOT EXISTS {$p}tt_wizard_drafts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            club_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            wizard_slug VARCHAR(64) NOT NULL,
            state_json LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_user_wizard (user_id, wizard_slug),
            KEY idx_club (club_id),
            KEY idx_updated (updated_at)
        ) {$c}";
        dbDelta( $sql );
    }
};
