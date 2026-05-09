<?php
/**
 * Migration 0088 — Exercises foundation tables (#0016 Sprint 1).
 *
 * Creates the four-table structure that #0016's photo-to-session
 * capture flow consumes. Sprint 1 only ships the schema + seeded
 * categories; Sprint 2 wires sessions to exercises (`tt_activity_exercises`),
 * Sprint 3-4 add the photo capture + AI extraction layer on top.
 *
 *   tt_exercise_categories   — warmup, rondo, conditioned-game,
 *                               finishing, set-piece, etc.
 *                               Seeded on first run; admin can extend.
 *   tt_exercises             — drill / exercise definitions with
 *                               versioning (superseded_by_id),
 *                               visibility ('club' / 'team' / 'private'),
 *                               and principles linkage.
 *   tt_exercise_principles   — M2M between tt_exercises and
 *                               tt_principles (#0006).
 *   tt_exercise_team_overrides — per-team opt-out / opt-in for
 *                                  visibility (matches the shaping
 *                                  decision: "club default with optional
 *                                  per-team overrides").
 *
 * Per CLAUDE.md §4 SaaS-readiness:
 *   - club_id NOT NULL DEFAULT 1 on every new tenant-scoped table
 *   - uuid CHAR(36) UNIQUE on the root entity (tt_exercises)
 *
 * Idempotent. Safe to re-run on already-migrated installs.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0088_exercises_foundation';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Categories — small, seeded, operator-extensible.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_exercise_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            slug VARCHAR(40) NOT NULL,
            label VARCHAR(120) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_category (club_id, slug)
        ) $charset;" );

        // Exercises — the core entity. Versioning via superseded_by_id;
        // historical sessions reference a specific row id, so editing
        // creates a new row with superseded_by_id pointing at the new one.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_exercises (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            category_id BIGINT UNSIGNED DEFAULT NULL,
            diagram_url VARCHAR(500) DEFAULT NULL,
            author_user_id BIGINT UNSIGNED DEFAULT NULL,
            visibility VARCHAR(20) NOT NULL DEFAULT 'club',
            version INT NOT NULL DEFAULT 1,
            superseded_by_id BIGINT UNSIGNED DEFAULT NULL,
            archived_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club_active (club_id, archived_at),
            KEY idx_category (category_id),
            KEY idx_author (author_user_id),
            KEY idx_supersession (superseded_by_id)
        ) $charset;" );

        // M2M between exercises and principles. tt_principles ships in
        // migration 0015 (#0006 methodology); the FK is logical, not
        // enforced — same pattern the rest of the codebase uses.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_exercise_principles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            exercise_id BIGINT UNSIGNED NOT NULL,
            principle_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_exercise_principle (club_id, exercise_id, principle_id),
            KEY idx_principle (principle_id)
        ) $charset;" );

        // Per-team visibility override. Default behaviour: an exercise
        // with visibility='club' is visible to every team. A row here
        // with is_enabled=0 hides it for that team; a row with
        // is_enabled=1 forces visibility for an exercise that's
        // visibility='team' or 'private' on a team that has been
        // explicitly opted in.
        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_exercise_team_overrides (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            exercise_id BIGINT UNSIGNED NOT NULL,
            team_id BIGINT UNSIGNED NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_exercise_team (club_id, exercise_id, team_id),
            KEY idx_team (team_id)
        ) $charset;" );

        // Seed the v1 category list. is_system=1 so the operator UI
        // can refuse deletion (these are referenced by AI extraction
        // prompts in Sprint 4).
        $seed = [
            [ 'warmup',           'Warm-up' ],
            [ 'rondo',            'Rondo' ],
            [ 'possession',       'Possession game' ],
            [ 'conditioned_game', 'Conditioned game' ],
            [ 'finishing',        'Finishing' ],
            [ 'set_piece',        'Set piece' ],
            [ 'cooldown',         'Cool-down' ],
            [ 'individual',       'Individual technical' ],
        ];
        $now = current_time( 'mysql' );
        foreach ( $seed as $i => [ $slug, $label ] ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_exercise_categories WHERE club_id = 1 AND slug = %s",
                $slug
            ) );
            if ( $exists ) continue;
            $wpdb->insert( "{$p}tt_exercise_categories", [
                'club_id'    => 1,
                'slug'       => $slug,
                'label'      => $label,
                'sort_order' => ( $i + 1 ) * 10,
                'is_system'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ] );
        }
    }
};
