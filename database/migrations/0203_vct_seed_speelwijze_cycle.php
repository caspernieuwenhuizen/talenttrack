<?php
/**
 * Migration 0203 — JO13-1 speelwijze reference cycle (#2322, epic #2316).
 *
 * Seeds ONE additional macro-block reference template that carries the
 * PPT's 5-week speelwijze cycle. Unlike the two conditioning-only
 * references seeded in migration 0126, every week here carries BOTH the
 * VCT conditioning phase + intensity multiplier AND an optional per-week
 * `tactical_theme` (the speelwijze theme from the canonical
 * `vct_tactical_theme` vocabulary):
 *
 *   Week 1 — Opbouw EH        → build_up   (Extensieve duur)
 *   Week 2 — Verdedigen HT    → defending
 *   Week 3 — Aanvallen HT     → possession (closest canonical key for the
 *                                PPT's "Aanvallen"; the vocabulary has no
 *                                dedicated "attacking" theme, and possession
 *                                covers team attacking build-up better than
 *                                the box-only "finishing")
 *   Week 4 — Verdedigen EH    → defending
 *   Week 5 — Neutrale week    → (no theme) lower multiplier
 *
 * Stored as a reference template: season_id = 0, team_id = 0 (the same
 * sentinel scope migration 0126 uses). Sequence 3 discriminates it from
 * the two existing conditioning references within the UNIQUE
 * (club_id, team_id, season_id, sequence) index.
 *
 * Placeholder start/end dates in the year 2000 never match a real season
 * query — the row is recognised as a template by the season_id = 0
 * sentinel, not the dates.
 *
 * Single-tenant convention: club_id = 1. Forward-only + idempotent —
 * existence check before insert; never touches real (season_id > 0) blocks.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0203_vct_seed_speelwijze_cycle';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $macro_blocks_table = $p . 'tt_vct_macro_blocks';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $macro_blocks_table ) ) !== $macro_blocks_table ) return;

        $club_id   = 1;
        $team_id   = 0;
        $season_id = 0;
        $sequence  = 3;

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$macro_blocks_table}
              WHERE club_id = %d AND team_id = %d AND season_id = %d AND sequence = %d
              LIMIT 1",
            $club_id, $team_id, $season_id, $sequence
        ) );
        if ( $existing > 0 ) return;

        $profile = [
            [ 'week' => 1, 'phase' => 'Extensieve duur', 'multiplier' => 0.90, 'tactical_theme' => 'build_up' ],
            [ 'week' => 2, 'phase' => 'opbouw',          'multiplier' => 1.00, 'tactical_theme' => 'defending' ],
            [ 'week' => 3, 'phase' => 'opbouw',          'multiplier' => 1.00, 'tactical_theme' => 'possession' ],
            [ 'week' => 4, 'phase' => 'opbouw',          'multiplier' => 1.00, 'tactical_theme' => 'defending' ],
            [ 'week' => 5, 'phase' => 'Neutrale week',   'multiplier' => 0.75, 'tactical_theme' => null ],
        ];

        $wpdb->insert( $macro_blocks_table, [
            'club_id'            => $club_id,
            'uuid'               => wp_generate_uuid4(),
            'season_id'          => $season_id,
            'team_id'            => $team_id,
            'sequence'           => $sequence,
            'label'              => 'Speelwijze JO13-1 (5-weken)',
            'start_date'         => '2000-04-01',
            'end_date'           => '2000-05-05',
            'phase_profile_json' => wp_json_encode( $profile ),
        ] );
    }
};
