<?php
/**
 * Migration 0125 — VCT age profiles + session templates seed (#909
 * VCT-4 / partial epic #905).
 *
 * Seeds two reference datasets per club:
 *   1. `tt_vct_age_profiles` — one row per (club_id, age_group) for
 *      U10-U14. Values are spec defaults per Appendix A as revised by
 *      the design review (session-minute caps relaxed; 72h recovery
 *      gap for U10-U12; intensity ceilings 3/4/5/7/7).
 *   2. `tt_vct_session_templates` — one row per (club_id, age_group,
 *      md_context) for every valid combination. U10/U11 ship only the
 *      `NONE` md_context (no MD logic); U12 ships `NONE` + a
 *      simplified MD-3/MD-1/MD+1 set; U13/U14 ship the full MD
 *      ladder.
 *
 * Pure-application defaults: spec defaults ship as **provisional
 * pending HoD/coach sign-off** (an epic-level approval gate). When
 * sign-off lands and values change, a follow-up migration UPDATEs
 * values for clubs that haven't customised — operator-edited rows
 * are preserved by the existence-check pattern.
 *
 * Per the spec § Decisions log #2: per-club seeding (no club_id = 0
 * shared overlay). Single-tenant convention: club_id = 1 (matches
 * the pattern from 0116_seed_trial_case_lookups.php).
 *
 * Idempotent — existence check on the natural key before insert.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0125_vct_seed_age_profiles_and_templates';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $age_profiles_table = $p . 'tt_vct_age_profiles';
        $templates_table    = $p . 'tt_vct_session_templates';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $age_profiles_table ) ) !== $age_profiles_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $templates_table ) ) !== $templates_table ) return;

        $club_id = 1;

        // Age profiles — Appendix A (revised). Recovery gap 72h for
        // U10-U12 per the DFB Trainerausbildung adjustment; 48h for
        // U13-U14. Intensity ceilings on the conservative side of
        // debated thresholds. Session minutes relaxed per domain
        // reviewer feedback (U10 60→70, U11/U12 75→85).
        //
        // weekly_load_envelope is derived as
        //   session_minutes_max × intensity_band_max × expected_sessions_per_week
        // with cadence: U10/U11 = 2 sessions/week, U12 = 3 (one extra
        // after MD logic kicks in), U13/U14 = 3.
        //
        // match_load_multiplier_per_minute is 7.0 across the board —
        // marked in the spec as needing domain-expert sign-off; exposed
        // via the Phase 2 age-profile editor so each club can override.
        //
        // growth_spurt_load_reduction_pct is the multiplier applied to
        // any PHV-flagged player's individual load contribution: 25%
        // for U10-U12, 30% for U13-U14 (later peak growth typically
        // requires more reduction during the spurt itself).
        $age_profiles = [
            [ 'age_group' => 'U10', 'session_minutes_max' => 70, 'intensity_band_max' => 3, 'md_logic_enabled' => 0, 'min_recovery_hours_between_high' => 72, 'growth_spurt_load_reduction_pct' => 25, 'weekly_load_envelope' => 70 * 3 * 2 ],
            [ 'age_group' => 'U11', 'session_minutes_max' => 85, 'intensity_band_max' => 4, 'md_logic_enabled' => 0, 'min_recovery_hours_between_high' => 72, 'growth_spurt_load_reduction_pct' => 25, 'weekly_load_envelope' => 85 * 4 * 2 ],
            [ 'age_group' => 'U12', 'session_minutes_max' => 85, 'intensity_band_max' => 5, 'md_logic_enabled' => 1, 'min_recovery_hours_between_high' => 72, 'growth_spurt_load_reduction_pct' => 25, 'weekly_load_envelope' => 85 * 5 * 3 ],
            [ 'age_group' => 'U13', 'session_minutes_max' => 90, 'intensity_band_max' => 7, 'md_logic_enabled' => 1, 'min_recovery_hours_between_high' => 48, 'growth_spurt_load_reduction_pct' => 30, 'weekly_load_envelope' => 90 * 7 * 3 ],
            [ 'age_group' => 'U14', 'session_minutes_max' => 90, 'intensity_band_max' => 7, 'md_logic_enabled' => 1, 'min_recovery_hours_between_high' => 48, 'growth_spurt_load_reduction_pct' => 30, 'weekly_load_envelope' => 90 * 7 * 3 ],
        ];

        foreach ( $age_profiles as $row ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$age_profiles_table} WHERE club_id = %d AND age_group = %s LIMIT 1",
                $club_id, $row['age_group']
            ) );
            if ( $existing > 0 ) continue;

            $wpdb->insert( $age_profiles_table, array_merge( [
                'club_id'                          => $club_id,
                'uuid'                             => wp_generate_uuid4(),
                'match_load_multiplier_per_minute' => 7.0,
            ], $row ) );
        }

        // Session templates — slot pattern per spec § Exercise Taxonomy:
        // warmup → technical → sided_game → conditioning → cool_down
        // with `finishing` replacing `conditioning` on MD-2/MD-1 for
        // U13-U14 (bias toward sharpening as the match approaches).
        //
        // Each slot carries:
        //   - category (the lookup_type vct_exercise_category value)
        //   - intensity_band_min / intensity_band_max (the band range
        //     the engine selects exercises within; respects the age's
        //     intensity_band_max ceiling)
        //   - duration_target / duration_tolerance (minutes; tolerance
        //     is the engine's swap-budget when rebalancing)
        //   - theme_filter (true = coach's tactical_theme applies;
        //     false = warm-up / cool-down ignore theme per spec § 4)
        //
        // total_duration_minutes_target equals the sum of slot
        // duration_target values; engine validates the actual session
        // duration against the age profile's session_minutes_max as
        // the hard ceiling.
        $template_set = $this->buildTemplates();

        foreach ( $template_set as $tpl ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$templates_table}
                  WHERE club_id = %d AND age_group = %s AND md_context = %s
                  LIMIT 1",
                $club_id, $tpl['age_group'], $tpl['md_context']
            ) );
            if ( $existing > 0 ) continue;

            $wpdb->insert( $templates_table, [
                'club_id'                       => $club_id,
                'uuid'                          => wp_generate_uuid4(),
                'age_group'                     => $tpl['age_group'],
                'md_context'                    => $tpl['md_context'],
                'slots_json'                    => wp_json_encode( $tpl['slots'] ),
                'total_duration_minutes_target' => array_sum( array_column( $tpl['slots'], 'duration_target' ) ),
                'description_nl'                => $tpl['description_nl'],
            ] );
        }
    }

    /** @return array<int, array<string,mixed>> */
    private function buildTemplates(): array {
        $rows = [];

        $rows[] = [
            'age_group'      => 'U10',
            'md_context'     => 'NONE',
            'description_nl' => 'Spelvormen + plezier. Basisslot zonder MD-context.',
            'slots'          => $this->slotsFor( 70, 3, false ),
        ];
        $rows[] = [
            'age_group'      => 'U11',
            'md_context'     => 'NONE',
            'description_nl' => 'Techniek + spelinzicht. Basisslot zonder MD-context.',
            'slots'          => $this->slotsFor( 85, 4, false ),
        ];

        $rows[] = [
            'age_group'      => 'U12',
            'md_context'     => 'NONE',
            'description_nl' => 'Standaardweek zonder match-context.',
            'slots'          => $this->slotsFor( 85, 5, false ),
        ];
        $rows[] = [
            'age_group'      => 'U12',
            'md_context'     => 'MD-3',
            'description_nl' => 'Volume-week, drie dagen voor de wedstrijd.',
            'slots'          => $this->slotsFor( 85, 5, false ),
        ];
        $rows[] = [
            'age_group'      => 'U12',
            'md_context'     => 'MD-1',
            'description_nl' => 'Aanscherpen één dag voor de wedstrijd; lagere belasting.',
            'slots'          => $this->slotsFor( 75, 4, false ),
        ];
        $rows[] = [
            'age_group'      => 'U12',
            'md_context'     => 'MD+1',
            'description_nl' => 'Hersteldag na de wedstrijd; lage intensiteit.',
            'slots'          => $this->slotsFor( 60, 3, false ),
        ];

        foreach ( [ 'U13', 'U14' ] as $age ) {
            $rows[] = [
                'age_group'      => $age,
                'md_context'     => 'NONE',
                'description_nl' => 'Standaardweek zonder match-context.',
                'slots'          => $this->slotsFor( 90, 7, false ),
            ];
            $rows[] = [
                'age_group'      => $age,
                'md_context'     => 'MD-4',
                'description_nl' => 'Volume-week, vier dagen voor de wedstrijd.',
                'slots'          => $this->slotsFor( 90, 7, false ),
            ];
            $rows[] = [
                'age_group'      => $age,
                'md_context'     => 'MD-3',
                'description_nl' => 'Hoogste belasting van de week.',
                'slots'          => $this->slotsFor( 90, 7, false ),
            ];
            $rows[] = [
                'age_group'      => $age,
                'md_context'     => 'MD-2',
                'description_nl' => 'Overgang van volume naar scherpte; afronding vervangt conditie.',
                'slots'          => $this->slotsFor( 85, 6, true ),
            ];
            $rows[] = [
                'age_group'      => $age,
                'md_context'     => 'MD-1',
                'description_nl' => 'Activatie en scherpte één dag voor de wedstrijd.',
                'slots'          => $this->slotsFor( 75, 5, true ),
            ];
            $rows[] = [
                'age_group'      => $age,
                'md_context'     => 'MD+1',
                'description_nl' => 'Hersteldag na de wedstrijd; lage intensiteit en motoriek.',
                'slots'          => $this->slotsFor( 60, 3, false ),
            ];
            $rows[] = [
                'age_group'      => $age,
                'md_context'     => 'MD+2',
                'description_nl' => 'Voorzichtige opbouw na herstel.',
                'slots'          => $this->slotsFor( 80, 5, false ),
            ];
        }

        return $rows;
    }

    /**
     * Compose the five-slot list for a session of the given total
     * minutes + intensity ceiling. When `$finishing_bias` is true,
     * the conditioning slot is replaced with a `finishing` slot in
     * a slightly lower intensity range (sharpening, not loading).
     *
     * Allocations follow the youth coaching convention: warmup
     * ~13%, technical ~22%, sided_game ~28%, conditioning|finishing
     * ~25%, cool_down ~12%. Adjusted to hit the total exactly.
     *
     * @return array<int, array<string,mixed>>
     */
    private function slotsFor( int $total_minutes, int $intensity_ceiling, bool $finishing_bias ): array {
        $warmup_min     = (int) round( $total_minutes * 0.14 );
        $technical_min  = (int) round( $total_minutes * 0.22 );
        $sided_min      = (int) round( $total_minutes * 0.28 );
        $cooldown_min   = (int) round( $total_minutes * 0.12 );
        $payload_min    = $total_minutes - ( $warmup_min + $technical_min + $sided_min + $cooldown_min );
        if ( $payload_min < 5 ) $payload_min = 5;

        $payload_category = $finishing_bias ? 'finishing' : 'conditioning';
        $payload_max      = $finishing_bias ? max( 3, $intensity_ceiling - 1 ) : $intensity_ceiling;
        $payload_min_band = $finishing_bias ? max( 2, $intensity_ceiling - 3 ) : max( 2, $intensity_ceiling - 2 );

        return [
            [
                'sequence'            => 1,
                'category'            => 'warmup',
                'intensity_band_min'  => 1,
                'intensity_band_max'  => 2,
                'duration_target'     => $warmup_min,
                'duration_tolerance'  => 2,
                'theme_filter'        => false,
            ],
            [
                'sequence'            => 2,
                'category'            => 'technical',
                'intensity_band_min'  => 2,
                'intensity_band_max'  => min( 4, $intensity_ceiling ),
                'duration_target'     => $technical_min,
                'duration_tolerance'  => 3,
                'theme_filter'        => true,
            ],
            [
                'sequence'            => 3,
                'category'            => 'sided_game',
                'intensity_band_min'  => max( 2, $intensity_ceiling - 2 ),
                'intensity_band_max'  => $intensity_ceiling,
                'duration_target'     => $sided_min,
                'duration_tolerance'  => 5,
                'theme_filter'        => true,
            ],
            [
                'sequence'            => 4,
                'category'            => $payload_category,
                'intensity_band_min'  => $payload_min_band,
                'intensity_band_max'  => $payload_max,
                'duration_target'     => $payload_min,
                'duration_tolerance'  => 5,
                'theme_filter'        => true,
            ],
            [
                'sequence'            => 5,
                'category'            => 'cool_down',
                'intensity_band_min'  => 1,
                'intensity_band_max'  => 2,
                'duration_target'     => $cooldown_min,
                'duration_tolerance'  => 2,
                'theme_filter'        => false,
            ],
        ];
    }
};
