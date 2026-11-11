<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\PlayedMatch;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #4957 — the one played-match rule, one fixture per case.
 *
 * Played: dated today or earlier, and completed, past, carrying minutes or
 * scored. Live: the club's, not archived, not trashed, not cancelled on
 * either axis (a NULL is not a cancellation), inside the demo scope.
 */
final class PlayedMatchRuleTest extends WP_UnitTestCase {

    private int $team   = 0;
    private int $player = 0;
    private string $today = '';

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Rule U12', 'age_group' => 'U12' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'team_id' => $this->team, 'first_name' => 'Rule', 'last_name' => 'Player', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        // Today as the database reads it: the date `CURDATE()` compares with.
        $this->today = (string) $wpdb->get_var( 'SELECT CURDATE()' );
    }

    /** @return array<string,array{int,array<string,mixed>,int,bool}> name => [day offset, columns, minutes, played] */
    public function cases(): array {
        return [
            'completed and future-dated'            => [ 3, [ 'activity_status_key' => 'completed' ], 0, false ],
            'planned today, no minutes, no score'   => [ 0, [], 0, false ],
            'scored and future-dated'               => [ 3, [ 'home_score' => 2, 'away_score' => 1 ], 0, false ],
            'minutes and future-dated'              => [ 3, [], 60, false ],
            'completed today'                       => [ 0, [ 'activity_status_key' => 'completed' ], 0, true ],
            'completed today, upper case'           => [ 0, [ 'activity_status_key' => 'Completed' ], 0, true ],
            'minutes today'                         => [ 0, [], 60, true ],
            'scored today'                          => [ 0, [ 'home_score' => 0, 'away_score' => 0 ], 0, true ],
            'a past planned fixture'                => [ -2, [], 0, true ],
        ];
    }

    /**
     * @dataProvider cases
     * @param array<string,mixed> $columns
     */
    public function test_the_rule( int $offset, array $columns, int $minutes, bool $played ): void {
        $id = $this->match( $offset, $columns, $minutes );
        $this->assertSame( $played, $this->isPlayed( $id ) );
        $this->assertTrue( $this->isLive( $id ), 'precondition: the fixture is live' );
    }

    /** @return array<string,array{array<string,mixed>}> */
    public function excluded(): array {
        return [
            'cancelled by status'                 => [ [ 'activity_status_key' => 'cancelled' ] ],
            'cancelled by status, upper case'     => [ [ 'activity_status_key' => 'CANCELLED' ] ],
            'cancelled by plan state'             => [ [ 'plan_state' => 'cancelled' ] ],
            'cancelled by plan state, mixed case' => [ [ 'plan_state' => 'Cancelled' ] ],
            'archived'                            => [ [ 'archived_at' => '2020-01-01 00:00:00' ] ],
            'trashed'                             => [ [ 'trashed_at' => '2020-01-01 00:00:00' ] ],
        ];
    }

    /**
     * @dataProvider excluded
     * @param array<string,mixed> $columns
     */
    public function test_a_match_that_is_not_live_is_never_counted( array $columns ): void {
        $id = $this->match( -2, $columns + [ 'activity_status_key' => 'completed' ], 60 );
        $this->assertFalse( $this->isLive( $id ) );
        $this->assertFalse( $this->counts( $id ) );
    }

    public function test_a_null_plan_state_is_kept(): void {
        global $wpdb;
        $id = $this->match( -2, [], 0 );
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}tt_activities SET plan_state = NULL WHERE id = %d", $id ) );
        $this->assertTrue( $this->isLive( $id ), 'a NULL plan state is not a cancellation' );
        $this->assertTrue( $this->counts( $id ) );
    }

    public function test_a_match_outside_the_demo_scope_is_never_counted(): void {
        global $wpdb;
        $id = $this->match( -2, [ 'activity_status_key' => 'completed' ], 60 );
        $this->assertTrue( $this->counts( $id ), 'precondition: counted while untagged' );

        // Demo mode off, the default, hides demo rows.
        $wpdb->insert( "{$wpdb->prefix}tt_demo_tags", [
            'club_id' => (int) CurrentClub::id(), 'batch_id' => 'played-rule', 'entity_type' => 'activity', 'entity_id' => $id,
        ] );
        $this->assertFalse( $this->counts( $id ) );
    }

    public function test_a_tournament_is_minutes_bearing_and_not_a_fixture(): void {
        global $wpdb;
        $id = $this->match( -2, [ 'activity_type_key' => 'tournament' ], 0 );
        $p  = $wpdb->prefix;
        $in = static fn( string $set ): int => (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_activities a WHERE a.id = %d AND " . PlayedMatch::playedInSetSql( $set, 'a' ),
            $id
        ) );
        $this->assertSame( 1, $in( PlayedMatch::MINUTES_BEARING ) );
        $this->assertSame( 0, $in( PlayedMatch::FIXTURES ) );
    }

    /** @param array<string,mixed> $columns */
    private function match( int $offset, array $columns, int $minutes ): int {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $date = gmdate( 'Y-m-d', (int) strtotime( $this->today . ' ' . ( $offset >= 0 ? '+' : '' ) . $offset . ' days' ) );

        $wpdb->insert( "{$p}tt_activities", $columns + [
            'club_id' => $club, 'team_id' => $this->team, 'title' => 'Rule ' . $date,
            'session_date' => $date, 'activity_type_key' => 'game', 'game_subtype_key' => 'League',
            'activity_status_key' => 'planned', 'plan_state' => 'scheduled', 'opponent' => 'VVV',
        ] );
        $id = (int) $wpdb->insert_id;
        if ( $minutes > 0 ) {
            $wpdb->insert( "{$p}tt_attendance", [
                'club_id' => $club, 'activity_id' => $id, 'player_id' => $this->player, 'is_guest' => 0,
                'record_type' => 'actual', 'status' => 'present', 'minutes_played' => $minutes,
            ] );
        }
        return $id;
    }

    private function isPlayed( int $id ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities a WHERE a.id = %d AND " . PlayedMatch::playedSql( 'a' ),
            $id
        ) ) === 1;
    }

    private function isLive( int $id ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities a WHERE a.id = %d AND " . PlayedMatch::liveSql( 'a' ),
            $id
        ) ) === 1;
    }

    private function counts( int $id ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities a WHERE a.id = %d AND " . PlayedMatch::playedInSetSql( PlayedMatch::FIXTURES, 'a' ),
            $id
        ) ) === 1;
    }
}
