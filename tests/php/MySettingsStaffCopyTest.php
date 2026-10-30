<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\FrontendMySettingsView;

/**
 * #4272 — My settings spoke to every account as a parent. A team manager
 * and an admin read about "your child" they don't have, which reads as an
 * account set up wrongly. A linked parent keeps today's wording.
 */
final class MySettingsStaffCopyTest extends WP_UnitTestCase {

    private const CHILD_PHRASES = [
        'your own child',
        'your child&#039;s file',
    ];

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_an_admin_without_a_child_reads_no_child_wording(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $html = $this->render();

        $this->assertStringContainsString( 'Messages you receive', $html, 'fixture sanity: the message card rendered' );
        $this->assertStringContainsString( 'What the academy holds about you', $html, 'fixture sanity: the held-contact card rendered' );
        foreach ( self::CHILD_PHRASES as $phrase ) {
            $this->assertStringNotContainsString( $phrase, $html );
        }
        $this->assertStringContainsString( 'concern you or the players you work with.', $html );
        $this->assertStringContainsString( 'Your account details are yours to change above.', $html );
    }

    public function test_a_linked_parent_keeps_the_child_wording(): void {
        global $wpdb;

        $parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => 'Daan',
            'last_name'  => 'Kind',
            'status'     => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_player_parents', [
            'club_id'        => 1,
            'player_id'      => (int) $wpdb->insert_id,
            'parent_user_id' => $parent,
            'is_primary'     => 1,
        ] );
        wp_set_current_user( $parent );

        $html = $this->render();

        foreach ( self::CHILD_PHRASES as $phrase ) {
            $this->assertStringContainsString( $phrase, $html );
        }
        $this->assertStringNotContainsString( 'the players you work with', $html );
    }

    private function render(): string {
        ob_start();
        FrontendMySettingsView::render();
        return (string) ob_get_clean();
    }
}
