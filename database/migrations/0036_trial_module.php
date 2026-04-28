<?php
/**
 * Migration 0036 — Trial player module (#0017 sprints 1+3+4+6).
 *
 * Six tables for the full trial workflow:
 *
 *   tt_trial_cases             — one per trialing player, status open/extended/decided/archived
 *   tt_trial_tracks            — track templates (Standard / Scout / Goalkeeper seeded)
 *   tt_trial_case_staff        — assigned staff per case, optional role label
 *   tt_trial_extensions        — audit trail for extensions (justification mandatory)
 *   tt_trial_case_staff_inputs — per-staff evaluation submissions, draft/submitted/released states
 *   tt_trial_letter_templates  — per-locale customizations of the three letter templates
 *
 * Plus an `acceptance_slip_returned_at` column on tt_trial_cases for
 * the optional acceptance-slip workflow.
 *
 * Idempotent. CREATE TABLE IF NOT EXISTS + ALTER guard via dbDelta.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0036_trial_module';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $cases = "CREATE TABLE IF NOT EXISTS {$p}tt_trial_cases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) DEFAULT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            track_id BIGINT UNSIGNED NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'open',
            extension_count INT UNSIGNED NOT NULL DEFAULT 0,
            decision VARCHAR(32) DEFAULT NULL,
            decision_made_at DATETIME DEFAULT NULL,
            decision_made_by BIGINT UNSIGNED DEFAULT NULL,
            decision_notes TEXT DEFAULT NULL,
            strengths_summary TEXT DEFAULT NULL,
            growth_areas TEXT DEFAULT NULL,
            inputs_released_at DATETIME DEFAULT NULL,
            inputs_released_by BIGINT UNSIGNED DEFAULT NULL,
            acceptance_slip_returned_at DATETIME DEFAULT NULL,
            acceptance_slip_returned_by BIGINT UNSIGNED DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by BIGINT UNSIGNED NOT NULL,
            archived_at DATETIME DEFAULT NULL,
            archived_by BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_player (player_id),
            KEY idx_status (status),
            KEY idx_dates (start_date, end_date),
            KEY idx_track (track_id),
            UNIQUE KEY uk_uuid (uuid)
        ) $charset;";
        dbDelta( $cases );

        $tracks = "CREATE TABLE IF NOT EXISTS {$p}tt_trial_tracks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            slug VARCHAR(64) NOT NULL,
            name VARCHAR(128) NOT NULL,
            description TEXT,
            default_duration_days INT UNSIGNED NOT NULL DEFAULT 28,
            is_seeded TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_slug (slug)
        ) $charset;";
        dbDelta( $tracks );

        $case_staff = "CREATE TABLE IF NOT EXISTS {$p}tt_trial_case_staff (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role_label VARCHAR(64) DEFAULT NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            unassigned_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_case (case_id),
            KEY idx_user (user_id),
            UNIQUE KEY uk_case_user (case_id, user_id)
        ) $charset;";
        dbDelta( $case_staff );

        $extensions = "CREATE TABLE IF NOT EXISTS {$p}tt_trial_extensions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id BIGINT UNSIGNED NOT NULL,
            previous_end_date DATE NOT NULL,
            new_end_date DATE NOT NULL,
            justification TEXT NOT NULL,
            extended_by BIGINT UNSIGNED NOT NULL,
            extended_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_case (case_id)
        ) $charset;";
        dbDelta( $extensions );

        $inputs = "CREATE TABLE IF NOT EXISTS {$p}tt_trial_case_staff_inputs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            submitted_at DATETIME DEFAULT NULL,
            category_ratings_json LONGTEXT DEFAULT NULL,
            overall_rating DECIMAL(3,2) DEFAULT NULL,
            free_text_notes TEXT DEFAULT NULL,
            released_at DATETIME DEFAULT NULL,
            released_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_case_user (case_id, user_id),
            UNIQUE KEY uk_case_user (case_id, user_id)
        ) $charset;";
        dbDelta( $inputs );

        $letter_templates = "CREATE TABLE IF NOT EXISTS {$p}tt_trial_letter_templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            template_key VARCHAR(64) NOT NULL,
            locale VARCHAR(10) NOT NULL,
            html_content LONGTEXT NOT NULL,
            is_customized TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_template_locale (template_key, locale)
        ) $charset;";
        dbDelta( $letter_templates );

        $this->seedTracks();
    }

    private function seedTracks(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_trial_tracks';

        $seeds = [
            [
                'slug' => 'standard',
                'name' => 'Standard',
                'description' => 'Typical youth-field trial — four weeks of training and games.',
                'default_duration_days' => 28,
                'sort_order' => 10,
            ],
            [
                'slug' => 'scout',
                'name' => 'Scout',
                'description' => 'Shorter, more focused assessment for scout-flagged players.',
                'default_duration_days' => 14,
                'sort_order' => 20,
            ],
            [
                'slug' => 'goalkeeper',
                'name' => 'Goalkeeper',
                'description' => 'Goalkeeper-specific evaluation focus over four weeks.',
                'default_duration_days' => 28,
                'sort_order' => 30,
            ],
        ];

        foreach ( $seeds as $seed ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE slug = %s LIMIT 1",
                $seed['slug']
            ) );
            if ( $exists ) continue;

            $wpdb->insert( $table, [
                'slug'                  => $seed['slug'],
                'name'                  => $seed['name'],
                'description'           => $seed['description'],
                'default_duration_days' => $seed['default_duration_days'],
                'is_seeded'             => 1,
                'sort_order'            => $seed['sort_order'],
            ] );
        }
    }
};
