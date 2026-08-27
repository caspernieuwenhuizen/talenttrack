<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Identity\ContactResolver;

/**
 * #2961 — the one precedence rule for reaching a person.
 *
 * The bug this class exists to prevent: an academy admin edits a coach's
 * email in TalentTrack, the People screen shows it saved, and the nightly
 * digest still goes to the old address because that path read `wp_users`
 * instead. So the case that matters most below is the DISAGREEING one —
 * both stores populated with different values. Anything that resolves the
 * agreeing case correctly but picks the wrong store when they differ has
 * not fixed anything.
 *
 * `emailForAccount()` is tested separately because it deliberately does
 * NOT follow that precedence: recovery mail belongs to the account, and
 * must not be redirectable by editing a person row.
 */
final class ContactResolverTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function makePerson( array $overrides = [] ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_people", array_merge( [
            'club_id'    => $this->club,
            'first_name' => 'Ada',
            'last_name'  => 'Vermeer',
            'email'      => null,
            'phone'      => null,
            'wp_user_id' => null,
            'role_type'  => 'staff',
            'status'     => 'active',
        ], $overrides ) );
        return (int) $wpdb->insert_id;
    }

    public function test_person_row_wins_when_the_two_stores_disagree(): void {
        $uid = self::factory()->user->create( [ 'user_email' => 'stale@example.test' ] );
        $pid = $this->makePerson( [ 'email' => 'edited@example.test', 'wp_user_id' => $uid ] );

        // The address the academy admin edited is the address we reach.
        $this->assertSame( 'edited@example.test', ContactResolver::emailForPerson( $pid ) );
        $this->assertSame( 'edited@example.test', ContactResolver::emailForUser( $uid ) );
    }

    public function test_agreeing_stores_resolve_to_that_address(): void {
        $uid = self::factory()->user->create( [ 'user_email' => 'same@example.test' ] );
        $pid = $this->makePerson( [ 'email' => 'same@example.test', 'wp_user_id' => $uid ] );

        $this->assertSame( 'same@example.test', ContactResolver::emailForPerson( $pid ) );
        $this->assertSame( 'same@example.test', ContactResolver::emailForUser( $uid ) );
    }

    public function test_falls_back_to_the_account_when_the_person_row_has_no_email(): void {
        $uid = self::factory()->user->create( [ 'user_email' => 'account@example.test' ] );
        $pid = $this->makePerson( [ 'email' => null, 'wp_user_id' => $uid ] );

        $this->assertSame( 'account@example.test', ContactResolver::emailForPerson( $pid ) );
        $this->assertSame( 'account@example.test', ContactResolver::emailForUser( $uid ) );
    }

    public function test_person_without_an_account_still_resolves(): void {
        // A parent who never logged in, a scout, a departed coach — the
        // reason tt_people keeps its own contact columns at all.
        $pid = $this->makePerson( [ 'email' => 'noaccount@example.test' ] );

        $this->assertSame( 'noaccount@example.test', ContactResolver::emailForPerson( $pid ) );
    }

    public function test_account_without_a_person_row_resolves_to_the_account(): void {
        $uid = self::factory()->user->create( [ 'user_email' => 'lone@example.test' ] );

        $this->assertSame( 'lone@example.test', ContactResolver::emailForUser( $uid ) );
    }

    public function test_neither_store_populated_resolves_to_null(): void {
        $pid = $this->makePerson( [ 'email' => null ] );

        $this->assertNull( ContactResolver::emailForPerson( $pid ) );
        $this->assertNull( ContactResolver::emailForUser( 0 ) );
        $this->assertNull( ContactResolver::emailForPerson( 0 ) );
    }

    public function test_an_archived_person_row_does_not_win_over_the_live_one(): void {
        $uid = self::factory()->user->create( [ 'user_email' => 'account@example.test' ] );
        $this->makePerson( [
            'email'      => 'archived@example.test',
            'wp_user_id' => $uid,
            'status'     => 'archived',
        ] );

        // Only active rows carry the link; the archived row keeps its
        // wp_user_id and must be ignored.
        $this->assertSame( 'account@example.test', ContactResolver::emailForUser( $uid ) );
    }

    public function test_recovery_address_ignores_the_person_row(): void {
        $uid = self::factory()->user->create( [ 'user_email' => 'recovery@example.test' ] );
        $this->makePerson( [ 'email' => 'contact@example.test', 'wp_user_id' => $uid ] );

        // If this ever returns the person row's address, an edit on the
        // People screen can redirect someone else's password reset.
        $this->assertSame( 'recovery@example.test', ContactResolver::emailForAccount( $uid ) );
        $this->assertSame( 'contact@example.test', ContactResolver::emailForUser( $uid ) );
    }

    public function test_billing_email_is_not_consulted( ): void {
        // #2997 — the WooCommerce field used to outrank the account's own
        // address for parents. It was drift, and it is gone. This is the
        // assertion that stops it coming back: a parent with a
        // billing_email and no person-row email now resolves to the
        // account address, not the billing one.
        $uid = self::factory()->user->create( [ 'user_email' => 'account@example.test' ] );
        update_user_meta( $uid, 'billing_email', 'billing@example.test' );

        $this->assertSame( 'account@example.test', ContactResolver::emailForUser( $uid ) );
    }

    public function test_a_parents_person_row_still_wins( ): void {
        // Unchanged by #2997 — step 1 always won, so no parent with a
        // person-row email sees their mail move.
        $uid = self::factory()->user->create( [ 'user_email' => 'account@example.test' ] );
        update_user_meta( $uid, 'billing_email', 'billing@example.test' );
        $this->makePerson( [ 'email' => 'edited@example.test', 'wp_user_id' => $uid ] );

        $this->assertSame( 'edited@example.test', ContactResolver::emailForUser( $uid ) );
    }

    public function test_parents_are_no_longer_a_special_case( ): void {
        // The resolver used to carry a parent-specific entry point. There
        // is one rule now, and this asserts the class does not quietly
        // grow a second one back.
        $this->assertFalse(
            method_exists( ContactResolver::class, 'emailForParent' ),
            'emailForParent() is back; parents should resolve like everyone else'
        );
    }

    public function test_phone_resolves_person_row_before_user_meta(): void {
        $uid = self::factory()->user->create();
        update_user_meta( $uid, 'tt_phone', '+31600000000' );
        $pid = $this->makePerson( [ 'phone' => '+31611111111', 'wp_user_id' => $uid ] );

        $this->assertSame( '+31611111111', ContactResolver::phoneForPerson( $pid ) );
        $this->assertSame( '+31611111111', ContactResolver::phoneForUser( $uid ) );
    }

    public function test_phone_falls_back_to_user_meta(): void {
        $uid = self::factory()->user->create();
        update_user_meta( $uid, 'tt_phone', '+31600000000' );
        $pid = $this->makePerson( [ 'phone' => null, 'wp_user_id' => $uid ] );

        $this->assertSame( '+31600000000', ContactResolver::phoneForPerson( $pid ) );
        $this->assertSame( '+31600000000', ContactResolver::phoneForUser( $uid ) );
    }

    public function test_a_malformed_stored_email_does_not_win(): void {
        $uid = self::factory()->user->create( [ 'user_email' => 'good@example.test' ] );
        $pid = $this->makePerson( [ 'email' => 'not-an-email', 'wp_user_id' => $uid ] );

        $this->assertSame( 'good@example.test', ContactResolver::emailForPerson( $pid ) );
        $this->assertSame( 'good@example.test', ContactResolver::emailForUser( $uid ) );
    }
}
