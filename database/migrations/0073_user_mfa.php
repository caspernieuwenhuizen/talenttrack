<?php
/**
 * Migration 0073 — `tt_user_mfa` (#0086 Workstream B Child 1, sprint 1).
 *
 * Holds per-WP-user MFA enrollment state: encrypted TOTP secret, hashed
 * backup codes, optional remembered-device cookies. Sprint 1 ships the
 * schema + repository + TotpService + BackupCodesService + Account-page
 * status indicator. Sprint 2 ships the 4-step enrollment wizard. Sprint 3
 * ships the WordPress `authenticate` filter integration + per-club
 * `require_mfa_for_personas` enforcement + rate-limited verification +
 * "remember device" cookie issuance.
 *
 *   - `secret_encrypted` is the base32-encoded TOTP shared secret stored
 *     under `CredentialEncryption` (AES-256-GCM via `wp_salt('auth')`).
 *     A DB dump alone cannot reconstruct the secret; shell access can.
 *     Same threat model as Spond credentials and VAPID push keys.
 *   - `backup_codes_hashed` is JSON: `[ { hash: <password_hash>, used_at: null|datetime } ]`.
 *     Each code single-use; `used_at` set on first verification.
 *   - `remembered_devices` is JSON: `[ { signed_token: "...", device_label: "...", expires_at: datetime, last_used_at: datetime } ]`.
 *     Issued by Sprint 3 when a user ticks "remember this device for 30 days"
 *     after a successful MFA verification. Sprint 1 ships the column shape;
 *     Sprint 3 fills it.
 *   - `enrolled_at` is set when a user completes the 4-step wizard (Sprint 2).
 *     A row exists from Sprint 2's wizard onward; pre-enrollment users have
 *     no row at all.
 *   - SaaS-readiness: `club_id` + `uuid` per CLAUDE.md §4.
 *
 * Idempotent. CREATE TABLE IF NOT EXISTS via dbDelta.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0073_user_mfa';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS {$p}tt_user_mfa (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) DEFAULT NULL,

            wp_user_id BIGINT UNSIGNED NOT NULL,

            secret_encrypted TEXT DEFAULT NULL,
            backup_codes_hashed LONGTEXT DEFAULT NULL,
            remembered_devices LONGTEXT DEFAULT NULL,

            enrolled_at DATETIME DEFAULT NULL,
            last_verified_at DATETIME DEFAULT NULL,
            failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME DEFAULT NULL,

            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,

            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            UNIQUE KEY uk_user (wp_user_id, club_id),
            KEY idx_club (club_id),
            KEY idx_enrolled (enrolled_at),
            KEY idx_locked (locked_until)
        ) $charset;";
        dbDelta( $sql );
    }
};
