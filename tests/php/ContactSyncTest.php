<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Identity\ContactResolver;
use TT\Infrastructure\Identity\ContactSync;
use TT\Infrastructure\Identity\PhoneMeta;
use TT\Infrastructure\People\PeopleRepository;

/**
 * #2962 — the two contact stores stay aligned in both directions.
 *
 * The test that earns its place here is the loop one. Both directions
 * write, and the TT-side write calls wp_update_user(), which fires
 * profile_update, which writes back to TT. Without the guard the very
 * first People-screen save recurses; with it, one save is one write each
 * way. A suite that only checked "the value arrived" would pass either
 * way right up until it hung.
 */
final class ContactSyncTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
        ContactSync::init();
    }

    private function makePerson( int $user_id, ?string $email = null, ?string $phone = null ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Ada',
            'last_name'  => 'Vermeer',
            'email'      => $email,
            'phone'      => $phone,
            'wp_user_id' => $user_id ?: null,
            'role_type'  => 'staff',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function personEmail( int $id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT email FROM {$this->p}tt_people WHERE id = %d", $id
        ) );
    }

    public function test_editing_the_wp_profile_updates_the_person_row(): void {
        $uid = self::factory()->user->create( [ 'user_email' => 'before@example.test' ] );
        $pid = $this->makePerson( $uid, 'before@example.test' );

        wp_update_user( [ 'ID' => $uid, 'user_email' => 'after@example.test' ] );

        $this->assertSame( 'after@example.test', $this->personEmail( $pid ) );
    }

    public function test_editing_the_person_updates_the_wp_account(): void {
        $uid = self::factory()->user->create( [ 'user_email' => 'before@example.test' ] );
        $pid = $this->makePerson( $uid, 'before@example.test' );

        $repo = new PeopleRepository();
        $this->assertTrue( $repo->update( $pid, [ 'email' => 'edited@example.test' ] ) );
        $this->assertSame( '', $repo->lastSyncError() );

        $this->assertSame( 'edited@example.test', get_userdata( $uid )->user_email );
        $this->assertSame( 'edited@example.test', ContactResolver::emailForUser( $uid ) );
    }

    public function test_a_save_does_not_loop(): void {
        // If the guard is missing this recurses rather than failing an
        // assertion, so the value check afterwards is secondary — the test
        // completing at all is the signal.
        $uid = self::factory()->user->create( [ 'user_email' => 'before@example.test' ] );
        $pid = $this->makePerson( $uid, 'before@example.test' );

        ( new PeopleRepository() )->update( $pid, [ 'email' => 'once@example.test' ] );

        $this->assertSame( 'once@example.test', $this->personEmail( $pid ) );
        $this->assertSame( 'once@example.test', get_userdata( $uid )->user_email );
    }

    public function test_a_duplicate_email_is_refused_loudly(): void {
        self::factory()->user->create( [ 'user_email' => 'taken@example.test' ] );
        $uid = self::factory()->user->create( [ 'user_email' => 'mine@example.test' ] );
        $pid = $this->makePerson( $uid, 'mine@example.test' );

        $repo = new PeopleRepository();
        $repo->update( $pid, [ 'email' => 'taken@example.test' ] );

        // The person row saved; the account write was refused, and the
        // caller can tell.
        $this->assertNotSame( '', $repo->lastSyncError() );
        $this->assertStringContainsString( 'taken@example.test', $repo->lastSyncError() );
        $this->assertSame( 'mine@example.test', get_userdata( $uid )->user_email );
    }

    public function test_a_person_with_no_account_saves_without_touching_wp(): void {
        $pid = $this->makePerson( 0, 'nobody@example.test' );

        $repo = new PeopleRepository();
        $this->assertTrue( $repo->update( $pid, [ 'email' => 'still-nobody@example.test' ] ) );
        $this->assertSame( '', $repo->lastSyncError() );
        $this->assertSame( 'still-nobody@example.test', $this->personEmail( $pid ) );
    }

    public function test_an_account_with_no_person_row_is_a_no_op(): void {
        $uid = self::factory()->user->create( [ 'user_email' => 'lone@example.test' ] );

        // Must not throw, must not invent a person row.
        wp_update_user( [ 'ID' => $uid, 'user_email' => 'lone2@example.test' ] );

        global $wpdb;
        $this->assertSame(
            0,
            (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->p}tt_people WHERE wp_user_id = %d", $uid
            ) )
        );
    }

    public function test_phone_syncs_from_the_person_to_the_account(): void {
        $uid = self::factory()->user->create();
        $pid = $this->makePerson( $uid, 'p@example.test' );

        ( new PeopleRepository() )->update( $pid, [ 'phone' => '+31611111111' ] );

        $this->assertSame( '+31611111111', PhoneMeta::get( $uid ) );
    }

    public function test_an_archived_person_row_is_not_synced_into(): void {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'user_email' => 'before@example.test' ] );
        $pid = $this->makePerson( $uid, 'archived@example.test' );
        $wpdb->update( "{$this->p}tt_people", [ 'status' => 'archived' ], [ 'id' => $pid ] );

        wp_update_user( [ 'ID' => $uid, 'user_email' => 'after@example.test' ] );

        // The archived row keeps its own value; only the live link syncs.
        $this->assertSame( 'archived@example.test', $this->personEmail( $pid ) );
    }
}
