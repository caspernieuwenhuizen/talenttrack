<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\FrontendActivitiesManageView;

/**
 * #2438 — the "Sync team from Spond" header action on an activity detail.
 *
 * The gate must mirror the endpoint's (`TeamSpondAccess::canManage()`), the
 * action must target the team-scoped sync route, and it must stay ahead of
 * the destructive Archive button. Anything else on the page is out of scope
 * here.
 */
final class SpondSyncActivityActionTest extends WP_UnitTestCase {

    private const LABEL = 'Sync team from Spond';

    /** @param array<int,array<string,mixed>> $actions */
    private function addAction( array &$actions, object $session ): void {
        $method = new \ReflectionMethod( FrontendActivitiesManageView::class, 'addSpondSyncAction' );
        $method->setAccessible( true );
        $method->invokeArgs( null, [ &$actions, $session ] );
    }

    /** @param array<int,array<string,mixed>> $actions */
    private function findSync( array $actions ): ?array {
        foreach ( $actions as $a ) {
            if ( ( $a['label'] ?? '' ) === self::LABEL ) return $a;
        }
        return null;
    }

    private function spondActivity( array $overrides = [] ): object {
        return (object) array_merge( [
            'id'                      => 4242,
            'team_id'                 => 7,
            'activity_source_key'     => 'spond',
            'archived_at'             => null,
            'team_spond_last_sync_at' => '2026-08-16 09:00:00',
        ], $overrides );
    }

    public function test_admin_sees_the_action_on_a_spond_activity(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $actions = [];
        $this->addAction( $actions, $this->spondActivity() );
        $sync = $this->findSync( $actions );

        $this->assertNotNull( $sync );
        $this->assertSame( 'teams/7/spond/sync', $sync['data_attrs']['tt-archive-rest-path'] );
        $this->assertSame( 'POST', $sync['data_attrs']['tt-archive-method'] );
    }

    /** The gate is the endpoint's, so a user without that authority sees nothing. */
    public function test_user_without_spond_authority_does_not_see_the_action(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $actions = [];
        $this->addAction( $actions, $this->spondActivity() );

        $this->assertNull( $this->findSync( $actions ) );
    }

    public function test_hidden_on_manual_archived_and_teamless_activities(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        foreach ( [
            'manual source' => [ 'activity_source_key' => 'manual' ],
            'archived'      => [ 'archived_at' => '2026-08-01 10:00:00' ],
            'no team'       => [ 'team_id' => 0 ],
        ] as $case => $overrides ) {
            $actions = [];
            $this->addAction( $actions, $this->spondActivity( $overrides ) );
            $this->assertNull( $this->findSync( $actions ), "Action leaked on: {$case}." );
        }
    }

    /** Archive is destructive and keeps the last slot. */
    public function test_action_sits_ahead_of_the_archive_button(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $actions = [
            [ 'label' => 'Edit', 'href' => '#', 'primary' => true ],
            [ 'label' => 'Archive', 'variant' => 'danger', 'data_attrs' => [] ],
        ];
        $this->addAction( $actions, $this->spondActivity() );

        $labels = array_column( $actions, 'label' );
        $this->assertLessThan(
            array_search( 'Archive', $labels, true ),
            array_search( self::LABEL, $labels, true )
        );
    }

    /** A sync moments ago is called out in the confirm copy, not blocked. */
    public function test_confirm_copy_flags_a_very_recent_sync(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $stale = [];
        $this->addAction( $stale, $this->spondActivity() );
        $fresh = [];
        $this->addAction( $fresh, $this->spondActivity( [ 'team_spond_last_sync_at' => current_time( 'mysql' ) ] ) );

        $this->assertStringContainsString(
            'less than a minute ago',
            $this->findSync( $fresh )['data_attrs']['tt-archive-confirm']
        );
        $this->assertStringNotContainsString(
            'less than a minute ago',
            $this->findSync( $stale )['data_attrs']['tt-archive-confirm']
        );
        // Both spell out that the refresh covers the whole team.
        $this->assertStringContainsString( 'whole team calendar', $this->findSync( $stale )['data_attrs']['tt-archive-confirm'] );
    }
}
