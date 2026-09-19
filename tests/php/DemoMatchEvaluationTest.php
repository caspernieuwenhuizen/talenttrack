<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\Generators\EvaluationGenerator;
use TT\Modules\DemoData\Generators\MatchDayGenerator;

/**
 * #3658 — a demo match evaluation is about a match the player played.
 *
 * They used to be rolled per calendar date by `EvaluationGenerator`, which
 * runs at `run_order` 10, long before `MatchDayGenerator` picks a side at
 * 110. So a player collected write-ups with a random opponent and 45-90
 * random minutes for matches they were never in the squad for, while every
 * minutes surface said they had played nothing. Two stories about the same
 * Saturday, and no way to tell which was true.
 */
final class DemoMatchEvaluationTest extends WP_UnitTestCase {

    private const FIXTURES = 6;

    private int $team_id = 0;

    /** @var object[] */
    private array $players = [];

    /** @var list<int> past fixture ids, oldest first */
    private array $fixtures = [];

    private int $future_fixture = 0;

    private int $match_type_id = 0;

    /** The team row, with the head coach the demo run hangs on the object. */
    private ?object $team = null;

    public function set_up(): void {
        parent::set_up();
        mt_srand( 3658 );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $this->match_type_id = $this->ensureMatchEvalType();
        $this->ensureEvalCategory();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Demo O11', 'age_group' => 'JO11' ] );
        $this->team_id = (int) $wpdb->insert_id;

        for ( $i = 0; $i < 16; $i++ ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id' => $club, 'team_id' => $this->team_id,
                'first_name' => 'Demo', 'last_name' => "Speler {$i}", 'status' => 'active',
            ] );
            $this->players[] = (object) [
                'id'        => (int) $wpdb->insert_id,
                'team_id'   => $this->team_id,
                'archetype' => 'steady_solid',
            ];
        }

        $registry = new DemoBatchRegistry( 'test-batch-3658' );
        for ( $i = self::FIXTURES; $i >= 1; $i-- ) {
            $this->fixtures[] = $this->fixture(
                $registry,
                gmdate( 'Y-m-d', strtotime( '-' . ( $i * 7 ) . ' days' ) ),
                $i % 2 === 0 ? 'home' : 'away'
            );
        }
        $this->future_fixture = $this->fixture( $registry, gmdate( 'Y-m-d', strtotime( '+7 days' ) ), 'home' );

        // `head_coach_user_id` is not a column on tt_teams — the demo run
        // hangs it on the team object, the way `DemoGenerator::loadTeams()`
        // does, and both evaluation writers read it from there.
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}tt_teams WHERE id = %d", $this->team_id ) );
        $team->head_coach_user_id = $admin;
        $this->team = $team;

        ( new MatchDayGenerator( $registry, $this->players, [ $team ], [ 'hjo' => $admin ], 'en_US' ) )->generate();
    }

    public function tear_down(): void {
        mt_srand();
        parent::tear_down();
    }

    public function test_the_demo_writes_match_evaluations_at_all(): void {
        $this->assertNotEmpty( $this->matchEvaluations(), 'a generated match is written up' );
    }

    /**
     * The defect itself: every match evaluation names the match it is
     * about, and the player was on the pitch in it for the minutes the
     * write-up claims.
     */
    public function test_every_match_evaluation_points_at_a_match_the_player_played(): void {
        global $wpdb;

        foreach ( $this->matchEvaluations() as $eval ) {
            $activity_id = (int) $eval['activity_id'];
            $this->assertContains( $activity_id, $this->fixtures, 'the evaluation names a played fixture of the team' );

            $minutes = $wpdb->get_var( $wpdb->prepare(
                "SELECT minutes_played FROM {$wpdb->prefix}tt_attendance
                  WHERE activity_id = %d AND player_id = %d AND record_type = 'actual'",
                $activity_id,
                (int) $eval['player_id']
            ) );

            $this->assertNotNull( $minutes, 'the player has a recorded register row for that match' );
            $this->assertGreaterThan( 0, (int) $minutes, 'a benched player is not written up' );
            $this->assertSame( (int) $minutes, (int) $eval['minutes_played'], 'the write-up quotes the minutes played' );
        }
    }

    public function test_opponent_result_and_venue_come_off_the_fixture(): void {
        global $wpdb;

        foreach ( $this->matchEvaluations() as $eval ) {
            $fixture = (array) $wpdb->get_row( $wpdb->prepare(
                "SELECT opponent, home_away, game_subtype_key, home_score, away_score
                   FROM {$wpdb->prefix}tt_activities WHERE id = %d",
                (int) $eval['activity_id']
            ), ARRAY_A );

            $this->assertSame( (string) $fixture['opponent'], (string) $eval['opponent'] );
            $this->assertSame( (string) $fixture['home_away'], (string) $eval['home_away'] );
            $this->assertSame( (string) $fixture['game_subtype_key'], (string) $eval['competition'] );

            $ours   = (int) $fixture['home_score'];
            $theirs = (int) $fixture['away_score'];
            $letter = $ours > $theirs ? 'W' : ( $ours < $theirs ? 'L' : 'D' );

            $this->assertSame(
                sprintf( '%s %d-%d', $letter, $ours, $theirs ),
                (string) $eval['game_result'],
                'the result agrees with the scoreline on the activity'
            );
        }
    }

    public function test_a_player_without_minutes_has_no_write_up_for_that_match(): void {
        global $wpdb;

        $written_up = [];
        foreach ( $this->matchEvaluations() as $eval ) {
            $written_up[ (int) $eval['activity_id'] . ':' . (int) $eval['player_id'] ] = true;
        }

        $checked = 0;
        foreach ( $this->fixtures as $activity_id ) {
            foreach ( $this->players as $player ) {
                $minutes = $wpdb->get_var( $wpdb->prepare(
                    "SELECT minutes_played FROM {$wpdb->prefix}tt_attendance
                      WHERE activity_id = %d AND player_id = %d AND record_type = 'actual'",
                    $activity_id,
                    (int) $player->id
                ) );
                if ( $minutes !== null && (int) $minutes > 0 ) continue;

                $checked++;
                $this->assertArrayNotHasKey(
                    $activity_id . ':' . (int) $player->id,
                    $written_up,
                    'a player who did not feature has no match evaluation for that match'
                );
            }
        }

        $this->assertGreaterThan( 0, $checked, 'the squad is bigger than the side, so somebody sat out' );
    }

    public function test_a_fixture_still_to_be_played_is_not_written_up(): void {
        foreach ( $this->matchEvaluations() as $eval ) {
            $this->assertNotSame(
                $this->future_fixture,
                (int) $eval['activity_id'],
                'a match that has not been played yet has nothing to evaluate'
            );
        }
    }

    /**
     * The other half of the fix: the round-evaluation generator no longer
     * invents match evaluations of its own.
     */
    public function test_the_evaluation_generator_writes_no_match_evaluations(): void {
        global $wpdb;

        $before = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_evaluations WHERE eval_type_id = %d",
            $this->match_type_id
        ) );

        $rounds_before = $this->countOtherEvaluations();

        $written = ( new EvaluationGenerator(
            new DemoBatchRegistry( 'test-batch-3658-rounds' ),
            $this->players,
            [ $this->team ],
            8
        ) )->generate();

        $after = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_evaluations WHERE eval_type_id = %d",
            $this->match_type_id
        ) );

        $this->assertSame( $before, $after, 'round evaluations only' );

        // Everything it wrote is a round evaluation. How many rounds have
        // come round depends on where in the season the suite runs, so the
        // count is read off the generator rather than assumed.
        $this->assertSame(
            $rounds_before + $written,
            $this->countOtherEvaluations(),
            'every row it wrote is a round evaluation'
        );
    }

    private function countOtherEvaluations(): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_evaluations WHERE eval_type_id != %d",
            $this->match_type_id
        ) );
    }

    /** @return list<array<string,mixed>> the generated match evaluations */
    private function matchEvaluations(): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, player_id, activity_id, opponent, competition, game_result, home_away, minutes_played
               FROM {$wpdb->prefix}tt_evaluations
              WHERE eval_type_id = %d
              ORDER BY id",
            $this->match_type_id
        ), ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    private function fixture( DemoBatchRegistry $registry, string $date, string $venue ): int {
        global $wpdb;

        $future = strtotime( $date ) > time();
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => (int) CurrentClub::id(),
            'team_id'             => $this->team_id,
            'title'               => 'Wedstrijd ' . $date,
            'session_date'        => $date,
            'opponent'            => 'SV Demo',
            'home_away'           => $venue,
            'game_subtype_key'    => 'League',
            'activity_type_key'   => 'game',
            'activity_status_key' => $future ? 'planned' : 'completed',
            'plan_state'          => $future ? 'scheduled' : 'completed',
        ] );
        $activity_id = (int) $wpdb->insert_id;
        $registry->tag( 'activity', $activity_id );

        // The recorded register `ActivityGenerator` would have written, which
        // is where the generator puts the minutes.
        foreach ( $this->players as $player ) {
            $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
                'club_id'     => (int) CurrentClub::id(),
                'activity_id' => $activity_id,
                'player_id'   => (int) $player->id,
                'is_guest'    => 0,
                'status'      => 'Present',
                'record_type' => 'actual',
            ] );
        }

        return $activity_id;
    }

    private function ensureMatchEvalType(): int {
        global $wpdb;

        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_lookups
              WHERE lookup_type = 'eval_type' AND name = 'Match' AND club_id = %d",
            (int) CurrentClub::id()
        ) );
        if ( $id > 0 ) return $id;

        $wpdb->insert( "{$wpdb->prefix}tt_lookups", [
            'club_id'     => (int) CurrentClub::id(),
            'lookup_type' => 'eval_type',
            'name'        => 'Match',
            'meta'        => '{"requires_match_details":true}',
            'sort_order'  => 2,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function ensureEvalCategory(): void {
        global $wpdb;

        $mains = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_eval_categories WHERE parent_id IS NULL AND is_active = 1"
        );
        if ( $mains > 0 ) return;

        $wpdb->insert( "{$wpdb->prefix}tt_eval_categories", [
            'parent_id'     => null,
            'category_key'  => 'technical',
            'label'         => 'Technical',
            'description'   => 'Ball control, passing, shooting, dribbling',
            'display_order' => 10,
            'is_active'     => 1,
        ] );
    }
}
