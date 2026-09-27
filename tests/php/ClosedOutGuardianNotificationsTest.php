<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Recipient\RecipientResolver;
use TT\Modules\Invitations\PlayerParentsRepository;
use TT\Modules\Push\Dispatchers\ParentEmailDispatcher;

/**
 * #3979 — a closed-out child's family is sent nothing about them.
 *
 * `ParentEmailDispatcher` and `Comms\RecipientResolver` read the raw parent
 * pivot, so the guardian of a released, archived or binned child was still
 * a recipient. Both now ask `ParentChildResolver`, and the legacy fallback
 * each keeps (the `parent_user_id` column, the `guardian_email` fields) is
 * asked only for a child who is not closed out — otherwise an empty guardian
 * list would reach the family through the old column instead.
 */
final class ClosedOutGuardianNotificationsTest extends WP_UnitTestCase {

    private int $player_id   = 0;
    private int $player_user = 0;
    private int $parent_user = 0;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $this->player_user = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'speler@example.test' ] );
        $this->parent_user = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'ouder@example.test' ] );

        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'        => (int) CurrentClub::id(),
            'first_name'     => 'Jip',
            'last_name'      => 'Janssen',
            'status'         => 'active',
            'wp_user_id'     => $this->player_user,
            'guardian_email' => 'oud-adres@example.test',
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        // Also writes the legacy `parent_user_id` column, which is exactly
        // the fallback a closed-out child must not reach the family through.
        ( new PlayerParentsRepository() )->link( $this->player_id, $this->parent_user, true );
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function closedOutStates(): array {
        return [
            'released' => [ [ 'status' => 'released' ] ],
            'archived' => [ [ 'archived_at' => '2026-09-01 10:00:00' ] ],
            'binned'   => [ [ 'trashed_at' => '2026-09-01 10:00:00' ] ],
        ];
    }

    /* ---- ParentEmailDispatcher ----------------------------------------- */

    public function test_the_parent_email_dispatcher_reaches_an_active_childs_guardian(): void {
        $this->assertTrue( ( new ParentEmailDispatcher() )->applicableTo( $this->context() ) );
    }

    /**
     * @dataProvider closedOutStates
     * @param array<string,mixed> $change
     */
    public function test_the_parent_email_dispatcher_sends_nothing_about_a_closed_out_child( array $change ): void {
        $this->closeOut( $change );

        $dispatcher = new ParentEmailDispatcher();
        $this->assertFalse( $dispatcher->applicableTo( $this->context() ) );
        $this->assertFalse( $dispatcher->deliver( $this->context() ) );
    }

    /* ---- Comms RecipientResolver --------------------------------------- */

    public function test_the_recipient_resolver_reaches_an_active_childs_guardian(): void {
        $this->assertSame( [ $this->parent_user ], $this->parentRecipients() );
    }

    /**
     * A trialist is not closed out: the trial welcome goes to their family.
     */
    public function test_the_recipient_resolver_reaches_a_trialists_guardian(): void {
        $this->closeOut( [ 'status' => 'trial' ] );
        $this->assertSame( [ $this->parent_user ], $this->parentRecipients() );
    }

    /**
     * @dataProvider closedOutStates
     * @param array<string,mixed> $change
     */
    public function test_the_recipient_resolver_sends_nothing_to_a_closed_out_childs_family( array $change ): void {
        $this->closeOut( $change );

        $this->assertSame( [], $this->parentRecipients(), 'a closed-out child\'s guardian is still a recipient' );
    }

    /**
     * @dataProvider closedOutStates
     * @param array<string,mixed> $change
     */
    public function test_the_legacy_guardian_fields_do_not_reach_a_closed_out_family( array $change ): void {
        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}tt_player_parents", [ 'player_id' => $this->player_id ] );
        $this->closeOut( $change );

        $this->assertSame( [], $this->parentRecipients(), 'the guardian_email fallback reached a closed-out family' );
    }

    public function test_the_resolver_names_the_same_guardians(): void {
        $this->assertSame( [ $this->parent_user ], ParentChildResolver::guardiansOf( $this->player_id ) );
        $this->closeOut( [ 'status' => 'released' ] );
        $this->assertSame( [], ParentChildResolver::guardiansOf( $this->player_id ) );
    }

    /* ---- helpers ------------------------------------------------------- */

    /** @param array<string,mixed> $change */
    private function closeOut( array $change ): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", $change, [ 'id' => $this->player_id ] );
    }

    /** @return array<string,mixed> */
    private function context(): array {
        return [ 'user_id' => $this->player_user, 'title' => 'Training verplaatst', 'body' => 'De training van dinsdag begint om 18:30.' ];
    }

    /**
     * Parent recipients as user ids; a legacy-field recipient counts as 0.
     *
     * @return list<int>
     */
    private function parentRecipients(): array {
        $out = [];
        foreach ( ( new RecipientResolver() )->forPlayerWithParents( $this->player_id ) as $recipient ) {
            if ( $recipient->kind === Recipient::KIND_PARENT ) $out[] = $recipient->userId;
        }
        return $out;
    }
}
