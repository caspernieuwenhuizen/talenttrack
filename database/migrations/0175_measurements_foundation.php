<?php
/**
 * Migration 0175 — Measurements & Testing foundation (#1856, epic #1854).
 *
 * Stands up the schema for the Measurements module: an academy defines
 * tests in editable categories with a recurrence, schedules team testing
 * sessions, records one value per player, and flags results against
 * per-age-group target bands.
 *
 * Tables (each carries the tenancy scaffold — `club_id INT UNSIGNED
 * DEFAULT 1` + `uuid VARCHAR(36)` unique — per CLAUDE.md §4 and the
 * 0038 precedent, and an `archived_at` / `archived_by` soft-delete pair
 * so the #1782 archive + referential-integrity delete framework drives
 * them generically):
 *
 *   - tt_measurement_definitions — a test (category, value type, unit,
 *     recurrence, direction).
 *   - tt_measurement_targets     — per-age-group green/amber bands.
 *   - tt_measurement_sessions    — a planned testing session for a team.
 *   - tt_measurement_results     — one recorded value for one player.
 *
 * Lookups (admin-editable; Dutch + English labels written straight to
 * `tt_translations`, never the dropped JSON column — the #902 lesson):
 *
 *   - measurement_category — Anthropometric / Physical / Technical / Mental.
 *   - measurement_unit     — proper units of measure (m, cm, kg, g, s,
 *                            min, reps, level, %, bpm). A definition picks
 *                            one of these OR supplies a custom unit string.
 *
 * Idempotent; additive; forward-only. Run alone (schema migration).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0175_measurements_foundation';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $this->exec(
            "CREATE TABLE IF NOT EXISTS {$p}tt_measurement_definitions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid VARCHAR(36) DEFAULT NULL,
                category_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(190) NOT NULL,
                value_type ENUM('numeric','scale','passfail') NOT NULL DEFAULT 'numeric',
                unit VARCHAR(50) DEFAULT NULL,
                scale_min DECIMAL(10,3) DEFAULT NULL,
                scale_max DECIMAL(10,3) DEFAULT NULL,
                frequency VARCHAR(20) NOT NULL DEFAULT 'adhoc',
                direction ENUM('higher','lower','neutral') NOT NULL DEFAULT 'higher',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                archived_by BIGINT UNSIGNED DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                KEY idx_club (club_id),
                KEY idx_category (category_id)
            ) {$charset}"
        );

        $this->exec(
            "CREATE TABLE IF NOT EXISTS {$p}tt_measurement_targets (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid VARCHAR(36) DEFAULT NULL,
                definition_id BIGINT UNSIGNED NOT NULL,
                age_group VARCHAR(20) NOT NULL DEFAULT '',
                green_min DECIMAL(12,3) DEFAULT NULL,
                green_max DECIMAL(12,3) DEFAULT NULL,
                amber_min DECIMAL(12,3) DEFAULT NULL,
                amber_max DECIMAL(12,3) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                archived_by BIGINT UNSIGNED DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                UNIQUE KEY uniq_def_age (definition_id, age_group),
                KEY idx_club (club_id),
                KEY idx_definition (definition_id)
            ) {$charset}"
        );

        $this->exec(
            "CREATE TABLE IF NOT EXISTS {$p}tt_measurement_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid VARCHAR(36) DEFAULT NULL,
                definition_id BIGINT UNSIGNED NOT NULL,
                team_id BIGINT UNSIGNED NOT NULL,
                planned_date DATE NOT NULL,
                status ENUM('planned','completed','cancelled') NOT NULL DEFAULT 'planned',
                notes TEXT DEFAULT NULL,
                created_by BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                archived_by BIGINT UNSIGNED DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                KEY idx_club (club_id),
                KEY idx_definition (definition_id),
                KEY idx_team (team_id)
            ) {$charset}"
        );

        $this->exec(
            "CREATE TABLE IF NOT EXISTS {$p}tt_measurement_results (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid VARCHAR(36) DEFAULT NULL,
                player_id BIGINT UNSIGNED NOT NULL,
                definition_id BIGINT UNSIGNED NOT NULL,
                measurement_session_id BIGINT UNSIGNED DEFAULT NULL,
                recorded_date DATE NOT NULL,
                value_numeric DECIMAL(12,3) DEFAULT NULL,
                value_text VARCHAR(190) DEFAULT NULL,
                recorded_by BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                archived_by BIGINT UNSIGNED DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                KEY idx_club (club_id),
                KEY idx_player (player_id),
                KEY idx_definition (definition_id),
                KEY idx_msession (measurement_session_id)
            ) {$charset}"
        );

        $this->seedLookups();
    }

    /**
     * Seed the two admin-editable lookups + their Dutch/English labels.
     * Existence-checked on (club_id, lookup_type, name); INSERT IGNORE on
     * the translations natural key — idempotent on re-run.
     */
    private function seedLookups(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $lookups = $p . 'tt_lookups';
        $trans   = $p . 'tt_translations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) !== $lookups ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $trans ) ) !== $trans ) return;

        $club_id = 1;
        $now     = current_time( 'mysql', true );

        $categories = [
            [ 'name' => 'Anthropometric', 'sort_order' => 10, 'labels' => [ 'en_US' => 'Anthropometric', 'nl_NL' => 'Antropometrie' ] ],
            [ 'name' => 'Physical',       'sort_order' => 20, 'labels' => [ 'en_US' => 'Physical',       'nl_NL' => 'Fysiek' ] ],
            [ 'name' => 'Technical',      'sort_order' => 30, 'labels' => [ 'en_US' => 'Technical',      'nl_NL' => 'Techniek' ] ],
            [ 'name' => 'Mental',         'sort_order' => 40, 'labels' => [ 'en_US' => 'Mental',         'nl_NL' => 'Mentaal' ] ],
        ];

        // Units: the stored name is the canonical symbol; the label is a
        // readable "<symbol> – <word>" rendered in the picker.
        $units = [
            [ 'name' => 'cm',    'sort_order' => 10, 'labels' => [ 'en_US' => 'cm (centimetre)', 'nl_NL' => 'cm (centimeter)' ] ],
            [ 'name' => 'm',     'sort_order' => 20, 'labels' => [ 'en_US' => 'm (metre)',       'nl_NL' => 'm (meter)' ] ],
            [ 'name' => 'kg',    'sort_order' => 30, 'labels' => [ 'en_US' => 'kg (kilogram)',   'nl_NL' => 'kg (kilogram)' ] ],
            [ 'name' => 'g',     'sort_order' => 40, 'labels' => [ 'en_US' => 'g (gram)',        'nl_NL' => 'g (gram)' ] ],
            [ 'name' => 's',     'sort_order' => 50, 'labels' => [ 'en_US' => 's (seconds)',     'nl_NL' => 's (seconden)' ] ],
            [ 'name' => 'min',   'sort_order' => 60, 'labels' => [ 'en_US' => 'min (minutes)',   'nl_NL' => 'min (minuten)' ] ],
            [ 'name' => 'reps',  'sort_order' => 70, 'labels' => [ 'en_US' => 'reps',            'nl_NL' => 'herhalingen' ] ],
            [ 'name' => 'level', 'sort_order' => 80, 'labels' => [ 'en_US' => 'level',           'nl_NL' => 'niveau' ] ],
            [ 'name' => '%',     'sort_order' => 90, 'labels' => [ 'en_US' => '%',               'nl_NL' => '%' ] ],
            [ 'name' => 'bpm',   'sort_order' => 100,'labels' => [ 'en_US' => 'bpm',             'nl_NL' => 'bpm' ] ],
        ];

        foreach ( [ 'measurement_category' => $categories, 'measurement_unit' => $units ] as $type => $seeds ) {
            foreach ( $seeds as $seed ) {
                $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$lookups}
                      WHERE club_id = %d AND lookup_type = %s AND name = %s LIMIT 1",
                    $club_id, $type, $seed['name']
                ) );

                if ( $existing_id <= 0 ) {
                    $wpdb->insert( $lookups, [
                        'club_id'     => $club_id,
                        'lookup_type' => $type,
                        'name'        => (string) $seed['name'],
                        'sort_order'  => (int) $seed['sort_order'],
                    ] );
                    $lookup_id = (int) $wpdb->insert_id;
                } else {
                    $lookup_id = $existing_id;
                }
                if ( $lookup_id <= 0 ) continue;

                foreach ( $seed['labels'] as $locale => $value ) {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT IGNORE INTO {$trans}
                           (club_id, entity_type, entity_id, field, locale, value, updated_at)
                         VALUES (%d, 'lookup', %d, 'name', %s, %s, %s)",
                        $club_id, $lookup_id, $locale, $value, $now
                    ) );
                }
            }
        }
    }
};
