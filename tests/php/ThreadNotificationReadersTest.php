<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\NotificationTemplate;
use TT\Modules\Invitations\PlayerParentsRepository;
use TT\Modules\Threads\Domain\ThreadAccess;
use TT\Modules\Threads\Domain\ThreadVisibility;
use TT\Modules\Threads\Subscribers\NotificationSubscriber;

/**
 * A conversation message, preview included, reaches only the participants
 * who may read the conversation. A parent whose child keeps goals to
 * themselves is a participant of the goal's conversation and may not read
 * it, so is not sent the message either.
 */
final class ThreadNotificationReadersTest extends WP_UnitTestCase {

    private int $player_id  = 0;
    private int $player_uid = 0;
    private int $parent_uid = 0;
    private int $goal_id    = 0;

    /** @var list<int> */
    private array $sent_to = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p = $wpdb->prefix;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new NotificationTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );
        $this->sent_to = [];

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Readers U13', 'age_group' => 'U13' ] );
        $team = (int) $wpdb->insert_id;
        $this->player_uid = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => 1, 'first_name' => 'Readers', 'last_name' => 'Childx',
            'team_id' => $team, 'wp_user_id' => $this->player_uid, 'status' => 'active',
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $this->parent_uid = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new PlayerParentsRepository() )->link( $this->player_id, $this->parent_uid, true );

        $wpdb->insert( "{$p}tt_goals", [
            'club_id' => 1, 'player_id' => $this->player_id, 'title' => 'Readers fixture',
            'status' => 'in_progress', 'created_by' => 1,
        ] );
        $this->goal_id = (int) $wpdb->insert_id;
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
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

    public function test_a_parent_the_child_hides_goals_from_is_not_sent_the_message(): void {
        $this->post();
        $this->assertContains( $this->parent_uid, $this->sent_to, 'precondition: with goals shared, the parent is sent it' );

        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player_id, 'goals', false );
        AuthorizationService::flushCache();
        $this->assertFalse( ThreadAccess::canRead( 'goal', $this->goal_id, $this->parent_uid ), 'precondition: the parent may not read it now' );

        $this->sent_to = [];
        $this->post();
        $this->assertNotContains( $this->parent_uid, $this->sent_to );
        $this->assertContains( $this->player_uid, $this->sent_to, 'the player still is' );
    }

    private function post(): void {
        NotificationSubscriber::onPosted( 'goal', $this->goal_id, 0, 1, ThreadVisibility::PUBLIC_LEVEL );
    }
}
