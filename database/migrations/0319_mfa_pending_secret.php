<?php
/**
 * Migration 0319 — pending second factor on `tt_user_mfa` (#4943).
 *
 * Setting up two-step verification again used to overwrite the working
 * secret as soon as the QR code was shown, so an abandoned wizard left the
 * account on a secret nobody had confirmed. The new secret and its backup
 * codes are now staged in these columns and promoted into the working ones
 * only when the person finishes the wizard. A first enrolment goes through
 * the same staging.
 *
 *   - `pending_secret_encrypted` — under `CredentialEncryption`, as the
 *     working secret is.
 *   - `pending_backup_codes_hashed` — the same JSON shape as
 *     `backup_codes_hashed`.
 *   - `pending_created_at` — a staged secret older than an hour is treated
 *     as absent.
 *
 * All nullable, no backfill. Idempotent: each column is added only when
 * absent. Forward-only. Run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0319_mfa_pending_secret';
    }

    public function up(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_user_mfa";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_user_mfa is missing; nothing to add' );
            return;
        }

        $columns = [
            'pending_secret_encrypted'    => 'TEXT DEFAULT NULL',
            'pending_backup_codes_hashed' => 'LONGTEXT DEFAULT NULL',
            'pending_created_at'          => 'DATETIME DEFAULT NULL',
        ];
        $added = 0;
        foreach ( $columns as $column => $definition ) {
            if ( MigrationHelpers::columnExists( $table, $column ) ) continue;
            $this->exec( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
            $added++;
        }

        $this->report( $added, sprintf( '%d pending column(s) added to tt_user_mfa', $added ) );
    }
};
