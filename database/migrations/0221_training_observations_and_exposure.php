<?php
/**
 * Migration 0221 — training observations and per-player principle exposure
 * (#2500, epic #2493).
 *
 * Two tables that between them answer the question the Training module
 * exists for: *what has this player actually been taught?*
 *
 *   `tt_training_observations` — authored. What a coach saw one player do
 *   during one block of one training. The only place in the module where
 *   a human writes about a named child, which is why it is permission-
 *   gated and audit-friendly rather than a feed.
 *
 *   `tt_player_principle_exposure` — derived. Rebuilt nightly from runs,
 *   run blocks, exercise principle links and attendance. Never authored,
 *   so it can be truncated and rebuilt without losing anything.
 *
 * ## Why `rating` is nullable, and DECIMAL(3,1)
 *
 * Nullable because a note with no score is a legitimate observation and
 * on a wet Tuesday it is the common one — forcing a number would mean
 * coaches invent one, which is worse than no data.
 *
 * `DECIMAL(3,1)` because the scale is operator-configured (`rating_min` /
 * `rating_max` / `rating_step` in `tt_config`) and some installs use half
 * steps. An INT would silently round 7.5 to 8 on those installs.
 *
 * ## Why exposure is a table rather than a query
 *
 * The source query spans runs × run blocks × exercise principles ×
 * attendance, per player, per season. Running that on every page load of
 * the most trafficked view in the plugin is not viable; running it nightly
 * and storing the answer is. The UNIQUE key is what makes the rebuild
 * idempotent — the acceptance criterion is that running it twice changes
 * nothing.
 *
 * Both tables carry `club_id` per CLAUDE.md §4, and observations carry a
 * `uuid` because they are user-authored records that a SaaS migration
 * would need to keep identity for. Exposure does not: it is derived, so
 * it can be rebuilt on the other side rather than migrated.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0221_training_observations_and_exposure';
    }

    public function up(): void {
        global $wpdb;

        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // CREATE TABLE IF NOT EXISTS, not dbDelta on an existing table:
        // both tables are new here, and dbDelta against a pre-existing
        // table is what the migration-lint gate refuses.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_training_observations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            run_id BIGINT UNSIGNED NOT NULL,
            run_block_id BIGINT UNSIGNED DEFAULT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            principle_id BIGINT UNSIGNED DEFAULT NULL,
            football_action_id BIGINT UNSIGNED DEFAULT NULL,
            rating DECIMAL(3,1) DEFAULT NULL,
            note TEXT NULL,
            author_user_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club_player (club_id, player_id, created_at),
            KEY idx_run (run_id),
            KEY idx_run_block (run_block_id),
            KEY idx_principle (principle_id)
        ) {$charset};" );

        // No uuid: derived rows are rebuilt, not migrated. The UNIQUE key
        // is the identity, and it is what makes the nightly rebuild
        // idempotent.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_player_principle_exposure (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            player_id BIGINT UNSIGNED NOT NULL,
            principle_id BIGINT UNSIGNED NOT NULL,
            -- NOT NULL DEFAULT 0, deliberately, against the shape #2500's
            -- body specified. MySQL does not treat two NULLs as equal in a
            -- UNIQUE index, so a nullable season_id would let every
            -- nightly rebuild insert a second, third, fourth row for the
            -- same player and principle whenever the season could not be
            -- resolved — and 'the rebuild is idempotent' is this wave's
            -- load-bearing acceptance criterion. 0 means 'no season', and
            -- it collides with itself, which is the whole point.
            season_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            minutes_total INT UNSIGNED NOT NULL DEFAULT 0,
            sessions_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_trained_on DATE DEFAULT NULL,
            computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_player_principle_season (club_id, player_id, principle_id, season_id),
            KEY idx_club_player (club_id, player_id),
            KEY idx_club_principle (club_id, principle_id)
        ) {$charset};" );
    }
};
