<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerVisibility;
use TT\Infrastructure\Query\PlayerFileCounts;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Threads\Adapters\PlayerThreadAdapter;
use TT\Modules\Threads\Domain\ThreadAccess;
use TT\Modules\Threads\Domain\ThreadVisibility;
use TT\Modules\Threads\ThreadMessagesRepository;
use TT\Shared\Frontend\Components\FrontendThreadView;

/**
 * #5059 — a scout reads the notes kept about the players they are linked
 * to, and never the ones marked staff only. The head of development, a
 * coach and a parent keep the access they had.
 *
 * Asserted at the adapter, over REST and on the rendered thread, with one
 * linked and one unlinked player.
 */
final class ScoutPlayerNotesScopeTest extends WP_UnitTestCase {

    private const TEAM_ID = 8591;

    private const PUBLIC_BODY     = 'Strong first touch under pressure.';
    private const STAFF_ONLY_BODY = 'Family situation, keep within the staff.';

    private int $club;
    private int $scout    = 0;
    private int $linked   = 0;
    private int $unlinked = 0;
    private int $public_id     = 0;
    private int $staff_only_id = 0;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        PlayerVisibility::flush();

        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'id' => self::TEAM_ID, 'name' => 'U15 Notes', 'club_id' => $this->club ] );

        $this->scout    = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->linked   = $this->makePlayer( 'Maxim', 'Terpstra' );
        $this->unlinked = $this->makePlayer( 'Joris', 'Mulder' );
        update_user_meta( $this->scout, 'tt_scout_player_ids', wp_json_encode( [ $this->linked ] ) );

        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->public_id     = $this->insertMessage( $this->linked, $admin, ThreadVisibility::PUBLIC_LEVEL, self::PUBLIC_BODY );
        $this->staff_only_id = $this->insertMessage( $this->linked, $admin, ThreadVisibility::PRIVATE_COACH, self::STAFF_ONLY_BODY );
        $this->insertMessage( $this->unlinked, $admin, ThreadVisibility::PUBLIC_LEVEL, self::PUBLIC_BODY );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        PlayerVisibility::flush();
        MatrixRepository::clearCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_seed_grants_the_scout_thread_read_at_player_scope(): void {
        $repo = new MatrixRepository();
        $this->assertTrue( $repo->lookup( 'scout', 'thread_messages', 'read', 'player' ) );
        $this->assertFalse( $repo->lookup( 'scout', 'thread_messages', 'read', 'global' ) );
        $this->assertFalse( ThreadAccess::hasGlobalAccess( $this->scout, 'read' ), 'a scout does not administer conversations' );
    }

    public function test_a_scout_reads_notes_only_for_linked_players(): void {
        $adapter = new PlayerThreadAdapter();
        $this->assertTrue( $adapter->canRead( $this->scout, $this->linked ) );
        $this->assertFalse( $adapter->canRead( $this->scout, $this->unlinked ) );

        $this->assertSame(
            [ $this->linked => true, $this->unlinked => false ],
            $adapter->canReadMany( $this->scout, [ $this->linked => 0, $this->unlinked => 0 ] ),
            'the batch answer is the single one'
        );
    }

    public function test_a_scout_never_sees_a_staff_only_note(): void {
        $this->assertFalse( ThreadAccess::canSeePrivate( 'player', $this->linked, $this->scout ) );

        wp_set_current_user( $this->scout );
        $response = $this->dispatch( 'GET', '/talenttrack/v1/threads/player/' . $this->linked );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ $this->public_id ], $this->messageIds( $response ) );
    }

    public function test_rest_refuses_an_unlinked_players_notes(): void {
        wp_set_current_user( $this->scout );
        $response = $this->dispatch( 'GET', '/talenttrack/v1/threads/player/' . $this->unlinked );
        $this->assertSame( 403, $response->get_status() );
    }

    public function test_the_rendered_thread_gives_the_same_answer(): void {
        ob_start();
        FrontendThreadView::render( 'player', $this->unlinked, $this->scout );
        $this->assertSame( '', (string) ob_get_clean(), 'no thread for an unlinked player' );

        ob_start();
        FrontendThreadView::render( 'player', $this->linked, $this->scout );
        $html = (string) ob_get_clean();
        $this->assertNotSame( '', $html, 'the thread renders for a linked player' );
        $this->assertStringNotContainsString( self::STAFF_ONLY_BODY, $html );
    }

    public function test_the_notes_badge_counts_a_staff_only_note_only_for_who_may_see_it(): void {
        $hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );

        $this->assertSame( 1, PlayerFileCounts::for( $this->linked, $this->scout )['notes'], 'the scout counts the public note only' );
        $this->assertSame( 2, PlayerFileCounts::for( $this->linked, $hod )['notes'], 'the head of development counts both' );

        wp_set_current_user( $this->scout );
        $this->assertSame( 1, PlayerFileCounts::for( $this->linked )['notes'], 'the current user is the default reader' );
    }

    /**
     * With the matrix off (`tt_authorization_active = 0`) the bridge filter
     * is not hooked and the role capabilities decide. The scout role holds
     * `tt_edit_evaluations` natively, which counts only on a team the reader
     * coaches.
     */
    public function test_with_the_matrix_off_a_scout_sees_no_staff_only_note_and_a_coach_does(): void {
        $coach = $this->coachOnTeam();
        $bridge = [ \TT\Modules\Authorization\AuthorizationModule::class, 'filterUserHasCap' ];
        $hooked = has_filter( 'user_has_cap', $bridge );
        $config = new \TT\Infrastructure\Config\ConfigService();
        $was    = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '0' );
        remove_filter( 'user_has_cap', $bridge, 10 );
        \TT\Infrastructure\Security\AuthorizationService::flushCache();

        try {
            $scout_reach = ThreadAccess::canSeePrivateOnTeams( $this->scout, [ self::TEAM_ID ] );
            $this->assertFalse( $scout_reach['all'] );
            $this->assertFalse( $scout_reach['teams'][ self::TEAM_ID ] ?? true, 'no staff-only reach on a team the scout does not coach' );
            $this->assertFalse( ThreadAccess::canSeePrivate( 'player', $this->linked, $this->scout ) );

            wp_set_current_user( $this->scout );
            $response = $this->dispatch( 'GET', '/talenttrack/v1/threads/player/' . $this->linked );
            if ( $response->get_status() === 200 ) {
                $this->assertNotContains( $this->staff_only_id, $this->messageIds( $response ) );
            }

            $this->assertTrue( user_can( $coach, 'tt_edit_evaluations' ), 'precondition: the coach role holds it' );
            $this->assertTrue( ThreadAccess::canSeePrivateOnTeams( $coach, [ self::TEAM_ID ] )['teams'][ self::TEAM_ID ] ?? false, 'the coach keeps the reach on their team' );
            $this->assertTrue( ThreadAccess::canSeePrivate( 'player', $this->linked, $coach ), 'the coach still sees the staff-only note' );
        } finally {
            $config->set( 'tt_authorization_active', $was );
            if ( $hooked !== false ) add_filter( 'user_has_cap', $bridge, 10, 4 );
            \TT\Infrastructure\Security\AuthorizationService::flushCache();
        }
    }

    public function test_the_head_of_development_keeps_every_note(): void {
        $hod = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $adapter = new PlayerThreadAdapter();
        $this->assertTrue( $adapter->canRead( $hod, $this->linked ) );
        $this->assertTrue( $adapter->canRead( $hod, $this->unlinked ) );
        $this->assertTrue( ThreadAccess::canSeePrivate( 'player', $this->linked, $hod ) );

        wp_set_current_user( $hod );
        $response = $this->dispatch( 'GET', '/talenttrack/v1/threads/player/' . $this->linked );
        $this->assertSame( 200, $response->get_status() );
        $ids = $this->messageIds( $response );
        sort( $ids );
        $this->assertSame( [ $this->public_id, $this->staff_only_id ], $ids );
    }

    public function test_a_coach_keeps_the_notes_of_their_own_team(): void {
        $coach = $this->coachOnTeam();

        $adapter = new PlayerThreadAdapter();
        $this->assertTrue( $adapter->canRead( $coach, $this->linked ), 'a player on the coach\'s team' );
        $this->assertTrue( $adapter->canRead( $coach, $this->unlinked ), 'and every other one on it' );
    }

    private function coachOnTeam(): int {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Team',
            'last_name'  => 'Coach',
            'role_type'  => 'coach',
            'wp_user_id' => $coach,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => self::TEAM_ID,
        ] );
        MatrixRepository::clearCache();
        \TT\Infrastructure\Security\AuthorizationService::flushCache();
        return $coach;
    }

    public function test_a_parent_is_refused_as_before(): void {
        global $wpdb;
        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $this->linked,
            'parent_user_id' => $parent,
        ] );

        $this->assertFalse( ( new PlayerThreadAdapter() )->canRead( $parent, $this->linked ) );
        $this->assertFalse( ThreadAccess::canSeePrivate( 'player', $this->linked, $parent ) );
    }

    public function test_the_migration_narrows_only_the_scouts_default_row(): void {
        global $wpdb;
        $table  = "{$wpdb->prefix}tt_authorization_matrix";
        $module = \TT\Modules\Threads\ThreadsModule::class;

        $before_others = $this->otherRows( $table );

        // An install that still carries the old default global row.
        $wpdb->query( "DELETE FROM {$table} WHERE persona = 'scout' AND entity = 'thread_messages'" );
        $wpdb->insert( $table, [
            'persona' => 'scout', 'entity' => 'thread_messages', 'activity' => 'read', 'scope_kind' => 'global',
            'module_class' => $module, 'is_default' => 1,
        ] );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0328_scout_thread_messages_scope_tightening.php';
        $migration->up();
        $migration->up(); // a re-run changes nothing

        $this->assertSame(
            [ [ 'scope_kind' => 'player', 'is_default' => '1' ] ],
            $this->scoutRows( $table )
        );
        $this->assertSame( $before_others, $this->otherRows( $table ), 'no other persona\'s row moves' );

        // An operator who widened it on purpose keeps their row.
        $wpdb->query( "DELETE FROM {$table} WHERE persona = 'scout' AND entity = 'thread_messages'" );
        $wpdb->insert( $table, [
            'persona' => 'scout', 'entity' => 'thread_messages', 'activity' => 'read', 'scope_kind' => 'global',
            'module_class' => $module, 'is_default' => 0,
        ] );
        $migration->up();
        $this->assertSame(
            [ [ 'scope_kind' => 'global', 'is_default' => '0' ] ],
            $this->scoutRows( $table )
        );
    }

    /** @return list<array<string,string>> */
    private function scoutRows( string $table ): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            "SELECT scope_kind, is_default FROM {$table}
              WHERE persona = 'scout' AND entity = 'thread_messages' AND activity = 'read'",
            ARRAY_A
        );
    }

    /** @return list<array<string,string>> */
    private function otherRows( string $table ): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            "SELECT persona, activity, scope_kind, is_default FROM {$table}
              WHERE entity = 'thread_messages' AND persona <> 'scout'
              ORDER BY persona, activity, scope_kind",
            ARRAY_A
        );
    }

    /** @return list<int> */
    private function messageIds( WP_REST_Response $response ): array {
        $data = (array) $response->get_data();
        return array_map(
            static fn( $m ): int => (int) ( (array) $m )['id'],
            (array) ( $data['messages'] ?? [] )
        );
    }

    private function makePlayer( string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'       => $this->club,
            'first_name'    => $first,
            'last_name'     => $last,
            'team_id'       => self::TEAM_ID,
            'date_of_birth' => '2011-04-04',
            'status'        => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMessage( int $player_id, int $author, string $visibility, string $body ): int {
        $id = ( new ThreadMessagesRepository() )->insert( [
            'thread_type'    => 'player',
            'thread_id'      => $player_id,
            'author_user_id' => $author,
            'body'           => $body,
            'visibility'     => $visibility,
        ] );
        $this->assertGreaterThan( 0, $id );
        return (int) $id;
    }

    private function dispatch( string $method, string $route ): WP_REST_Response {
        return rest_get_server()->dispatch( new WP_REST_Request( $method, $route ) );
    }
}
