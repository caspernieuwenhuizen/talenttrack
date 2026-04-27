<?php
/**
 * Migration: 0032_team_development
 *
 * #0018 sprint 1 — foundation schema for team development + chemistry.
 *
 * Adds:
 *   - tt_team_formations       (one row per team — current formation assignment)
 *   - tt_team_playing_styles   (one row per team — possession / counter / press blend, sums to 100)
 *   - tt_formation_templates   (seeded + custom 4-3-3 templates with role-profile JSON)
 *   - tt_player_team_history   (joined_at / left_at — backfilled from existing rosters)
 *
 * Seeds four 4-3-3 templates (Neutral / Possession / Counter / Press-heavy)
 * with structured `slots_json` weights across the four main evaluation
 * categories (technical / tactical / physical / mental). Backfills
 * `tt_player_team_history` with one row per current (player, team) pair.
 *
 * Sprint 2's CompatibilityEngine consumes the slot weights against
 * the rolling-5 player rating per category. Weights sum to 1.0 within
 * each slot.
 *
 * Idempotent. Safe to re-run.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0032_team_development';
    }

    public function up(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = [
            "CREATE TABLE {$p}tt_team_formations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                team_id BIGINT UNSIGNED NOT NULL,
                formation_template_id BIGINT UNSIGNED NOT NULL,
                assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                assigned_by BIGINT UNSIGNED DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_team (team_id),
                KEY idx_template (formation_template_id)
            ) $c;",

            "CREATE TABLE {$p}tt_team_playing_styles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                team_id BIGINT UNSIGNED NOT NULL,
                possession_weight TINYINT UNSIGNED NOT NULL DEFAULT 33,
                counter_weight TINYINT UNSIGNED NOT NULL DEFAULT 33,
                press_weight TINYINT UNSIGNED NOT NULL DEFAULT 34,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by BIGINT UNSIGNED DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_team (team_id)
            ) $c;",

            "CREATE TABLE {$p}tt_formation_templates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                formation_shape VARCHAR(16) NOT NULL,
                slots_json LONGTEXT NOT NULL,
                is_seeded TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                archived_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_shape (formation_shape),
                KEY idx_archived (archived_at)
            ) $c;",

            "CREATE TABLE {$p}tt_player_team_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                player_id BIGINT UNSIGNED NOT NULL,
                team_id BIGINT UNSIGNED NOT NULL,
                joined_at DATE NOT NULL,
                left_at DATE DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_player (player_id),
                KEY idx_team (team_id),
                KEY idx_active (left_at)
            ) $c;",
        ];

        foreach ( $tables as $sql ) dbDelta( $sql );

        $this->seedFormationTemplates();
        $this->backfillPlayerTeamHistory();
    }

    /**
     * Seed the four shipped 4-3-3 templates. Skips if any seeded row
     * already exists (admin may have edited; never overwrite).
     */
    private function seedFormationTemplates(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = $p . 'tt_formation_templates';

        $existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table` WHERE is_seeded = 1" );
        if ( $existing > 0 ) return;

        foreach ( self::shippedTemplates() as $tpl ) {
            $wpdb->insert( $table, [
                'name'            => (string) $tpl['name'],
                'formation_shape' => (string) $tpl['formation_shape'],
                'slots_json'      => wp_json_encode( $tpl['slots'] ),
                'is_seeded'       => 1,
            ] );
        }
    }

    /**
     * Backfill: one history row per current (player, team) pair. Uses
     * the player's `created_at` as `joined_at` — imperfect (doesn't
     * reconstruct historical moves before the plugin tracked them) but
     * the chemistry bonus degrades gracefully for short history.
     *
     * Only runs when the history table is empty so re-runs are safe.
     */
    private function backfillPlayerTeamHistory(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $hist = $p . 'tt_player_team_history';

        $existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$hist`" );
        if ( $existing > 0 ) return;

        $wpdb->query(
            "INSERT INTO `$hist` (player_id, team_id, joined_at, left_at)
             SELECT id, team_id, DATE(created_at), NULL
               FROM {$p}tt_players
              WHERE team_id IS NOT NULL AND team_id > 0
                AND status = 'active'"
        );
    }

    /**
     * Four 4-3-3 templates. Each slot's weights map evaluation main
     * categories (technical/tactical/physical/mental) to a fraction.
     * Weights within a slot sum to 1.0; cross-slot weights are
     * independent.
     *
     * `pos` is a normalized {x, y} on the pitch (0,0 = top-left,
     * 1,1 = bottom-right) — Sprint 3's isometric board reads it.
     *
     * @return list<array{name:string, formation_shape:string, slots:list<array<string,mixed>>}>
     */
    private static function shippedTemplates(): array {
        return [
            [
                'name'            => 'Neutral 4-3-3',
                'formation_shape' => '4-3-3',
                'slots'           => [
                    [ 'label' => 'GK',  'pos' => [ 'x' => 0.50, 'y' => 0.95 ], 'side' => 'center', 'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.20, 'mental' => 0.20 ] ],
                    [ 'label' => 'LB',  'pos' => [ 'x' => 0.15, 'y' => 0.75 ], 'side' => 'left',   'weights' => [ 'technical' => 0.25, 'tactical' => 0.30, 'physical' => 0.30, 'mental' => 0.15 ] ],
                    [ 'label' => 'LCB', 'pos' => [ 'x' => 0.35, 'y' => 0.80 ], 'side' => 'left',   'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.30, 'mental' => 0.10 ] ],
                    [ 'label' => 'RCB', 'pos' => [ 'x' => 0.65, 'y' => 0.80 ], 'side' => 'right',  'weights' => [ 'technical' => 0.20, 'tactical' => 0.40, 'physical' => 0.30, 'mental' => 0.10 ] ],
                    [ 'label' => 'RB',  'pos' => [ 'x' => 0.85, 'y' => 0.75 ], 'side' => 'right',  'weights' => [ 'technical' => 0.25, 'tactical' => 0.30, 'physical' => 0.30, 'mental' => 0.15 ] ],
                    [ 'label' => 'CDM', 'pos' => [ 'x' => 0.50, 'y' => 0.60 ], 'side' => 'center', 'weights' => [ 'technical' => 0.30, 'tactical' => 0.40, 'physical' => 0.20, 'mental' => 0.10 ] ],
                    [ 'label' => 'LCM', 'pos' => [ 'x' => 0.30, 'y' => 0.50 ], 'side' => 'left',   'weights' => [ 'technical' => 0.30, 'tactical' => 0.35, 'physical' => 0.25, 'mental' => 0.10 ] ],
                    [ 'label' => 'RCM', 'pos' => [ 'x' => 0.70, 'y' => 0.50 ], 'side' => 'right',  'weights' => [ 'technical' => 0.30, 'tactical' => 0.35, 'physical' => 0.25, 'mental' => 0.10 ] ],
                    [ 'label' => 'LW',  'pos' => [ 'x' => 0.20, 'y' => 0.25 ], 'side' => 'left',   'weights' => [ 'technical' => 0.40, 'tactical' => 0.20, 'physical' => 0.30, 'mental' => 0.10 ] ],
                    [ 'label' => 'ST',  'pos' => [ 'x' => 0.50, 'y' => 0.15 ], 'side' => 'center', 'weights' => [ 'technical' => 0.40, 'tactical' => 0.25, 'physical' => 0.25, 'mental' => 0.10 ] ],
                    [ 'label' => 'RW',  'pos' => [ 'x' => 0.80, 'y' => 0.25 ], 'side' => 'right',  'weights' => [ 'technical' => 0.40, 'tactical' => 0.20, 'physical' => 0.30, 'mental' => 0.10 ] ],
                ],
            ],
            [
                'name'            => 'Possession 4-3-3',
                'formation_shape' => '4-3-3',
                'slots'           => [
                    [ 'label' => 'GK',  'pos' => [ 'x' => 0.50, 'y' => 0.95 ], 'side' => 'center', 'weights' => [ 'technical' => 0.30, 'tactical' => 0.40, 'physical' => 0.15, 'mental' => 0.15 ] ],
                    [ 'label' => 'LB',  'pos' => [ 'x' => 0.15, 'y' => 0.75 ], 'side' => 'left',   'weights' => [ 'technical' => 0.35, 'tactical' => 0.35, 'physical' => 0.20, 'mental' => 0.10 ] ],
                    [ 'label' => 'LCB', 'pos' => [ 'x' => 0.35, 'y' => 0.80 ], 'side' => 'left',   'weights' => [ 'technical' => 0.35, 'tactical' => 0.40, 'physical' => 0.15, 'mental' => 0.10 ] ],
                    [ 'label' => 'RCB', 'pos' => [ 'x' => 0.65, 'y' => 0.80 ], 'side' => 'right',  'weights' => [ 'technical' => 0.35, 'tactical' => 0.40, 'physical' => 0.15, 'mental' => 0.10 ] ],
                    [ 'label' => 'RB',  'pos' => [ 'x' => 0.85, 'y' => 0.75 ], 'side' => 'right',  'weights' => [ 'technical' => 0.35, 'tactical' => 0.35, 'physical' => 0.20, 'mental' => 0.10 ] ],
                    [ 'label' => 'CDM', 'pos' => [ 'x' => 0.50, 'y' => 0.60 ], 'side' => 'center', 'weights' => [ 'technical' => 0.40, 'tactical' => 0.40, 'physical' => 0.10, 'mental' => 0.10 ] ],
                    [ 'label' => 'LCM', 'pos' => [ 'x' => 0.30, 'y' => 0.50 ], 'side' => 'left',   'weights' => [ 'technical' => 0.40, 'tactical' => 0.35, 'physical' => 0.15, 'mental' => 0.10 ] ],
                    [ 'label' => 'RCM', 'pos' => [ 'x' => 0.70, 'y' => 0.50 ], 'side' => 'right',  'weights' => [ 'technical' => 0.40, 'tactical' => 0.35, 'physical' => 0.15, 'mental' => 0.10 ] ],
                    [ 'label' => 'LW',  'pos' => [ 'x' => 0.20, 'y' => 0.25 ], 'side' => 'left',   'weights' => [ 'technical' => 0.50, 'tactical' => 0.20, 'physical' => 0.20, 'mental' => 0.10 ] ],
                    [ 'label' => 'ST',  'pos' => [ 'x' => 0.50, 'y' => 0.15 ], 'side' => 'center', 'weights' => [ 'technical' => 0.45, 'tactical' => 0.25, 'physical' => 0.20, 'mental' => 0.10 ] ],
                    [ 'label' => 'RW',  'pos' => [ 'x' => 0.80, 'y' => 0.25 ], 'side' => 'right',  'weights' => [ 'technical' => 0.50, 'tactical' => 0.20, 'physical' => 0.20, 'mental' => 0.10 ] ],
                ],
            ],
            [
                'name'            => 'Counter 4-3-3',
                'formation_shape' => '4-3-3',
                'slots'           => [
                    [ 'label' => 'GK',  'pos' => [ 'x' => 0.50, 'y' => 0.95 ], 'side' => 'center', 'weights' => [ 'technical' => 0.15, 'tactical' => 0.35, 'physical' => 0.30, 'mental' => 0.20 ] ],
                    [ 'label' => 'LB',  'pos' => [ 'x' => 0.15, 'y' => 0.75 ], 'side' => 'left',   'weights' => [ 'technical' => 0.20, 'tactical' => 0.25, 'physical' => 0.45, 'mental' => 0.10 ] ],
                    [ 'label' => 'LCB', 'pos' => [ 'x' => 0.35, 'y' => 0.80 ], 'side' => 'left',   'weights' => [ 'technical' => 0.15, 'tactical' => 0.30, 'physical' => 0.45, 'mental' => 0.10 ] ],
                    [ 'label' => 'RCB', 'pos' => [ 'x' => 0.65, 'y' => 0.80 ], 'side' => 'right',  'weights' => [ 'technical' => 0.15, 'tactical' => 0.30, 'physical' => 0.45, 'mental' => 0.10 ] ],
                    [ 'label' => 'RB',  'pos' => [ 'x' => 0.85, 'y' => 0.75 ], 'side' => 'right',  'weights' => [ 'technical' => 0.20, 'tactical' => 0.25, 'physical' => 0.45, 'mental' => 0.10 ] ],
                    [ 'label' => 'CDM', 'pos' => [ 'x' => 0.50, 'y' => 0.60 ], 'side' => 'center', 'weights' => [ 'technical' => 0.20, 'tactical' => 0.30, 'physical' => 0.40, 'mental' => 0.10 ] ],
                    [ 'label' => 'LCM', 'pos' => [ 'x' => 0.30, 'y' => 0.50 ], 'side' => 'left',   'weights' => [ 'technical' => 0.25, 'tactical' => 0.25, 'physical' => 0.40, 'mental' => 0.10 ] ],
                    [ 'label' => 'RCM', 'pos' => [ 'x' => 0.70, 'y' => 0.50 ], 'side' => 'right',  'weights' => [ 'technical' => 0.25, 'tactical' => 0.25, 'physical' => 0.40, 'mental' => 0.10 ] ],
                    [ 'label' => 'LW',  'pos' => [ 'x' => 0.20, 'y' => 0.25 ], 'side' => 'left',   'weights' => [ 'technical' => 0.25, 'tactical' => 0.15, 'physical' => 0.50, 'mental' => 0.10 ] ],
                    [ 'label' => 'ST',  'pos' => [ 'x' => 0.50, 'y' => 0.15 ], 'side' => 'center', 'weights' => [ 'technical' => 0.30, 'tactical' => 0.20, 'physical' => 0.40, 'mental' => 0.10 ] ],
                    [ 'label' => 'RW',  'pos' => [ 'x' => 0.80, 'y' => 0.25 ], 'side' => 'right',  'weights' => [ 'technical' => 0.25, 'tactical' => 0.15, 'physical' => 0.50, 'mental' => 0.10 ] ],
                ],
            ],
            [
                'name'            => 'Press-heavy 4-3-3',
                'formation_shape' => '4-3-3',
                'slots'           => [
                    [ 'label' => 'GK',  'pos' => [ 'x' => 0.50, 'y' => 0.95 ], 'side' => 'center', 'weights' => [ 'technical' => 0.20, 'tactical' => 0.35, 'physical' => 0.25, 'mental' => 0.20 ] ],
                    [ 'label' => 'LB',  'pos' => [ 'x' => 0.15, 'y' => 0.75 ], 'side' => 'left',   'weights' => [ 'technical' => 0.20, 'tactical' => 0.25, 'physical' => 0.40, 'mental' => 0.15 ] ],
                    [ 'label' => 'LCB', 'pos' => [ 'x' => 0.35, 'y' => 0.80 ], 'side' => 'left',   'weights' => [ 'technical' => 0.15, 'tactical' => 0.30, 'physical' => 0.40, 'mental' => 0.15 ] ],
                    [ 'label' => 'RCB', 'pos' => [ 'x' => 0.65, 'y' => 0.80 ], 'side' => 'right',  'weights' => [ 'technical' => 0.15, 'tactical' => 0.30, 'physical' => 0.40, 'mental' => 0.15 ] ],
                    [ 'label' => 'RB',  'pos' => [ 'x' => 0.85, 'y' => 0.75 ], 'side' => 'right',  'weights' => [ 'technical' => 0.20, 'tactical' => 0.25, 'physical' => 0.40, 'mental' => 0.15 ] ],
                    [ 'label' => 'CDM', 'pos' => [ 'x' => 0.50, 'y' => 0.60 ], 'side' => 'center', 'weights' => [ 'technical' => 0.20, 'tactical' => 0.30, 'physical' => 0.35, 'mental' => 0.15 ] ],
                    [ 'label' => 'LCM', 'pos' => [ 'x' => 0.30, 'y' => 0.50 ], 'side' => 'left',   'weights' => [ 'technical' => 0.20, 'tactical' => 0.30, 'physical' => 0.35, 'mental' => 0.15 ] ],
                    [ 'label' => 'RCM', 'pos' => [ 'x' => 0.70, 'y' => 0.50 ], 'side' => 'right',  'weights' => [ 'technical' => 0.20, 'tactical' => 0.30, 'physical' => 0.35, 'mental' => 0.15 ] ],
                    [ 'label' => 'LW',  'pos' => [ 'x' => 0.20, 'y' => 0.25 ], 'side' => 'left',   'weights' => [ 'technical' => 0.25, 'tactical' => 0.15, 'physical' => 0.45, 'mental' => 0.15 ] ],
                    [ 'label' => 'ST',  'pos' => [ 'x' => 0.50, 'y' => 0.15 ], 'side' => 'center', 'weights' => [ 'technical' => 0.30, 'tactical' => 0.20, 'physical' => 0.35, 'mental' => 0.15 ] ],
                    [ 'label' => 'RW',  'pos' => [ 'x' => 0.80, 'y' => 0.25 ], 'side' => 'right',  'weights' => [ 'technical' => 0.25, 'tactical' => 0.15, 'physical' => 0.45, 'mental' => 0.15 ] ],
                ],
            ],
        ];
    }
};
