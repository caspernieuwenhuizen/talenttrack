<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #4399 — every lifecycle write in the archive layer announces itself once,
 * with the entity, the ids and the players those rows belonged to, read
 * before the change. These writes bypass the entity's own repository, so
 * this action is the only way a module hears that a record was trashed,
 * restored or deleted.
 *
 * The delete and purge cases run a real cascade, which commits, so this
 * class cleans up after itself; see `CommitsAfterCascade`.
 */
final class ArchiveRowsChangedActionTest extends WP_UnitTestCase {

    use CommitsAfterCascade;

    private ArchiveRepository $repo;
    private int $player = 0;
    private int $other  = 0;

    /** @var list<array{0:string,1:list<int>,2:list<int>}> */
    private array $seen = [];

    /** @var callable */
    private $spy;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        global $wpdb;
        $wpdb->hide_errors();

        $this->repo   = new ArchiveRepository();
        $this->player = $this->insertPlayer( 'Archive' );
        $this->other  = $this->insertPlayer( 'Other' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $this->seen = [];
        $this->spy  = function ( $entity, $ids, $player_ids ): void {
            $this->seen[] = [ (string) $entity, array_values( (array) $ids ), array_values( (array) $player_ids ) ];
        };
        add_action( ArchiveRepository::ROWS_CHANGED_ACTION, $this->spy, 5, 3 );
    }

    public function tear_down(): void {
        remove_action( ArchiveRepository::ROWS_CHANGED_ACTION, $this->spy, 5 );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_archive_announces_the_rows_and_their_player_once(): void {
        $a = $this->insertInjury( $this->player );
        $b = $this->insertInjury( $this->player );

        $this->assertSame( 2, $this->repo->archive( 'injury', [ $a, $b ], 0 ) );

        $this->assertSame( [ [ 'injury', [ $a, $b ], [ $this->player ] ] ], $this->seen );
    }

    public function test_restore_announces_the_player(): void {
        $id = $this->insertInjury( $this->player, true );

        $this->assertSame( 1, $this->repo->restore( 'injury', [ $id ] ) );

        $this->assertSame( [ [ 'injury', [ $id ], [ $this->player ] ] ], $this->seen );
    }

    public function test_trash_announces_the_player(): void {
        $id = $this->insertInjury( $this->player, true );

        $this->assertSame( 1, $this->repo->trash( 'injury', [ $id ], get_current_user_id() ) );

        $this->assertSame( [ [ 'injury', [ $id ], [ $this->player ] ] ], $this->seen );
    }

    public function test_restore_from_trash_announces_the_player(): void {
        $id = $this->insertInjury( $this->player, true, true );

        $this->assertSame( 1, $this->repo->restoreFromTrash( 'injury', [ $id ], get_current_user_id() ) );

        $this->assertSame( [ [ 'injury', [ $id ], [ $this->player ] ] ], $this->seen );
    }

    public function test_delete_permanently_names_the_player_of_a_row_that_is_gone(): void {
        $id = $this->insertInjury( $this->player, true );

        $deleted = $this->repo->deletePermanently( 'injury', [ $id ] );
        $seen    = $this->seen;
        $exists  = $this->injuryExists( $id );

        $this->cleanUpAndCommit();

        $this->assertSame( 1, $deleted );
        $this->assertFalse( $exists, 'the row is gone, and still its player was named' );
        $this->assertSame( [ [ 'injury', [ $id ], [ $this->player ] ] ], $seen );
    }

    public function test_purge_names_the_player(): void {
        $id = $this->insertInjury( $this->player, true, true );

        $deleted = $this->repo->purge( 'injury', [ $id ], get_current_user_id() );
        $seen    = $this->seen;

        $this->cleanUpAndCommit();

        $this->assertSame( 1, $deleted );
        $this->assertSame( [ [ 'injury', [ $id ], [ $this->player ] ] ], $seen );
    }

    public function test_rows_of_two_players_name_both(): void {
        $a = $this->insertInjury( $this->player );
        $b = $this->insertInjury( $this->other );

        $this->repo->archive( 'injury', [ $a, $b ], 0 );

        $this->assertCount( 1, $this->seen );
        $players = $this->seen[0][2];
        sort( $players );
        $expected = [ $this->player, $this->other ];
        sort( $expected );
        $this->assertSame( $expected, $players );
    }

    public function test_a_call_that_changes_nothing_announces_nothing(): void {
        $id = $this->insertInjury( $this->player, true );

        $this->assertSame( 0, $this->repo->archive( 'injury', [ $id ], 0 ), 'already archived' );
        $this->assertSame( 0, $this->repo->restoreFromTrash( 'injury', [ $id ], 0 ), 'not in the bin' );

        $this->assertSame( [], $this->seen );
    }

    public function test_an_entity_without_a_player_column_names_no_players(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'No players here' ] );
        $team = (int) $wpdb->insert_id;

        $this->repo->archive( 'team', [ $team ], 0 );

        $this->assertSame( [ [ 'team', [ $team ], [] ] ], $this->seen );
    }

    /**
     * #4445 — `tt_players` has no `player_id` column; its rows are the
     * players. Archiving a player runs its PDP cascade in a transaction that
     * commits, hence the cleanup.
     */
    public function test_archiving_a_player_names_that_player(): void {
        $archived = $this->repo->archive( 'player', [ $this->player ], 0 );
        $seen     = $this->seen;

        $this->cleanUpAndCommit();

        $this->assertSame( 1, $archived );
        $this->assertSame( [ [ 'player', [ $this->player ], [ $this->player ] ] ], $seen );
    }

    public function test_restoring_a_player_names_that_player(): void {
        $this->repo->archive( 'player', [ $this->player ], 0 );
        $this->seen = [];

        $restored = $this->repo->restore( 'player', [ $this->player ] );
        $seen     = $this->seen;

        $this->cleanUpAndCommit();

        $this->assertSame( 1, $restored );
        $this->assertSame( [ [ 'player', [ $this->player ], [ $this->player ] ] ], $seen );
    }

    // ---- fixtures ------------------------------------------------------

    private function insertPlayer( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => CurrentClub::id(),
            'first_name' => 'Hook',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** Written without the repository, so no injury event fires. */
    private function insertInjury( int $player_id, bool $archived = false, bool $trashed = false ): int {
        global $wpdb;
        $row = [
            'club_id'    => CurrentClub::id(),
            'player_id'  => $player_id,
            'started_on' => gmdate( 'Y-m-d' ),
        ];
        if ( $archived ) $row['archived_at'] = current_time( 'mysql' );
        if ( $trashed ) $row['trashed_at'] = current_time( 'mysql' );
        $wpdb->insert( "{$wpdb->prefix}tt_player_injuries", $row );
        return (int) $wpdb->insert_id;
    }

    private function injuryExists( int $id ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_player_injuries WHERE id = %d",
            $id
        ) ) !== null;
    }
}
