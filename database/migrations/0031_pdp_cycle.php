<?php
/**
 * Migration: 0031_pdp_cycle
 *
 * #0044 sprint 1 — schema for the Player Development Plan cycle.
 *
 * Adds:
 *   - tt_seasons          (first-class season entity)
 *   - tt_pdp_files        (one row per (player, season))
 *   - tt_pdp_conversations (per-cycle conversation rows)
 *   - tt_pdp_verdicts     (end-of-season decision)
 *   - tt_goal_links       (polymorphic: principle / football_action / position / value)
 *   - tt_pdp_calendar_links (provider-agnostic calendar event log)
 *
 * Plus:
 *   - tt_teams.pdp_cycle_size      (NULL = follow club default)
 *   - tt_config defaults: pdp_cycle_default = 3, pdp_print_include_evidence = 0
 *   - Seeds 8 player_value lookup rows
 *   - Backfills tt_seasons with one current row from `season_label` config
 *
 * Idempotent. Safe to re-run.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0031_pdp_cycle';
    }

    public function up(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = [
            "CREATE TABLE {$p}tt_seasons (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                is_current TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_current (is_current),
                KEY idx_dates (start_date, end_date)
            ) $c;",

            "CREATE TABLE {$p}tt_pdp_files (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                player_id BIGINT UNSIGNED NOT NULL,
                season_id BIGINT UNSIGNED NOT NULL,
                owner_coach_id BIGINT UNSIGNED DEFAULT NULL,
                cycle_size TINYINT UNSIGNED DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                notes LONGTEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_player_season (player_id, season_id),
                KEY idx_owner (owner_coach_id),
                KEY idx_status (status)
            ) $c;",

            "CREATE TABLE {$p}tt_pdp_conversations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                pdp_file_id BIGINT UNSIGNED NOT NULL,
                sequence TINYINT UNSIGNED NOT NULL,
                template_key VARCHAR(20) NOT NULL,
                scheduled_at DATETIME DEFAULT NULL,
                conducted_at DATETIME DEFAULT NULL,
                agenda LONGTEXT,
                notes LONGTEXT,
                agreed_actions LONGTEXT,
                player_reflection LONGTEXT,
                coach_signoff_at DATETIME DEFAULT NULL,
                parent_ack_at DATETIME DEFAULT NULL,
                player_ack_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_file_sequence (pdp_file_id, sequence),
                KEY idx_file (pdp_file_id),
                KEY idx_scheduled (scheduled_at)
            ) $c;",

            "CREATE TABLE {$p}tt_pdp_verdicts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                pdp_file_id BIGINT UNSIGNED NOT NULL,
                decision VARCHAR(20) NOT NULL,
                summary LONGTEXT,
                coach_id BIGINT UNSIGNED DEFAULT NULL,
                head_of_academy_id BIGINT UNSIGNED DEFAULT NULL,
                signed_off_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_file (pdp_file_id),
                KEY idx_decision (decision)
            ) $c;",

            "CREATE TABLE {$p}tt_goal_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                goal_id BIGINT UNSIGNED NOT NULL,
                link_type VARCHAR(32) NOT NULL,
                link_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_goal_target (goal_id, link_type, link_id),
                KEY idx_goal (goal_id),
                KEY idx_target (link_type, link_id)
            ) $c;",

            "CREATE TABLE {$p}tt_pdp_calendar_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_id BIGINT UNSIGNED NOT NULL,
                provider VARCHAR(20) NOT NULL DEFAULT 'native',
                provider_event_id VARCHAR(191) DEFAULT NULL,
                provider_payload LONGTEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_conv_provider (conversation_id, provider),
                KEY idx_conversation (conversation_id),
                KEY idx_provider (provider)
            ) $c;",
        ];

        foreach ( $tables as $sql ) dbDelta( $sql );

        // Per-team override column on tt_teams. Idempotent guard.
        $teams_table = $p . 'tt_teams';
        $has_col = $wpdb->get_row( $wpdb->prepare(
            "SHOW COLUMNS FROM `$teams_table` LIKE %s",
            'pdp_cycle_size'
        ) );
        if ( $has_col === null ) {
            $wpdb->query( "ALTER TABLE `$teams_table` ADD COLUMN `pdp_cycle_size` TINYINT UNSIGNED DEFAULT NULL" );
        }

        // tt_config defaults — only insert if missing; never overwrite admin choice.
        $config_table = $p . 'tt_config';
        foreach ( [
            'pdp_cycle_default'           => '3',
            'pdp_print_include_evidence'  => '0',
        ] as $key => $value ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT config_key FROM `$config_table` WHERE config_key = %s",
                $key
            ) );
            if ( $existing === null ) {
                $wpdb->insert( $config_table, [ 'config_key' => $key, 'config_value' => $value ] );
            }
        }

        // Player-value lookup seed. Skip if any rows of this type already
        // exist — admin may have edited the list already.
        $lookups_table = $p . 'tt_lookups';
        $existing_values = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `$lookups_table` WHERE lookup_type = %s",
            'player_value'
        ) );
        if ( $existing_values === 0 ) {
            $values = [
                [ 'commitment',    'Commitment',    10 ],
                [ 'coachability',  'Coachability',  20 ],
                [ 'leadership',    'Leadership',    30 ],
                [ 'resilience',    'Resilience',    40 ],
                [ 'communication', 'Communication', 50 ],
                [ 'work_ethic',    'Work ethic',    60 ],
                [ 'fair_play',     'Fair play',     70 ],
                [ 'ambition',      'Ambition',      80 ],
            ];
            foreach ( $values as $v ) {
                $wpdb->insert( $lookups_table, [
                    'lookup_type' => 'player_value',
                    'name'        => $v[1],
                    'description' => null,
                    'meta'        => wp_json_encode( [ 'key' => $v[0] ] ),
                    'sort_order'  => $v[2],
                ] );
            }
        }

        // Seed one current season if none exist. Use the existing
        // `season_label` config string as the name; default the date
        // window to Aug 1 (start) → Jun 30 next year (end) of the
        // current academic year. Admins can edit later.
        $seasons_table = $p . 'tt_seasons';
        $season_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$seasons_table`" );
        if ( $season_count === 0 ) {
            $label = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT config_value FROM `$config_table` WHERE config_key = %s",
                'season_label'
            ) );
            if ( $label === '' ) {
                $year_start = (int) gmdate( 'n' ) >= 7 ? (int) gmdate( 'Y' ) : ( (int) gmdate( 'Y' ) - 1 );
                $label = $year_start . '/' . ( $year_start + 1 );
            }
            // Parse "2025/2026" → start 2025-08-01, end 2026-06-30.
            $start_year = 0;
            if ( preg_match( '/^(\d{4})/', $label, $m ) ) {
                $start_year = (int) $m[1];
            } else {
                $now = (int) gmdate( 'n' ) >= 7 ? (int) gmdate( 'Y' ) : ( (int) gmdate( 'Y' ) - 1 );
                $start_year = $now;
            }

            $wpdb->insert( $seasons_table, [
                'name'       => $label,
                'start_date' => $start_year . '-08-01',
                'end_date'   => ( $start_year + 1 ) . '-06-30',
                'is_current' => 1,
            ] );
        }
    }
};
