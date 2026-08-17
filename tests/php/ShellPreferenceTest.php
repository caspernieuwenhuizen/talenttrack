<?php
/**
 * ShellPreference resolution (#2456).
 *
 * The resolver is the single chokepoint every consumer reads through, so
 * the cases that matter are the fallbacks: an install that has never seen
 * the setting must render `classic`, and a value that is not a known shell
 * must never produce a chrome-less page.
 */

use TT\Shared\Frontend\ShellPreference;

class ShellPreferenceTest extends WP_UnitTestCase {

    private int $user_id = 0;

    public function set_up(): void {
        parent::set_up();
        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        ShellPreference::setClubDefault( ShellPreference::CLASSIC );
        delete_user_meta( $this->user_id, ShellPreference::USER_META_KEY );
    }

    public function test_defaults_to_classic_when_nothing_is_configured(): void {
        $this->assertSame( ShellPreference::CLASSIC, ShellPreference::clubDefault() );
        $this->assertSame( ShellPreference::CLASSIC, ShellPreference::resolve( $this->user_id ) );
        $this->assertFalse( ShellPreference::isApp( $this->user_id ) );
    }

    public function test_club_default_applies_when_the_user_has_no_override(): void {
        ShellPreference::setClubDefault( ShellPreference::APP );

        $this->assertSame( ShellPreference::INHERIT, ShellPreference::userOverride( $this->user_id ) );
        $this->assertSame( ShellPreference::APP, ShellPreference::resolve( $this->user_id ) );
        $this->assertTrue( ShellPreference::isApp( $this->user_id ) );
    }

    public function test_user_override_beats_the_club_default_in_both_directions(): void {
        ShellPreference::setClubDefault( ShellPreference::APP );
        ShellPreference::setUserOverride( $this->user_id, ShellPreference::CLASSIC );
        $this->assertSame( ShellPreference::CLASSIC, ShellPreference::resolve( $this->user_id ) );

        ShellPreference::setClubDefault( ShellPreference::CLASSIC );
        ShellPreference::setUserOverride( $this->user_id, ShellPreference::APP );
        $this->assertSame( ShellPreference::APP, ShellPreference::resolve( $this->user_id ) );
    }

    public function test_inherit_clears_the_override_and_follows_later_club_changes(): void {
        ShellPreference::setUserOverride( $this->user_id, ShellPreference::APP );
        ShellPreference::setUserOverride( $this->user_id, ShellPreference::INHERIT );

        $this->assertSame( ShellPreference::INHERIT, ShellPreference::userOverride( $this->user_id ) );
        $this->assertSame( '', (string) get_user_meta( $this->user_id, ShellPreference::USER_META_KEY, true ) );

        // The point of clearing rather than storing the resolved value:
        // a later club change reaches the user.
        ShellPreference::setClubDefault( ShellPreference::APP );
        $this->assertSame( ShellPreference::APP, ShellPreference::resolve( $this->user_id ) );
    }

    public function test_unknown_stored_values_fall_through_rather_than_render_nothing(): void {
        // A hand-edited config, or a value written by a future version
        // this install has been rolled back from.
        ( new \TT\Infrastructure\Config\ConfigService() )->set( ShellPreference::CONFIG_KEY, 'sidebar-v2' );
        $this->assertSame( ShellPreference::CLASSIC, ShellPreference::clubDefault() );

        update_user_meta( $this->user_id, ShellPreference::USER_META_KEY, 'sidebar-v2' );
        $this->assertSame( ShellPreference::INHERIT, ShellPreference::userOverride( $this->user_id ) );
        $this->assertSame( ShellPreference::CLASSIC, ShellPreference::resolve( $this->user_id ) );
    }

    public function test_setters_ignore_values_that_are_not_shells(): void {
        ShellPreference::setClubDefault( ShellPreference::APP );
        ShellPreference::setClubDefault( 'nonsense' );
        $this->assertSame( ShellPreference::APP, ShellPreference::clubDefault() );

        ShellPreference::setUserOverride( $this->user_id, ShellPreference::CLASSIC );
        ShellPreference::setUserOverride( $this->user_id, 'nonsense' );
        $this->assertSame( ShellPreference::CLASSIC, ShellPreference::userOverride( $this->user_id ) );
    }

    public function test_root_class_tracks_the_resolved_shell(): void {
        $this->assertSame( 'tt-shell-classic', ShellPreference::rootClass( $this->user_id ) );

        ShellPreference::setUserOverride( $this->user_id, ShellPreference::APP );
        $this->assertSame( 'tt-shell-app', ShellPreference::rootClass( $this->user_id ) );
    }

    public function test_logged_out_visitors_resolve_to_the_club_default(): void {
        ShellPreference::setClubDefault( ShellPreference::APP );
        wp_set_current_user( 0 );

        // resolve(0) falls back to get_current_user_id(), which is 0 here;
        // there is no override to read, so the club default stands.
        $this->assertSame( ShellPreference::APP, ShellPreference::resolve( 0 ) );
    }
}
