<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Threads\Domain\ThreadAccess;
use TT\Modules\Threads\Domain\ThreadVisibility;
use TT\Modules\Threads\ThreadMessagesRepository;

/**
 * #4717 — administering conversations is its own right.
 *
 * Reading every staff-only note, marking notes staff-only and deleting
 * somebody else's message follow the academy-wide thread right in the
 * matrix (or `manage_options`), the staff-only right on the thread's team,
 * and the author rule. Viewing the settings is not part of any of them.
 *
 * Six seats, two thread types. The read-only observer is the seat whose
 * only right beyond reading is viewing the settings, so it is the one the
 * rule is about; the other five pin that nobody else moved.
 */
final class ThreadAdministrationRightTest extends WP_UnitTestCase {

    /** @var array<string,int> seat => user id */
    private array $users = [];

    private int $team_id   = 0;
    private int $player_id = 0;
    private int $goal_id   = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p = $wpdb->prefix;

        ( new RolesService() )->installRoles();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Admin Right U15' ] );
        $this->team_id = (int) $wpdb->insert_id;

        $this->users['player'] = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Admin',
            'last_name'  => 'Right',
            'team_id'    => $this->team_id,
            'wp_user_id' => $this->users['player'],
            'status'     => 'active',
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $this->users['parent'] = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$p}tt_player_parents", [
            'club_id'        => 1,
            'player_id'      => $this->player_id,
            'parent_user_id' => $this->users['parent'],
            'is_primary'     => 1,
        ] );

        $this->users['admin']    = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->users['hod']      = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->users['observer'] = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->users['staff']    = $this->makeTeamManager( $this->team_id );

        $wpdb->insert( "{$p}tt_goals", [
            'club_id'    => 1,
            'player_id'  => $this->player_id,
            'title'      => 'Weak foot',
            'status'     => 'in_progress',
            'created_by' => $this->users['staff'],
        ] );
        $this->goal_id = (int) $wpdb->insert_id;

        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * The fixture has to be the case the issue names: an observer who may
     * view the settings and reads goals academy-wide, so reaches the goal
     * conversation. Without this the refusals below would prove nothing.
     */
    public function test_the_observer_views_settings_and_reaches_the_goal_conversation(): void {
        $observer = $this->users['observer'];

        $this->assertTrue( user_can( $observer, 'tt_view_settings' ), 'the observer may view the settings' );
        $this->assertTrue( ThreadAccess::canRead( 'goal', $this->goal_id, $observer ), 'the observer reads the goal conversation' );
        $this->assertFalse( ThreadAccess::hasGlobalAccess( $observer, 'read' ), 'viewing settings is not reading every conversation' );
        $this->assertFalse( ThreadAccess::hasGlobalAccess( $observer, 'change' ), 'viewing settings is not administering conversations' );
    }

    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public static function staffOnlyCases(): array {
        return [
            'admin'    => [ 'admin', true ],
            'hod'      => [ 'hod', true ],
            'staff'    => [ 'staff', true ],
            'observer' => [ 'observer', false ],
            'parent'   => [ 'parent', false ],
            'player'   => [ 'player', false ],
        ];
    }

    /**
     * @dataProvider staffOnlyCases
     */
    public function test_staff_only_notes_on_the_goal_thread( string $seat, bool $expected ): void {
        $uid = $this->users[ $seat ];
        $this->assertSame( $expected, ThreadAccess::canSeePrivate( 'goal', $this->goal_id, $uid ), "{$seat}: read staff-only" );
        $this->assertSame( $expected, ThreadAccess::canWritePrivate( 'goal', $this->goal_id, $uid ), "{$seat}: write staff-only" );
    }

    /**
     * @dataProvider staffOnlyCases
     */
    public function test_staff_only_notes_on_the_player_thread( string $seat, bool $expected ): void {
        $uid = $this->users[ $seat ];
        $this->assertSame( $expected, ThreadAccess::canSeePrivate( 'player', $this->player_id, $uid ), "{$seat}: read staff-only" );
        $this->assertSame( $expected, ThreadAccess::canWritePrivate( 'player', $this->player_id, $uid ), "{$seat}: write staff-only" );
    }

    /**
     * Deleting a message somebody else wrote: the academy-wide thread right
     * only. Team staff delete their own messages, not their colleagues'.
     *
     * @return array<string,array{0:string,1:string,2:int}>
     */
    public static function deleteOthersCases(): array {
        $out = [];
        foreach ( [ 'goal', 'player' ] as $type ) {
            foreach ( [
                'admin'    => 200,
                'hod'      => 200,
                'staff'    => 403,
                'observer' => 403,
                'parent'   => 403,
                'player'   => 403,
            ] as $seat => $status ) {
                $out[ "{$type}/{$seat}" ] = [ $type, $seat, $status ];
            }
        }
        return $out;
    }

    /**
     * @dataProvider deleteOthersCases
     */
    public function test_deleting_somebody_elses_message( string $type, string $seat, int $status ): void {
        // Written by an account none of the six seats is.
        $author = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $msg_id = $this->insertMessage( $type, $author, ThreadVisibility::PUBLIC_LEVEL );

        $response = $this->delete( $type, $msg_id, $this->users[ $seat ] );

        $this->assertSame( $status, $response->get_status(), "{$type}/{$seat}" );
        $this->assertSame( $status === 200, $this->isDeleted( $msg_id ), "{$type}/{$seat}: stored state follows the answer" );
    }

    /** The author rule is unchanged: the thread's own people delete what they wrote. */
    public function test_an_author_deletes_their_own_message(): void {
        $staff  = $this->users['staff'];
        $msg_id = $this->insertMessage( 'goal', $staff, ThreadVisibility::PUBLIC_LEVEL );
        $this->assertSame( 200, $this->delete( 'goal', $msg_id, $staff )->get_status() );

        $msg_id = $this->insertMessage( 'player', $staff, ThreadVisibility::PUBLIC_LEVEL );
        $this->assertSame( 200, $this->delete( 'player', $msg_id, $staff )->get_status() );

        $player = $this->users['player'];
        $msg_id = $this->insertMessage( 'goal', $player, ThreadVisibility::PUBLIC_LEVEL );
        $this->assertSame( 200, $this->delete( 'goal', $msg_id, $player )->get_status() );
    }

    /** The observer is handed no staff-only message in the goal conversation it reads. */
    public function test_the_observer_reads_only_the_public_side_of_the_goal_conversation(): void {
        $admin = $this->users['admin'];
        $this->insertMessage( 'goal', $admin, ThreadVisibility::PUBLIC_LEVEL );
        $this->insertMessage( 'goal', $admin, ThreadVisibility::PRIVATE_COACH );

        wp_set_current_user( $this->users['observer'] );
        $response = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/threads/goal/' . $this->goal_id )
        );

        $this->assertSame( 200, $response->get_status() );
        $data     = (array) $response->get_data();
        $messages = isset( $data['messages'] ) && is_array( $data['messages'] ) ? $data['messages'] : $data;
        $this->assertNotEmpty( $messages );
        foreach ( $messages as $message ) {
            $this->assertNotSame(
                ThreadVisibility::PRIVATE_COACH,
                (string) ( ( (array) $message )['visibility'] ?? '' ),
                'a staff-only message reached the observer'
            );
        }
    }

    /* ---- helpers -------------------------------------------------------- */

    private function insertMessage( string $type, int $author, string $visibility ): int {
        $id = ( new ThreadMessagesRepository() )->insert( [
            'thread_type'    => $type,
            'thread_id'      => $type === 'goal' ? $this->goal_id : $this->player_id,
            'author_user_id' => $author,
            'body'           => 'Fixture message.',
            'visibility'     => $visibility,
        ] );
        $this->assertGreaterThan( 0, $id );
        return $id;
    }

    private function delete( string $type, int $msg_id, int $uid ): \WP_REST_Response {
        wp_set_current_user( $uid );
        $thread_id = $type === 'goal' ? $this->goal_id : $this->player_id;
        $request   = new WP_REST_Request( 'DELETE', "/talenttrack/v1/threads/{$type}/{$thread_id}/messages/{$msg_id}" );
        return rest_get_server()->dispatch( $request );
    }

    private function isDeleted( int $msg_id ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT deleted_at FROM {$wpdb->prefix}tt_thread_messages WHERE id = %d",
            $msg_id
        ) ) !== null;
    }

    /**
     * A team manager on `$team_id`: team staff with the staff-only right at
     * team scope and no academy-wide thread right.
     */
    private function makeTeamManager( int $team_id ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_team_manager' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Team',
            'last_name'  => 'Manager',
            'role_type'  => 'team_manager',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'      => $team_id,
            'person_id'    => $person_id,
            'role_in_team' => 'team_manager',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => 1,
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        return $uid;
    }
}
