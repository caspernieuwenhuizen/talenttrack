<?php
/**
 * Migration 0025 — Invitation flow staging tables (#0032).
 *
 * Two tables:
 *
 *  - `tt_invitations` — the invitation rows. Token is the credential;
 *    32-char URL-safe random (~192 bits of entropy).
 *  - `tt_player_parents` — many-to-many pivot replacing the single-
 *    column `tt_players.parent_user_id`. Backfilled from existing rows
 *    on first run with `is_primary = 1`.
 *
 * `tt_players.parent_user_id` stays in place as a derived "primary
 * parent" shortcut so #0022's `PlayerOrParentResolver` keeps working
 * unchanged. The InvitationService re-projects the pivot's
 * `is_primary = 1` row into the column on every write.
 *
 * Plus a new WP role: `tt_parent`. Capabilities:
 *  - `read` (always)
 *  - `tt_view_parent_dashboard` — gates the "Children" group on the
 *    tile grid, scoped to the linked players via the pivot.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0025_invitations';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_invitations" ) ) !== "{$p}tt_invitations" ) {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_invitations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token VARCHAR(64) NOT NULL,
                kind VARCHAR(20) NOT NULL DEFAULT 'player',
                target_player_id BIGINT UNSIGNED NULL,
                target_person_id BIGINT UNSIGNED NULL,
                target_team_id BIGINT UNSIGNED NULL,
                target_functional_role_key VARCHAR(64) NULL,
                prefill_first_name VARCHAR(100) NULL,
                prefill_last_name VARCHAR(100) NULL,
                prefill_email VARCHAR(255) NULL,
                locale VARCHAR(10) NULL,
                created_by BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                accepted_at DATETIME NULL,
                accepted_user_id BIGINT UNSIGNED NULL,
                revoked_at DATETIME NULL,
                revoked_by BIGINT UNSIGNED NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                PRIMARY KEY (id),
                UNIQUE KEY uniq_token (token),
                KEY idx_status (status),
                KEY idx_target_player (target_player_id),
                KEY idx_target_person (target_person_id),
                KEY idx_created_by (created_by, created_at)
            ) {$charset}" );
        }

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_player_parents" ) ) !== "{$p}tt_player_parents" ) {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_player_parents (
                player_id BIGINT UNSIGNED NOT NULL,
                parent_user_id BIGINT UNSIGNED NOT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (player_id, parent_user_id),
                KEY idx_parent (parent_user_id),
                KEY idx_primary (player_id, is_primary)
            ) {$charset}" );

            // Backfill from the existing tt_players.parent_user_id column
            // (added in migration 0021 by #0022). Each existing parent
            // becomes the primary parent of its player.
            $wpdb->query(
                "INSERT IGNORE INTO {$p}tt_player_parents (player_id, parent_user_id, is_primary)
                 SELECT id, parent_user_id, 1
                 FROM {$p}tt_players
                 WHERE parent_user_id IS NOT NULL"
            );
        }

        $this->ensureLocaleColumns();
        $this->ensureParentRole();
        $this->seedDefaultMessages();
    }

    /**
     * Add a `locale` column to tt_players + tt_people. Used by the
     * invitation locale precedence ("target row's locale wins"). Both
     * additive + nullable; existing rows are unaffected.
     */
    private function ensureLocaleColumns(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach ( [ 'tt_players', 'tt_people' ] as $tbl ) {
            $full = "{$p}{$tbl}";
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) {
                continue;
            }
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'locale'",
                $full
            ) );
            if ( $exists === null ) {
                $wpdb->query( "ALTER TABLE {$full} ADD COLUMN locale VARCHAR(10) DEFAULT NULL" );
            }
        }
    }

    /**
     * Add the new `tt_parent` WP role with read + the new
     * `tt_view_parent_dashboard` capability. Existing rows continue
     * working — the migration only adds, never strips.
     */
    private function ensureParentRole(): void {
        $existing = get_role( 'tt_parent' );
        if ( ! $existing ) {
            add_role( 'tt_parent', __( 'Parent', 'talenttrack' ), [
                'read'                       => true,
                'tt_view_parent_dashboard'   => true,
            ] );
        } else {
            if ( ! $existing->has_cap( 'tt_view_parent_dashboard' ) ) {
                $existing->add_cap( 'tt_view_parent_dashboard' );
            }
        }

        // Administrator + tt_head_dev should also have the cap so they can
        // see what parents see (debugging + support).
        foreach ( [ 'administrator', 'tt_head_dev' ] as $slug ) {
            $role = get_role( $slug );
            if ( $role && ! $role->has_cap( 'tt_view_parent_dashboard' ) ) {
                $role->add_cap( 'tt_view_parent_dashboard' );
            }
        }
    }

    /**
     * Seed the six default WhatsApp message templates into tt_config.
     * Idempotent — only writes the key if it doesn't already exist, so
     * a club admin's edits are preserved across re-installs.
     */
    private function seedDefaultMessages(): void {
        $defaults = [
            // English
            'invite_message_player_en_US' => "Hi! {sender} has invited you to join {club} on TalentTrack as a player in {team}. Set up your account here: {url} — the link is valid for {ttl_days} days.",
            'invite_message_parent_en_US' => "Hi! {sender} has invited you to follow {player}'s development at {club} on TalentTrack. Set up your account here: {url} — the link is valid for {ttl_days} days.",
            'invite_message_staff_en_US'  => "Hi! {sender} has invited you to join {club} on TalentTrack as {role} for {team}. Set up your account here: {url} — the link is valid for {ttl_days} days.",
            // Dutch
            'invite_message_player_nl_NL' => "Hoi! {sender} heeft je uitgenodigd om bij {club} op TalentTrack te komen als speler in {team}. Stel hier je account in: {url} — de link is {ttl_days} dagen geldig.",
            'invite_message_parent_nl_NL' => "Hoi! {sender} heeft je uitgenodigd om de ontwikkeling van {player} te volgen bij {club} op TalentTrack. Stel hier je account in: {url} — de link is {ttl_days} dagen geldig.",
            'invite_message_staff_nl_NL'  => "Hoi! {sender} heeft je uitgenodigd om bij {club} op TalentTrack te komen als {role} voor {team}. Stel hier je account in: {url} — de link is {ttl_days} dagen geldig.",
            // Defaults for the resolver fallback chain.
            'invite_default_locale'       => 'nl_NL',
            'invite_token_ttl_days'       => '14',
        ];

        global $wpdb;
        $p = $wpdb->prefix;
        $config_table = "{$p}tt_config";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $config_table ) ) !== $config_table ) {
            return;
        }

        foreach ( $defaults as $key => $value ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT config_value FROM {$config_table} WHERE config_key = %s",
                $key
            ) );
            if ( $existing === null ) {
                $wpdb->insert( $config_table, [
                    'config_key'   => $key,
                    'config_value' => $value,
                ] );
            }
        }
    }
};
