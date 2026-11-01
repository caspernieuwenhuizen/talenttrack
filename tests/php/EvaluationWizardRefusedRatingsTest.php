<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Wizards\Evaluation\ReviewStep;
use TT\Shared\Frontend\FlashMessages;

/**
 * The activity-first submit tells the coach about a typed rating the write
 * check refused, instead of dropping it in silence or blaming an empty form.
 */
final class EvaluationWizardRefusedRatingsTest extends WP_UnitTestCase {

    private int $own_player   = 0;
    private int $other_player = 0;
    private int $activity     = 0;
    private int $coach        = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Notsaved Own U13', 'age_group' => 'U13' ] );
        $own_team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Notsaved Other U13', 'age_group' => 'U13' ] );
        $other_team = (int) $wpdb->insert_id;

        $this->own_player   = $this->player( $own_team, 'Notsavedown' );
        $this->other_player = $this->player( $other_team, 'Notsavedother' );

        $wpdb->insert( "{$p}tt_activities", [
            'club_id' => $club, 'team_id' => $own_team, 'title' => 'Notsaved training',
            'session_date' => gmdate( 'Y-m-d', (int) strtotime( '-2 days' ) ),
            'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        $this->activity = (int) $wpdb->insert_id;

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $club, 'first_name' => 'Notsaved', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $this->coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => $club, 'team_id' => $own_team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $own_team,
        ] );
        AuthorizationService::flushCache();
        wp_set_current_user( $this->coach );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_one_saved_and_one_refused_tells_the_coach_one_was_not_saved(): void {
        $result = ( new ReviewStep() )->submit( [
            '_path' => 'activity-first', 'activity_id' => $this->activity,
            'ratings' => [ $this->own_player => [ 1 => 7 ], $this->other_player => [ 1 => 7 ] ],
        ] );

        $this->assertIsArray( $result );
        $this->assertSame( 1, $this->evaluationsFor( $this->own_player ), 'the own player is saved' );
        $this->assertSame( 0, $this->evaluationsFor( $this->other_player ), 'the other team\'s player is not' );
        $this->assertSame( 1, $result['not_saved'] ?? null );

        $warnings = array_values( array_filter(
            FlashMessages::peek( $this->coach ),
            static fn( array $m ): bool => $m['type'] === FlashMessages::TYPE_WARNING
        ) );
        $this->assertCount( 1, $warnings, 'one not-saved message is queued for the page the coach lands on' );
        $this->assertStringContainsString( '1 rating was not saved', $warnings[0]['message'] );
    }

    public function test_every_row_refused_shows_the_not_saved_message(): void {
        $result = ( new ReviewStep() )->submit( [
            '_path' => 'activity-first', 'activity_id' => $this->activity,
            'ratings' => [ $this->other_player => [ 1 => 7 ] ],
        ] );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'ratings_not_saved', $result->get_error_code() );
        $this->assertStringContainsString( '1 rating was not saved', $result->get_error_message() );
        $this->assertStringNotContainsString( 'No ratings were entered', $result->get_error_message() );
    }

    public function test_nothing_typed_still_says_no_ratings_were_entered(): void {
        $result = ( new ReviewStep() )->submit( [
            '_path' => 'activity-first', 'activity_id' => $this->activity,
            'ratings' => [ $this->own_player => [ 1 => 0 ], $this->other_player => [ 1 => 0 ] ],
        ] );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'empty_evaluation', $result->get_error_code() );
        $this->assertSame( [], FlashMessages::peek( $this->coach ) );
    }

    private function evaluationsFor( int $player_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_evaluations WHERE player_id = %d",
            $player_id
        ) );
    }

    private function player( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $team_id,
            'first_name' => $first, 'last_name' => 'Kidx', 'status' => 'active',
            'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_player' ] ),
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'fixture: the player row was written' );
        return $id;
    }
}
