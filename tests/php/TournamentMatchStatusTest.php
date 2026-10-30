<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Tournaments\Services\TournamentMatchStatus;
use TT\Shared\Frontend\FrontendTournamentsManageView;

/**
 * #4264 — a fixture's status, and the chip that shows it apart from the
 * opponent level. A fixture with a score that nobody completed used to fall
 * through to the level chip, so nothing said its minutes were not booked.
 */
final class TournamentMatchStatusTest extends WP_UnitTestCase {

    /** @param array<string,mixed> $fields */
    private static function row( array $fields = [] ): array {
        return array_merge( [
            'kicked_off_at'  => null,
            'completed_at'   => null,
            'our_score'      => null,
            'their_score'    => null,
            'opponent_level' => 'equal',
        ], $fields );
    }

    public function test_no_kickoff_and_no_score_is_scheduled(): void {
        $this->assertSame( TournamentMatchStatus::SCHEDULED, TournamentMatchStatus::of( self::row() ) );
    }

    public function test_one_score_alone_is_still_scheduled(): void {
        $this->assertSame( TournamentMatchStatus::SCHEDULED, TournamentMatchStatus::of( self::row( [ 'our_score' => 2 ] ) ) );
    }

    public function test_kicked_off_is_in_progress(): void {
        $this->assertSame(
            TournamentMatchStatus::IN_PROGRESS,
            TournamentMatchStatus::of( self::row( [ 'kicked_off_at' => '2026-10-03 10:00:00', 'our_score' => 1, 'their_score' => 0 ] ) )
        );
    }

    public function test_both_scores_without_completion_is_result_entered(): void {
        $this->assertSame(
            TournamentMatchStatus::RESULT_ENTERED,
            TournamentMatchStatus::of( self::row( [ 'our_score' => 2, 'their_score' => 4 ] ) )
        );
    }

    public function test_a_goalless_draw_is_a_result(): void {
        $this->assertSame(
            TournamentMatchStatus::RESULT_ENTERED,
            TournamentMatchStatus::of( (object) self::row( [ 'our_score' => 0, 'their_score' => 0 ] ) )
        );
    }

    public function test_completed_wins_over_everything(): void {
        $this->assertSame(
            TournamentMatchStatus::COMPLETED,
            TournamentMatchStatus::of( self::row( [ 'kicked_off_at' => '2026-10-03 10:00:00', 'completed_at' => '2026-10-03 10:30:00', 'our_score' => 2, 'their_score' => 4 ] ) )
        );
    }

    public function test_count_not_completed_skips_scheduled_and_completed(): void {
        $matches = [
            self::row(),
            self::row( [ 'kicked_off_at' => '2026-10-03 10:00:00' ] ),
            self::row( [ 'our_score' => 2, 'their_score' => 4 ] ),
            self::row( [ 'kicked_off_at' => '2026-10-03 10:00:00', 'completed_at' => '2026-10-03 10:30:00' ] ),
        ];
        $this->assertSame( 2, TournamentMatchStatus::countNotCompleted( $matches ) );
    }

    public function test_a_scored_unclosed_fixture_renders_the_not_closed_chip_and_its_level(): void {
        $html = FrontendTournamentsManageView::matchChipsHtml(
            (object) self::row( [ 'our_score' => 2, 'their_score' => 4 ] ),
            [ 'equal' => 'Equal' ],
            []
        );

        $this->assertStringContainsString( 'data-tt-match-status="result_entered"', $html );
        $this->assertStringContainsString( 'tt-tour-chip--open', $html );
        $this->assertStringContainsString( 'Not closed', $html );
        $this->assertStringContainsString( 'tt-tour-chip--level', $html );
        $this->assertStringContainsString( 'Equal', $html );
    }

    public function test_the_level_chip_renders_beside_every_status(): void {
        $cases = [
            'scheduled'   => self::row(),
            'in_progress' => self::row( [ 'kicked_off_at' => '2026-10-03 10:00:00' ] ),
            'completed'   => self::row( [ 'kicked_off_at' => '2026-10-03 10:00:00', 'completed_at' => '2026-10-03 10:30:00' ] ),
        ];
        $classes = [ 'scheduled' => 'tt-tour-chip--scheduled', 'in_progress' => 'tt-tour-chip--live', 'completed' => 'tt-tour-chip--done' ];
        foreach ( $cases as $status => $row ) {
            $html = FrontendTournamentsManageView::matchChipsHtml( (object) $row, [ 'equal' => 'Equal' ], [] );
            $this->assertStringContainsString( 'data-tt-match-status="' . $status . '"', $html, $status );
            $this->assertStringContainsString( $classes[ $status ], $html, $status );
            $this->assertStringContainsString( 'tt-tour-chip--level', $html, $status );
        }
    }

    public function test_the_tournament_detail_returns_each_matchs_status(): void {
        ( new RolesService() )->ensureCapabilities();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wpdb;
        $wpdb->hide_errors();
        $wpdb->insert( $wpdb->prefix . 'tt_tournaments', [
            'club_id' => 1, 'team_id' => 552, 'name' => 'Autumn cup',
        ] );
        $tournament_id = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_tournament_matches', [
            'club_id'              => 1,
            'tournament_id'        => $tournament_id,
            'sequence'             => 1,
            'opponent_name'        => 'Ajax',
            'opponent_level'       => 'equal',
            'duration_min'         => 20,
            'substitution_windows' => '[]',
            'our_score'            => 2,
            'their_score'          => 4,
        ] );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $res  = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/tournaments/' . $tournament_id ) );
        $data = $res->get_data();
        $wp_rest_server = null;

        $this->assertSame( 200, $res->get_status() );
        $body    = (array) ( $data['data'] ?? $data );
        $matches = $body['matches'] ?? [];
        $this->assertCount( 1, $matches );
        $this->assertSame( 'result_entered', $matches[0]['status'] );
    }
}
