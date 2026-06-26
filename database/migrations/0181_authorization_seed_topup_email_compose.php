<?php
/**
 * Migration: 0181_authorization_seed_topup_email_compose
 *
 * #1945 — backfill the new `email_compose` matrix action-entity (the
 * in-product mailer act, #0063) into `tt_authorization_matrix`. The seed
 * grants head_coach + assistant_coach + head_of_development +
 * academy_admin `rcd[global]` on `email_compose`; without this migration
 * the entity exists in the seed file but not in the live matrix, so the
 * cap bridge (`tt_send_email` → `email_compose:create_delete`) would
 * resolve to false for everyone once the matrix is active — silently
 * revoking the email-compose access the raw `tt_send_email` cap grants
 * today (administrator [bypass] + tt_head_dev + tt_coach + tt_club_admin).
 * `tt_coach` backs BOTH coach personas, so seeding both head_coach AND
 * assistant_coach keeps assistant coaches from losing the mailer (the
 * #1944 dual-persona trap).
 *
 * Scoped to the new entity only (same as
 * `0180_authorization_seed_topup_exercises`) so it never re-adds rows an
 * operator deliberately removed for other entities.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves any operator-edited
 * rows untouched and only adds the missing tuples.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0181_authorization_seed_topup_email_compose';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) return;

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) return;

        $sql = "INSERT IGNORE INTO {$table}
                  (persona, entity, activity, scope_kind, module_class, is_default)
                VALUES (%s, %s, %s, %s, %s, 1)";

        foreach ( $rows as $row ) {
            if ( ( $row['entity'] ?? '' ) !== 'email_compose' ) {
                continue;
            }
            $wpdb->query( $wpdb->prepare(
                $sql,
                (string) $row['persona'],
                (string) $row['entity'],
                (string) $row['activity'],
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }
    }
};
