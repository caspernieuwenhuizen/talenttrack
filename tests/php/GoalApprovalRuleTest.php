<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\GoalStatus;
use TT\Infrastructure\Goals\GoalAccess;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Goals\Admin\GoalsPage;

/**
 * #5112 — goal approval follows one rule on every route that writes a
 * status. A goal waiting for approval (`pending_approval`, or the seeded
 * `Pending Approval`) leaves that status only through the player's head
 * coach or an administrator: on `PATCH /goals/{id}/status`, on
 * `PUT /goals/{id}` and on the wp-admin goal form.
 *
 * The academy is SearchFixtures': `coach` is the head coach of team A,
 * `assistant` an assistant coach of the same team, and Daan den Besten a
 * player on it.
 */
final class GoalApprovalRuleTest extends WP_UnitTestCase {

    use SearchFixtures;

    public function set_up(): void {
        parent::set_up();
        $this->searchSetUp();
    }

    public function tear_down(): void {
        unset( $_POST['id'], $_POST['player_id'], $_POST['title'], $_POST['status'], $_POST['tt_nonce'], $_REQUEST['tt_nonce'] );
        $this->searchTearDown();
        parent::tear_down();
    }

    /** @return array<string, array{0:string}> */
    public function waitingSpellings(): array {
        return [ 'snake_case' => [ 'pending_approval' ], 'TitleCase' => [ 'Pending Approval' ] ];
    }

    /** @dataProvider waitingSpellings */
    public function test_put_by_an_assistant_coach_is_refused_and_by_the_head_coach_allowed( string $waiting ): void {
        $goal = $this->goal( $waiting );

        $refused = $this->rest( $this->personas['assistant'], 'PUT', "/goals/{$goal}", [ 'status' => GoalStatus::PENDING ] );
        $this->assertSame( 403, $refused->get_status(), (string) wp_json_encode( $refused->get_data() ) );
        $this->assertSame( 'forbidden_approval', $this->errorCode( $refused ) );
        $this->assertSame( $waiting, $this->statusOf( $goal ), 'nothing written' );

        $allowed = $this->rest( $this->personas['coach'], 'PUT', "/goals/{$goal}", [ 'status' => GoalStatus::PENDING ] );
        $this->assertSame( 200, $allowed->get_status(), (string) wp_json_encode( $allowed->get_data() ) );
        $this->assertSame( GoalStatus::PENDING, $this->statusOf( $goal ) );
    }

    /** @dataProvider waitingSpellings */
    public function test_patch_status_follows_the_same_rule( string $waiting ): void {
        $goal = $this->goal( $waiting );

        $refused = $this->rest( $this->personas['assistant'], 'PATCH', "/goals/{$goal}/status", [ 'status' => GoalStatus::CANCELLED ] );
        $this->assertSame( 403, $refused->get_status(), (string) wp_json_encode( $refused->get_data() ) );
        $this->assertSame( 'forbidden_approval', $this->errorCode( $refused ) );
        $this->assertSame( $waiting, $this->statusOf( $goal ) );

        $allowed = $this->rest( $this->personas['coach'], 'PATCH', "/goals/{$goal}/status", [ 'status' => GoalStatus::CANCELLED ] );
        $this->assertSame( 200, $allowed->get_status(), (string) wp_json_encode( $allowed->get_data() ) );
        $this->assertSame( GoalStatus::CANCELLED, $this->statusOf( $goal ) );
    }

    /** An edit that keeps the status, or any edit of a goal not waiting, is not an approval. */
    public function test_an_assistant_may_edit_a_waiting_goal_without_deciding_it(): void {
        $waiting = $this->goal( 'Pending Approval' );
        $kept    = $this->rest( $this->personas['assistant'], 'PUT', "/goals/{$waiting}", [ 'title' => 'Beter koppen', 'status' => GoalStatus::PENDING_APPROVAL ] );
        $this->assertSame( 200, $kept->get_status(), (string) wp_json_encode( $kept->get_data() ) );

        $open  = $this->goal( GoalStatus::IN_PROGRESS );
        $moved = $this->rest( $this->personas['assistant'], 'PUT', "/goals/{$open}", [ 'status' => GoalStatus::ON_HOLD ] );
        $this->assertSame( 200, $moved->get_status(), (string) wp_json_encode( $moved->get_data() ) );
    }

    public function test_the_rule_itself(): void {
        $player    = $this->players['besten'];
        $assistant = $this->personas['assistant'];
        $this->assertFalse( GoalAccess::mayChangeStatus( $assistant, $player, 'Pending Approval', 'pending' ) );
        $this->assertFalse( GoalAccess::mayChangeStatus( $assistant, $player, 'pending-approval', 'rejected' ) );
        $this->assertTrue( GoalAccess::mayChangeStatus( $assistant, $player, 'pending_approval', 'Pending Approval' ), 'staying is no decision' );
        $this->assertTrue( GoalAccess::mayChangeStatus( $assistant, $player, 'in_progress', 'completed' ), 'not waiting' );
        $this->assertTrue( GoalAccess::mayChangeStatus( $this->personas['coach'], $player, 'pending_approval', 'pending' ), 'the head coach' );
        $this->assertTrue( GoalAccess::mayChangeStatus( $this->personas['admin'], $player, 'pending_approval', 'pending' ), 'an administrator' );
    }

    public function test_a_wp_admin_save_by_an_assistant_leaves_the_status_alone(): void {
        $goal = $this->goal( GoalStatus::PENDING_APPROVAL );
        wp_set_current_user( $this->personas['assistant'] );
        $this->resetSearchCaches();
        $_POST['id']        = (string) $goal;
        $_POST['player_id'] = (string) $this->players['besten'];
        $_POST['title']     = 'Goedgekeurd door de assistent';
        $_POST['status']    = GoalStatus::PENDING;
        $_POST['tt_nonce']  = wp_create_nonce( 'tt_save_goal' );
        $_REQUEST['tt_nonce'] = $_POST['tt_nonce'];

        $died = '';
        try {
            GoalsPage::handle_save();
        } catch ( \WPDieException $e ) {
            $died = $e->getMessage();
        }
        $this->assertStringContainsString( 'head coach', $died, 'refused by the approval rule, not by the page gate' );
        $this->assertSame( GoalStatus::PENDING_APPROVAL, $this->statusOf( $goal ) );
        $this->assertNotSame( 'Goedgekeurd door de assistent', $this->titleOf( $goal ), 'nothing written' );
    }

    private function goal( string $status ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_goals", [
            'club_id' => (int) CurrentClub::id(), 'player_id' => $this->players['besten'], 'title' => 'Doel van de speler',
            'status' => $status, 'created_by' => 0,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $body */
    private function rest( int $user_id, string $method, string $route, array $body ): \WP_REST_Response {
        wp_set_current_user( $user_id );
        $this->resetSearchCaches();
        $request = new \WP_REST_Request( $method, '/talenttrack/v1' . $route );
        $request->set_body_params( $body );
        return rest_do_request( $request );
    }

    private function errorCode( \WP_REST_Response $response ): string {
        $body = (array) $response->get_data();
        return (string) ( $body['errors'][0]['code'] ?? '' );
    }

    private function statusOf( int $goal ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}tt_goals WHERE id = %d", $goal ) );
    }

    private function titleOf( int $goal ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$wpdb->prefix}tt_goals WHERE id = %d", $goal ) );
    }
}
