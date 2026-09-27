<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Recipient\RecipientResolver;
use TT\Modules\Push\Dispatchers\ParentEmailDispatcher;

/**
 * #4128 — the parent e-mail link in the push chain forwards what the
 * child's status allows (#4089): a trialist's family is forwarded the
 * schedule and nothing about the child's development, an active child's
 * family everything, a released or graduated child's family nothing.
 *
 * Each refusal sits beside a grant, on a sibling or on the other kind of
 * push, so "narrowed correctly" cannot pass as "refused everything".
 */
final class ParentEmailForwardingByStatusTest extends WP_UnitTestCase {

    private int $club = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        $this->club = (int) CurrentClub::id();
    }

    public function test_the_classifier_admits_only_schedule_messages(): void {
        $this->assertFalse( RecipientResolver::isDevelopmentMessage( MessageType::TRAINING_CANCELLED ) );
        $this->assertFalse( RecipientResolver::isDevelopmentMessage( MessageType::SCHEDULE_CHANGE_FROM_SPOND ) );
        $this->assertFalse( RecipientResolver::isDevelopmentMessage( MessageType::TRIAL_PLAYER_WELCOME ) );
        $this->assertTrue( RecipientResolver::isDevelopmentMessage( MessageType::GOAL_NUDGE ) );
        $this->assertTrue( RecipientResolver::isDevelopmentMessage( MessageType::PDP_READY ) );
        $this->assertTrue( RecipientResolver::isDevelopmentMessage( 'workflow_task_created' ) );
        $this->assertTrue( RecipientResolver::isDevelopmentMessage( '' ), 'an unnamed push is development content' );
    }

    public function test_a_trialists_family_is_forwarded_the_schedule_only(): void {
        $trialist = $this->playerAccount( 'trial' );
        $active   = $this->playerAccount( 'active' );
        $dispatch = new ParentEmailDispatcher();

        $this->assertFalse( $dispatch->applicableTo( $this->push( $trialist, 'workflow_task_created' ) ), 'a development push is not forwarded' );
        $this->assertTrue( $dispatch->applicableTo( $this->push( $trialist, MessageType::TRAINING_CANCELLED ) ), 'a schedule push is' );

        $this->assertTrue( $dispatch->applicableTo( $this->push( $active, 'workflow_task_created' ) ), 'an active child\'s family is forwarded development' );
        $this->assertTrue( $dispatch->applicableTo( $this->push( $active, MessageType::TRAINING_CANCELLED ) ) );
    }

    public function test_the_legacy_column_does_not_reach_a_trialists_family_with_development(): void {
        global $wpdb;
        $trialist = $this->playerAccount( 'trial', false );
        $legacy   = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'parent_user_id' => $legacy ], [ 'wp_user_id' => $trialist ] );

        $dispatch = new ParentEmailDispatcher();
        $this->assertFalse( $dispatch->applicableTo( $this->push( $trialist, 'workflow_task_created' ) ) );
        $this->assertTrue( $dispatch->applicableTo( $this->push( $trialist, MessageType::TRAINING_CANCELLED ) ) );
    }

    public function test_a_closed_out_childs_family_is_forwarded_nothing(): void {
        $dispatch = new ParentEmailDispatcher();
        foreach ( [ 'released', 'graduated' ] as $status ) {
            $account = $this->playerAccount( $status );
            $this->assertFalse( $dispatch->applicableTo( $this->push( $account, 'workflow_task_created' ) ), "{$status}: no development push" );
            $this->assertFalse( $dispatch->applicableTo( $this->push( $account, MessageType::TRAINING_CANCELLED ) ), "{$status}: no schedule push" );
        }
    }

    /**
     * A player with their own account and, unless told otherwise, one
     * guardian linked through the pivot. Returns the player's WP user id.
     */
    private function playerAccount( string $status, bool $with_guardian = true ): int {
        global $wpdb;
        $account = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'first_name' => 'Forward',
            'last_name'  => ucfirst( $status ),
            'status'     => $status,
            'wp_user_id' => $account,
        ] );
        $player = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $player, "fixture: the {$status} player was written" );

        if ( $with_guardian ) {
            $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
            $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
                'club_id'        => $this->club,
                'player_id'      => $player,
                'parent_user_id' => $parent,
                'is_primary'     => 1,
            ] );
        }
        return $account;
    }

    /** @return array{user_id:int,title:string,body:string,event:string} */
    private function push( int $user_id, string $event ): array {
        return [ 'user_id' => $user_id, 'title' => 'Title', 'body' => 'Body', 'event' => $event ];
    }
}
