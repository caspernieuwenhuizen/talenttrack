<?php
/**
 * Migration 0128 — VCT exercise catalogue STARTER set (#941, VCT-8
 * starter slice, follow-up to epic #905 close).
 *
 * **STARTER SET — methodology unreviewed.**
 *
 * Spec § Seed data calls for an 80-exercise pedagogically-curated
 * catalogue across six categories, gated on HoD/pilot-coach review
 * before merge (per spec § Risks + mitigations). This migration
 * ships a ~25-exercise slim starter so the wizard becomes usable
 * end-to-end today; the canonical 80-exercise expert-curated
 * catalogue ships as the eventual full VCT-8 after HoD/coach
 * review.
 *
 * Each starter exercise carries the full schema (intensity_band,
 * age range, MD bit-flags, equipment, coaching points) but the
 * exercise PICKS, descriptions, and intensity attribution are
 * not coach-reviewed. Recommend HoD audit before broad rollout.
 *
 * Coverage: 5 warmup / 5 technical / 7 sided_game / 4 conditioning
 * / 2 finishing / 2 cool_down = 25 exercises. Per-exercise: 2-3
 * coaching points in tt_vct_coaching_points + Dutch text in
 * tt_translations. English canonical lives in the cp.code column
 * (resolved via the COALESCE fallback in
 * VctCoachingPointsRepository::listForExercise() when no
 * translation row exists for the requested locale).
 *
 * seed_revision = 1; future catalogue corrections raise the
 * revision + UPDATE WHERE seed_revision < N.
 *
 * Idempotent: existence-check on (club_id, code) before insert.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0128_vct_seed_exercises_starter';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $exercises_table   = $p . 'tt_vct_exercises';
        $coaching_table    = $p . 'tt_vct_coaching_points';
        $translations_table = $p . 'tt_translations';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $exercises_table ) ) !== $exercises_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $coaching_table ) ) !== $coaching_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        $club_id = 1;
        $now     = current_time( 'mysql', true );

        foreach ( $this->catalogue() as $ex ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$exercises_table} WHERE club_id = %d AND code = %s LIMIT 1",
                $club_id, $ex['code']
            ) );
            if ( $existing > 0 ) continue;

            $md = $ex['md'] ?? [];
            $wpdb->insert( $exercises_table, [
                'club_id'                  => $club_id,
                'uuid'                     => wp_generate_uuid4(),
                'code'                     => (string) $ex['code'],
                'name_canonical'           => (string) $ex['name'],
                'category'                 => (string) $ex['category'],
                'tactical_theme'           => isset( $ex['theme'] ) ? (string) $ex['theme'] : null,
                'intensity_band'           => (int)    $ex['intensity'],
                'duration_minutes_min'     => (int)    $ex['dur_min'],
                'duration_minutes_max'     => (int)    $ex['dur_max'],
                'players_min'              => (int)    $ex['players_min'],
                'players_max'              => (int)    $ex['players_max'],
                'sided_size'               => isset( $ex['sided'] ) ? (string) $ex['sided'] : null,
                'age_min'                  => (int)    $ex['age_min'],
                'age_max'                  => (int)    $ex['age_max'],
                'md_minus_4'               => isset( $md['md-4'] )  ? 1 : 0,
                'md_minus_3'               => isset( $md['md-3'] )  ? 1 : 0,
                'md_minus_2'               => isset( $md['md-2'] )  ? 1 : 0,
                'md_minus_1'               => isset( $md['md-1'] )  ? 1 : 0,
                'md_zero'                  => isset( $md['md'] )    ? 1 : 0,
                'md_plus_1'                => isset( $md['md+1'] )  ? 1 : 0,
                'md_plus_2'                => isset( $md['md+2'] )  ? 1 : 0,
                'md_none'                  => isset( $md['none'] )  ? 1 : 0,
                'equipment_json'           => wp_json_encode( (array) ( $ex['equipment'] ?? [] ) ),
                'diagram_url'              => null,
                'verheijen_classification' => isset( $ex['verheijen'] ) ? (string) $ex['verheijen'] : null,
                'seed_revision'            => 1,
            ] );
            $exercise_id = (int) $wpdb->insert_id;
            if ( $exercise_id <= 0 ) continue;

            $seq = 0;
            foreach ( $ex['coaching_points'] ?? [] as $cp ) {
                $seq++;
                $wpdb->insert( $coaching_table, [
                    'club_id'     => $club_id,
                    'exercise_id' => $exercise_id,
                    'sequence'    => $seq,
                    'code'        => (string) $cp['code'],
                ] );
                $cp_id = (int) $wpdb->insert_id;
                if ( $cp_id <= 0 ) continue;

                if ( ! empty( $cp['nl'] ) ) {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT IGNORE INTO {$translations_table}
                           (club_id, entity_type, entity_id, field, locale, value, updated_at)
                         VALUES (%d, 'vct_coaching_point', %d, 'text', %s, %s, %s)",
                        $club_id, $cp_id, 'nl_NL', (string) $cp['nl'], $now
                    ) );
                }
            }
        }
    }

    /**
     * The starter catalogue. Compact array per row to keep the
     * migration readable.
     *
     * Field reference (all required unless marked optional):
     *   code            unique slug
     *   name            canonical English name
     *   category        vct_exercise_category value
     *   theme           vct_tactical_theme value (nullable; null = theme-agnostic)
     *   intensity       1-10 band
     *   dur_min/dur_max minutes range
     *   players_min/max headcount range
     *   sided           e.g. '4v4' (nullable)
     *   age_min/age_max numeric age (9 = U10, 10 = U11, …)
     *   md              array of suitable MD contexts (keys: 'md-4'..'md+2', 'md', 'none')
     *   equipment       array of equipment names
     *   verheijen       classification (nullable)
     *   coaching_points list of { code, nl }
     *
     * @return list<array<string,mixed>>
     */
    private function catalogue(): array {
        $all_md_none_and_minus = [ 'md-4' => 1, 'md-3' => 1, 'md-2' => 1, 'md-1' => 1, 'none' => 1 ];

        return [
            // ── WARMUP (5) ──────────────────────────────────────────────
            [
                'code' => 'warmup_dynamic_passing', 'name' => 'Dynamic passing warm-up',
                'category' => 'warmup', 'theme' => null, 'intensity' => 2,
                'dur_min' => 8, 'dur_max' => 15, 'players_min' => 6, 'players_max' => 24,
                'age_min' => 9, 'age_max' => 14,
                'md' => $all_md_none_and_minus + [ 'md+1' => 1, 'md+2' => 1 ],
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'first_touch_away', 'nl' => 'Eerste contact weg van de tegenstander.' ],
                    [ 'code' => 'eyes_up', 'nl' => 'Hoofd omhoog voordat je de bal aanneemt.' ],
                ],
            ],
            [
                'code' => 'warmup_rondo_4v1', 'name' => 'Rondo 4v1',
                'category' => 'warmup', 'theme' => 'possession', 'intensity' => 2,
                'dur_min' => 6, 'dur_max' => 12, 'players_min' => 5, 'players_max' => 20,
                'sided' => '4v1', 'age_min' => 9, 'age_max' => 14,
                'md' => $all_md_none_and_minus + [ 'md+1' => 1, 'md+2' => 1 ],
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'two_touch_max', 'nl' => 'Maximaal twee balcontacten per actie.' ],
                    [ 'code' => 'support_angles', 'nl' => 'Bied steun aan in een open driehoek.' ],
                    [ 'code' => 'protect_ball', 'nl' => 'Lichaam tussen bal en verdediger.' ],
                ],
            ],
            [
                'code' => 'warmup_coordination_ladder', 'name' => 'Coordination ladder + ball',
                'category' => 'warmup', 'theme' => null, 'intensity' => 2,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 4, 'players_max' => 18,
                'age_min' => 9, 'age_max' => 12,
                'md' => $all_md_none_and_minus + [ 'md+1' => 1, 'md+2' => 1 ],
                'equipment' => [ 'agility ladder', 'balls' ],
                'coaching_points' => [
                    [ 'code' => 'light_feet', 'nl' => 'Lichte voeten, korte contacten op de grond.' ],
                    [ 'code' => 'ball_after_ladder', 'nl' => 'Direct na de ladder: bal aannemen en passen.' ],
                ],
            ],
            [
                'code' => 'warmup_movement_circuit', 'name' => 'Dynamic movement circuit',
                'category' => 'warmup', 'theme' => null, 'intensity' => 3,
                'dur_min' => 10, 'dur_max' => 15, 'players_min' => 6, 'players_max' => 24,
                'age_min' => 11, 'age_max' => 14,
                'md' => $all_md_none_and_minus + [ 'md+2' => 1 ],
                'equipment' => [ 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'full_range_motion', 'nl' => 'Voer elke beweging volledig uit, geen halve bewegingen.' ],
                    [ 'code' => 'progressive_speed', 'nl' => 'Bouw het tempo geleidelijk op gedurende het rondje.' ],
                ],
            ],
            [
                'code' => 'warmup_partner_passing', 'name' => 'Partner short-short-long passing',
                'category' => 'warmup', 'theme' => 'build_up', 'intensity' => 2,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 4, 'players_max' => 20,
                'age_min' => 11, 'age_max' => 14,
                'md' => $all_md_none_and_minus + [ 'md+1' => 1, 'md+2' => 1 ],
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'pass_weight', 'nl' => 'Pasgewicht afstemmen op de afstand.' ],
                    [ 'code' => 'open_body', 'nl' => 'Open lichaamshouding om beide kanten te zien.' ],
                ],
            ],

            // ── TECHNICAL (5) ──────────────────────────────────────────
            [
                'code' => 'technical_first_touch_directions', 'name' => 'First-touch into space (4 directions)',
                'category' => 'technical', 'theme' => null, 'intensity' => 3,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 6, 'players_max' => 18,
                'age_min' => 9, 'age_max' => 12,
                'md' => $all_md_none_and_minus,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'check_shoulder', 'nl' => 'Kijk over je schouder voordat je de bal krijgt.' ],
                    [ 'code' => 'soft_first_touch', 'nl' => 'Zachte eerste aanname, bal blijft dicht bij.' ],
                ],
            ],
            [
                'code' => 'technical_dribbling_gates', 'name' => 'Dribbling through gates',
                'category' => 'technical', 'theme' => '1v1_duels', 'intensity' => 4,
                'dur_min' => 10, 'dur_max' => 15, 'players_min' => 6, 'players_max' => 18,
                'age_min' => 9, 'age_max' => 12,
                'md' => $all_md_none_and_minus,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'change_pace', 'nl' => 'Wissel van tempo om de poortjes te halen.' ],
                    [ 'code' => 'use_both_feet', 'nl' => 'Beide voeten gebruiken, niet alleen sterke kant.' ],
                ],
            ],
            [
                'code' => 'technical_passing_diamond', 'name' => 'Passing diamond with movement',
                'category' => 'technical', 'theme' => 'build_up', 'intensity' => 4,
                'dur_min' => 12, 'dur_max' => 20, 'players_min' => 8, 'players_max' => 16,
                'age_min' => 11, 'age_max' => 14,
                'md' => $all_md_none_and_minus,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'pass_then_move', 'nl' => 'Speel de bal en breek direct weg.' ],
                    [ 'code' => 'receive_back_foot', 'nl' => 'Aannemen op de achterste voet, klaar voor de volgende actie.' ],
                ],
            ],
            [
                'code' => 'technical_combination_play', 'name' => 'Wall-pass combinations',
                'category' => 'technical', 'theme' => 'build_up', 'intensity' => 5,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 8, 'players_max' => 16,
                'age_min' => 12, 'age_max' => 14,
                'md' => $all_md_none_and_minus,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'one_two_timing', 'nl' => 'Eén-twee combinatie: timing van de loopactie is alles.' ],
                    [ 'code' => 'protect_then_play', 'nl' => 'Eerst lichaam tussen bal en verdediger, dan de pas.' ],
                ],
            ],
            [
                'code' => 'technical_long_pass_control', 'name' => 'Long pass + chest/thigh control',
                'category' => 'technical', 'theme' => 'transition', 'intensity' => 5,
                'dur_min' => 10, 'dur_max' => 15, 'players_min' => 6, 'players_max' => 16,
                'age_min' => 12, 'age_max' => 14,
                'md' => $all_md_none_and_minus,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'plant_foot', 'nl' => 'Standbeen naast de bal, lichaam in balans.' ],
                    [ 'code' => 'cushion_ball', 'nl' => 'Borst- of dijbeenaanname: vang de bal zacht op.' ],
                ],
            ],

            // ── SIDED GAME (7) ─────────────────────────────────────────
            [
                'code' => 'sided_3v3_possession', 'name' => '3v3 possession (small grid)',
                'category' => 'sided_game', 'theme' => 'possession', 'intensity' => 5,
                'dur_min' => 12, 'dur_max' => 20, 'players_min' => 6, 'players_max' => 12,
                'sided' => '3v3', 'age_min' => 9, 'age_max' => 12,
                'md' => $all_md_none_and_minus,
                'equipment' => [ 'balls', 'cones', 'pinnies' ],
                'coaching_points' => [
                    [ 'code' => 'create_triangles', 'nl' => 'Vorm voortdurend driehoekjes met je teamgenoten.' ],
                    [ 'code' => 'first_time_when_possible', 'nl' => 'Eerste keer spelen als het kan, anders aannemen.' ],
                ],
            ],
            [
                'code' => 'sided_4v4_directional', 'name' => '4v4 with end-zones (directional)',
                'category' => 'sided_game', 'theme' => 'transition', 'intensity' => 6,
                'dur_min' => 12, 'dur_max' => 20, 'players_min' => 8, 'players_max' => 16,
                'sided' => '4v4', 'age_min' => 11, 'age_max' => 14,
                'md' => $all_md_none_and_minus,
                'equipment' => [ 'balls', 'cones', 'pinnies' ],
                'coaching_points' => [
                    [ 'code' => 'transition_speed', 'nl' => 'Schakel snel om bij balverlies of -winst.' ],
                    [ 'code' => 'use_width', 'nl' => 'Gebruik de volle breedte van het veld.' ],
                ],
            ],
            [
                'code' => 'sided_4v4_pressing', 'name' => '4v4 pressing-trigger game',
                'category' => 'sided_game', 'theme' => 'pressing', 'intensity' => 6,
                'dur_min' => 10, 'dur_max' => 18, 'players_min' => 8, 'players_max' => 16,
                'sided' => '4v4', 'age_min' => 11, 'age_max' => 14,
                'md' => $all_md_none_and_minus,
                'equipment' => [ 'balls', 'cones', 'pinnies' ],
                'coaching_points' => [
                    [ 'code' => 'press_trigger', 'nl' => 'Druk zetten op het signaal van de coach.' ],
                    [ 'code' => 'compact_unit', 'nl' => 'Houd de linie compact, geen gat tussen de spelers.' ],
                ],
            ],
            [
                'code' => 'sided_5v5_build_up', 'name' => '5v5 + 2 keepers, build-up from back',
                'category' => 'sided_game', 'theme' => 'build_up', 'intensity' => 5,
                'dur_min' => 15, 'dur_max' => 25, 'players_min' => 10, 'players_max' => 14,
                'sided' => '5v5', 'age_min' => 11, 'age_max' => 14,
                'md' => $all_md_none_and_minus,
                'equipment' => [ 'balls', 'cones', 'pinnies', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'start_from_keeper', 'nl' => 'Elke aanval start bij de keeper.' ],
                    [ 'code' => 'first_pass_forward', 'nl' => 'Probeer de eerste pas naar voren te spelen.' ],
                ],
            ],
            [
                'code' => 'sided_6v6_defending', 'name' => '6v6 with defending focus',
                'category' => 'sided_game', 'theme' => 'defending', 'intensity' => 6,
                'dur_min' => 15, 'dur_max' => 22, 'players_min' => 12, 'players_max' => 16,
                'sided' => '6v6', 'age_min' => 12, 'age_max' => 14,
                'md' => $all_md_none_and_minus,
                'equipment' => [ 'balls', 'cones', 'pinnies', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'defending_distance', 'nl' => 'Goede afstand tot je tegenstander: niet te dichtbij, niet te ver.' ],
                    [ 'code' => 'cover_diagonal', 'nl' => 'Dek je teamgenoot in een diagonale lijn.' ],
                ],
            ],
            [
                'code' => 'sided_7v7_full_game', 'name' => '7v7 full game with rules',
                'category' => 'sided_game', 'theme' => 'mixed', 'intensity' => 6,
                'dur_min' => 18, 'dur_max' => 25, 'players_min' => 14, 'players_max' => 18,
                'sided' => '7v7', 'age_min' => 12, 'age_max' => 14,
                'md' => $all_md_none_and_minus,
                'equipment' => [ 'balls', 'cones', 'pinnies', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'apply_match_rules', 'nl' => 'Pas de wedstrijdregels toe die we hebben besproken.' ],
                    [ 'code' => 'communicate', 'nl' => 'Praat met elkaar: vraag de bal, waarschuw bij druk.' ],
                ],
            ],
            [
                'code' => 'sided_2v1_continuous', 'name' => '2v1 continuous waves',
                'category' => 'sided_game', 'theme' => 'counter', 'intensity' => 6,
                'dur_min' => 10, 'dur_max' => 16, 'players_min' => 6, 'players_max' => 14,
                'sided' => '2v1', 'age_min' => 11, 'age_max' => 14,
                'md' => $all_md_none_and_minus,
                'equipment' => [ 'balls', 'cones', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'attack_at_pace', 'nl' => 'Val aan met snelheid; geef de verdediger geen tijd.' ],
                    [ 'code' => 'commit_defender', 'nl' => 'Lok de verdediger naar je toe en speel dan de pass.' ],
                ],
            ],

            // ── CONDITIONING (4) ───────────────────────────────────────
            [
                'code' => 'conditioning_shuttle_runs', 'name' => 'Football-action shuttle runs',
                'category' => 'conditioning', 'theme' => null, 'intensity' => 6,
                'dur_min' => 10, 'dur_max' => 18, 'players_min' => 4, 'players_max' => 20,
                'age_min' => 12, 'age_max' => 14,
                'md' => [ 'md-4' => 1, 'md-3' => 1, 'none' => 1 ],
                'equipment' => [ 'cones', 'balls' ],
                'verheijen' => 'football_endurance',
                'coaching_points' => [
                    [ 'code' => 'recover_actively', 'nl' => 'Actief herstellen tussen sprints, niet stilstaan.' ],
                    [ 'code' => 'finish_with_ball', 'nl' => 'Elke sprint eindigt met een balactie.' ],
                ],
            ],
            [
                'code' => 'conditioning_high_intensity_intervals', 'name' => 'High-intensity interval game',
                'category' => 'conditioning', 'theme' => null, 'intensity' => 7,
                'dur_min' => 12, 'dur_max' => 20, 'players_min' => 8, 'players_max' => 18,
                'age_min' => 12, 'age_max' => 14,
                'md' => [ 'md-4' => 1, 'md-3' => 1, 'none' => 1 ],
                'equipment' => [ 'balls', 'cones' ],
                'verheijen' => 'football_endurance',
                'coaching_points' => [
                    [ 'code' => 'all_out_intervals', 'nl' => 'Geef alles tijdens de werkblokken.' ],
                    [ 'code' => 'short_rest', 'nl' => 'Korte herstelperiodes: bewegen blijven, geen sprintherstel.' ],
                ],
            ],
            [
                'code' => 'conditioning_position_game', 'name' => 'Position-specific conditioning game',
                'category' => 'conditioning', 'theme' => 'mixed', 'intensity' => 6,
                'dur_min' => 15, 'dur_max' => 25, 'players_min' => 10, 'players_max' => 18,
                'age_min' => 11, 'age_max' => 14,
                'md' => [ 'md-4' => 1, 'md-3' => 1, 'none' => 1 ],
                'equipment' => [ 'balls', 'cones', 'pinnies' ],
                'verheijen' => 'football_endurance',
                'coaching_points' => [
                    [ 'code' => 'stay_in_zone', 'nl' => 'Blijf in je positie-zone, kom alleen uit voor specifieke acties.' ],
                    [ 'code' => 'repeat_movements', 'nl' => 'Herhaal de wedstrijdspecifieke loopacties.' ],
                ],
            ],
            [
                'code' => 'conditioning_low_intensity_recovery', 'name' => 'Low-intensity recovery circuit',
                'category' => 'conditioning', 'theme' => null, 'intensity' => 3,
                'dur_min' => 10, 'dur_max' => 18, 'players_min' => 4, 'players_max' => 20,
                'age_min' => 9, 'age_max' => 14,
                'md' => [ 'md+1' => 1, 'md+2' => 1 ],
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'low_heart_rate', 'nl' => 'Houd het tempo laag: hartslag bewust lager houden.' ],
                    [ 'code' => 'mobility_focus', 'nl' => 'Focus op mobiliteit en techniek, niet op tempo.' ],
                ],
            ],

            // ── FINISHING (2) ──────────────────────────────────────────
            [
                'code' => 'finishing_one_touch_box', 'name' => 'One-touch finishing in the box',
                'category' => 'finishing', 'theme' => 'finishing', 'intensity' => 5,
                'dur_min' => 10, 'dur_max' => 18, 'players_min' => 6, 'players_max' => 14,
                'age_min' => 12, 'age_max' => 14,
                'md' => [ 'md-2' => 1, 'md-1' => 1, 'none' => 1 ],
                'equipment' => [ 'balls', 'cones', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'eyes_on_keeper', 'nl' => 'Kijk waar de keeper staat voor je schiet.' ],
                    [ 'code' => 'placement_over_power', 'nl' => 'Plaatsing boven kracht: hoek kiezen, niet alleen hard.' ],
                ],
            ],
            [
                'code' => 'finishing_combination_to_shot', 'name' => 'Combination play to shot',
                'category' => 'finishing', 'theme' => 'finishing', 'intensity' => 5,
                'dur_min' => 12, 'dur_max' => 20, 'players_min' => 8, 'players_max' => 14,
                'age_min' => 12, 'age_max' => 14,
                'md' => [ 'md-2' => 1, 'md-1' => 1, 'none' => 1 ],
                'equipment' => [ 'balls', 'cones', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'combo_then_shot', 'nl' => 'Combinatie eerst, dan pas afronden — niet andersom.' ],
                    [ 'code' => 'follow_up_rebound', 'nl' => 'Loop door op de rebound, ook na een goede poging.' ],
                ],
            ],

            // ── COOL DOWN (2) ──────────────────────────────────────────
            [
                'code' => 'cool_down_stretch_circuit', 'name' => 'Static stretching circuit',
                'category' => 'cool_down', 'theme' => null, 'intensity' => 1,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 4, 'players_max' => 24,
                'age_min' => 9, 'age_max' => 14,
                'md' => [ 'md-4' => 1, 'md-3' => 1, 'md-2' => 1, 'md-1' => 1, 'md+1' => 1, 'md+2' => 1, 'none' => 1 ],
                'equipment' => [],
                'coaching_points' => [
                    [ 'code' => 'hold_30_seconds', 'nl' => 'Houd elke stretch 30 seconden vast.' ],
                    [ 'code' => 'breathe_through', 'nl' => 'Adem rustig door tijdens het rekken.' ],
                ],
            ],
            [
                'code' => 'cool_down_walking_recovery', 'name' => 'Light walking + mobility',
                'category' => 'cool_down', 'theme' => null, 'intensity' => 1,
                'dur_min' => 6, 'dur_max' => 10, 'players_min' => 4, 'players_max' => 24,
                'age_min' => 9, 'age_max' => 14,
                'md' => [ 'md-4' => 1, 'md-3' => 1, 'md-2' => 1, 'md-1' => 1, 'md+1' => 1, 'md+2' => 1, 'none' => 1 ],
                'equipment' => [],
                'coaching_points' => [
                    [ 'code' => 'walk_at_chat_pace', 'nl' => 'Wandel in een tempo waarop je kunt praten.' ],
                    [ 'code' => 'mobility_joints', 'nl' => 'Beweeg alle gewrichten rustig door hun volledige bereik.' ],
                ],
            ],
        ];
    }
};
