<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\FrontendMySettingsView;

/**
 * #4393 — the "Messages you receive" card had a parent wording and a
 * staff wording, so a player read about "the players you work with".
 * A player now gets their own sentence; parent and staff keep theirs.
 */
final class MySettingsPlayerCopyTest extends WP_UnitTestCase {

    private const PLAYER_SENTENCE = 'You will only ever receive messages that concern you.';
    private const PARENT_SENTENCE = 'concern you or your own child.';
    private const STAFF_SENTENCE  = 'concern you or the players you work with.';

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_player_reads_the_player_sentence(): void {
        global $wpdb;

        $user = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => 'Sem',
            'last_name'  => 'Speler',
            'status'     => 'active',
            'wp_user_id' => $user,
        ] );
        wp_set_current_user( $user );

        $html = $this->render();

        $this->assertStringContainsString( 'Messages you receive', $html, 'fixture sanity: the message card rendered' );
        $this->assertStringContainsString( self::PLAYER_SENTENCE, $html );
        $this->assertStringNotContainsString( self::STAFF_SENTENCE, $html );
        $this->assertStringNotContainsString( self::PARENT_SENTENCE, $html );
    }

    public function test_a_linked_parent_reads_the_parent_sentence(): void {
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

        $this->assertStringContainsString( self::PARENT_SENTENCE, $html );
        $this->assertStringNotContainsString( self::PLAYER_SENTENCE, $html );
        $this->assertStringNotContainsString( self::STAFF_SENTENCE, $html );
    }

    public function test_staff_read_the_staff_sentence(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_coach' ] ) );

        $html = $this->render();

        $this->assertStringContainsString( self::STAFF_SENTENCE, $html );
        $this->assertStringNotContainsString( self::PLAYER_SENTENCE, $html );
        $this->assertStringNotContainsString( self::PARENT_SENTENCE, $html );
    }

    private function render(): string {
        ob_start();
        FrontendMySettingsView::render();
        return (string) ob_get_clean();
    }
}
