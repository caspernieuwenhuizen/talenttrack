<?php
/**
 * Migration 0126 — VCT phase-profile reference rows (#909 VCT-4 /
 * partial epic #905).
 *
 * Seeds two reference macro-block templates per club that HoDs can
 * clone when defining a season's actual blocks via the Phase 2
 * configuration tile:
 *
 *   - 4-week macro-block: [introductie 0.85, opbouw 1.00, opbouw 1.00, deload 0.70]
 *   - 6-week macro-block (default): [introductie 0.85, opbouw 0.95, opbouw 1.00, piek 1.00, piek 1.00, deload 0.70]
 *
 * Reference rows are stored at `season_id = 0` (sentinel — never
 * matches a real season query, so the engine's macro-block lookup
 * for a current season won't accidentally apply them). The HoD UI
 * (Phase 2) reads rows with `season_id = 0` as templates and
 * `INSERT`s copies at the real `season_id` when the operator clicks
 * "Use as template".
 *
 * `team_id = 0` matches the spec's "club-wide season default"
 * convention (vs. non-zero per-team overrides). Sequence
 * discriminates the two reference rows within the UNIQUE
 * `(club_id, team_id, season_id, sequence)` index: sequence 1 =
 * 4-week, sequence 2 = 6-week.
 *
 * `start_date` / `end_date` are placeholders (NOT NULL columns)
 * with dates in the year 2000 that will never match a real season
 * query — the rows are recognised as templates by the `season_id = 0`
 * sentinel, not by the dates.
 *
 * Single-tenant convention: club_id = 1. Idempotent — existence
 * check before insert.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0126_vct_seed_phase_profiles';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $macro_blocks_table = $p . 'tt_vct_macro_blocks';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $macro_blocks_table ) ) !== $macro_blocks_table ) return;

        $club_id   = 1;
        $team_id   = 0;
        $season_id = 0;

        $references = [
            [
                'sequence'   => 1,
                'label'      => 'Reference: 4-week phase profile',
                'start_date' => '2000-01-01',
                'end_date'   => '2000-01-28',
                'profile'    => [
                    [ 'week' => 1, 'phase' => 'introductie', 'multiplier' => 0.85 ],
                    [ 'week' => 2, 'phase' => 'opbouw',      'multiplier' => 1.00 ],
                    [ 'week' => 3, 'phase' => 'opbouw',      'multiplier' => 1.00 ],
                    [ 'week' => 4, 'phase' => 'deload',      'multiplier' => 0.70 ],
                ],
            ],
            [
                'sequence'   => 2,
                'label'      => 'Reference: 6-week phase profile (default)',
                'start_date' => '2000-02-01',
                'end_date'   => '2000-03-14',
                'profile'    => [
                    [ 'week' => 1, 'phase' => 'introductie', 'multiplier' => 0.85 ],
                    [ 'week' => 2, 'phase' => 'opbouw',      'multiplier' => 0.95 ],
                    [ 'week' => 3, 'phase' => 'opbouw',      'multiplier' => 1.00 ],
                    [ 'week' => 4, 'phase' => 'piek',        'multiplier' => 1.00 ],
                    [ 'week' => 5, 'phase' => 'piek',        'multiplier' => 1.00 ],
                    [ 'week' => 6, 'phase' => 'deload',      'multiplier' => 0.70 ],
                ],
            ],
        ];

        foreach ( $references as $ref ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$macro_blocks_table}
                  WHERE club_id = %d AND team_id = %d AND season_id = %d AND sequence = %d
                  LIMIT 1",
                $club_id, $team_id, $season_id, $ref['sequence']
            ) );
            if ( $existing > 0 ) continue;

            $wpdb->insert( $macro_blocks_table, [
                'club_id'            => $club_id,
                'uuid'               => wp_generate_uuid4(),
                'season_id'          => $season_id,
                'team_id'            => $team_id,
                'sequence'           => (int) $ref['sequence'],
                'label'              => (string) $ref['label'],
                'start_date'         => (string) $ref['start_date'],
                'end_date'           => (string) $ref['end_date'],
                'phase_profile_json' => wp_json_encode( $ref['profile'] ),
            ] );
        }
    }
};
