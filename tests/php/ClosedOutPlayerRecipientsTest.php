<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Recipient\RecipientResolver;

/**
 * #4088 — a closed-out player's own account is sent nothing, and the
 * player is looked up within the current club only.
 *
 * The companion of `ClosedOutGuardianNotificationsTest`, which covers the
 * family. There is no exception list: a released, archived or binned player
 * resolves to nobody on every `RecipientResolver` entry point.
 */
final class ClosedOutPlayerRecipientsTest extends WP_UnitTestCase {

    private int $player_id   = 0;
    private int $player_user = 0;

    public function set_up(): void {
        parent::set_up();

        $this->player_user = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'speler@example.test' ] );
        $this->player_id   = $this->insertPlayer( (int) CurrentClub::id(), $this->player_user );
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function closedOutStates(): array {
        return [
            'released' => [ [ 'status' => 'released' ] ],
            // #4129 — nor is an inactive player's own account.
            'inactive' => [ [ 'status' => 'inactive' ] ],
            'archived' => [ [ 'archived_at' => '2026-09-01 10:00:00' ] ],
            'binned'   => [ [ 'trashed_at' => '2026-09-01 10:00:00' ] ],
        ];
    }

    public function test_an_active_player_is_reached(): void {
        $this->assertSame( [ $this->player_user ], $this->selfRecipients( 'forPlayer' ) );
        $this->assertSame( [ $this->player_user ], $this->selfRecipients( 'forPlayerWithParents' ) );
    }

    public function test_a_trialist_is_reached(): void {
        $this->closeOut( [ 'status' => 'trial' ] );

        $this->assertSame( [ $this->player_user ], $this->selfRecipients( 'forPlayer' ) );
        $this->assertSame( [ $this->player_user ], $this->selfRecipients( 'forPlayerWithParents' ) );
    }

    /**
     * @dataProvider closedOutStates
     * @param array<string,mixed> $change
     */
    public function test_a_closed_out_player_is_sent_nothing( array $change ): void {
        $this->closeOut( $change );

        $this->assertSame( [], ( new RecipientResolver() )->forPlayer( $this->player_id ) );
        $this->assertSame( [], ( new RecipientResolver() )->forPlayerWithParents( $this->player_id ) );
    }

    public function test_a_player_from_another_club_resolves_to_nobody(): void {
        $other_user = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'ander@example.test' ] );
        $other_id   = $this->insertPlayer( (int) CurrentClub::id() + 1, $other_user );

        $this->assertSame( [], ( new RecipientResolver() )->forPlayer( $other_id ) );
        $this->assertSame( [], ( new RecipientResolver() )->forPlayerWithParents( $other_id ) );
    }

    /* ---- helpers ------------------------------------------------------- */

    private function insertPlayer( int $club_id, int $user_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $club_id,
            'first_name' => 'Jip',
            'last_name'  => 'Janssen',
            'status'     => 'active',
            'wp_user_id' => $user_id,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $change */
    private function closeOut( array $change ): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", $change, [ 'id' => $this->player_id ] );
    }

    /**
     * The player's own recipients from one resolver entry point, as user ids.
     *
     * @return list<int>
     */
    private function selfRecipients( string $method ): array {
        $resolver   = new RecipientResolver();
        $recipients = $method === 'forPlayer'
            ? $resolver->forPlayer( $this->player_id )
            : $resolver->forPlayerWithParents( $this->player_id );

        $out = [];
        foreach ( $recipients as $recipient ) {
            if ( $recipient->kind === Recipient::KIND_SELF ) $out[] = $recipient->userId;
        }
        return $out;
    }
}
