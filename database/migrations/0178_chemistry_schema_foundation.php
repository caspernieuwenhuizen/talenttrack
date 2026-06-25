<?php
/**
 * Migration 0178 — Chemistry rework, Phase 1: schema foundation
 * (#1912, epic #1017).
 *
 * Stands up the data layer the reworked chemistry engine needs, with NO
 * engine change — additive tables + seeds only, so BlueprintChemistryEngine
 * keeps working while Phases 2–6 build on top.
 *
 * Normalised player-attribute model (catalogue + values) so the 10
 * attribute groups are seedable AND extensible (spec §12 future-proof;
 * CLAUDE.md §4 SaaS-ready). Each table carries the club_id + uuid tenancy
 * scaffold + an archive lifecycle where it has one.
 *
 *   - tt_player_attribute_defs   — the attribute catalogue (groups B–G:
 *     physical / technical / tactical / mental / behaviour / development),
 *     seeded with the spec's 0–100 attributes (+ Dutch labels). Role,
 *     footedness, experience and demographic stay derivable from existing
 *     columns / attendance, so they are NOT catalogued here.
 *   - tt_player_attribute_values — one row per player per attribute.
 *   - tt_chemistry_position_matrix — the configurable Position
 *     Relationship Matrix (default group-level weights seeded).
 *   - tt_team_chemistry_snapshots — time-series of lineup chemistry
 *     (written by Phase 4; the table exists now).
 *
 * Component weights (Compatibility/Familiarity/Development/Behaviour/
 * Performance, default 35/25/10/15/15) live in tt_config keyed by club_id
 * (CLAUDE.md tenant-config rule) — no table here.
 *
 * Idempotent; additive; forward-only. Run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0178_chemistry_schema_foundation';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $this->exec(
            "CREATE TABLE IF NOT EXISTS {$p}tt_player_attribute_defs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid VARCHAR(36) DEFAULT NULL,
                attr_group VARCHAR(40) NOT NULL,
                attr_key VARCHAR(60) NOT NULL,
                label VARCHAR(190) NOT NULL,
                min_value SMALLINT NOT NULL DEFAULT 0,
                max_value SMALLINT NOT NULL DEFAULT 100,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                archived_at DATETIME DEFAULT NULL,
                archived_by BIGINT UNSIGNED DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                UNIQUE KEY uniq_club_key (club_id, attr_key),
                KEY idx_group (attr_group)
            ) {$charset}"
        );

        $this->exec(
            "CREATE TABLE IF NOT EXISTS {$p}tt_player_attribute_values (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid VARCHAR(36) DEFAULT NULL,
                player_id BIGINT UNSIGNED NOT NULL,
                attribute_def_id BIGINT UNSIGNED NOT NULL,
                value SMALLINT DEFAULT NULL,
                recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                recorded_by BIGINT UNSIGNED DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                UNIQUE KEY uniq_player_attr (player_id, attribute_def_id),
                KEY idx_club (club_id),
                KEY idx_player (player_id),
                KEY idx_def (attribute_def_id)
            ) {$charset}"
        );

        $this->exec(
            "CREATE TABLE IF NOT EXISTS {$p}tt_chemistry_position_matrix (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid VARCHAR(36) DEFAULT NULL,
                position_a VARCHAR(40) NOT NULL,
                position_b VARCHAR(40) NOT NULL,
                weight DECIMAL(3,2) NOT NULL DEFAULT 0.50,
                updated_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                UNIQUE KEY uniq_club_pair (club_id, position_a, position_b),
                KEY idx_club (club_id)
            ) {$charset}"
        );

        $this->exec(
            "CREATE TABLE IF NOT EXISTS {$p}tt_team_chemistry_snapshots (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid VARCHAR(36) DEFAULT NULL,
                team_id BIGINT UNSIGNED NOT NULL,
                lineup_chemistry SMALLINT DEFAULT NULL,
                source VARCHAR(40) NOT NULL DEFAULT 'blueprint_save',
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                KEY idx_club (club_id),
                KEY idx_team (team_id),
                KEY idx_team_time (team_id, computed_at)
            ) {$charset}"
        );

        $this->seedAttributeCatalogue();
        $this->seedPositionMatrix();
    }

    /**
     * Seed the manually-scored attribute catalogue (groups B–G, all 0–100)
     * with Dutch + English labels in tt_translations. Existence-checked on
     * (club_id, attr_key); idempotent.
     */
    private function seedAttributeCatalogue(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $defs  = $p . 'tt_player_attribute_defs';
        $trans = $p . 'tt_translations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $trans ) ) !== $trans ) return;

        $club_id = 1;
        $now     = current_time( 'mysql', true );

        // group, attr_key, en, nl
        $catalogue = [
            [ 'physical',   'speed',                'Speed',                'Snelheid' ],
            [ 'physical',   'endurance',            'Endurance',            'Uithoudingsvermogen' ],
            [ 'physical',   'strength',             'Strength',             'Kracht' ],
            [ 'physical',   'agility',              'Agility',              'Wendbaarheid' ],
            [ 'technical',  'ball_control',         'Ball control',         'Balcontrole' ],
            [ 'technical',  'passing',              'Passing',              'Passing' ],
            [ 'technical',  'dribbling',            'Dribbling',            'Dribbelen' ],
            [ 'technical',  'finishing',            'Finishing',            'Afronden' ],
            [ 'tactical',   'positioning',          'Positioning',          'Positionering' ],
            [ 'tactical',   'game_intelligence',    'Game intelligence',    'Spelinzicht' ],
            [ 'tactical',   'defensive_awareness',  'Defensive awareness',  'Verdedigend inzicht' ],
            [ 'tactical',   'spatial_awareness',    'Spatial awareness',    'Ruimtelijk inzicht' ],
            [ 'mental',     'resilience',           'Resilience',           'Veerkracht' ],
            [ 'mental',     'confidence',           'Confidence',           'Zelfvertrouwen' ],
            [ 'mental',     'concentration',        'Concentration',        'Concentratie' ],
            [ 'mental',     'leadership',           'Leadership',           'Leiderschap' ],
            [ 'behaviour',  'coachability',         'Coachability',         'Coachbaarheid' ],
            [ 'behaviour',  'discipline',           'Discipline',           'Discipline' ],
            [ 'behaviour',  'team_orientation',     'Team orientation',     'Teamgerichtheid' ],
            [ 'behaviour',  'professionalism',      'Professionalism',      'Professionaliteit' ],
            [ 'development','potential',            'Potential',            'Potentieel' ],
            [ 'development','development_forecast',  'Development forecast',  'Ontwikkelingsprognose' ],
            [ 'development','ceiling_estimate',     'Ceiling estimate',     'Plafondinschatting' ],
        ];

        $sort = 0;
        foreach ( $catalogue as $row ) {
            [ $group, $key, $en, $nl ] = $row;
            $sort += 10;

            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$defs} WHERE club_id = %d AND attr_key = %s LIMIT 1",
                $club_id, $key
            ) );
            if ( $existing > 0 ) continue;

            $wpdb->insert( $defs, [
                'club_id'    => $club_id,
                'uuid'       => wp_generate_uuid4(),
                'attr_group' => $group,
                'attr_key'   => $key,
                'label'      => $en,
                'min_value'  => 0,
                'max_value'  => 100,
                'sort_order' => $sort,
                'created_at' => $now,
            ] );
            $def_id = (int) $wpdb->insert_id;
            if ( $def_id <= 0 ) continue;

            foreach ( [ 'en_US' => $en, 'nl_NL' => $nl ] as $locale => $value ) {
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$trans}
                       (club_id, entity_type, entity_id, field, locale, value, updated_at)
                     VALUES (%d, 'player_attribute_def', %d, 'label', %s, %s, %s)",
                    $club_id, $def_id, $locale, $value, $now
                ) );
            }
        }
    }

    /**
     * Seed a sensible default Position Relationship Matrix at the line-group
     * level (gk / def / mid / att). Phase 5's editor refines it; Phase 3
     * reads it. Symmetric pairs; existence-checked.
     */
    private function seedPositionMatrix(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = $p . 'tt_chemistry_position_matrix';
        $club_id = 1;
        $now     = current_time( 'mysql', true );

        // weights: 1.0 adjacent · 0.8 connected · 0.5 indirect · 0.2 minimal
        $defaults = [
            [ 'gk',  'gk',  1.00 ],
            [ 'def', 'def', 1.00 ],
            [ 'mid', 'mid', 1.00 ],
            [ 'att', 'att', 1.00 ],
            [ 'gk',  'def', 0.80 ],
            [ 'def', 'mid', 0.80 ],
            [ 'mid', 'att', 0.80 ],
            [ 'def', 'att', 0.50 ],
            [ 'gk',  'mid', 0.20 ],
            [ 'gk',  'att', 0.20 ],
        ];

        foreach ( $defaults as $row ) {
            [ $a, $b, $w ] = $row;
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE club_id = %d AND position_a = %s AND position_b = %s LIMIT 1",
                $club_id, $a, $b
            ) );
            if ( $existing > 0 ) continue;
            $wpdb->insert( $table, [
                'club_id'    => $club_id,
                'uuid'       => wp_generate_uuid4(),
                'position_a' => $a,
                'position_b' => $b,
                'weight'     => $w,
                'created_at' => $now,
            ] );
        }
    }
};
