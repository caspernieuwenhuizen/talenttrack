<?php
/**
 * Live-match layout preference (#2934).
 *
 * The resolver is the only door to the config key, so what is worth
 * pinning is the resolution order, the direction it fails in, and that
 * `inherit` really does hand the user back to the academy default rather
 * than freezing whatever the default happened to be at the time.
 */

use TT\Modules\MatchExecution\MatchExecutionLayout;

class MatchExecutionLayoutTest extends WP_UnitTestCase {

    private int $user_id = 0;

    public function set_up(): void {
        parent::set_up();
        $this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user_id );
        delete_user_meta( $this->user_id, MatchExecutionLayout::USER_META_KEY );
        MatchExecutionLayout::setClubDefault( MatchExecutionLayout::CLASSIC );
    }

    public function test_a_fresh_install_gets_the_classic_layout(): void {
        // Nothing configured anywhere: existing installs must see no
        // change until somebody opts in.
        $this->assertSame( MatchExecutionLayout::CLASSIC, MatchExecutionLayout::clubDefault() );
        $this->assertSame( MatchExecutionLayout::CLASSIC, MatchExecutionLayout::resolve( $this->user_id ) );
        $this->assertFalse( MatchExecutionLayout::isSections( $this->user_id ) );
    }

    public function test_the_club_default_is_inherited_when_the_user_has_no_override(): void {
        MatchExecutionLayout::setClubDefault( MatchExecutionLayout::SECTIONS );

        $this->assertSame( MatchExecutionLayout::INHERIT, MatchExecutionLayout::userOverride( $this->user_id ) );
        $this->assertSame( MatchExecutionLayout::SECTIONS, MatchExecutionLayout::resolve( $this->user_id ) );
        $this->assertTrue( MatchExecutionLayout::isSections( $this->user_id ) );
    }

    public function test_a_user_override_beats_the_club_default(): void {
        // The whole point: an academy flipping its default must not move a
        // coach who has pinned the layout they know.
        MatchExecutionLayout::setClubDefault( MatchExecutionLayout::SECTIONS );
        MatchExecutionLayout::setUserOverride( $this->user_id, MatchExecutionLayout::CLASSIC );

        $this->assertSame( MatchExecutionLayout::CLASSIC, MatchExecutionLayout::resolve( $this->user_id ) );
    }

    public function test_a_user_can_opt_in_before_the_academy_does(): void {
        MatchExecutionLayout::setUserOverride( $this->user_id, MatchExecutionLayout::SECTIONS );

        $this->assertSame( MatchExecutionLayout::CLASSIC, MatchExecutionLayout::clubDefault() );
        $this->assertSame( MatchExecutionLayout::SECTIONS, MatchExecutionLayout::resolve( $this->user_id ) );
    }

    public function test_inherit_follows_later_club_changes(): void {
        // Deleting the meta rather than storing the current default is
        // what makes "use the academy default" keep meaning that.
        MatchExecutionLayout::setUserOverride( $this->user_id, MatchExecutionLayout::SECTIONS );
        MatchExecutionLayout::setUserOverride( $this->user_id, MatchExecutionLayout::INHERIT );

        $this->assertSame( '', (string) get_user_meta( $this->user_id, MatchExecutionLayout::USER_META_KEY, true ) );

        MatchExecutionLayout::setClubDefault( MatchExecutionLayout::SECTIONS );
        $this->assertSame( MatchExecutionLayout::SECTIONS, MatchExecutionLayout::resolve( $this->user_id ) );
    }

    public function test_an_unknown_stored_value_falls_through_rather_than_breaking(): void {
        // A hand-edited config or a value left by a future version must
        // never produce a layout nothing knows how to render.
        update_user_meta( $this->user_id, MatchExecutionLayout::USER_META_KEY, 'holographic' );

        $this->assertSame( MatchExecutionLayout::INHERIT, MatchExecutionLayout::userOverride( $this->user_id ) );
        $this->assertSame( MatchExecutionLayout::CLASSIC, MatchExecutionLayout::resolve( $this->user_id ) );
    }

    public function test_setters_ignore_unknown_values(): void {
        MatchExecutionLayout::setClubDefault( MatchExecutionLayout::SECTIONS );
        MatchExecutionLayout::setClubDefault( 'holographic' );

        $this->assertSame( MatchExecutionLayout::SECTIONS, MatchExecutionLayout::clubDefault() );

        MatchExecutionLayout::setUserOverride( $this->user_id, 'holographic' );
        $this->assertSame( MatchExecutionLayout::INHERIT, MatchExecutionLayout::userOverride( $this->user_id ) );
    }

    public function test_a_logged_out_visitor_resolves_rather_than_erroring(): void {
        wp_set_current_user( 0 );

        $this->assertSame( MatchExecutionLayout::INHERIT, MatchExecutionLayout::userOverride( 0 ) );
        $this->assertSame( MatchExecutionLayout::CLASSIC, MatchExecutionLayout::resolve( 0 ) );
    }

    public function test_every_layout_has_a_label(): void {
        $labels = MatchExecutionLayout::labels();

        foreach ( MatchExecutionLayout::layouts() as $layout ) {
            $this->assertArrayHasKey( $layout, $labels );
            $this->assertNotSame( '', trim( $labels[ $layout ] ) );
        }
    }

    public function test_the_config_key_is_accepted_by_the_config_endpoint(): void {
        // The Configuration screen posts through ConfigRestController's
        // allowlist; a key missing from it saves silently into nothing.
        $ref  = new ReflectionClass( \TT\Infrastructure\REST\ConfigRestController::class );
        $keys = $ref->getConstant( 'ALLOWED_KEYS' );

        $this->assertContains( MatchExecutionLayout::CONFIG_KEY, $keys );
    }
}
