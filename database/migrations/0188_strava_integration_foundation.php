<?php
/**
 * Migration 0188 — Strava integration foundation (#2055, epic #2002).
 *
 * Stands up the schema two later children build on: per-player Strava
 * account connections (encrypted OAuth tokens) and a player-scoped
 * activity store for the personal training those connections import.
 *
 * Strava activities are an athlete's *own* runs / rides / conditioning
 * work — distinct from `tt_activities`, which are team sessions. They
 * land on a dedicated `tt_player_activities` table so the two never
 * share a row shape and a player's personal load can be queried on its
 * own timeline (epic §1 — the journey is the narrative).
 *
 * Tables (each carries the tenancy scaffold — `club_id INT UNSIGNED
 * DEFAULT 1` + `uuid VARCHAR(36)` unique — per CLAUDE.md §4):
 *
 *   - tt_player_strava_connections — one row per connected player; OAuth
 *     access + refresh tokens stored encrypted at rest (the column holds
 *     a `CredentialEncryption` envelope, not plaintext). The rotating
 *     refresh token is persisted here atomically alongside the access
 *     token (#2057).
 *   - tt_player_activities — imported activity entries, upserted by
 *     `(source, external_id)` and soft-archived on delete / deauth
 *     (#2058). Non-HR / non-biometric metrics ONLY: distance, duration,
 *     pace/speed, elevation. There is deliberately NO heart-rate column
 *     — Strava blocks HR for under-16s and the academy cohort is mostly
 *     minors (Gate 1, resolved 2026-06-28), so HR never enters the model.
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS); additive; forward-only. Run
 * alone — schema migration.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0188_strava_integration_foundation';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        // Per-player connection + encrypted OAuth token store. One
        // connection per player per tenant (uniq_player). The token
        // columns hold CredentialEncryption envelopes; TEXT, not
        // VARCHAR, so a re-keyed/longer envelope never truncates.
        $this->exec(
            "CREATE TABLE IF NOT EXISTS {$p}tt_player_strava_connections (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid VARCHAR(36) DEFAULT NULL,
                player_id BIGINT UNSIGNED NOT NULL,
                strava_athlete_id BIGINT UNSIGNED DEFAULT NULL,
                access_token_enc TEXT DEFAULT NULL,
                refresh_token_enc TEXT DEFAULT NULL,
                token_expires_at DATETIME DEFAULT NULL,
                scope VARCHAR(255) DEFAULT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'connected',
                consent_at DATETIME DEFAULT NULL,
                consent_by BIGINT UNSIGNED DEFAULT NULL,
                connected_at DATETIME DEFAULT NULL,
                last_sync_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                UNIQUE KEY uniq_player (club_id, player_id),
                KEY idx_club (club_id),
                KEY idx_athlete (strava_athlete_id),
                KEY idx_status (status)
            ) {$charset}"
        );

        // Player-scoped imported activities. Upsert key is
        // (source, external_id) within a club; soft-archive via
        // archived_at on delete/deauth. No HR / biometric columns by
        // design (Gate 1).
        $this->exec(
            "CREATE TABLE IF NOT EXISTS {$p}tt_player_activities (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid VARCHAR(36) DEFAULT NULL,
                player_id BIGINT UNSIGNED NOT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'strava',
                external_id VARCHAR(191) NOT NULL,
                activity_type VARCHAR(64) DEFAULT NULL,
                name VARCHAR(255) DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                distance_m DECIMAL(12,2) DEFAULT NULL,
                moving_time_s INT UNSIGNED DEFAULT NULL,
                elapsed_time_s INT UNSIGNED DEFAULT NULL,
                average_speed_ms DECIMAL(8,3) DEFAULT NULL,
                total_elevation_gain_m DECIMAL(10,2) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                archived_by BIGINT UNSIGNED DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                UNIQUE KEY uniq_source_ext (club_id, source, external_id),
                KEY idx_club (club_id),
                KEY idx_player (player_id),
                KEY idx_started (started_at),
                KEY idx_source (source)
            ) {$charset}"
        );
    }

    public function down(): void {
        // Forward-only. Dropping these tables would lose every connected
        // player's encrypted tokens and imported training history; a
        // genuine rollback restores from backup. The tables are inert
        // when the feature is unused.
    }
};
