<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Admin\BulkActionsHelper;

/**
 * #4003 — a bulk action filters the rows it was handed, and the dead
 * `includes/Admin/` page classes are gone.
 *
 * `BulkActionsHelper::handle()` checked one club-wide capability and passed
 * `ids[]` straight from the POST to the repository. A checkbox list is the
 * easiest thing in the application to edit, so the batch is filtered row by
 * row now. Every refusal below is paired with the grant beside it: a filter
 * that dropped the caller's own rows would be the worse bug.
 */
final class RecordScopeAdminTest extends WP_UnitTestCase {

    private int $admin        = 0;
    private int $coach        = 0;
    private int $mineTeam     = 0;
    private int $otherTeam    = 0;
    private int $minePlayer   = 0;
    private int $otherPlayer  = 0;
    private int $mineActivity = 0;
    private int $otherActivity = 0;
    private int $mineGoal     = 0;
    private int $otherGoal    = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Bulk team mine' ] );
        $this->mineTeam = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Bulk team theirs' ] );
        $this->otherTeam = (int) $wpdb->insert_id;

        $this->minePlayer  = $this->makePlayer( 'Bulkalpha', $this->mineTeam );
        $this->otherPlayer = $this->makePlayer( 'Bulkbravo', $this->otherTeam );

        $this->mineActivity  = $this->makeActivity( $this->mineTeam, 'Bulk training mine' );
        $this->otherActivity = $this->makeActivity( $this->otherTeam, 'Bulk training theirs' );

        $this->mineGoal  = $this->makeGoal( $this->minePlayer );
        $this->otherGoal = $this->makeGoal( $this->otherPlayer );

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->coach = $this->makeHeadCoach( $this->mineTeam );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_mixed_batch_keeps_only_the_rows_the_caller_may_touch(): void {
        wp_set_current_user( $this->coach );
        AuthorizationService::flushCache();
        $this->assertTrue( current_user_can( 'tt_edit_players' ), 'the coach holds the club-wide capability, so the filter is what narrows the batch' );

        $this->assertSame(
            [ $this->minePlayer ],
            BulkActionsHelper::reachableIds( 'player', [ $this->minePlayer, $this->otherPlayer ] )
        );
        $this->assertSame(
            [ $this->mineActivity ],
            BulkActionsHelper::reachableIds( 'activity', [ $this->mineActivity, $this->otherActivity ] )
        );
        $this->assertSame(
            [ $this->mineGoal ],
            BulkActionsHelper::reachableIds( 'goal', [ $this->mineGoal, $this->otherGoal ] )
        );
    }

    public function test_an_academy_admin_keeps_every_row(): void {
        wp_set_current_user( $this->admin );
        AuthorizationService::flushCache();

        $this->assertSame(
            [ $this->minePlayer, $this->otherPlayer ],
            BulkActionsHelper::reachableIds( 'player', [ $this->minePlayer, $this->otherPlayer ] )
        );
        $this->assertSame(
            [ $this->mineActivity, $this->otherActivity ],
            BulkActionsHelper::reachableIds( 'activity', [ $this->mineActivity, $this->otherActivity ] )
        );
    }

    public function test_an_entity_with_no_player_or_team_dimension_passes_through(): void {
        wp_set_current_user( $this->coach );
        AuthorizationService::flushCache();

        // A lookup-shaped entity has nothing to narrow to; the per-entity
        // capability is the whole answer for it.
        $this->assertSame(
            [ 7, 8, 9 ],
            BulkActionsHelper::reachableIds( 'custom_widget', [ 7, 8, 9 ] )
        );

        // `team` is deliberately unfiltered — see the helper's docblock: the
        // available check asks a different question than "is this your squad".
        $this->assertSame(
            [ $this->mineTeam, $this->otherTeam ],
            BulkActionsHelper::reachableIds( 'team', [ $this->mineTeam, $this->otherTeam ] )
        );
    }

    public function test_a_row_that_does_not_exist_is_dropped_rather_than_dispatched(): void {
        wp_set_current_user( $this->coach );
        AuthorizationService::flushCache();

        $missing = $this->otherGoal + 100000;
        $this->assertSame( [], BulkActionsHelper::reachableIds( 'goal', [ $missing ] ) );
        $this->assertSame( [], BulkActionsHelper::reachableIds( 'activity', [ $missing, 0 ] ) );
    }

    /**
     * The seven `includes/Admin/` page classes registered a second
     * `admin_post_tt_save_player` / `tt_delete_player` and friends with no
     * capability check and no nonce. Composer maps `TT\` to `src/` only, so
     * they never loaded — but a stray `require` would have armed them, and a
     * duplicate handler on a live action is not a thing to leave lying about.
     */
    public function test_the_legacy_admin_page_classes_are_gone(): void {
        $dir = TT_PLUGIN_DIR . 'includes/Admin/';

        foreach ( [ 'Players', 'Teams', 'Goals', 'Evaluations', 'Sessions', 'Reports', 'Configuration', 'Menu' ] as $name ) {
            $this->assertFileDoesNotExist( $dir . $name . '.php' );
            $this->assertFalse(
                class_exists( '\\TT\\Admin\\' . $name ),
                "TT\\Admin\\{$name} must not be loadable"
            );
        }
    }

    // Fixtures

    private function makePlayer( string $last, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'Bulk',
            'last_name'     => $last,
            'team_id'       => $team_id,
            'status'        => 'active',
            'date_of_birth' => '2011-04-02',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write the player' );
        return $id;
    }

    private function makeActivity( int $team_id, string $title ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'           => (int) CurrentClub::id(),
            'team_id'           => $team_id,
            'title'             => $title,
            'session_date'      => '2026-03-01',
            'activity_type_key' => 'training',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write the activity' );
        return $id;
    }

    private function makeGoal( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_goals", [
            'club_id'    => (int) CurrentClub::id(),
            'player_id'  => $player_id,
            'title'      => 'Bulk goal ' . $player_id,
            'status'     => 'pending',
            'priority'   => 'medium',
            'created_by' => 1,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write the goal' );
        return $id;
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Hoofd',
            'last_name'  => 'Trainer',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $person_id, 'the fixture must write the person' );

        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        AuthorizationService::flushCache();
        $this->assertTrue( AuthorizationService::canEditPlayer( $uid, $this->minePlayer ), 'the coach must reach their own squad, or every refusal here is vacuous' );
        $this->assertFalse( AuthorizationService::canEditPlayer( $uid, $this->otherPlayer ), 'the other squad is outside the coach\'s scope' );
        return $uid;
    }
}
