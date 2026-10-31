<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\OptOut\OptOutPolicy;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\NotificationTemplate;
use TT\Modules\Threads\Domain\ThreadAccess;
use TT\Modules\Threads\Domain\ThreadVisibility;
use TT\Modules\Threads\Subscribers\NotificationSubscriber;
use TT\Modules\Threads\ThreadTypeRegistry;

/**
 * #4426 — a message on a player's thread notifies the player's team staff
 * who can read the thread, minus the author. A staff-only message reaches
 * only those `ThreadAccess::canSeePrivate()` admits. The player and the
 * guardians are never notified, staff of another team are never notified,
 * and a person who muted notifications is not sent anything.
 */
final class PlayerThreadNotificationTest extends WP_UnitTestCase {

    private int $team_id    = 0;
    private int $other_team = 0;
    private int $player_id  = 0;
    private int $player_uid = 0;
    private int $parent_uid = 0;
    private int $admin_uid  = 0;

    private int $head_coach   = 0;
    private int $assistant    = 0;
    private int $manager      = 0;
    private int $physio       = 0;
    private int $other_coach  = 0;

    /** @var list<int> user ids the email transport was asked to reach */
    private array $sent_to = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p = $wpdb->prefix;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new NotificationTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );
        $this->sent_to = [];

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Notes Mine', 'age_group' => 'U15' ] );
        $this->team_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Notes Theirs', 'age_group' => 'U17' ] );
        $this->other_team = (int) $wpdb->insert_id;

        $this->player_uid = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Notes',
            'last_name'  => 'Subject',
            'team_id'    => $this->team_id,
            'wp_user_id' => $this->player_uid,
            'status'     => 'active',
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $this->parent_uid = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$p}tt_player_parents", [
            'club_id'        => 1,
            'player_id'      => $this->player_id,
            'parent_user_id' => $this->parent_uid,
            'is_primary'     => 1,
        ] );

        $this->admin_uid = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

        $this->head_coach  = $this->makeStaff( $this->team_id, 'tt_coach', 'head_coach', 'coach' );
        $this->assistant   = $this->makeStaff( $this->team_id, 'tt_coach', 'assistant_coach', 'assistant_coach' );
        $this->manager     = $this->makeStaff( $this->team_id, 'tt_team_manager', 'manager', 'manager' );
        $this->physio      = $this->makeStaff( $this->team_id, 'tt_staff', 'physio', 'physio' );
        $this->other_coach = $this->makeStaff( $this->other_team, 'tt_coach', 'head_coach', 'coach' );
    }

    public function tear_down(): void {
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        MatrixRepository::clearCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * @param mixed               $accepted
     * @param array<string,mixed> $args
     */
    public function captureSend( $accepted, $args ): bool {
        $recipient = $args['recipient'] ?? null;
        if ( is_object( $recipient ) && isset( $recipient->userId ) ) {
            $this->sent_to[] = (int) $recipient->userId;
        }
        return true;
    }

    public function test_a_public_message_reaches_the_team_staff_who_can_read_it_but_not_the_author(): void {
        $this->post( ThreadVisibility::PUBLIC_LEVEL, $this->head_coach );

        $expected = [];
        foreach ( [ $this->assistant, $this->manager, $this->physio ] as $uid ) {
            if ( ThreadAccess::canRead( 'player', $this->player_id, $uid ) ) $expected[] = $uid;
        }
        $this->assertContains( $this->manager, $expected, 'Precondition: the team manager reads the player thread.' );

        $this->assertSameSet( $expected, $this->sent_to );
        $this->assertNotContains( $this->head_coach, $this->sent_to, 'the author is never notified of their own message' );
    }

    public function test_a_staff_only_message_reaches_only_the_staff_who_can_see_it(): void {
        $this->post( ThreadVisibility::PRIVATE_COACH, $this->head_coach );

        $expected = [];
        foreach ( [ $this->assistant, $this->manager, $this->physio ] as $uid ) {
            if ( ThreadAccess::canSeePrivate( 'player', $this->player_id, $uid ) ) $expected[] = $uid;
        }
        $this->assertContains( $this->manager, $expected, 'Precondition: the team manager holds the staff-only right on this team.' );

        $this->assertSameSet( $expected, $this->sent_to );
        foreach ( $this->sent_to as $uid ) {
            $this->assertTrue( ThreadAccess::canSeePrivate( 'player', $this->player_id, $uid ), "notified {$uid}, who cannot read a staff-only message" );
        }
    }

    public function test_the_player_and_the_guardians_are_never_notified(): void {
        foreach ( [ $this->head_coach, $this->admin_uid ] as $author ) {
            foreach ( [ ThreadVisibility::PUBLIC_LEVEL, ThreadVisibility::PRIVATE_COACH ] as $visibility ) {
                $this->sent_to = [];
                $this->post( $visibility, $author );
                $this->assertNotContains( $this->player_uid, $this->sent_to, "{$visibility}: the player was notified" );
                $this->assertNotContains( $this->parent_uid, $this->sent_to, "{$visibility}: a guardian was notified" );
            }
        }
    }

    public function test_a_guardian_who_is_also_team_staff_is_not_notified(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => 1,
            'player_id'      => $this->player_id,
            'parent_user_id' => $this->assistant,
            'is_primary'     => 0,
        ] );

        $adapter = ThreadTypeRegistry::get( 'player' );
        $this->assertNotNull( $adapter );
        $this->assertNotContains( $this->assistant, $adapter->participantUserIds( $this->player_id ) );

        $this->post( ThreadVisibility::PUBLIC_LEVEL, $this->head_coach );
        $this->assertNotContains( $this->assistant, $this->sent_to );
    }

    public function test_a_coach_of_another_team_is_not_notified(): void {
        // An admin author is the widest case: the read check bypasses its
        // team scope for a current user who holds the settings capability.
        foreach ( [ $this->head_coach, $this->admin_uid ] as $author ) {
            $this->sent_to = [];
            $this->post( ThreadVisibility::PUBLIC_LEVEL, $author );
            $this->assertNotContains( $this->other_coach, $this->sent_to );
        }
    }

    public function test_a_person_who_muted_notifications_is_not_notified(): void {
        ( new OptOutPolicy() )->setOptedOut( $this->manager, MessageType::NOTIFICATION, true );

        $this->post( ThreadVisibility::PUBLIC_LEVEL, $this->head_coach );

        $this->assertNotContains( $this->manager, $this->sent_to );
        $this->assertContains( $this->assistant, $this->sent_to, 'the others are still notified' );
    }

    public function test_the_notified_set_is_never_wider_than_the_participants(): void {
        $adapter = ThreadTypeRegistry::get( 'player' );
        $this->assertNotNull( $adapter );
        $participants = $adapter->participantUserIds( $this->player_id );

        foreach ( $participants as $uid ) {
            $this->assertTrue( ThreadAccess::canRead( 'player', $this->player_id, $uid ), "participant {$uid} cannot read the thread" );
        }

        foreach ( [ ThreadVisibility::PUBLIC_LEVEL, ThreadVisibility::PRIVATE_COACH ] as $visibility ) {
            $this->sent_to = [];
            $this->post( $visibility, $this->admin_uid );
            foreach ( $this->sent_to as $uid ) {
                $this->assertContains( $uid, $participants, "{$visibility}: notified someone who is not a participant" );
            }
        }
    }

    /* ---- helpers -------------------------------------------------------- */

    private function post( string $visibility, int $author ): void {
        // The REST route fires the action as the author, so the subscriber
        // runs with the author as the current user.
        wp_set_current_user( $author );
        NotificationSubscriber::onPosted( 'player', $this->player_id, 0, $author, $visibility );
    }

    /**
     * @param list<int> $expected
     * @param list<int> $actual
     */
    private function assertSameSet( array $expected, array $actual ): void {
        sort( $expected );
        sort( $actual );
        $this->assertSame( $expected, $actual );
    }

    /**
     * A staff member assigned to `$team_id` under a functional role, with
     * the team-scope grant the read side resolves.
     */
    private function makeStaff( int $team_id, string $wp_role, string $role_key, string $role_type ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $uid = (int) self::factory()->user->create( [ 'role' => $wp_role ] );

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_functional_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            $role_key, 1
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$p}tt_functional_roles", [
                'club_id'  => 1,
                'role_key' => $role_key,
                'label'    => ucfirst( str_replace( '_', ' ', $role_key ) ),
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Staff',
            'last_name'  => ucfirst( $role_key ),
            'role_type'  => $role_type,
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'            => 1,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $role_id,
            'role_in_team'       => $role_key,
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
