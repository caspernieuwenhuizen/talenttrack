<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4398 — migration 0303 clears account links that point at an account no
 * longer in `wp_users`: guardian pivot rows are removed, player and person
 * links set to NULL, one audit entry written per affected player under its
 * own club. Links to accounts that still exist are untouched. Deleting an
 * account now also clears `tt_players.parent_user_id`.
 */
final class DeletedAccountLinksSweepTest extends WP_UnitTestCase {

    private const ACTION = 'player.account_links_cleared';

    /** @var string */
    private $p;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
        $wpdb->hide_errors();
    }

    public function test_links_to_a_missing_account_are_cleared_and_live_links_stay(): void {
        $gone        = $this->deletedAccountId();
        $live        = self::factory()->user->create();
        $live_parent = self::factory()->user->create();

        $orphan_player = $this->player( $gone, null );
        $orphan_parent = $this->player( null, $gone );
        $live_player   = $this->player( $live, $live_parent );
        $orphan_person = $this->person( $gone );
        $live_person   = $this->person( $live );
        $this->parentLink( $live_player, $gone );
        $this->parentLink( $live_player, $live_parent );

        $this->migrate();

        $this->assertSame( 0, $this->parentLinkCount( $gone ), 'a guardian link to a missing account is removed' );
        $this->assertNull( $this->playerColumn( $orphan_player, 'wp_user_id' ), 'a player link to a missing account is nulled' );
        $this->assertNull( $this->playerColumn( $orphan_parent, 'parent_user_id' ), 'a parent column to a missing account is nulled' );
        $this->assertNull( $this->personUserId( $orphan_person ), 'a person link to a missing account is nulled' );

        $this->assertSame( 1, $this->parentLinkCount( $live_parent ), 'a guardian link to an existing account stays' );
        $this->assertSame( (string) $live, $this->playerColumn( $live_player, 'wp_user_id' ), 'a player link to an existing account stays' );
        $this->assertSame( (string) $live_parent, $this->playerColumn( $live_player, 'parent_user_id' ), 'a parent column to an existing account stays' );
        $this->assertSame( (string) $live, $this->personUserId( $live_person ), 'a person link to an existing account stays' );

        $this->assertSame( 1, $this->playerCount( $orphan_player ), 'the player record itself is kept' );
        $this->assertSame( 1, $this->personCount( $orphan_person ), 'the person record itself is kept' );
    }

    public function test_each_affected_player_gets_one_audit_entry_under_its_own_club(): void {
        $gone = $this->deletedAccountId();
        $live = self::factory()->user->create();

        $club_one   = $this->player( $gone, null, 1 );
        $club_two   = $this->player( null, $gone, 2 );
        $pivot_only = $this->player( $live, null, 1 );
        $untouched  = $this->player( null, $live, 1 );
        $this->parentLink( $pivot_only, $gone );
        $this->parentLink( $club_one, $gone );

        $this->migrate();

        $this->assertSame( [ '1' ], $this->auditClubs( $club_one ), 'one entry, under the player\'s club' );
        $this->assertSame( [ '2' ], $this->auditClubs( $club_two ), 'a second club\'s player is audited under that club' );
        $this->assertSame( [ '1' ], $this->auditClubs( $pivot_only ), 'a removed guardian link counts as affecting the player' );
        $this->assertSame( [], $this->auditClubs( $untouched ), 'a player with only live links is not audited' );

        $payload = json_decode( (string) $this->auditPayload( $club_one ), true );
        $this->assertSame( $gone, $payload['wp_user_id'] ?? null );
        $this->assertSame( [ $gone ], $payload['parent_links_removed'] ?? null );
    }

    public function test_a_second_run_changes_nothing(): void {
        $gone = $this->deletedAccountId();
        $live = self::factory()->user->create();

        $orphan_player = $this->player( $gone, $gone );
        $live_player   = $this->player( $live, null );
        $this->person( $gone );
        $this->parentLink( $live_player, $gone );
        $this->parentLink( $orphan_player, $live );

        $this->migrate();
        $before = $this->snapshot();

        $this->migrate();

        $this->assertSame( $before, $this->snapshot(), 'the sweep is idempotent, audit log included' );
        $this->assertSame( 1, $this->parentLinkCount( $live ) );
    }

    public function test_deleting_an_account_clears_the_parent_column(): void {
        $parent = self::factory()->user->create();
        $other  = self::factory()->user->create();
        $child  = $this->player( null, $parent );
        $peer   = $this->player( null, $other );

        wp_delete_user( $parent );

        $this->assertNull( $this->playerColumn( $child, 'parent_user_id' ), 'the deleted account no longer sits on the child' );
        $this->assertSame( (string) $other, $this->playerColumn( $peer, 'parent_user_id' ), 'another parent is untouched' );
    }

    // Fixtures

    private function migrate(): void {
        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0303_sweep_deleted_account_links.php';
        $migration->up();
    }

    /**
     * An id that was an account and no longer is, removed without the
     * `delete_user` hook, the way a deletion before #1772 left it.
     */
    private function deletedAccountId(): int {
        global $wpdb;
        $id = self::factory()->user->create();
        $wpdb->delete( $wpdb->users, [ 'ID' => $id ] );
        $wpdb->delete( $wpdb->usermeta, [ 'user_id' => $id ] );
        clean_user_cache( $id );
        return $id;
    }

    private function player( ?int $user_id, ?int $parent_user_id, int $club = 1 ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'        => $club,
            'first_name'     => 'Sem',
            'last_name'      => uniqid( 'Sweep', false ),
            'status'         => 'active',
            'wp_user_id'     => $user_id,
            'parent_user_id' => $parent_user_id,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function person( int $user_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Kim',
            'last_name'  => 'Sweep' . $user_id,
            'role_type'  => 'parent',
            'wp_user_id' => $user_id,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function parentLink( int $player_id, int $user_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_player_parents", [
            'player_id'      => $player_id,
            'parent_user_id' => $user_id,
            'is_primary'     => 0,
        ] );
    }

    private function parentLinkCount( int $user_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_player_parents WHERE parent_user_id = %d",
            $user_id
        ) );
    }

    private function playerColumn( int $player_id, string $column ): ?string {
        global $wpdb;
        $column = $column === 'parent_user_id' ? 'parent_user_id' : 'wp_user_id';
        $value  = $wpdb->get_var( $wpdb->prepare(
            "SELECT {$column} FROM {$this->p}tt_players WHERE id = %d",
            $player_id
        ) );
        return $value === null ? null : (string) $value;
    }

    private function personUserId( int $person_id ): ?string {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM {$this->p}tt_people WHERE id = %d",
            $person_id
        ) );
        return $value === null ? null : (string) $value;
    }

    private function playerCount( int $player_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_players WHERE id = %d",
            $player_id
        ) );
    }

    private function personCount( int $person_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_people WHERE id = %d",
            $person_id
        ) );
    }

    /** @return list<string> */
    private function auditClubs( int $player_id ): array {
        global $wpdb;
        $clubs = $wpdb->get_col( $wpdb->prepare(
            "SELECT club_id FROM {$this->p}tt_audit_log
              WHERE action = %s AND entity_type = 'player' AND entity_id = %d
              ORDER BY id",
            self::ACTION, $player_id
        ) );
        return array_values( array_map( 'strval', (array) $clubs ) );
    }

    private function auditPayload( int $player_id ): ?string {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT payload FROM {$this->p}tt_audit_log
              WHERE action = %s AND entity_type = 'player' AND entity_id = %d
              ORDER BY id DESC LIMIT 1",
            self::ACTION, $player_id
        ) );
        return $value === null ? null : (string) $value;
    }

    /** @return array<string, mixed> */
    private function snapshot(): array {
        global $wpdb;
        return [
            'parents' => $wpdb->get_results( "SELECT player_id, parent_user_id FROM {$this->p}tt_player_parents ORDER BY player_id, parent_user_id", ARRAY_A ),
            'players' => $wpdb->get_results( "SELECT id, wp_user_id, parent_user_id FROM {$this->p}tt_players ORDER BY id", ARRAY_A ),
            'people'  => $wpdb->get_results( "SELECT id, wp_user_id FROM {$this->p}tt_people ORDER BY id", ARRAY_A ),
            'audit'   => $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$this->p}tt_audit_log WHERE action = %s ORDER BY id", self::ACTION ), ARRAY_A ),
        ];
    }
}
