<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\DemoGenerator;
use TT\Modules\DemoData\DemoRatingScale;
use TT\Modules\DemoData\DemoRoster;
use TT\Modules\DemoData\Generators\EvaluationGenerator;

/**
 * #3401 / #3402 / #3403 — the shape of a demo run in time.
 *
 * Three things used to be decided in three places and disagree: the
 * evaluation stream ran on its own two-a-week clock, the PDP cycle was
 * spread across whatever single season existed however long the window was,
 * and the squad a past season's work belonged to was always the player's
 * current one. What is asserted here is that they now come from one
 * calendar and one roster plan, and that the archetype behind a player's
 * ratings is legible on the install's own scale rather than only on a chart.
 *
 * `$now` is pinned in every case: a test whose expectations drift with the
 * wall clock is one that fails on a Tuesday in August for no reason.
 */
final class DemoSeasonCadenceTest extends WP_UnitTestCase {

    /** Mid-September, early in the 2026/2027 season. */
    private const NOW = 1789430400; // 2026-09-15 00:00:00 UTC

    private function calendar( int $weeks ): DemoCalendar {
        return new DemoCalendar( $weeks, self::NOW );
    }

    // ----- Seasons -----

    public function test_a_window_inside_one_season_produces_one_season(): void {
        $this->assertSame( 1, $this->calendar( 8 )->seasonCount() );
    }

    public function test_a_window_spanning_three_years_produces_a_season_per_year_it_touches(): void {
        $seasons = $this->calendar( 156 )->seasons();

        $this->assertSame(
            [ '2023/2024', '2024/2025', '2025/2026', '2026/2027' ],
            array_column( $seasons, 'name' ),
            'three years back from mid-September reaches into a fourth season-year'
        );
        $this->assertSame( '2023-08-01', $seasons[0]['start_date'] );
        $this->assertSame( '2027-06-30', $seasons[3]['end_date'] );
        $this->assertTrue( $seasons[3]['is_current'] );
        $this->assertFalse( $seasons[0]['is_current'] );
    }

    public function test_seasons_follow_the_clubs_august_to_june_convention(): void {
        foreach ( $this->calendar( 104 )->seasons() as $season ) {
            $this->assertStringEndsWith( '-08-01', $season['start_date'] );
            $this->assertStringEndsWith( '-06-30', $season['end_date'] );
        }
    }

    // ----- Rounds and the conversations they are evidence for -----

    public function test_each_season_carries_exactly_four_rounds(): void {
        $calendar = $this->calendar( 156 );

        foreach ( $calendar->seasons() as $season ) {
            $this->assertCount(
                DemoCalendar::ROUNDS_PER_SEASON,
                $calendar->roundDates( $season ),
                'four a season — start, two mid, end'
            );
        }
    }

    public function test_a_round_precedes_the_conversation_that_reviews_it(): void {
        $calendar = $this->calendar( 104 );

        foreach ( $calendar->seasons() as $season ) {
            $rounds = $calendar->roundDates( $season );
            $talks  = $calendar->conversationDates( $season );

            foreach ( $rounds as $i => $round ) {
                $this->assertLessThan(
                    $talks[ $i ],
                    $round,
                    'evidence has to exist before the talk it is evidence for'
                );
                if ( $i > 0 ) {
                    $this->assertGreaterThan(
                        $talks[ $i - 1 ],
                        $round,
                        'and after the previous talk, or EvidencePacket::forConversation() will not see it'
                    );
                }
            }
        }
    }

    public function test_rounds_and_conversations_stay_inside_their_season(): void {
        $calendar = $this->calendar( 104 );

        foreach ( $calendar->seasons() as $season ) {
            foreach ( array_merge( $calendar->roundDates( $season ), $calendar->conversationDates( $season ) ) as $when ) {
                $this->assertGreaterThanOrEqual( $season['start_date'], $when );
                $this->assertLessThanOrEqual( $season['end_date'], $when );
            }
        }
    }

    public function test_the_first_round_is_a_baseline_and_the_last_an_end_of_season_review(): void {
        $calendar = $this->calendar( 8 );
        $season   = $calendar->seasons()[0];
        $rounds   = $calendar->roundDates( $season );

        // Not an even divide: the old cycle put its first conversation a
        // fifth of the way in, which on a September demo meant no round had
        // happened yet and the current season showed nothing at all.
        $this->assertLessThan( '2026-09-01', $rounds[0], 'the opening round is a start-of-season baseline' );
        $this->assertGreaterThan( '2027-05-01', $rounds[3], 'the closing round is what a verdict is written against' );
    }

    // ----- Fixtures -----

    public function test_fixtures_come_from_the_calendar_so_a_match_evaluation_is_about_a_match(): void {
        $slots = $this->calendar( 36 )->activitySlots();
        $games = array_values( array_filter( $slots, static fn( array $s ): bool => $s['is_game'] ) );

        $this->assertNotSame( [], $games );
        foreach ( $games as $game ) {
            $this->assertSame( 1, $game['slot'], 'a fixture is the second slot of its week' );
            $this->assertSame( 2, $game['week'] % 3, 'every third week' );
            $this->assertNotNull( $game['subtype'] );
        }

        $future = array_filter( $slots, static fn( array $s ): bool => $s['is_future'] );
        $this->assertNotSame( [], $future, '#3030 — the window straddles today rather than ending on it' );
    }

    // ----- The rating scale -----

    /** @return array<string, array{float, float, float}> */
    public function scaleProvider(): array {
        return [
            'this install: 5-9 step 1'  => [ 5.0, 9.0, 1.0 ],
            'the default: 5-10 step .5' => [ 5.0, 10.0, 0.5 ],
            'a 1-10 whole-number scale' => [ 1.0, 10.0, 1.0 ],
        ];
    }

    /**
     * @dataProvider scaleProvider
     */
    public function test_an_improving_archetype_moves_a_step_across_a_season( float $min, float $max, float $step ): void {
        $calendar = $this->calendar( 104 );
        $scale    = new DemoRatingScale( $min, $max, $step );
        $climb    = $scale->climbOver( $calendar->seasonCount() );
        $gen      = new EvaluationGenerator( new DemoBatchRegistry( 'test-cadence' ), [], [], 104, $calendar );

        $seasons = $calendar->seasons();
        foreach ( $seasons as $index => $season ) {
            $rounds = $calendar->roundDates( $season );
            $first  = $scale->quantise( $gen->archetypeRating( 'rising_star', $calendar->progressForDate( $rounds[0] ), $scale, $climb ) );
            $last   = $scale->quantise( $gen->archetypeRating( 'rising_star', $calendar->progressForDate( $rounds[3] ), $scale, $climb ) );

            $this->assertGreaterThanOrEqual(
                $step,
                round( $last - $first, 4 ),
                sprintf( 'season %d has to move at least one step of a %s-%s/%s scale', $index, $min, $max, $step )
            );
        }
    }

    /**
     * @dataProvider scaleProvider
     */
    public function test_a_steady_archetype_does_not_move( float $min, float $max, float $step ): void {
        $calendar = $this->calendar( 104 );
        $scale    = new DemoRatingScale( $min, $max, $step );
        $climb    = $scale->climbOver( $calendar->seasonCount() );
        $gen      = new EvaluationGenerator( new DemoBatchRegistry( 'test-cadence' ), [], [], 104, $calendar );

        $values = [];
        foreach ( $calendar->seasons() as $season ) {
            foreach ( $calendar->roundDates( $season ) as $round ) {
                $values[] = $scale->quantise( $gen->archetypeRating( 'steady_solid', $calendar->progressForDate( $round ), $scale, $climb ) );
            }
        }

        $this->assertCount( 1, array_unique( $values ), 'the flat archetype is the control — it must stay flat' );
    }

    /**
     * @dataProvider scaleProvider
     */
    public function test_every_rating_is_a_value_the_scale_can_express( float $min, float $max, float $step ): void {
        $scale = new DemoRatingScale( $min, $max, $step );

        foreach ( [ -3.0, $min, $min + 0.4 * $step, $min + 1.7 * $step, $max, $max + 5 ] as $raw ) {
            $value = $scale->quantise( (float) $raw );

            $this->assertGreaterThanOrEqual( $min, $value );
            $this->assertLessThanOrEqual( $max, $value );
            $this->assertEqualsWithDelta(
                0.0,
                fmod( round( $value - $min, 4 ), $step ),
                0.0001,
                'a coach cannot record 6.4 on a step-1 scale, so the demo must not either'
            );
        }
    }

    // ----- The roster plan -----

    /** @return object[] */
    private function squad(): array {
        $players = [];
        $id      = 100;
        foreach ( [ 11, 12, 13, 14 ] as $team_index => $age ) {
            foreach ( [ 'rising_star', 'steady_solid', 'new_arrival', DemoRoster::ARCHETYPE_DEPARTED ] as $archetype ) {
                $players[] = (object) [
                    'id'          => $id++,
                    'team_id'     => 50 + $team_index,
                    'archetype'   => $archetype,
                    'date_joined' => '2021-01-01',
                ];
            }
        }
        return $players;
    }

    /** @return object[] */
    private function teams(): array {
        return [
            (object) [ 'id' => 50, 'age_group' => 'U11' ],
            (object) [ 'id' => 51, 'age_group' => 'U12' ],
            (object) [ 'id' => 52, 'age_group' => 'U13' ],
            (object) [ 'id' => 53, 'age_group' => 'U14' ],
        ];
    }

    public function test_the_spells_written_to_history_are_the_squad_the_work_belongs_to(): void {
        $calendar = $this->calendar( 156 );
        $squad    = $this->squad();
        $roster   = new DemoRoster( $calendar, $this->teams(), $squad );

        $checked = 0;
        foreach ( $squad as $player ) {
            foreach ( $roster->spellsFor( (int) $player->id ) as $spell ) {
                $on_that_day = $roster->teamForPlayerOn( (int) $player->id, (string) $spell['joined_at'] );

                // A spell that opens before the window's first season starts
                // resolves to that season, which is the spell's own team.
                $this->assertSame(
                    (int) $spell['team_id'],
                    $on_that_day,
                    'the age-group history and the season roster must not contradict each other'
                );
                $this->assertContains(
                    $player,
                    $roster->rosterFor( (int) $spell['team_id'], (string) $spell['joined_at'] ),
                    'and the team has to have the player on it'
                );
                $checked++;
            }
        }

        $this->assertGreaterThan( 0, $checked );
    }

    public function test_a_player_climbs_one_rung_a_season(): void {
        $calendar = $this->calendar( 156 );
        $roster   = new DemoRoster( $calendar, $this->teams(), $this->squad() );

        // The U14 rising star: four seasons, four rungs, oldest first.
        $spells = $roster->spellsFor( 112 );
        $this->assertSame( [ 50, 51, 52, 53 ], array_column( $spells, 'team_id' ) );
        $this->assertNull( $spells[3]['left_at'], 'the current spell is open-ended' );
        $this->assertNotNull( $spells[0]['left_at'] );
    }

    public function test_the_squad_changes_between_seasons(): void {
        $calendar = $this->calendar( 156 );
        $squad    = $this->squad();
        $roster   = new DemoRoster( $calendar, $this->teams(), $squad );

        $last    = $calendar->seasonCount() - 1;
        $arrived = 0;
        $left    = 0;
        foreach ( $squad as $player ) {
            $id = (int) $player->id;
            if ( $roster->teamForPlayerInSeason( $id, 0 ) === 0 && $roster->teamForPlayerInSeason( $id, $last ) > 0 ) {
                $arrived++;
            }
            if ( $roster->teamForPlayerInSeason( $id, 0 ) > 0 && $roster->teamForPlayerInSeason( $id, $last ) === 0 ) {
                $left++;
            }
        }

        $this->assertGreaterThan( 0, $arrived, 'at least one arrival across the window' );
        $this->assertGreaterThan( 0, $left, 'at least one departure across the window' );
    }

    public function test_a_single_season_window_has_nobody_leaving(): void {
        $calendar = $this->calendar( 8 );
        $squad    = $this->squad();
        $roster   = new DemoRoster( $calendar, $this->teams(), $squad );

        foreach ( $squad as $player ) {
            if ( (string) $player->archetype !== DemoRoster::ARCHETYPE_DEPARTED ) continue;

            $this->assertSame(
                [],
                $roster->spellsFor( (int) $player->id ),
                'a squad cannot have changed between seasons there are not two of'
            );
        }
    }

    // ----- The window ceiling -----

    public function test_the_weeks_ceiling_is_three_years(): void {
        $this->assertSame( 156, DemoGenerator::SIZE_CEILINGS['weeks'] );
        $this->assertSame( 40, DemoGenerator::SIZE_CEILINGS['teams'] );
        $this->assertSame( 40, DemoGenerator::SIZE_CEILINGS['players_per_team'] );
    }
}
