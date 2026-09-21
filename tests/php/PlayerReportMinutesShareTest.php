<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Analytics\Reports\PlayerReport;
use TT\Modules\Analytics\Reports\PlayerReportAudience;

/**
 * #3991 — playing time as a share of the minutes available, against the
 * position group and the team.
 *
 * Pinned: the arithmetic on a fixture squad (the player's share, the team
 * mean with the player and the players who did not play, the group mean
 * without the player); no profile position and an empty group give no
 * position line; zero available minutes gives no percentages; the numbers
 * come from the team minutes query; and the comparison is stripped from the
 * data for a family and a scout, who keep the player's own share.
 *
 * The staff caller is a `tt_club_admin` (the `academy_admin` persona); a
 * WordPress administrator would not resolve to it.
 */
final class PlayerReportMinutesShareTest extends WP_UnitTestCase {

    /**
     * Five on the roster, 120 minutes available. Player 1 (CB, RB) played 60,
     * player 2 (CB) 30, player 3 (RB, LB) 90, player 4 (ST) and player 5 (no
     * position) did not play.
     *
     * @return array{0:array<int,list<string>>, 1:array<int,int>}
     */
    private function squad(): array {
        return [
            [ 1 => [ 'CB', 'RB' ], 2 => [ 'CB' ], 3 => [ 'RB', 'LB' ], 4 => [ 'ST' ], 5 => [] ],
            [ 1 => 60, 2 => 30, 3 => 90 ],
        ];
    }

    public function test_share_team_and_position_group(): void {
        [ $roster, $played ] = $this->squad();
        $r = PlayerReport::shareComparison( 1, $roster, $played, 120 );

        $this->assertSame( 50, $r['share'] );
        $this->assertNotNull( $r['comparison'] );
        // (0.5 + 0.25 + 0.75 + 0 + 0) / 5: the player included, and the two
        // who did not play count as nothing rather than dropping out.
        $this->assertSame( 30, $r['comparison']['team'] );

        $position = $r['comparison']['position'];
        $this->assertNotNull( $position );
        // Players 2 (CB) and 3 (RB), without player 1: (0.25 + 0.75) / 2.
        $this->assertSame( 50, $position['share'] );
        $this->assertSame( 2, $position['count'] );
        $this->assertSame( [ 'CB', 'RB' ], $position['positions'] );
    }

    public function test_no_profile_position_gives_no_position_line(): void {
        [ $roster, $played ] = $this->squad();
        $r = PlayerReport::shareComparison( 5, $roster, $played, 120 );

        $this->assertSame( 0, $r['share'] );
        $this->assertNotNull( $r['comparison'] );
        $this->assertNull( $r['comparison']['position'] );
        $this->assertSame( [], $r['comparison']['player_positions'], 'the renderer says there is no profile position' );
        $this->assertSame( 30, $r['comparison']['team'] );
    }

    public function test_an_empty_position_group_gives_no_position_line(): void {
        [ $roster, $played ] = $this->squad();
        $r = PlayerReport::shareComparison( 4, $roster, $played, 120 );

        $this->assertNotNull( $r['comparison'] );
        $this->assertNull( $r['comparison']['position'], 'nobody else plays ST' );
        $this->assertSame( [ 'ST' ], $r['comparison']['player_positions'] );
    }

    public function test_zero_available_minutes_gives_no_percentages(): void {
        [ $roster, $played ] = $this->squad();
        $this->assertSame( [ 'share' => null, 'comparison' => null ], PlayerReport::shareComparison( 1, $roster, $played, 0 ) );
    }

    public function test_the_comparison_is_stripped_for_a_family_and_a_scout(): void {
        $report = [
            'player_id' => 1, 'from' => '2026-03-01', 'to' => '2026-03-31',
            'blocks'    => [ 'letterhead', 'minutes' ],
            'data'      => [
                'letterhead' => [],
                'minutes'    => [ 'apps' => 2, 'minutes' => 60, 'matches' => 2, 'share' => 50, 'comparison' => [ 'team' => 30, 'position' => null, 'player_positions' => [] ] ],
            ],
        ];

        foreach ( [ PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ] as $audience ) {
            $out = PlayerReportAudience::apply( $report, $audience );
            $this->assertArrayNotHasKey( 'comparison', $out['data']['minutes'], $audience . ' must not receive teammates\' minutes' );
            $this->assertSame( 50, $out['data']['minutes']['share'], $audience . ' keeps the player\'s own share' );
        }

        $staff = PlayerReportAudience::apply( $report, PlayerReportAudience::INTERNAL );
        $this->assertArrayHasKey( 'comparison', $staff['data']['minutes'] );
    }

    public function test_end_to_end_from_the_team_minutes_query(): void {
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $admin = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        wp_set_current_user( $admin );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Share U15' ] );
        $team = (int) $wpdb->insert_id;

        $keeper  = $this->player( $team, 'Keeper', 'One', '["GK"]' );
        $second  = $this->player( $team, 'Keeper', 'Two', '["GK"]' );
        $striker = $this->player( $team, 'Striker', 'Three', '["ST"]' );

        $wpdb->insert( "{$p}tt_activities", [
            'club_id' => $club, 'team_id' => $team, 'title' => 'Match',
            'session_date' => '2026-03-10', 'activity_type_key' => 'match',
        ] );
        $match = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_match_prep", [ 'club_id' => $club, 'activity_id' => $match, 'half_length_minutes' => 30, 'uuid' => wp_generate_uuid4() ] );
        foreach ( [ $keeper => 60, $striker => 30 ] as $pid => $mins ) {
            $wpdb->insert( "{$p}tt_attendance", [
                'club_id' => $club, 'activity_id' => $match, 'player_id' => $pid,
                'status' => 'present', 'is_guest' => 0, 'record_type' => 'actual', 'minutes_played' => $mins,
            ] );
        }

        $rows = ( new MinutesQuery() )->forTeam( $team, '2026-03-01', '2026-03-31' );
        $this->assertSame( 60, (int) $rows[0]['available_minutes'], 'precondition: one 2 x 30 match' );

        $staff = ( new PlayerReport() )->forPlayer( $second, '2026-03-01', '2026-03-31', [ 'minutes' ], $admin );
        $this->assertNotNull( $staff );
        $m = $staff['data']['minutes'];
        $this->assertSame( 0, $m['share'] );
        $this->assertSame( 50, $m['comparison']['team'], '(100% + 0% + 50%) / 3' );
        $this->assertSame( 100, $m['comparison']['position']['share'], 'the other keeper played every minute' );
        $this->assertSame( 1, $m['comparison']['position']['count'] );

        // Shared with the family: the other keeper's minutes must not travel.
        $family = ( new PlayerReport() )->forPlayer( $second, '2026-03-01', '2026-03-31', [ 'minutes' ], $admin, PlayerReportAudience::FAMILY );
        $this->assertNotNull( $family );
        $this->assertSame( 0, $family['data']['minutes']['share'] );
        $this->assertArrayNotHasKey( 'comparison', $family['data']['minutes'] );

        wp_set_current_user( 0 );
    }

    private function player( int $team, string $first, string $last, string $positions ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $team, 'first_name' => $first, 'last_name' => $last,
            'status' => 'active', 'wp_user_id' => null, 'preferred_positions' => $positions,
        ] );
        return (int) $wpdb->insert_id;
    }
}
