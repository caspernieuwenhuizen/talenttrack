<?php
/**
 * Migration 0181 — VCT exercise catalogue, full 80 spread (#1129,
 * VCT-8, spun out of epic #905).
 *
 * Completes the VCT-8 catalogue started by the 0177 scaffold. Where
 * 0177 shipped a representative draft of 12 exercises (2 per category),
 * this migration adds the remaining 68 so the `cat_*` catalogue reaches
 * the full target spread of 80:
 *
 *   warmup       +8  (→ 10)
 *   technical    +18 (→ 20)
 *   sided_game   +18 (→ 20)
 *   conditioning +8  (→ 10)
 *   finishing    +8  (→ 10)
 *   cool_down    +8  (→ 10)
 *
 * Scope of this slice: canonical English plus native Dutch (nl_NL)
 * coaching points only. The fr_FR / de_DE / es_ES translations and the
 * HoD / pilot-coach methodology review of the exercise picks, intensity
 * bands, and age ranges are a deliberate follow-up — #1129 stays open
 * until both land.
 *
 * Mechanics mirror 0177 exactly: codes namespaced `cat_*` and distinct
 * from the 12 scaffold codes; coaching-point text is DATA (lives in
 * `tt_vct_coaching_points` + `tt_translations`), not gettext, so it
 * carries no `.po` entries; `cp.code` holds a stable English slug used
 * as the `VctCoachingPointsRepository::listForExercise()` COALESCE
 * fallback; the readable canonical English is stored as an `en_US`
 * translation row.
 *
 * Intensity bands respect the per-age ceilings seeded in
 * `0125_vct_seed_age_profiles_and_templates` (U10=3, U11=4, U12=5,
 * U13/U14=7) so no exercise exceeds the workload envelope for the
 * youngest age it's offered to.
 *
 * `seed_revision = 1` on every row so a later catalogue correction can
 * raise the revision and re-write provisional entries without trampling
 * operator edits.
 *
 * Idempotent + forward-only: existence-check on `(club_id, code)` before
 * each exercise insert, `INSERT IGNORE` on the translation rows.
 * Re-running on an already-seeded club is a no-op.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Tenancy\CurrentClub;

return new class extends Migration {

    public function getName(): string {
        return '0183_seed_vct_exercise_catalogue_full';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $exercises_table    = $p . 'tt_vct_exercises';
        $coaching_table     = $p . 'tt_vct_coaching_points';
        $translations_table = $p . 'tt_translations';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $exercises_table ) ) !== $exercises_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $coaching_table ) ) !== $coaching_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        $club_id = (int) CurrentClub::id();
        if ( $club_id <= 0 ) $club_id = 1;

        $now = current_time( 'mysql', true );

        foreach ( $this->catalogue() as $ex ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$exercises_table} WHERE club_id = %d AND code = %s LIMIT 1",
                $club_id, (string) $ex['code']
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
                'intensity_band'           => (int) $ex['intensity'],
                'duration_minutes_min'     => (int) $ex['dur_min'],
                'duration_minutes_max'     => (int) $ex['dur_max'],
                'players_min'              => (int) $ex['players_min'],
                'players_max'              => (int) $ex['players_max'],
                'sided_size'               => isset( $ex['sided'] ) ? (string) $ex['sided'] : null,
                'age_min'                  => (int) $ex['age_min'],
                'age_max'                  => (int) $ex['age_max'],
                'md_minus_4'               => isset( $md['md-4'] ) ? 1 : 0,
                'md_minus_3'               => isset( $md['md-3'] ) ? 1 : 0,
                'md_minus_2'               => isset( $md['md-2'] ) ? 1 : 0,
                'md_minus_1'               => isset( $md['md-1'] ) ? 1 : 0,
                'md_zero'                  => isset( $md['md'] )   ? 1 : 0,
                'md_plus_1'                => isset( $md['md+1'] ) ? 1 : 0,
                'md_plus_2'                => isset( $md['md+2'] ) ? 1 : 0,
                'md_none'                  => isset( $md['none'] ) ? 1 : 0,
                'equipment_json'           => wp_json_encode( (array) ( $ex['equipment'] ?? [] ) ),
                'diagram_url'              => isset( $ex['diagram'] ) ? (string) $ex['diagram'] : null,
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

                foreach ( $this->locales() as $locale => $key ) {
                    if ( empty( $cp[ $key ] ) ) continue;
                    $wpdb->query( $wpdb->prepare(
                        "INSERT IGNORE INTO {$translations_table}
                           (club_id, entity_type, entity_id, field, locale, value, updated_at)
                         VALUES (%d, 'vct_coaching_point', %d, 'text', %s, %s, %s)",
                        $club_id, $cp_id, $locale, (string) $cp[ $key ], $now
                    ) );
                }
            }
        }
    }

    /**
     * Locale → catalogue-array key. Canonical English is stored as an
     * explicit `en_US` row so the rendered cue reads as prose, not as
     * the slug `cp.code` fallback. This slice authors en + nl only;
     * fr / de / es are a deliberate follow-up on #1129.
     *
     * @return array<string,string>
     */
    private function locales(): array {
        return [
            'en_US' => 'en',
            'nl_NL' => 'nl',
        ];
    }

    /**
     * The 68 catalogue rows that complete the full 80 spread. Field
     * reference matches 0177's catalogue():
     *   code            unique slug (namespaced `cat_*`)
     *   name            canonical English name
     *   category        vct_exercise_category value
     *   theme           vct_tactical_theme value (optional; null = theme-agnostic)
     *   intensity       1-10 band (respects per-age ceilings, see header)
     *   dur_min/dur_max minutes range
     *   players_min/max headcount range
     *   sided           e.g. '4v4' (optional)
     *   age_min/age_max numeric age (9 = U10, 10 = U11, …, 13 = U14)
     *   md              array of suitable MD contexts ('md-4'..'md+2', 'md', 'none')
     *   equipment       array of equipment names (optional)
     *   verheijen       classification (optional)
     *   coaching_points list of { code, en, nl }
     *
     * @return list<array<string,mixed>>
     */
    private function catalogue(): array {
        $pre_md  = [ 'md-4' => 1, 'md-3' => 1, 'md-2' => 1, 'md-1' => 1, 'none' => 1 ];
        $any_md  = $pre_md + [ 'md+1' => 1, 'md+2' => 1 ];
        $broad   = [ 'md-4' => 1, 'md-3' => 1, 'md-2' => 1, 'md-1' => 1, 'md+1' => 1, 'md+2' => 1, 'none' => 1 ];
        $sharp   = [ 'md-2' => 1, 'md-1' => 1, 'none' => 1 ];
        $load    = [ 'md-4' => 1, 'md-3' => 1, 'none' => 1 ];
        $recover = [ 'md+1' => 1, 'md+2' => 1 ];

        return [
            // ── WARMUP (+8 → 10) ────────────────────────────────────────
            [
                'code' => 'cat_warmup_dynamic_mobility', 'name' => 'Dynamic mobility flow',
                'category' => 'warmup', 'theme' => null, 'intensity' => 2,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 4, 'players_max' => 24,
                'age_min' => 9, 'age_max' => 13, 'md' => $any_md,
                'equipment' => [ 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'full_range_movement', 'en' => 'Take every joint through its full range of movement.', 'nl' => 'Beweeg elk gewricht door zijn volledige bewegingsbereik.' ],
                    [ 'code' => 'controlled_not_rushed', 'en' => 'Move in a controlled way, never rushed or jerky.', 'nl' => 'Beweeg gecontroleerd, nooit gehaast of schokkerig.' ],
                    [ 'code' => 'raise_temperature', 'en' => 'Build up gently so the body warms before the ball work.', 'nl' => 'Bouw rustig op zodat het lichaam opwarmt voor het balwerk.' ],
                ],
            ],
            [
                'code' => 'cat_warmup_dribble_gates', 'name' => 'Dribble through the gates',
                'category' => 'warmup', 'theme' => null, 'intensity' => 2,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 4, 'players_max' => 20,
                'age_min' => 9, 'age_max' => 12, 'md' => $any_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'head_up_dribble', 'en' => 'Keep your head up to spot the next open gate.', 'nl' => 'Houd je hoofd omhoog om het volgende open poortje te zien.' ],
                    [ 'code' => 'small_touches', 'en' => 'Use small touches to keep the ball under control.', 'nl' => 'Gebruik kleine tikjes om de bal onder controle te houden.' ],
                    [ 'code' => 'change_of_direction', 'en' => 'Change direction sharply after each gate.', 'nl' => 'Verander scherp van richting na elk poortje.' ],
                ],
            ],
            [
                'code' => 'cat_warmup_partner_passing', 'name' => 'Partner passing rhythm',
                'category' => 'warmup', 'theme' => null, 'intensity' => 2,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 6, 'players_max' => 24,
                'age_min' => 9, 'age_max' => 13, 'md' => $any_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'inside_foot_pass', 'en' => 'Pass with the inside of the foot for accuracy.', 'nl' => 'Pass met de binnenkant van de voet voor precisie.' ],
                    [ 'code' => 'open_up_to_receive', 'en' => 'Open your body up before the ball arrives.', 'nl' => 'Draai je lichaam open voordat de bal aankomt.' ],
                    [ 'code' => 'steady_rhythm', 'en' => 'Find a steady passing rhythm with your partner.', 'nl' => 'Vind een rustig pass-ritme met je maatje.' ],
                ],
            ],
            [
                'code' => 'cat_warmup_ladder_coordination', 'name' => 'Agility ladder coordination',
                'category' => 'warmup', 'theme' => null, 'intensity' => 3,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 4, 'players_max' => 16,
                'age_min' => 10, 'age_max' => 13, 'md' => $any_md,
                'equipment' => [ 'ladders', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'quick_feet', 'en' => 'Keep the feet quick and light through the ladder.', 'nl' => 'Houd de voeten snel en licht door de ladder.' ],
                    [ 'code' => 'arms_drive', 'en' => 'Drive the arms in time with the feet.', 'nl' => 'Pomp de armen in ritme met de voeten.' ],
                    [ 'code' => 'stay_balanced', 'en' => 'Stay balanced and upright over the ankles.', 'nl' => 'Blijf in balans en rechtop boven de enkels.' ],
                ],
            ],
            [
                'code' => 'cat_warmup_possession_circle', 'name' => 'Possession warm-up circle',
                'category' => 'warmup', 'theme' => 'possession', 'intensity' => 3,
                'dur_min' => 8, 'dur_max' => 14, 'players_min' => 8, 'players_max' => 20,
                'age_min' => 10, 'age_max' => 13, 'md' => $any_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'show_for_ball', 'en' => 'Always show for the ball on an open angle.', 'nl' => 'Bied je altijd aan in een open hoek.' ],
                    [ 'code' => 'first_touch_away', 'en' => 'Take your first touch away from the nearest player.', 'nl' => 'Neem je eerste aanname weg van de dichtstbijzijnde speler.' ],
                    [ 'code' => 'keep_it_simple', 'en' => 'Keep the passes simple while the body warms up.', 'nl' => 'Houd de passes simpel terwijl het lichaam opwarmt.' ],
                ],
            ],
            [
                'code' => 'cat_warmup_two_touch_grid', 'name' => 'Two-touch movement grid',
                'category' => 'warmup', 'theme' => 'possession', 'intensity' => 3,
                'dur_min' => 8, 'dur_max' => 14, 'players_min' => 8, 'players_max' => 20,
                'age_min' => 10, 'age_max' => 13, 'md' => $any_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'pass_into_path', 'en' => 'Pass into the path of the moving player.', 'nl' => 'Speel de bal in de loop van de bewegende speler.' ],
                    [ 'code' => 'support_quickly', 'en' => 'Move to support as soon as you have passed.', 'nl' => 'Beweeg meteen om steun te bieden nadat je hebt gepasst.' ],
                    [ 'code' => 'scan_constantly', 'en' => 'Scan constantly so you always know your options.', 'nl' => 'Scan voortdurend zodat je altijd je opties kent.' ],
                ],
            ],
            [
                'code' => 'cat_warmup_handball_activation', 'name' => 'Handball space-finding game',
                'category' => 'warmup', 'theme' => null, 'intensity' => 3,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 8, 'players_max' => 20,
                'age_min' => 10, 'age_max' => 13, 'md' => $any_md,
                'equipment' => [ 'balls', 'cones', 'bibs' ],
                'coaching_points' => [
                    [ 'code' => 'find_free_space', 'en' => 'Move into free space to offer a catching option.', 'nl' => 'Beweeg naar vrije ruimte om een vangkans te bieden.' ],
                    [ 'code' => 'communicate_calls', 'en' => 'Call for the ball so teammates find you.', 'nl' => 'Roep om de bal zodat ploeggenoten je vinden.' ],
                    [ 'code' => 'spread_out', 'en' => 'Spread out — do not bunch around the ball.', 'nl' => 'Verspreid je — klit niet samen rond de bal.' ],
                ],
            ],
            [
                'code' => 'cat_warmup_reaction_colours', 'name' => 'Colour-call reaction warm-up',
                'category' => 'warmup', 'theme' => null, 'intensity' => 3,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 6, 'players_max' => 18,
                'age_min' => 10, 'age_max' => 13, 'md' => $any_md,
                'equipment' => [ 'cones', 'balls' ],
                'coaching_points' => [
                    [ 'code' => 'react_on_signal', 'en' => 'React the instant the colour is called.', 'nl' => 'Reageer op het moment dat de kleur wordt geroepen.' ],
                    [ 'code' => 'eyes_up', 'en' => 'Keep your eyes up to read the next signal.', 'nl' => 'Houd je ogen omhoog om het volgende signaal te lezen.' ],
                    [ 'code' => 'soft_first_steps', 'en' => 'Push off with quick, soft first steps.', 'nl' => 'Zet af met snelle, soepele eerste passen.' ],
                ],
            ],

            // ── TECHNICAL (+18 → 20) ────────────────────────────────────
            [
                'code' => 'cat_technical_cones_dribble_slalom', 'name' => 'Cone slalom dribbling',
                'category' => 'technical', 'theme' => '1v1_duels', 'intensity' => 3,
                'dur_min' => 10, 'dur_max' => 16, 'players_min' => 4, 'players_max' => 18,
                'age_min' => 9, 'age_max' => 12, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'both_feet_touches', 'en' => 'Touch the ball with both feet through the slalom.', 'nl' => 'Raak de bal met beide voeten door de slalom.' ],
                    [ 'code' => 'close_control', 'en' => 'Keep the ball close so it never runs away.', 'nl' => 'Houd de bal dichtbij zodat hij nooit wegloopt.' ],
                    [ 'code' => 'accelerate_out', 'en' => 'Accelerate out of the last cone with a big touch.', 'nl' => 'Versnel uit de laatste pion met een grote tik.' ],
                ],
            ],
            [
                'code' => 'cat_technical_wall_pass_combo', 'name' => 'Wall-pass combination',
                'category' => 'technical', 'theme' => 'build_up', 'intensity' => 4,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 6, 'players_max' => 16,
                'age_min' => 10, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'set_firm', 'en' => 'Set the wall pass back firm and accurate.', 'nl' => 'Kaats de muurpass stevig en zuiver terug.' ],
                    [ 'code' => 'run_off_the_pass', 'en' => 'Sprint past the defender as you play the wall pass.', 'nl' => 'Sprint langs de verdediger terwijl je de muurpass speelt.' ],
                    [ 'code' => 'first_time_return', 'en' => 'Return the ball first-time into space.', 'nl' => 'Leg de bal in één keer terug in de ruimte.' ],
                ],
            ],
            [
                'code' => 'cat_technical_receiving_under_pressure', 'name' => 'Receiving under light pressure',
                'category' => 'technical', 'theme' => 'possession', 'intensity' => 4,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 6, 'players_max' => 16,
                'age_min' => 10, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs' ],
                'coaching_points' => [
                    [ 'code' => 'check_shoulder', 'en' => 'Check your shoulder before the ball reaches you.', 'nl' => 'Kijk over je schouder voordat de bal je bereikt.' ],
                    [ 'code' => 'protect_the_ball', 'en' => 'Use your body to shield the ball from the defender.', 'nl' => 'Gebruik je lichaam om de bal af te schermen van de verdediger.' ],
                    [ 'code' => 'touch_into_space', 'en' => 'Take your first touch into the available space.', 'nl' => 'Neem je eerste aanname richting de vrije ruimte.' ],
                ],
            ],
            [
                'code' => 'cat_technical_long_pass_switch', 'name' => 'Switching the play long',
                'category' => 'technical', 'theme' => 'build_up', 'intensity' => 4,
                'dur_min' => 12, 'dur_max' => 20, 'players_min' => 8, 'players_max' => 16,
                'age_min' => 11, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'plant_foot_aim', 'en' => 'Point your plant foot at the target before you strike.', 'nl' => 'Richt je standbeen op het doel voordat je raakt.' ],
                    [ 'code' => 'lofted_weight', 'en' => 'Loft the ball with enough weight to clear the middle.', 'nl' => 'Geef de bal genoeg hoogte en vaart om het middenveld te overbruggen.' ],
                    [ 'code' => 'receiver_prepares', 'en' => 'The receiver opens up early to control the switch.', 'nl' => 'De ontvanger draait vroeg open om de wissel te controleren.' ],
                ],
            ],
            [
                'code' => 'cat_technical_turning_drill', 'name' => 'Turning under pressure drill',
                'category' => 'technical', 'theme' => '1v1_duels', 'intensity' => 4,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 6, 'players_max' => 16,
                'age_min' => 10, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs' ],
                'coaching_points' => [
                    [ 'code' => 'disguise_the_turn', 'en' => 'Disguise the turn until the last moment.', 'nl' => 'Verberg de draai tot het laatste moment.' ],
                    [ 'code' => 'turn_to_open_side', 'en' => 'Turn towards the open side, away from the marker.', 'nl' => 'Draai naar de open kant, weg van je tegenstander.' ],
                    [ 'code' => 'accelerate_after_turn', 'en' => 'Accelerate immediately after completing the turn.', 'nl' => 'Versnel direct na het voltooien van de draai.' ],
                ],
            ],
            [
                'code' => 'cat_technical_first_touch_directional', 'name' => 'Directional first touch gates',
                'category' => 'technical', 'theme' => null, 'intensity' => 3,
                'dur_min' => 10, 'dur_max' => 16, 'players_min' => 6, 'players_max' => 18,
                'age_min' => 9, 'age_max' => 12, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'decide_before_touch', 'en' => 'Decide where to go before the ball arrives.', 'nl' => 'Beslis waar je heen gaat voordat de bal aankomt.' ],
                    [ 'code' => 'touch_towards_target', 'en' => 'Direct your first touch towards the next gate.', 'nl' => 'Stuur je eerste aanname richting het volgende poortje.' ],
                    [ 'code' => 'cushion_the_ball', 'en' => 'Cushion the ball softly so it stays playable.', 'nl' => 'Demp de bal zacht zodat hij bespeelbaar blijft.' ],
                ],
            ],
            [
                'code' => 'cat_technical_third_man_run', 'name' => 'Third-man combination',
                'category' => 'technical', 'theme' => 'build_up', 'intensity' => 5,
                'dur_min' => 14, 'dur_max' => 20, 'players_min' => 9, 'players_max' => 16,
                'age_min' => 11, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs' ],
                'coaching_points' => [
                    [ 'code' => 'set_for_the_third', 'en' => 'Set the ball for the third man arriving in space.', 'nl' => 'Leg de bal klaar voor de derde man die in de ruimte komt.' ],
                    [ 'code' => 'time_third_run', 'en' => 'Time the third-man run to arrive as the set is played.', 'nl' => 'Time de loop van de derde man zodat hij aankomt als de bal wordt klaargelegd.' ],
                    [ 'code' => 'play_forward_quickly', 'en' => 'Play forward quickly to beat the recovering defender.', 'nl' => 'Speel snel naar voren om de terugkerende verdediger te verslaan.' ],
                ],
            ],
            [
                'code' => 'cat_technical_one_v_one_dribble', 'name' => '1v1 dribbling to a line',
                'category' => 'technical', 'theme' => '1v1_duels', 'intensity' => 5,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 4, 'players_max' => 16,
                'sided' => '1v1', 'age_min' => 11, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs' ],
                'coaching_points' => [
                    [ 'code' => 'attack_at_speed', 'en' => 'Attack the defender at speed to commit them.', 'nl' => 'Val de verdediger met snelheid aan om hem te laten ingrijpen.' ],
                    [ 'code' => 'use_a_feint', 'en' => 'Use a feint to shift the defender\'s weight.', 'nl' => 'Gebruik een schijnbeweging om de verdediger uit balans te brengen.' ],
                    [ 'code' => 'explode_past', 'en' => 'Explode past once the defender is beaten.', 'nl' => 'Explodeer erlangs zodra de verdediger is gepasseerd.' ],
                ],
            ],
            [
                'code' => 'cat_technical_passing_square_rotations', 'name' => 'Passing square with rotations',
                'category' => 'technical', 'theme' => 'possession', 'intensity' => 4,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 8, 'players_max' => 16,
                'age_min' => 10, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'pass_and_follow', 'en' => 'Pass and follow your ball to the next corner.', 'nl' => 'Speel de bal en volg hem naar de volgende hoek.' ],
                    [ 'code' => 'crisp_passes', 'en' => 'Keep the passes crisp and along the ground.', 'nl' => 'Houd de passes scherp en over de grond.' ],
                    [ 'code' => 'communicate_swap', 'en' => 'Call the swap so the rotation stays clean.', 'nl' => 'Roep de wissel zodat de rotatie zuiver blijft.' ],
                ],
            ],
            [
                'code' => 'cat_technical_chest_thigh_control', 'name' => 'Chest and thigh control',
                'category' => 'technical', 'theme' => null, 'intensity' => 3,
                'dur_min' => 10, 'dur_max' => 16, 'players_min' => 4, 'players_max' => 16,
                'age_min' => 10, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls' ],
                'coaching_points' => [
                    [ 'code' => 'cushion_on_contact', 'en' => 'Cushion the ball on contact to take the pace off.', 'nl' => 'Demp de bal bij contact om de vaart eruit te halen.' ],
                    [ 'code' => 'drop_into_path', 'en' => 'Let the ball drop into your playing path.', 'nl' => 'Laat de bal in je speelrichting vallen.' ],
                    [ 'code' => 'settle_then_play', 'en' => 'Settle the ball before your next action.', 'nl' => 'Leg de bal eerst dood voor je volgende actie.' ],
                ],
            ],
            [
                'code' => 'cat_technical_overlap_pattern', 'name' => 'Overlap passing pattern',
                'category' => 'technical', 'theme' => 'build_up', 'intensity' => 5,
                'dur_min' => 14, 'dur_max' => 20, 'players_min' => 8, 'players_max' => 16,
                'age_min' => 11, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'hold_to_invite', 'en' => 'Hold the ball briefly to invite the overlap.', 'nl' => 'Houd de bal even vast om de overlap uit te lokken.' ],
                    [ 'code' => 'release_into_run', 'en' => 'Release the ball into the overlapping run.', 'nl' => 'Speel de bal in de overlappende loopactie.' ],
                    [ 'code' => 'communicate_overlap', 'en' => 'Call clearly when you start the overlap.', 'nl' => 'Roep duidelijk wanneer je de overlap inzet.' ],
                ],
            ],
            [
                'code' => 'cat_technical_driven_pass_range', 'name' => 'Driven pass over range',
                'category' => 'technical', 'theme' => null, 'intensity' => 4,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 6, 'players_max' => 16,
                'age_min' => 11, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'strike_through_middle', 'en' => 'Strike through the middle of the ball to keep it low.', 'nl' => 'Raak de bal door het midden om hem laag te houden.' ],
                    [ 'code' => 'firm_but_controlled', 'en' => 'Drive the pass firm but still controllable to receive.', 'nl' => 'Speel de pass stevig maar nog steeds aanneembaar.' ],
                    [ 'code' => 'follow_through', 'en' => 'Follow through towards your target.', 'nl' => 'Maak de beweging af richting je doel.' ],
                ],
            ],
            [
                'code' => 'cat_technical_y_drill_combination', 'name' => 'Y-drill passing combination',
                'category' => 'technical', 'theme' => 'build_up', 'intensity' => 4,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 8, 'players_max' => 16,
                'age_min' => 10, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'open_before_receive', 'en' => 'Open up before receiving to see the next pass.', 'nl' => 'Draai open voor de aanname om de volgende pass te zien.' ],
                    [ 'code' => 'one_two_tempo', 'en' => 'Keep a sharp one-two tempo through the pattern.', 'nl' => 'Houd een scherp één-twee-tempo door het patroon.' ],
                    [ 'code' => 'finish_into_run', 'en' => 'Finish the pattern with a pass into a run.', 'nl' => 'Sluit het patroon af met een pass in de loop.' ],
                ],
            ],
            [
                'code' => 'cat_technical_close_control_box', 'name' => 'Close-control box work',
                'category' => 'technical', 'theme' => null, 'intensity' => 3,
                'dur_min' => 10, 'dur_max' => 16, 'players_min' => 4, 'players_max' => 16,
                'age_min' => 9, 'age_max' => 12, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'many_touches', 'en' => 'Use lots of small touches inside the box.', 'nl' => 'Gebruik veel kleine tikjes in het vak.' ],
                    [ 'code' => 'use_sole', 'en' => 'Use the sole to stop and drag the ball.', 'nl' => 'Gebruik de zool om de bal te stoppen en te slepen.' ],
                    [ 'code' => 'stay_low', 'en' => 'Stay low and balanced over the ball.', 'nl' => 'Blijf laag en in balans boven de bal.' ],
                ],
            ],
            [
                'code' => 'cat_technical_volley_control', 'name' => 'Volley and half-volley control',
                'category' => 'technical', 'theme' => null, 'intensity' => 4,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 4, 'players_max' => 14,
                'age_min' => 11, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls' ],
                'coaching_points' => [
                    [ 'code' => 'watch_ball_onto_foot', 'en' => 'Watch the ball all the way onto your foot.', 'nl' => 'Volg de bal helemaal tot op je voet.' ],
                    [ 'code' => 'firm_ankle', 'en' => 'Lock the ankle firm to strike cleanly.', 'nl' => 'Span de enkel stevig om zuiver te raken.' ],
                    [ 'code' => 'controlled_contact', 'en' => 'Keep the contact controlled, not a wild swing.', 'nl' => 'Houd het contact gecontroleerd, geen wilde uithaal.' ],
                ],
            ],
            [
                'code' => 'cat_technical_pass_and_press_relay', 'name' => 'Pass-and-press relay',
                'category' => 'technical', 'theme' => 'pressing', 'intensity' => 5,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 8, 'players_max' => 16,
                'age_min' => 11, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs' ],
                'coaching_points' => [
                    [ 'code' => 'pass_then_pressure', 'en' => 'Pass, then immediately pressure the next receiver.', 'nl' => 'Pass, en zet daarna meteen druk op de volgende ontvanger.' ],
                    [ 'code' => 'angle_the_press', 'en' => 'Angle your press to show the ball one way.', 'nl' => 'Stuur je druk zodat de bal één kant op moet.' ],
                    [ 'code' => 'quick_feet_close', 'en' => 'Take quick small steps as you close down.', 'nl' => 'Neem snelle kleine pasjes terwijl je inknijpt.' ],
                ],
            ],
            [
                'code' => 'cat_technical_cross_and_control', 'name' => 'Receiving a cross',
                'category' => 'technical', 'theme' => 'finishing', 'intensity' => 4,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 6, 'players_max' => 14,
                'age_min' => 11, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'attack_the_cross', 'en' => 'Attack the cross rather than waiting for it.', 'nl' => 'Val de voorzet aan in plaats van erop te wachten.' ],
                    [ 'code' => 'control_into_space', 'en' => 'Take the first touch into space to shoot.', 'nl' => 'Neem de eerste aanname in de ruimte om te schieten.' ],
                    [ 'code' => 'read_the_flight', 'en' => 'Read the flight of the ball early.', 'nl' => 'Lees de baan van de bal vroeg.' ],
                ],
            ],
            [
                'code' => 'cat_technical_two_v_one_finish_pass', 'name' => '2v1 to a final pass',
                'category' => 'technical', 'theme' => 'transition', 'intensity' => 5,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 6, 'players_max' => 15,
                'sided' => '2v1', 'age_min' => 11, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs' ],
                'coaching_points' => [
                    [ 'code' => 'commit_defender', 'en' => 'Run at the defender to commit them to the ball.', 'nl' => 'Loop op de verdediger af om hem naar de bal te lokken.' ],
                    [ 'code' => 'pass_at_right_moment', 'en' => 'Release the pass the moment the defender steps in.', 'nl' => 'Speel de pass op het moment dat de verdediger ingrijpt.' ],
                    [ 'code' => 'support_wide', 'en' => 'The support player stays wide to keep the angle.', 'nl' => 'De steunende speler blijft breed om de hoek open te houden.' ],
                ],
            ],

            // ── SIDED GAME (+18 → 20) ───────────────────────────────────
            [
                'code' => 'cat_sided_3v3_small_goals', 'name' => '3v3 to small goals',
                'category' => 'sided_game', 'theme' => 'transition', 'intensity' => 5,
                'dur_min' => 12, 'dur_max' => 20, 'players_min' => 6, 'players_max' => 12,
                'sided' => '3v3', 'age_min' => 11, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs' ],
                'coaching_points' => [
                    [ 'code' => 'find_the_free_man', 'en' => 'Always look to find the free teammate.', 'nl' => 'Zoek altijd de vrije ploeggenoot.' ],
                    [ 'code' => 'defend_as_a_three', 'en' => 'Defend together so no goal is left open.', 'nl' => 'Verdedig samen zodat geen doel open blijft.' ],
                    [ 'code' => 'switch_quickly', 'en' => 'Switch attack and defence the moment the ball turns.', 'nl' => 'Schakel snel om zodra de bal verandert van ploeg.' ],
                ],
            ],
            [
                'code' => 'cat_sided_4v2_possession', 'name' => '4v2 possession grid',
                'category' => 'sided_game', 'theme' => 'possession', 'intensity' => 5,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 6, 'players_max' => 14,
                'sided' => '4v2', 'age_min' => 11, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs' ],
                'coaching_points' => [
                    [ 'code' => 'keep_passing_angles', 'en' => 'Hold passing angles so there are always two options.', 'nl' => 'Houd passlijnen open zodat er altijd twee opties zijn.' ],
                    [ 'code' => 'play_through_middle', 'en' => 'Play through the middle when the line opens.', 'nl' => 'Speel door het midden als de lijn opengaat.' ],
                    [ 'code' => 'press_in_pairs', 'en' => 'The two defenders press in a coordinated pair.', 'nl' => 'De twee verdedigers persen als een gecoördineerd duo.' ],
                ],
            ],
            [
                'code' => 'cat_sided_5v5_possession', 'name' => '5v5 possession to targets',
                'category' => 'sided_game', 'theme' => 'possession', 'intensity' => 6,
                'dur_min' => 15, 'dur_max' => 22, 'players_min' => 10, 'players_max' => 14,
                'sided' => '5v5', 'age_min' => 12, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs' ],
                'coaching_points' => [
                    [ 'code' => 'create_overloads', 'en' => 'Move to create an overload on the ball side.', 'nl' => 'Beweeg om een overtal te maken aan de balzijde.' ],
                    [ 'code' => 'switch_to_relieve', 'en' => 'Switch the play to relieve pressure.', 'nl' => 'Wissel van spel om de druk te verlichten.' ],
                    [ 'code' => 'patient_build', 'en' => 'Build patiently — do not force the forward pass.', 'nl' => 'Bouw geduldig op — forceer de pass naar voren niet.' ],
                ],
            ],
            [
                'code' => 'cat_sided_7v7_full_game', 'name' => '7v7 conditioned match',
                'category' => 'sided_game', 'theme' => 'mixed', 'intensity' => 6,
                'dur_min' => 18, 'dur_max' => 25, 'players_min' => 14, 'players_max' => 16,
                'sided' => '7v7', 'age_min' => 12, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'keep_shape', 'en' => 'Keep your team shape in and out of possession.', 'nl' => 'Behoud de teamvorm met en zonder bal.' ],
                    [ 'code' => 'play_the_picture', 'en' => 'Play what you see, not a fixed routine.', 'nl' => 'Speel wat je ziet, niet een vast trucje.' ],
                    [ 'code' => 'communicate_loud', 'en' => 'Communicate loudly to organise teammates.', 'nl' => 'Communiceer luid om ploeggenoten te sturen.' ],
                ],
            ],
            [
                'code' => 'cat_sided_4v4_two_zones', 'name' => '4v4 with two scoring zones',
                'category' => 'sided_game', 'theme' => 'transition', 'intensity' => 6,
                'dur_min' => 14, 'dur_max' => 22, 'players_min' => 8, 'players_max' => 16,
                'sided' => '4v4', 'age_min' => 12, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs' ],
                'coaching_points' => [
                    [ 'code' => 'attack_open_zone', 'en' => 'Attack whichever zone the defenders leave open.', 'nl' => 'Val de zone aan die de verdedigers openlaten.' ],
                    [ 'code' => 'recover_fast', 'en' => 'Recover fast to protect both zones on the turnover.', 'nl' => 'Herstel snel om beide zones te beschermen bij balverlies.' ],
                    [ 'code' => 'use_width', 'en' => 'Use the full width to switch the point of attack.', 'nl' => 'Gebruik de volle breedte om de aanval te verleggen.' ],
                ],
            ],
            [
                'code' => 'cat_sided_6v6_pressing_game', 'name' => '6v6 high-pressing game',
                'category' => 'sided_game', 'theme' => 'pressing', 'intensity' => 7,
                'dur_min' => 15, 'dur_max' => 22, 'players_min' => 12, 'players_max' => 16,
                'sided' => '6v6', 'age_min' => 12, 'age_max' => 13, 'md' => $sharp,
                'equipment' => [ 'balls', 'cones', 'bibs', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'press_on_trigger', 'en' => 'Press together on the agreed trigger.', 'nl' => 'Pers samen op de afgesproken trigger.' ],
                    [ 'code' => 'cut_passing_lanes', 'en' => 'Cut the inside passing lanes as you press.', 'nl' => 'Knijp de binnenste passlijnen dicht tijdens het persen.' ],
                    [ 'code' => 'win_it_high', 'en' => 'Aim to win the ball high and attack quickly.', 'nl' => 'Probeer de bal hoog te winnen en val snel aan.' ],
                ],
            ],
            [
                'code' => 'cat_sided_3v3_plus_keepers', 'name' => '3v3 plus keepers',
                'category' => 'sided_game', 'theme' => 'finishing', 'intensity' => 6,
                'dur_min' => 14, 'dur_max' => 20, 'players_min' => 8, 'players_max' => 14,
                'sided' => '3v3', 'age_min' => 12, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'create_shooting_chance', 'en' => 'Combine quickly to create a shooting chance.', 'nl' => 'Combineer snel om een schietkans te creëren.' ],
                    [ 'code' => 'shoot_early', 'en' => 'Shoot early when the goal opens up.', 'nl' => 'Schiet vroeg wanneer het doel opengaat.' ],
                    [ 'code' => 'screen_the_goal', 'en' => 'Defend by screening the shot at goal.', 'nl' => 'Verdedig door het schot op doel af te schermen.' ],
                ],
            ],
            [
                'code' => 'cat_sided_5v5_transition_game', 'name' => '5v5 transition game',
                'category' => 'sided_game', 'theme' => 'transition', 'intensity' => 7,
                'dur_min' => 15, 'dur_max' => 22, 'players_min' => 10, 'players_max' => 16,
                'sided' => '5v5', 'age_min' => 12, 'age_max' => 13, 'md' => $sharp,
                'equipment' => [ 'balls', 'cones', 'bibs', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'first_pass_forward', 'en' => 'Make the first pass after winning the ball go forward.', 'nl' => 'Maak de eerste pass na balverovering naar voren.' ],
                    [ 'code' => 'react_in_two_seconds', 'en' => 'React within two seconds of the turnover.', 'nl' => 'Reageer binnen twee seconden na het balverlies of -winst.' ],
                    [ 'code' => 'sprint_to_support', 'en' => 'Sprint to support the counter immediately.', 'nl' => 'Sprint meteen om de counter te steunen.' ],
                ],
            ],
            [
                'code' => 'cat_sided_4v4_possession_only', 'name' => '4v4 keep-ball',
                'category' => 'sided_game', 'theme' => 'possession', 'intensity' => 5,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 8, 'players_max' => 14,
                'sided' => '4v4', 'age_min' => 11, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs' ],
                'coaching_points' => [
                    [ 'code' => 'always_two_options', 'en' => 'Make sure the player on the ball has two options.', 'nl' => 'Zorg dat de speler aan de bal twee opties heeft.' ],
                    [ 'code' => 'move_after_pass', 'en' => 'Keep moving after every pass to stay available.', 'nl' => 'Blijf bewegen na elke pass om aanspeelbaar te blijven.' ],
                    [ 'code' => 'protect_under_pressure', 'en' => 'Shield the ball calmly when pressed.', 'nl' => 'Scherm de bal rustig af onder druk.' ],
                ],
            ],
            [
                'code' => 'cat_sided_6v6_wide_channels', 'name' => '6v6 with wide channels',
                'category' => 'sided_game', 'theme' => 'build_up', 'intensity' => 6,
                'dur_min' => 16, 'dur_max' => 24, 'players_min' => 12, 'players_max' => 16,
                'sided' => '6v6', 'age_min' => 12, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'use_the_channels', 'en' => 'Use the wide channels to stretch the defence.', 'nl' => 'Gebruik de buitenbanen om de verdediging uit te rekken.' ],
                    [ 'code' => 'cross_with_purpose', 'en' => 'Deliver from the channel with purpose.', 'nl' => 'Lever vanuit de baan een doelgerichte voorzet.' ],
                    [ 'code' => 'overload_then_switch', 'en' => 'Overload one side then switch to the free channel.', 'nl' => 'Maak overtal aan één kant en wissel dan naar de vrije baan.' ],
                ],
            ],
            [
                'code' => 'cat_sided_3v3_directional', 'name' => '3v3 directional with goals',
                'category' => 'sided_game', 'theme' => 'counter', 'intensity' => 6,
                'dur_min' => 12, 'dur_max' => 20, 'players_min' => 6, 'players_max' => 12,
                'sided' => '3v3', 'age_min' => 12, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'counter_at_speed', 'en' => 'Counter at speed when you regain the ball.', 'nl' => 'Counter met snelheid zodra je de bal herovert.' ],
                    [ 'code' => 'commit_then_release', 'en' => 'Commit a defender, then release the runner.', 'nl' => 'Bind een verdediger en speel dan de loper aan.' ],
                    [ 'code' => 'delay_when_outnumbered', 'en' => 'Delay the attack when you are outnumbered at the back.', 'nl' => 'Vertraag de aanval als je achterin in ondertal bent.' ],
                ],
            ],
            [
                'code' => 'cat_sided_8v8_phase_game', 'name' => '8v8 phase of play',
                'category' => 'sided_game', 'theme' => 'mixed', 'intensity' => 6,
                'dur_min' => 18, 'dur_max' => 25, 'players_min' => 14, 'players_max' => 16,
                'sided' => '8v8', 'age_min' => 12, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'recognise_the_phase', 'en' => 'Recognise the phase and apply the team plan.', 'nl' => 'Herken de fase en pas het teamplan toe.' ],
                    [ 'code' => 'stay_compact', 'en' => 'Stay compact between the lines when defending.', 'nl' => 'Blijf compact tussen de linies bij verdedigen.' ],
                    [ 'code' => 'progress_with_purpose', 'en' => 'Progress the ball with a clear purpose.', 'nl' => 'Breng de bal met een duidelijk doel naar voren.' ],
                ],
            ],
            [
                'code' => 'cat_sided_4v4_pressing_traps', 'name' => '4v4 with pressing traps',
                'category' => 'sided_game', 'theme' => 'pressing', 'intensity' => 7,
                'dur_min' => 14, 'dur_max' => 22, 'players_min' => 8, 'players_max' => 16,
                'sided' => '4v4', 'age_min' => 12, 'age_max' => 13, 'md' => $sharp,
                'equipment' => [ 'balls', 'cones', 'bibs', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'set_the_trap', 'en' => 'Steer the ball into the pressing trap.', 'nl' => 'Stuur de bal de pressing-val in.' ],
                    [ 'code' => 'spring_together', 'en' => 'Spring the trap together, not alone.', 'nl' => 'Klap de val samen dicht, niet alleen.' ],
                    [ 'code' => 'counter_on_win', 'en' => 'Attack immediately when the trap wins the ball.', 'nl' => 'Val direct aan wanneer de val de bal wint.' ],
                ],
            ],
            [
                'code' => 'cat_sided_2v2_rapid_games', 'name' => '2v2 rapid-fire games',
                'category' => 'sided_game', 'theme' => '1v1_duels', 'intensity' => 6,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 8, 'players_max' => 16,
                'sided' => '2v2', 'age_min' => 12, 'age_max' => 13, 'md' => $sharp,
                'equipment' => [ 'balls', 'cones', 'bibs', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'support_the_duel', 'en' => 'Support your partner in every duel.', 'nl' => 'Steun je maatje in elk duel.' ],
                    [ 'code' => 'attack_quickly', 'en' => 'Attack quickly before the defence sets.', 'nl' => 'Val snel aan voordat de verdediging staat.' ],
                    [ 'code' => 'cover_each_other', 'en' => 'Cover each other when one of you steps up.', 'nl' => 'Dek elkaar als een van jullie uitstapt.' ],
                ],
            ],
            [
                'code' => 'cat_sided_6v6_build_to_thirds', 'name' => '6v6 build through thirds',
                'category' => 'sided_game', 'theme' => 'build_up', 'intensity' => 6,
                'dur_min' => 16, 'dur_max' => 24, 'players_min' => 12, 'players_max' => 16,
                'sided' => '6v6', 'age_min' => 12, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'progress_through_thirds', 'en' => 'Move the ball cleanly through each third.', 'nl' => 'Breng de bal zuiver door elke linie.' ],
                    [ 'code' => 'support_ahead_and_behind', 'en' => 'Offer support both ahead of and behind the ball.', 'nl' => 'Bied steun zowel voor als achter de bal.' ],
                    [ 'code' => 'break_lines_when_on', 'en' => 'Break a line with a pass when it is on.', 'nl' => 'Speel door een linie als de pass erop zit.' ],
                ],
            ],
            [
                'code' => 'cat_sided_4v4_defending_focus', 'name' => '4v4 defending-shape game',
                'category' => 'sided_game', 'theme' => 'defending', 'intensity' => 6,
                'dur_min' => 14, 'dur_max' => 22, 'players_min' => 8, 'players_max' => 16,
                'sided' => '4v4', 'age_min' => 12, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'press_cover_balance', 'en' => 'Organise press, cover and balance as a unit.', 'nl' => 'Organiseer druk, dekking en balans als eenheid.' ],
                    [ 'code' => 'deny_the_centre', 'en' => 'Deny the central pass and force play wide.', 'nl' => 'Verbied de centrale pass en dwing het spel naar buiten.' ],
                    [ 'code' => 'stay_goalside', 'en' => 'Stay goalside of your direct opponent.', 'nl' => 'Blijf aan de doelzijde van je directe tegenstander.' ],
                ],
            ],
            [
                'code' => 'cat_sided_3v2_overload', 'name' => '3v2 attacking overload',
                'category' => 'sided_game', 'theme' => 'counter', 'intensity' => 6,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 8, 'players_max' => 15,
                'sided' => '3v2', 'age_min' => 12, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'use_the_extra_man', 'en' => 'Use the extra attacker before the defence recovers.', 'nl' => 'Benut de extra aanvaller voordat de verdediging herstelt.' ],
                    [ 'code' => 'move_at_pace', 'en' => 'Move the ball at pace to keep the overload alive.', 'nl' => 'Beweeg de bal met tempo om het overtal te benutten.' ],
                    [ 'code' => 'finish_the_overload', 'en' => 'Finish the move before the numbers even out.', 'nl' => 'Maak de aanval af voordat de aantallen gelijk zijn.' ],
                ],
            ],
            [
                'code' => 'cat_sided_7v7_possession_phases', 'name' => '7v7 possession phases',
                'category' => 'sided_game', 'theme' => 'possession', 'intensity' => 6,
                'dur_min' => 18, 'dur_max' => 25, 'players_min' => 14, 'players_max' => 16,
                'sided' => '7v7', 'age_min' => 12, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'bibs' ],
                'coaching_points' => [
                    [ 'code' => 'circulate_to_find_gap', 'en' => 'Circulate the ball to find the gap to play through.', 'nl' => 'Laat de bal rondgaan om het gat te vinden om door te spelen.' ],
                    [ 'code' => 'hold_shape_in_possession', 'en' => 'Keep your positional shape while in possession.', 'nl' => 'Behoud je positionele vorm in balbezit.' ],
                    [ 'code' => 'tempo_control', 'en' => 'Control the tempo — slow then quick to break through.', 'nl' => 'Beheers het tempo — langzaam, dan snel om door te breken.' ],
                ],
            ],

            // ── CONDITIONING (+8 → 10) ──────────────────────────────────
            [
                'code' => 'cat_conditioning_interval_dribbles', 'name' => 'Interval dribble runs',
                'category' => 'conditioning', 'theme' => null, 'intensity' => 6,
                'dur_min' => 8, 'dur_max' => 14, 'players_min' => 4, 'players_max' => 20,
                'age_min' => 12, 'age_max' => 13, 'md' => $load,
                'equipment' => [ 'balls', 'cones' ], 'verheijen' => 'football_endurance',
                'coaching_points' => [
                    [ 'code' => 'high_tempo_dribble', 'en' => 'Dribble at high tempo during the work block.', 'nl' => 'Dribbel op hoog tempo tijdens het werkblok.' ],
                    [ 'code' => 'keep_control_tired', 'en' => 'Keep control of the ball even when tired.', 'nl' => 'Houd controle over de bal, ook als je moe bent.' ],
                    [ 'code' => 'active_rest', 'en' => 'Jog gently in the rest, do not stop dead.', 'nl' => 'Jog rustig in de rust, sta niet stil.' ],
                ],
            ],
            [
                'code' => 'cat_conditioning_repeated_sprints', 'name' => 'Repeated sprint ability',
                'category' => 'conditioning', 'theme' => null, 'intensity' => 7,
                'dur_min' => 8, 'dur_max' => 14, 'players_min' => 4, 'players_max' => 20,
                'age_min' => 12, 'age_max' => 13, 'md' => $load,
                'equipment' => [ 'cones' ], 'verheijen' => 'football_sprint',
                'coaching_points' => [
                    [ 'code' => 'full_sprint_each_rep', 'en' => 'Give a true full sprint on every repetition.', 'nl' => 'Geef bij elke herhaling een echte volle sprint.' ],
                    [ 'code' => 'drive_the_first_steps', 'en' => 'Drive hard in the first explosive steps.', 'nl' => 'Zet hard af in de eerste explosieve passen.' ],
                    [ 'code' => 'recover_in_walk', 'en' => 'Use the walk-back to recover before the next rep.', 'nl' => 'Gebruik de terugwandeling om te herstellen voor de volgende sprint.' ],
                ],
            ],
            [
                'code' => 'cat_conditioning_tempo_runs', 'name' => 'Football tempo runs',
                'category' => 'conditioning', 'theme' => null, 'intensity' => 6,
                'dur_min' => 10, 'dur_max' => 15, 'players_min' => 4, 'players_max' => 20,
                'age_min' => 12, 'age_max' => 13, 'md' => $load,
                'equipment' => [ 'cones', 'balls' ], 'verheijen' => 'football_endurance',
                'coaching_points' => [
                    [ 'code' => 'hold_steady_pace', 'en' => 'Hold a strong, steady pace through each run.', 'nl' => 'Houd een sterk, gelijkmatig tempo aan in elke loop.' ],
                    [ 'code' => 'relaxed_upper_body', 'en' => 'Keep the upper body relaxed and efficient.', 'nl' => 'Houd het bovenlichaam ontspannen en efficiënt.' ],
                    [ 'code' => 'finish_strong', 'en' => 'Finish each run as strong as you started it.', 'nl' => 'Eindig elke loop net zo sterk als je hem begon.' ],
                ],
            ],
            [
                'code' => 'cat_conditioning_change_direction', 'name' => 'Change-of-direction circuit',
                'category' => 'conditioning', 'theme' => null, 'intensity' => 6,
                'dur_min' => 8, 'dur_max' => 14, 'players_min' => 4, 'players_max' => 18,
                'age_min' => 12, 'age_max' => 13, 'md' => $load,
                'equipment' => [ 'cones', 'poles' ], 'verheijen' => 'football_agility',
                'coaching_points' => [
                    [ 'code' => 'plant_and_push', 'en' => 'Plant firmly and push off to change direction.', 'nl' => 'Plant stevig en zet af om van richting te wisselen.' ],
                    [ 'code' => 'low_centre_of_gravity', 'en' => 'Stay low through the turns to keep balance.', 'nl' => 'Blijf laag in de draaien om in balans te blijven.' ],
                    [ 'code' => 'accelerate_out_of_turn', 'en' => 'Accelerate hard out of every turn.', 'nl' => 'Versnel krachtig uit elke draai.' ],
                ],
            ],
            [
                'code' => 'cat_conditioning_box_to_box', 'name' => 'Box-to-box endurance shuttles',
                'category' => 'conditioning', 'theme' => null, 'intensity' => 7,
                'dur_min' => 10, 'dur_max' => 15, 'players_min' => 4, 'players_max' => 20,
                'age_min' => 12, 'age_max' => 13, 'md' => $load,
                'equipment' => [ 'cones', 'balls' ], 'verheijen' => 'football_endurance',
                'coaching_points' => [
                    [ 'code' => 'pace_the_effort', 'en' => 'Pace the effort so you can repeat it.', 'nl' => 'Verdeel de inspanning zodat je hem kunt herhalen.' ],
                    [ 'code' => 'work_with_the_ball', 'en' => 'Carry the ball on the way out, sprint on the way back.', 'nl' => 'Neem de bal mee op de heenweg, sprint op de terugweg.' ],
                    [ 'code' => 'breathe_rhythmically', 'en' => 'Breathe in a steady rhythm to sustain the work.', 'nl' => 'Adem in een vast ritme om het werk vol te houden.' ],
                ],
            ],
            [
                'code' => 'cat_conditioning_small_group_game_cond', 'name' => 'Conditioning small-group game',
                'category' => 'conditioning', 'theme' => null, 'intensity' => 7,
                'dur_min' => 10, 'dur_max' => 15, 'players_min' => 8, 'players_max' => 16,
                'age_min' => 12, 'age_max' => 13, 'md' => $load,
                'equipment' => [ 'balls', 'cones', 'bibs', 'goals' ], 'verheijen' => 'football_endurance',
                'coaching_points' => [
                    [ 'code' => 'keep_intensity_high', 'en' => 'Keep the game intensity high throughout the block.', 'nl' => 'Houd de spelintensiteit hoog tijdens het hele blok.' ],
                    [ 'code' => 'work_every_action', 'en' => 'Work hard on every attacking and defending action.', 'nl' => 'Werk hard op elke aanvallende en verdedigende actie.' ],
                    [ 'code' => 'recover_between_rounds', 'en' => 'Use the breaks between rounds to recover well.', 'nl' => 'Gebruik de pauzes tussen rondes om goed te herstellen.' ],
                ],
            ],
            [
                'code' => 'cat_conditioning_pyramid_runs', 'name' => 'Pyramid distance runs',
                'category' => 'conditioning', 'theme' => null, 'intensity' => 6,
                'dur_min' => 8, 'dur_max' => 14, 'players_min' => 4, 'players_max' => 20,
                'age_min' => 12, 'age_max' => 13, 'md' => $load,
                'equipment' => [ 'cones' ], 'verheijen' => 'football_endurance',
                'coaching_points' => [
                    [ 'code' => 'judge_the_distance', 'en' => 'Judge your effort to the changing distance.', 'nl' => 'Stem je inspanning af op de wisselende afstand.' ],
                    [ 'code' => 'stay_consistent', 'en' => 'Keep your times consistent across the pyramid.', 'nl' => 'Houd je tijden consistent over de piramide.' ],
                    [ 'code' => 'control_breathing', 'en' => 'Control your breathing on the longer runs.', 'nl' => 'Beheers je ademhaling op de langere lopen.' ],
                ],
            ],
            [
                'code' => 'cat_conditioning_active_recovery_pass', 'name' => 'Active-recovery passing flow',
                'category' => 'conditioning', 'theme' => null, 'intensity' => 3,
                'dur_min' => 8, 'dur_max' => 14, 'players_min' => 6, 'players_max' => 20,
                'age_min' => 9, 'age_max' => 13, 'md' => $recover,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [ 'code' => 'low_load_movement', 'en' => 'Keep the movement light to aid recovery.', 'nl' => 'Houd de beweging licht om herstel te bevorderen.' ],
                    [ 'code' => 'gentle_passing', 'en' => 'Pass gently and accurately, no sprinting.', 'nl' => 'Pass rustig en zuiver, geen sprintwerk.' ],
                    [ 'code' => 'loosen_the_legs', 'en' => 'Use the flow to loosen tired legs.', 'nl' => 'Gebruik de oefening om vermoeide benen los te maken.' ],
                ],
            ],

            // ── FINISHING (+8 → 10) ─────────────────────────────────────
            [
                'code' => 'cat_finishing_one_v_keeper', 'name' => '1v1 versus the keeper',
                'category' => 'finishing', 'theme' => 'finishing', 'intensity' => 5,
                'dur_min' => 10, 'dur_max' => 18, 'players_min' => 4, 'players_max' => 14,
                'age_min' => 12, 'age_max' => 13, 'md' => $sharp,
                'equipment' => [ 'balls', 'cones', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'stay_composed', 'en' => 'Stay composed as you approach the keeper.', 'nl' => 'Blijf rustig terwijl je de keeper nadert.' ],
                    [ 'code' => 'open_the_body', 'en' => 'Open your body to pick the far corner.', 'nl' => 'Draai je lichaam open om de verre hoek te kiezen.' ],
                    [ 'code' => 'finish_before_keeper_set', 'en' => 'Finish before the keeper sets their feet.', 'nl' => 'Rond af voordat de keeper goed staat.' ],
                ],
            ],
            [
                'code' => 'cat_finishing_volleys_from_cross', 'name' => 'Volleys from a cross',
                'category' => 'finishing', 'theme' => 'finishing', 'intensity' => 5,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 6, 'players_max' => 14,
                'age_min' => 12, 'age_max' => 13, 'md' => $sharp,
                'equipment' => [ 'balls', 'cones', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'attack_the_ball', 'en' => 'Attack the cross, do not wait for it to drop.', 'nl' => 'Val de voorzet aan, wacht niet tot hij valt.' ],
                    [ 'code' => 'keep_volley_down', 'en' => 'Keep the volley down by getting over the ball.', 'nl' => 'Houd de volley laag door over de bal te komen.' ],
                    [ 'code' => 'time_the_arrival', 'en' => 'Time your arrival to meet the cross cleanly.', 'nl' => 'Time je aankomst om de voorzet zuiver te raken.' ],
                ],
            ],
            [
                'code' => 'cat_finishing_turn_and_shoot', 'name' => 'Turn and shoot from the edge',
                'category' => 'finishing', 'theme' => 'finishing', 'intensity' => 5,
                'dur_min' => 10, 'dur_max' => 18, 'players_min' => 4, 'players_max' => 14,
                'age_min' => 12, 'age_max' => 13, 'md' => $sharp,
                'equipment' => [ 'balls', 'cones', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'quick_turn', 'en' => 'Turn quickly to create the shooting angle.', 'nl' => 'Draai snel om de schiethoek te creëren.' ],
                    [ 'code' => 'set_then_strike', 'en' => 'Set the ball out of your feet, then strike.', 'nl' => 'Leg de bal uit je voeten, en schiet dan.' ],
                    [ 'code' => 'low_hard_shot', 'en' => 'Aim low and hard into the corner.', 'nl' => 'Mik laag en hard in de hoek.' ],
                ],
            ],
            [
                'code' => 'cat_finishing_cutback_finish', 'name' => 'Finishing the cut-back',
                'category' => 'finishing', 'theme' => 'finishing', 'intensity' => 5,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 6, 'players_max' => 14,
                'age_min' => 12, 'age_max' => 13, 'md' => $sharp,
                'equipment' => [ 'balls', 'cones', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'arrive_late', 'en' => 'Arrive late onto the cut-back to lose your marker.', 'nl' => 'Kom laat in op de teruglegbal om je tegenstander af te schudden.' ],
                    [ 'code' => 'side_foot_placement', 'en' => 'Side-foot the cut-back for placement.', 'nl' => 'Plaats de teruglegbal met de binnenkant van de voet.' ],
                    [ 'code' => 'first_time_when_set', 'en' => 'Finish first-time when the ball is well set.', 'nl' => 'Rond in één keer af als de bal goed klaarligt.' ],
                ],
            ],
            [
                'code' => 'cat_finishing_long_range_strikes', 'name' => 'Long-range striking',
                'category' => 'finishing', 'theme' => 'finishing', 'intensity' => 5,
                'dur_min' => 10, 'dur_max' => 16, 'players_min' => 4, 'players_max' => 14,
                'age_min' => 12, 'age_max' => 13, 'md' => $sharp,
                'equipment' => [ 'balls', 'cones', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'clean_contact', 'en' => 'Strike through the centre for a clean contact.', 'nl' => 'Raak de bal door het midden voor een zuiver contact.' ],
                    [ 'code' => 'plant_foot_steady', 'en' => 'Keep the plant foot steady and pointed at goal.', 'nl' => 'Houd het standbeen stabiel en gericht op het doel.' ],
                    [ 'code' => 'keep_it_on_target', 'en' => 'Sacrifice power to keep the shot on target.', 'nl' => 'Lever kracht in om het schot op doel te houden.' ],
                ],
            ],
            [
                'code' => 'cat_finishing_rebound_reaction', 'name' => 'Rebound reaction finishing',
                'category' => 'finishing', 'theme' => 'finishing', 'intensity' => 5,
                'dur_min' => 10, 'dur_max' => 16, 'players_min' => 4, 'players_max' => 14,
                'age_min' => 12, 'age_max' => 13, 'md' => $sharp,
                'equipment' => [ 'balls', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'follow_every_shot', 'en' => 'Follow in on every shot for the rebound.', 'nl' => 'Loop bij elk schot door op de rebound.' ],
                    [ 'code' => 'react_quickly', 'en' => 'React quickly to the loose ball.', 'nl' => 'Reageer snel op de losse bal.' ],
                    [ 'code' => 'finish_with_first_contact', 'en' => 'Finish with your first contact when you can.', 'nl' => 'Rond af met je eerste contact als het kan.' ],
                ],
            ],
            [
                'code' => 'cat_finishing_two_v_two_to_goal', 'name' => '2v2 attacking to goal',
                'category' => 'finishing', 'theme' => 'finishing', 'intensity' => 6,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 8, 'players_max' => 14,
                'sided' => '2v2', 'age_min' => 12, 'age_max' => 13, 'md' => $sharp,
                'equipment' => [ 'balls', 'cones', 'bibs', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'create_the_chance', 'en' => 'Combine with your partner to create the chance.', 'nl' => 'Combineer met je maatje om de kans te creëren.' ],
                    [ 'code' => 'shoot_when_open', 'en' => 'Shoot the moment the goal is open.', 'nl' => 'Schiet op het moment dat het doel open is.' ],
                    [ 'code' => 'support_for_rebound', 'en' => 'Support your partner for the rebound.', 'nl' => 'Steun je maatje voor de rebound.' ],
                ],
            ],
            [
                'code' => 'cat_finishing_one_touch_layoff', 'name' => 'One-touch lay-off finish',
                'category' => 'finishing', 'theme' => 'finishing', 'intensity' => 5,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 6, 'players_max' => 14,
                'age_min' => 12, 'age_max' => 13, 'md' => $sharp,
                'equipment' => [ 'balls', 'cones', 'goals' ],
                'coaching_points' => [
                    [ 'code' => 'firm_layoff', 'en' => 'Lay the ball off firm and into the striker\'s path.', 'nl' => 'Leg de bal stevig terug in de loop van de spits.' ],
                    [ 'code' => 'strike_through_layoff', 'en' => 'Strike the lay-off without breaking stride.', 'nl' => 'Schiet de teruglegbal zonder je pas te onderbreken.' ],
                    [ 'code' => 'aim_for_corner', 'en' => 'Aim for the open corner of the goal.', 'nl' => 'Mik op de open hoek van het doel.' ],
                ],
            ],

            // ── COOL DOWN (+8 → 10) ─────────────────────────────────────
            [
                'code' => 'cat_cool_down_jog_and_breathe', 'name' => 'Easy jog and breathe',
                'category' => 'cool_down', 'theme' => null, 'intensity' => 1,
                'dur_min' => 6, 'dur_max' => 10, 'players_min' => 4, 'players_max' => 24,
                'age_min' => 9, 'age_max' => 13, 'md' => $broad,
                'equipment' => [],
                'coaching_points' => [
                    [ 'code' => 'gentle_jog', 'en' => 'Jog gently to flush the legs.', 'nl' => 'Jog rustig om de benen los te maken.' ],
                    [ 'code' => 'slow_breathing', 'en' => 'Slow your breathing down with each lap.', 'nl' => 'Vertraag je ademhaling met elke ronde.' ],
                    [ 'code' => 'relax_shoulders', 'en' => 'Drop and relax the shoulders as you go.', 'nl' => 'Laat de schouders zakken en ontspan terwijl je loopt.' ],
                ],
            ],
            [
                'code' => 'cat_cool_down_foam_roll', 'name' => 'Guided foam-roll release',
                'category' => 'cool_down', 'theme' => null, 'intensity' => 1,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 4, 'players_max' => 24,
                'age_min' => 11, 'age_max' => 13, 'md' => $broad,
                'equipment' => [],
                'coaching_points' => [
                    [ 'code' => 'roll_slowly', 'en' => 'Roll slowly over each muscle group.', 'nl' => 'Rol langzaam over elke spiergroep.' ],
                    [ 'code' => 'pause_on_tight', 'en' => 'Pause and breathe on the tight spots.', 'nl' => 'Pauzeer en adem op de stijve plekken.' ],
                    [ 'code' => 'keep_it_comfortable', 'en' => 'Keep the pressure comfortable, never painful.', 'nl' => 'Houd de druk comfortabel, nooit pijnlijk.' ],
                ],
            ],
            [
                'code' => 'cat_cool_down_partner_stretch', 'name' => 'Partner-assisted stretching',
                'category' => 'cool_down', 'theme' => null, 'intensity' => 1,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 6, 'players_max' => 24,
                'age_min' => 11, 'age_max' => 13, 'md' => $broad,
                'equipment' => [],
                'coaching_points' => [
                    [ 'code' => 'communicate_with_partner', 'en' => 'Tell your partner when the stretch is enough.', 'nl' => 'Zeg je maatje wanneer de rek genoeg is.' ],
                    [ 'code' => 'ease_into_stretch', 'en' => 'Ease into each stretch, never force it.', 'nl' => 'Ga rustig de rek in, forceer nooit.' ],
                    [ 'code' => 'hold_and_relax', 'en' => 'Hold the position and relax into it.', 'nl' => 'Houd de houding vast en ontspan erin.' ],
                ],
            ],
            [
                'code' => 'cat_cool_down_breathing_reset', 'name' => 'Breathing and reset',
                'category' => 'cool_down', 'theme' => null, 'intensity' => 1,
                'dur_min' => 6, 'dur_max' => 10, 'players_min' => 4, 'players_max' => 24,
                'age_min' => 9, 'age_max' => 13, 'md' => $broad,
                'equipment' => [],
                'coaching_points' => [
                    [ 'code' => 'deep_belly_breaths', 'en' => 'Take slow, deep belly breaths.', 'nl' => 'Neem rustige, diepe buikademhalingen.' ],
                    [ 'code' => 'long_exhale', 'en' => 'Make the exhale longer than the inhale.', 'nl' => 'Maak de uitademing langer dan de inademing.' ],
                    [ 'code' => 'calm_the_mind', 'en' => 'Let the mind settle after the session.', 'nl' => 'Laat de geest tot rust komen na de training.' ],
                ],
            ],
            [
                'code' => 'cat_cool_down_light_juggling', 'name' => 'Light juggling wind-down',
                'category' => 'cool_down', 'theme' => null, 'intensity' => 2,
                'dur_min' => 6, 'dur_max' => 10, 'players_min' => 4, 'players_max' => 20,
                'age_min' => 9, 'age_max' => 13, 'md' => $broad,
                'equipment' => [ 'balls' ],
                'coaching_points' => [
                    [ 'code' => 'soft_touches', 'en' => 'Use soft, controlled touches to juggle.', 'nl' => 'Gebruik zachte, gecontroleerde tikjes om hoog te houden.' ],
                    [ 'code' => 'relaxed_pace', 'en' => 'Keep the pace relaxed and unhurried.', 'nl' => 'Houd het tempo ontspannen en rustig.' ],
                    [ 'code' => 'both_feet_calm', 'en' => 'Use both feet calmly as you wind down.', 'nl' => 'Gebruik beide voeten rustig terwijl je afbouwt.' ],
                ],
            ],
            [
                'code' => 'cat_cool_down_mobility_flow', 'name' => 'Cool-down mobility flow',
                'category' => 'cool_down', 'theme' => null, 'intensity' => 1,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 4, 'players_max' => 24,
                'age_min' => 9, 'age_max' => 13, 'md' => $broad,
                'equipment' => [],
                'coaching_points' => [
                    [ 'code' => 'slow_controlled_flow', 'en' => 'Move through the flow slowly and controlled.', 'nl' => 'Beweeg langzaam en gecontroleerd door de flow.' ],
                    [ 'code' => 'breathe_with_movement', 'en' => 'Match your breathing to each movement.', 'nl' => 'Stem je ademhaling af op elke beweging.' ],
                    [ 'code' => 'full_range', 'en' => 'Take each joint through its comfortable range.', 'nl' => 'Beweeg elk gewricht door zijn comfortabele bereik.' ],
                ],
            ],
            [
                'code' => 'cat_cool_down_walk_and_talk_review', 'name' => 'Walk-and-talk session review',
                'category' => 'cool_down', 'theme' => null, 'intensity' => 1,
                'dur_min' => 6, 'dur_max' => 10, 'players_min' => 4, 'players_max' => 24,
                'age_min' => 9, 'age_max' => 13, 'md' => $broad,
                'equipment' => [],
                'coaching_points' => [
                    [ 'code' => 'walk_easy_pace', 'en' => 'Walk at an easy, conversational pace.', 'nl' => 'Wandel in een rustig, pratend tempo.' ],
                    [ 'code' => 'reflect_on_session', 'en' => 'Reflect on one thing that went well today.', 'nl' => 'Denk na over één ding dat vandaag goed ging.' ],
                    [ 'code' => 'let_heart_rate_drop', 'en' => 'Let the heart rate drop gradually as you talk.', 'nl' => 'Laat de hartslag geleidelijk dalen terwijl je praat.' ],
                ],
            ],
            [
                'code' => 'cat_cool_down_calf_hip_stretch', 'name' => 'Calf and hip stretch set',
                'category' => 'cool_down', 'theme' => null, 'intensity' => 1,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 4, 'players_max' => 24,
                'age_min' => 9, 'age_max' => 13, 'md' => $broad,
                'equipment' => [],
                'coaching_points' => [
                    [ 'code' => 'hold_thirty_seconds', 'en' => 'Hold each stretch for about thirty seconds.', 'nl' => 'Houd elke stretch ongeveer dertig seconden vast.' ],
                    [ 'code' => 'target_worked_muscles', 'en' => 'Focus on the muscles you worked hardest.', 'nl' => 'Richt je op de spieren die het hardst werkten.' ],
                    [ 'code' => 'breathe_into_stretch', 'en' => 'Breathe calmly into each stretch.', 'nl' => 'Adem rustig in elke stretch.' ],
                ],
            ],
        ];
    }
};
