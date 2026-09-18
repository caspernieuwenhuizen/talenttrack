<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\AdminCenterClient\SupportGrantBanner;
use TT\Modules\AdminCenterClient\SupportGrants;
use TT\Modules\AdminCenterClient\SupportOperator;
use TT\Modules\Authorization\Impersonation\ImpersonationService;

/**
 * #3501 — time-boxed operator access, and what the club can see about it.
 *
 * The decision was **visibility, not consent**: a grant needs no approval step
 * from the club, and that trade only holds because of three properties. Two of
 * them are enforced on this install and both are asserted here — every grant
 * ends on its own even with the control plane unreachable, and a revoked grant
 * stops working on the next ping because held grants are replaced rather than
 * merged.
 */
final class SupportGrantsTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        SupportGrants::forget();
    }

    public function tear_down(): void {
        SupportGrants::forget();
        parent::tear_down();
    }

    /** @return array<string,mixed> */
    private function grant( string $expires, int $id = 3 ): array {
        return [
            'id'         => $id,
            'operator'   => 'Casper',
            'reason'     => 'Attendance not saving for U14 — asked by Jan',
            'expires_at' => $expires,
        ];
    }

    private function future(): string {
        return gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
    }

    private function past(): string {
        return gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
    }

    // ---------------------------------------------------------------
    // Parsing
    // ---------------------------------------------------------------

    public function test_a_response_without_the_key_leaves_what_is_held_alone(): void {
        $this->assertNull( SupportGrants::fromResponse( [] ) );
    }

    public function test_an_empty_array_is_a_real_answer_and_clears_the_grants(): void {
        SupportGrants::store( [ $this->grant( $this->future() ) ] );
        $this->assertTrue( SupportGrants::anyLive() );

        // This is how revocation reaches the install: the control plane stops
        // sending the grant, and the replace wipes it.
        $parsed = SupportGrants::fromResponse( [ 'support_grants' => [] ] );
        $this->assertSame( [], $parsed );

        SupportGrants::store( $parsed );
        $this->assertFalse( SupportGrants::anyLive() );
    }

    public function test_a_grant_with_no_expiry_is_refused(): void {
        // "Ends on its own" is the property that makes this safe. A grant
        // without an end has already failed it.
        $parsed = SupportGrants::fromResponse( [ 'support_grants' => [
            [ 'id' => 1, 'operator' => 'Casper', 'reason' => 'x' ],
        ] ] );

        $this->assertSame( [], $parsed );
    }

    public function test_a_grant_with_no_operator_is_refused(): void {
        $parsed = SupportGrants::fromResponse( [ 'support_grants' => [
            [ 'id' => 1, 'reason' => 'x', 'expires_at' => $this->future() ],
        ] ] );

        $this->assertSame( [], $parsed );
    }

    public function test_a_grant_with_no_reason_is_kept(): void {
        // The club is owed the operator and the end time even when nobody
        // typed a reason; hiding the grant would be worse.
        $parsed = SupportGrants::fromResponse( [ 'support_grants' => [
            [ 'id' => 1, 'operator' => 'Casper', 'expires_at' => $this->future() ],
        ] ] );

        $this->assertCount( 1, $parsed );
        $this->assertSame( '', $parsed[0]['reason'] );
    }

    // ---------------------------------------------------------------
    // Expiry — enforced here, not by the control plane
    // ---------------------------------------------------------------

    public function test_a_live_grant_is_live(): void {
        SupportGrants::store( [ $this->grant( $this->future() ) ] );

        $this->assertTrue( SupportGrants::anyLive() );
        $this->assertSame( 3, SupportGrants::current()['id'] );
    }

    public function test_an_expired_grant_is_not_live_even_with_no_fresh_response(): void {
        // The install closes the grant on time whether or not it can reach the
        // Admin Center. This is the first of the three properties.
        SupportGrants::store( [ $this->grant( $this->past() ) ] );

        $this->assertFalse( SupportGrants::anyLive() );
        $this->assertNull( SupportGrants::current() );
    }

    public function test_an_unparseable_expiry_fails_closed(): void {
        update_option( SupportGrants::OPTION, (string) wp_json_encode( [
            [ 'id' => 1, 'operator' => 'Casper', 'reason' => '', 'expires_at' => 'whenever' ],
        ] ) );

        $this->assertFalse( SupportGrants::anyLive() );
    }

    public function test_the_earliest_expiring_grant_is_the_current_one(): void {
        SupportGrants::store( [
            $this->grant( gmdate( 'Y-m-d H:i:s', time() + 3 * HOUR_IN_SECONDS ), 9 ),
            $this->grant( gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ), 4 ),
        ] );

        $this->assertSame( 4, SupportGrants::current()['id'] );
    }

    // ---------------------------------------------------------------
    // The gate
    // ---------------------------------------------------------------

    public function test_an_operator_cannot_impersonate_without_a_live_grant(): void {
        $operator = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $target   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        SupportOperator::setOperator( $operator, true );

        $error = ImpersonationService::start( $operator, $target );

        $this->assertInstanceOf( \WP_Error::class, $error );
        $this->assertSame( 'no_support_grant', $error->get_error_code() );
    }

    public function test_an_operator_with_a_live_grant_may_impersonate(): void {
        $operator = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $target   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        SupportOperator::setOperator( $operator, true );
        SupportGrants::store( [ $this->grant( $this->future() ) ] );

        $this->assertNull( ImpersonationService::start( $operator, $target ) );
    }

    public function test_an_expired_grant_refuses_the_operator(): void {
        $operator = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $target   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        SupportOperator::setOperator( $operator, true );
        SupportGrants::store( [ $this->grant( $this->past() ) ] );

        $error = ImpersonationService::start( $operator, $target );

        $this->assertInstanceOf( \WP_Error::class, $error );
        $this->assertSame( 'no_support_grant', $error->get_error_code() );
    }

    /**
     * The gate must bite on exactly one side of the operator/club line.
     * Gating the club's own administrators would lock an academy out of its
     * own records the first time the control plane was unreachable.
     */
    public function test_the_clubs_own_administrator_is_never_gated(): void {
        $admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $target = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $this->assertFalse( SupportOperator::isOperator( $admin ) );
        $this->assertNull( ImpersonationService::start( $admin, $target ) );
    }

    // ---------------------------------------------------------------
    // What the club sees
    // ---------------------------------------------------------------

    public function test_the_banner_names_the_operator_the_reason_and_the_end(): void {
        $sentence = SupportGrantBanner::sentence( $this->grant( $this->future() ) );

        $this->assertStringContainsString( 'Casper', $sentence );
        $this->assertStringContainsString( 'Attendance not saving', $sentence );
        $this->assertStringContainsString( 'Ends', $sentence );
    }

    public function test_the_banner_says_so_when_no_reason_was_given(): void {
        $sentence = SupportGrantBanner::sentence( [
            'id' => 1, 'operator' => 'Casper', 'reason' => '', 'expires_at' => $this->future(),
        ] );

        $this->assertStringContainsString( 'no reason was given', $sentence );
    }

    /**
     * Visibility is the entire safeguard behind "no approval step", so the
     * banner carries no dismiss control at all — unlike the broadcast banner,
     * whose notices are ordinary operator messages.
     */
    public function test_the_banner_cannot_be_dismissed(): void {
        $html = SupportGrantBanner::html( [ $this->grant( $this->future() ) ] );

        $this->assertStringContainsString( 'tt-support-grant', $html );
        $this->assertStringNotContainsString( 'dismiss', $html );
        $this->assertStringNotContainsString( '<button', $html );
        $this->assertStringNotContainsString( '<form', $html );
    }

    public function test_the_banner_renders_nothing_with_no_live_grant(): void {
        $this->assertSame( '', SupportGrantBanner::html( SupportGrants::live() ) );
    }

    public function test_the_reason_is_escaped_where_it_is_rendered(): void {
        $html = SupportGrantBanner::html( [ [
            'id' => 1, 'operator' => 'Casper', 'reason' => '<script>x</script>',
            'expires_at' => $this->future(),
        ] ] );

        $this->assertStringNotContainsString( '<script', $html );
    }
}
