<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\NotificationTemplate;
use TT\Modules\Threads\Domain\ThreadAccess;
use TT\Modules\Threads\Domain\ThreadVisibility;
use TT\Modules\Threads\Subscribers\NotificationSubscriber;
use TT\Modules\Threads\ThreadTypeRegistry;

/**
 * #4303 — a staff-only message notifies exactly the participants who can
 * read it.
 *
 * The filter used to ask `tt_view_settings || tt_edit_evaluations`, the
 * rule from before staff-only notes had a right of their own. It now asks
 * `ThreadAccess::canSeePrivate()`, the rule the read side applies, and it
 * still only narrows the adapter's participant list: nobody is added.
 */
final class ThreadStaffOnlyNotificationTest extends WP_UnitTestCase {

    private int $team_id    = 0;
    private int $other_team = 0;
    private int $player_id  = 0;
    private int $player_uid = 0;
    private int $parent_uid = 0;
    private int $poster_uid = 0;

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

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Notify Mine', 'age_group' => 'U15' ] );
        $this->team_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Notify Theirs', 'age_group' => 'U17' ] );
        $this->other_team = (int) $wpdb->insert_id;

        $this->player_uid = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Notify',
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

        $this->poster_uid = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
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

    public function test_a_staff_only_message_reaches_the_staff_author_and_not_the_family(): void {
        $coach   = $this->makeTeamManager( $this->team_id );
        $goal_id = $this->goalBy( $coach );

        $this->assertTrue(
            ThreadAccess::canSeePrivate( 'goal', $goal_id, $coach ),
            'Precondition: the team manager of the player\'s team reads staff-only messages here.'
        );

        $this->post( $goal_id, ThreadVisibility::PRIVATE_COACH );

        $this->assertSame( [ $coach ], $this->sent_to );
    }

    public function test_a_staff_author_who_cannot_read_it_is_not_notified(): void {
        // Staff of another team who wrote the goal: a participant, but the
        // staff-only right counts on their own team's conversations only.
        $stranger = $this->makeTeamManager( $this->other_team );
        $goal_id  = $this->goalBy( $stranger );

        $this->assertFalse(
            ThreadAccess::canSeePrivate( 'goal', $goal_id, $stranger ),
            'Precondition: staff of another team cannot read a staff-only message on this player.'
        );

        $this->post( $goal_id, ThreadVisibility::PRIVATE_COACH );

        $this->assertSame( [], $this->sent_to );
    }

    public function test_a_public_message_still_reaches_every_participant_but_the_author(): void {
        $coach   = $this->makeTeamManager( $this->team_id );
        $goal_id = $this->goalBy( $coach );

        $this->post( $goal_id, ThreadVisibility::PUBLIC_LEVEL, $coach );

        $sent = $this->sent_to;
        sort( $sent );
        $expected = [ $this->player_uid, $this->parent_uid ];
        sort( $expected );
        $this->assertSame( $expected, $sent, 'the author is never notified of their own message' );
    }

    public function test_the_notified_set_is_never_wider_than_the_participants(): void {
        $coach   = $this->makeTeamManager( $this->team_id );
        $goal_id = $this->goalBy( $coach );

        $adapter = ThreadTypeRegistry::get( 'goal' );
        $this->assertNotNull( $adapter );
        $participants = $adapter->participantUserIds( $goal_id );

        foreach ( [ ThreadVisibility::PUBLIC_LEVEL, ThreadVisibility::PRIVATE_COACH ] as $visibility ) {
            $this->sent_to = [];
            $this->post( $goal_id, $visibility );
            foreach ( $this->sent_to as $uid ) {
                $this->assertContains( $uid, $participants, "{$visibility}: notified someone who is not a participant" );
                $this->assertNotSame( $this->poster_uid, $uid );
            }
        }
    }

    /* ---- helpers -------------------------------------------------------- */

    private function post( int $goal_id, string $visibility, ?int $author = null ): void {
        NotificationSubscriber::onPosted( 'goal', $goal_id, 0, $author ?? $this->poster_uid, $visibility );
    }

    private function goalBy( int $author ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_goals", [
            'club_id'    => 1,
            'player_id'  => $this->player_id,
            'title'      => 'Notify fixture',
            'status'     => 'in_progress',
            'created_by' => $author,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * A team manager on `$team_id`: holds the staff-only right at team scope
     * and no evaluation change, so neither of the old capabilities.
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

        $this->assertContains(
            'team_manager',
            \TT\Modules\Authorization\PersonaResolver::personasFor( $uid ),
            'the fixture must resolve to the team manager persona'
        );
        return $uid;
    }
}
