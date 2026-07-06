<?php
/**
 * Migration 0197 — per-team Spond credentials (#2286).
 *
 * Adds an optional per-team Spond account on tt_teams so a team can override
 * the club-wide login. When these are empty the team falls back to the club
 * account (see CredentialsManager::forTeam). The password + cached token are
 * stored as CredentialEncryption envelopes (TEXT — never truncate); the email
 * is plaintext (not secret alone), mirroring the club credential store.
 *
 * Additive + idempotent — column-add on an existing table via
 * MigrationHelpers::addColumnIfMissing (never dbDelta). Forward-only. Run
 * alone (schema migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0197_spond_per_team_credentials';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_teams';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing( $table, 'spond_email',             'VARCHAR(255) DEFAULT NULL', 'spond_group_id' );
        MigrationHelpers::addColumnIfMissing( $table, 'spond_password_enc',      'TEXT DEFAULT NULL',         'spond_email' );
        MigrationHelpers::addColumnIfMissing( $table, 'spond_token_enc',         'TEXT DEFAULT NULL',         'spond_password_enc' );
        MigrationHelpers::addColumnIfMissing( $table, 'spond_token_expires_at',  'BIGINT UNSIGNED DEFAULT NULL', 'spond_token_enc' );
    }
};
