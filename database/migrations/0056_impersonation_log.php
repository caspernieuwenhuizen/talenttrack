<?php
/**
 * Migration 0056 — Impersonation log (#0071 child 5).
 *
 * Audit table for the `tt_impersonation_log` rows the
 * ImpersonationService writes on start/end. Separate from
 * `tt_authorization_changelog` because they record different
 * domains (matrix-config edits vs authentication events) and
 * conflating them would muddy queries.
 *
 * Idempotent.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0056_impersonation_log';
    }

    public function up(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_impersonation_log";
        $c     = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id BIGINT UNSIGNED NOT NULL,
            target_user_id BIGINT UNSIGNED NOT NULL,
            club_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            started_at DATETIME NOT NULL,
            ended_at DATETIME DEFAULT NULL,
            end_reason VARCHAR(20) DEFAULT NULL,
            actor_ip VARCHAR(45) DEFAULT NULL,
            actor_user_agent VARCHAR(255) DEFAULT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_actor_time (actor_user_id, started_at),
            KEY idx_target (target_user_id),
            KEY idx_club (club_id),
            KEY idx_active (ended_at)
        ) {$c}";

        dbDelta( $sql );
    }
};
