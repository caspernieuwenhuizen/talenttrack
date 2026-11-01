<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Journey\JourneyEventSubscriber;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\REST\PlayerJourneyRestController;
use TT\Infrastructure\REST\TeamsRestController;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Invitations\PlayerParentsRepository;
use TT\Modules\Comms\Cron\CommsScheduledCron;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Templates\OnboardingNudgeInactiveTemplate;
use TT\Modules\PersonaDashboard\Kpis\MyEvaluationsReceived;
use TT\Modules\PersonaDashboard\Kpis\MyRatingTrend;
use TT\Modules\PersonaDashboard\Kpis\MyTeamPodiumPosition;
use TT\Shared\Frontend\FrontendMyTeamView;
use TT\Shared\Frontend\FrontendOverviewView;
use TT\Shared\Frontend\FrontendPlayerDetailView;

/**
 * Evaluation scores outside the Evaluations tab follow the child's
 * evaluations switch: the Player card tab, My team's card, rank and trend
 * chip, the My development header and the rating trend. A parent the child
 * keeps evaluations from gets the card without its numbers; the team's
 * coach still reads them.
 */
final class PlayerCardFamilySwitchTest extends WP_UnitTestCase {

    private int $team   = 0;
    private int $player = 0;
    private int $parent = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Card U13', 'age_group' => 'U13' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => 1, 'first_name' => 'Card', 'last_name' => 'Childx',
            'team_id' => $this->team, 'status' => 'active',
            'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_player' ] ),
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new PlayerParentsRepository() )->link( $this->player, $this->parent, true );

        foreach ( [ '-20 days', '-3 days' ] as $when ) {
            $wpdb->insert( "{$p}tt_evaluations", [
                'club_id' => (int) CurrentClub::id(), 'player_id' => $this->player,
                'coach_id' => 1, 'eval_date' => gmdate( 'Y-m-d', (int) strtotime( $when ) ),
            ] );
            $wpdb->insert( "{$p}tt_eval_ratings", [
                'club_id' => (int) CurrentClub::id(), 'evaluation_id' => (int) $wpdb->insert_id,
                'category_id' => 1, 'rating' => 7,
            ] );
        }
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        QueryHelpers::set_config( 'tt_player_visible_rank', '0' );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_parent_sees_the_scores_the_child_shares(): void {
        $this->assertStringContainsString( 'tt-player-cardtab__kpis', $this->cardTab( $this->parent ), 'precondition: the card tab shows the ratings' );
        $this->assertStringContainsString( 'tt-pc__rating', $this->myTeam(), 'precondition: My team\'s card shows the rating' );
        $this->assertStringContainsString( 'tt-mt-trend-chip', $this->myTeam(), 'precondition: and the trend chip' );
        $this->assertStringContainsString( 'tt-ov-ovr', $this->hero(), 'precondition: the header shows the rating' );
        $this->assertTrue( ( new MyRatingTrend() )->compute( $this->parent, 1 )->is_available, 'precondition: the trend tile has a value' );
        $this->assertSame( 200, $this->trendRoute()->get_status(), 'precondition: the trend route answers' );
    }

    public function test_the_card_tab_shows_no_scores_the_child_keeps_from_family(): void {
        $this->hideEvaluations();

        $card = $this->cardTab( $this->parent );
        $this->assertStringContainsString( 'tt-pc__name', $card, 'the card itself stays' );
        $this->assertStringNotContainsString( 'tt-player-cardtab__kpis', $card );
        $this->assertStringNotContainsString( 'tt-pc__rating', $card );
        $this->assertStringNotContainsString( 'tt-pc__stats', $card );
        $this->assertStringNotContainsString( 'tt-player-cardtab__radar', $card );
    }

    public function test_my_team_shows_no_scores_the_child_keeps_from_family(): void {
        $this->hideEvaluations();

        $team = $this->myTeam();
        $this->assertStringContainsString( 'tt-pc__name', $team, 'My team\'s card stays' );
        $this->assertStringNotContainsString( 'tt-pc__rating', $team );
        $this->assertStringNotContainsString( 'tt-mt-trend-chip', $team );
        $this->assertStringNotContainsString( 'tt-mt-rank-badge', $team );
    }

    public function test_the_development_header_shows_no_rating_the_child_keeps_from_family(): void {
        $this->hideEvaluations();
        $this->assertStringNotContainsString( 'tt-ov-ovr', $this->hero() );
    }

    public function test_the_trend_tile_is_not_given_to_family_the_child_keeps_it_from(): void {
        $this->hideEvaluations();
        $this->assertFalse( ( new MyRatingTrend() )->compute( $this->parent, 1 )->is_available );
    }

    public function test_the_trend_route_refuses_family_the_child_keeps_it_from(): void {
        $this->hideEvaluations();
        $this->assertSame( 403, $this->trendRoute()->get_status() );
    }

    public function test_the_timeline_keeps_the_evaluation_entry_without_its_score(): void {
        $this->emitEvaluationEvents();
        $this->assertArrayHasKey( 'overall', $this->evaluationEntry()['payload'] ?? [], 'precondition: the entry carries the score' );

        $this->hideEvaluations();
        $entry = $this->evaluationEntry();
        $this->assertNotSame( [], $entry, 'the journey switch keeps the entry' );
        $this->assertArrayNotHasKey( 'overall', $entry['payload'] ?? [] );
    }

    public function test_the_team_route_gives_no_rank(): void {
        QueryHelpers::set_config( 'tt_player_visible_rank', '1' );
        $this->assertNotNull( $this->teamRoute()['rank'] ?? null, 'precondition: the academy shows rank and there is one' );

        $this->hideEvaluations();
        $this->assertNull( $this->teamRoute()['rank'] ?? null );
    }

    public function test_the_podium_tile_is_not_given(): void {
        QueryHelpers::set_config( 'tt_player_visible_rank', '1' );
        $this->assertTrue( ( new MyTeamPodiumPosition() )->compute( $this->parent, 1 )->is_available, 'precondition: the tile has a rank' );

        $this->hideEvaluations();
        $this->assertFalse( ( new MyTeamPodiumPosition() )->compute( $this->parent, 1 )->is_available );
    }

    public function test_the_evaluation_count_tile_is_not_given(): void {
        $this->assertTrue( ( new MyEvaluationsReceived() )->compute( $this->parent, 1 )->is_available, 'precondition: the tile has a count' );

        $this->hideEvaluations();
        $this->assertFalse( ( new MyEvaluationsReceived() )->compute( $this->parent, 1 )->is_available );
    }

    public function test_the_inactive_parent_nudge_counts_what_the_child_shares(): void {
        $body = $this->nudgeBody();
        $this->assertStringContainsString( '2 new evaluation(s)', $body );
        $this->assertStringContainsString( '0 new goal(s)', $body );
    }

    public function test_the_inactive_parent_nudge_leaves_out_hidden_evaluations(): void {
        $this->hideEvaluations();
        $body = $this->nudgeBody();
        $this->assertStringContainsString( '0 new goal(s)', $body, 'precondition: the nudge was sent' );
        $this->assertStringNotContainsString( 'evaluation', $body );
        $this->assertStringNotContainsString( "\n\n\n", $body );
    }

    public function test_the_inactive_parent_nudge_leaves_out_hidden_goals(): void {
        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'goals', false );
        AuthorizationService::flushCache();
        $this->assertFalse( AuthorizationService::parentCanViewSection( $this->parent, $this->player, 'goals' ), 'precondition: the switch is off' );

        $body = $this->nudgeBody();
        $this->assertStringContainsString( '2 new evaluation(s)', $body, 'precondition: the nudge was sent' );
        $this->assertStringNotContainsString( 'goal', $body );
        $this->assertStringNotContainsString( "\n\n\n", $body );
    }

    public function test_the_teams_coach_still_sees_the_scores(): void {
        global $wpdb;
        $this->hideEvaluations();

        $p     = $wpdb->prefix;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Card', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => 1, 'team_id' => $this->team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        AuthorizationService::flushCache();

        $card = $this->cardTab( $coach );
        $this->assertStringContainsString( 'tt-player-cardtab__kpis', $card, 'the switch is the family\'s, not the coach\'s' );
        $this->assertStringContainsString( 'tt-pc__rating', $card );
    }

    private function hideEvaluations(): void {
        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'evaluations', false );
        AuthorizationService::flushCache();
        $this->assertFalse( AuthorizationService::parentCanViewSection( $this->parent, $this->player, 'evaluations' ), 'precondition: the switch is off' );
    }

    private function cardTab( int $viewer ): string {
        wp_set_current_user( $viewer );
        AuthorizationService::flushCache();
        ob_start();
        FrontendPlayerDetailView::render( $this->player, $viewer, false, 'card' );
        return (string) ob_get_clean();
    }

    private function myTeam(): string {
        wp_set_current_user( $this->parent );
        ob_start();
        FrontendMyTeamView::render( $this->row() );
        return (string) ob_get_clean();
    }

    private function hero(): string {
        wp_set_current_user( $this->parent );
        ob_start();
        FrontendOverviewView::renderHero( $this->row() );
        return (string) ob_get_clean();
    }

    private function trendRoute(): \WP_REST_Response {
        wp_set_current_user( $this->parent );
        $request = new \WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/rating-trend' );
        $request->set_param( 'id', $this->player );
        return PlayerJourneyRestController::get_rating_trend( $request );
    }

    private function emitEvaluationEvents(): void {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}tt_evaluations WHERE player_id = %d", $this->player ) );
        foreach ( $ids as $id ) {
            JourneyEventSubscriber::on_evaluation_saved( $this->player, (int) $id );
        }
    }

    /** @return array<string,mixed> */
    private function evaluationEntry(): array {
        wp_set_current_user( $this->parent );
        $request = new \WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/timeline' );
        $request->set_param( 'id', $this->player );
        $data = PlayerJourneyRestController::get_timeline( $request )->get_data();
        foreach ( (array) ( $data['data']['events'] ?? $data['events'] ?? [] ) as $event ) {
            if ( ( $event['event_type'] ?? '' ) === 'evaluation_completed' ) return $event;
        }
        return [];
    }

    /** @return array<string,mixed> */
    private function teamRoute(): array {
        wp_set_current_user( $this->parent );
        $request = new \WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/team' );
        $request->set_param( 'id', $this->player );
        $data = TeamsRestController::get_player_team( $request )->get_data();
        return (array) ( $data['data'] ?? $data );
    }

    /** The body of the inactive-parent nudge, as the parent would read it. */
    private function nudgeBody(): string {
        update_user_meta( $this->parent, 'last_login', gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) );
        delete_user_meta( $this->parent, 'tt_comms_onboarding_nudge_at' );
        $sent    = null;
        $capture = function ( $key, $payload ) use ( &$sent ): void {
            if ( $key === 'onboarding_nudge_inactive' ) $sent = (array) $payload;
        };
        add_action( 'tt_comms_dispatch', $capture, 1, 2 );
        $detect = new \ReflectionMethod( CommsScheduledCron::class, 'detectOnboardingNudges' );
        $detect->setAccessible( true );
        $detect->invoke( null );
        remove_action( 'tt_comms_dispatch', $capture, 1 );
        $this->assertIsArray( $sent, 'precondition: the nudge was dispatched' );

        $request = new CommsRequest( 'onboarding_nudge_inactive', 'onboarding_nudge_inactive', 1, 0, [], $sent );
        return ( new OnboardingNudgeInactiveTemplate() )->render( 'email', $request, Recipient::parent( $this->parent, $this->player ), 'en_US' )[1];
    }

    private function row(): object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_players WHERE id = %d", $this->player ) );
    }
}
