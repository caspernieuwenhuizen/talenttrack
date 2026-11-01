<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Database\AfterCommit;
use TT\Infrastructure\Database\Transaction;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Backup\PartialRestorer;
use TT\Modules\Pdp\PdpCascadeDeleter;
use TT\Modules\Vct\Repositories\VctSessionsRepository;

/**
 * #4561 — one transaction helper, with savepoints for the inner levels.
 *
 * MySQL has no nested transactions: a `START TRANSACTION` while one is open
 * commits it. Each repository that wanted atomic writes used to open its own,
 * so one running inside another committed the outer work early. Everything
 * goes through `Transaction` now, and nothing else in `src` issues the
 * statements itself.
 */
final class TransactionTest extends WP_UnitTestCase {

    // The helper's outermost COMMIT ends the transaction the suite opened.
    use CommitsAfterCascade;

    /** @var list<string> */
    private array $statements = [];

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        remove_filter( 'query', [ $this, 'capture' ] );
        $this->assertSame( 0, Transaction::depth(), 'every level the test opened was closed' );
        $this->cleanUpAndCommit();
        parent::tear_down();
    }

    /** @param string $sql */
    public function capture( $sql ) {
        $sql = (string) $sql;
        if ( preg_match( '/^\s*(START TRANSACTION|COMMIT|ROLLBACK|SAVEPOINT|RELEASE)\b/i', $sql ) ) {
            $this->statements[] = trim( $sql );
        }
        return $sql;
    }

    // The helper

    public function test_on_its_own_it_issues_what_the_callers_issued_before(): void {
        add_filter( 'query', [ $this, 'capture' ] );
        Transaction::begin();
        Transaction::commit();
        Transaction::begin();
        Transaction::rollback();

        $this->assertSame( [ 'START TRANSACTION', 'COMMIT', 'START TRANSACTION', 'ROLLBACK' ], $this->statements );
    }

    public function test_an_inner_level_is_a_savepoint_and_its_rollback_keeps_the_outer_work(): void {
        add_filter( 'query', [ $this, 'capture' ] );
        Transaction::begin();
        $outer = $this->team( 'Outer team' );
        Transaction::begin();
        $inner = $this->team( 'Inner team' );
        Transaction::rollback();
        Transaction::commit();

        $this->assertSame(
            [ 'START TRANSACTION', 'SAVEPOINT tt_sp_1', 'ROLLBACK TO SAVEPOINT tt_sp_1', 'RELEASE SAVEPOINT tt_sp_1', 'COMMIT' ],
            $this->statements
        );
        $this->assertTrue( $this->teamExists( $outer ), 'the outer write is committed' );
        $this->assertFalse( $this->teamExists( $inner ), 'the inner write was rolled back' );
    }

    public function test_an_outer_rollback_undoes_an_inner_level_that_committed(): void {
        Transaction::begin();
        $outer = $this->team( 'Outer team' );
        Transaction::begin();
        $inner = $this->team( 'Inner team' );
        Transaction::commit();
        Transaction::rollback();

        $this->assertFalse( $this->teamExists( $outer ) );
        $this->assertFalse( $this->teamExists( $inner ), 'an inner commit is only a released savepoint' );
    }

    public function test_held_sends_go_out_on_the_outermost_commit_only(): void {
        $sent = [];
        AfterCommit::run( static function () use ( &$sent ): void { $sent[] = 'outside'; } );
        $this->assertSame( [ 'outside' ], $sent, 'outside a transaction a send goes at once' );

        Transaction::begin();
        AfterCommit::run( static function () use ( &$sent ): void { $sent[] = 'outer'; } );
        Transaction::begin();
        AfterCommit::run( static function () use ( &$sent ): void { $sent[] = 'released'; } );
        Transaction::commit();
        Transaction::begin();
        AfterCommit::run( static function () use ( &$sent ): void { $sent[] = 'rolled back'; } );
        Transaction::rollback();
        $this->assertSame( [ 'outside' ], $sent, 'nothing is sent before the outermost commit' );
        Transaction::commit();

        $this->assertSame( [ 'outside', 'outer', 'released' ], $sent, 'what a rolled-back level queued is never sent' );
    }

    public function test_an_outermost_rollback_sends_nothing(): void {
        $sent = 0;
        Transaction::begin();
        AfterCommit::run( static function () use ( &$sent ): void { $sent++; } );
        Transaction::rollback();

        $this->assertSame( 0, $sent );
    }

    public function test_nothing_in_src_opens_or_closes_a_transaction_itself(): void {
        $root     = dirname( __DIR__, 2 ) . '/src';
        $helper   = realpath( $root . '/Infrastructure/Database/Transaction.php' );
        $found    = [];
        $iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file ) {
            if ( ! $file instanceof \SplFileInfo || $file->getExtension() !== 'php' ) continue;
            if ( realpath( $file->getPathname() ) === $helper ) continue;
            $lines = file( $file->getPathname() );
            if ( ! is_array( $lines ) ) continue;
            foreach ( $lines as $n => $line ) {
                if ( preg_match( '/query\(\s*[\'"]\s*(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE)\b/i', $line ) ) {
                    $found[] = substr( $file->getPathname(), strlen( $root ) ) . ':' . ( $n + 1 );
                }
            }
        }

        $this->assertSame( [], $found, 'transactions go through TT\\Infrastructure\\Database\\Transaction' );
    }

    // The converted callers, each on its own

    public function test_a_vct_session_is_deleted_with_its_blocks(): void {
        global $wpdb;
        $repo    = new VctSessionsRepository();
        $session = $repo->create( [
            'team_id'                => $this->team( 'VCT team' ),
            'session_date'           => '2026-11-02',
            'age_group'              => 'O13',
            'md_context'             => 'MD-3',
            'total_duration_minutes' => 75,
            'generated_by'           => get_current_user_id(),
        ] );
        $this->assertGreaterThan( 0, $session );
        $wpdb->insert( $wpdb->prefix . 'tt_vct_session_blocks', [
            'club_id'          => (int) CurrentClub::id(),
            'vct_session_id'   => $session,
            'sequence'         => 1,
            'slot_category'    => 'warm_up',
            'duration_minutes' => 15,
            'intensity_band'   => 2,
        ] );

        $this->assertTrue( $repo->delete( $session ) );
        $this->assertNull( $repo->find( $session ) );
        $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_vct_session_blocks WHERE vct_session_id = %d",
            $session
        ) ) );
    }

    public function test_a_pdp_file_is_deleted_with_its_conversations(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_seasons', [
            'name'       => '2031/2032',
            'start_date' => '2031-07-01',
            'end_date'   => '2032-06-30',
        ] );
        $season = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Pdp',
            'last_name'  => 'Delete',
            'status'     => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_pdp_files', [
            'club_id'   => (int) CurrentClub::id(),
            'player_id' => (int) $wpdb->insert_id,
            'season_id' => $season,
            'status'    => 'open',
        ] );
        $file = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $file );

        $deleted = ( new PdpCascadeDeleter() )->deletePdpFile( $file );

        $this->assertSame( 1, $deleted['tt_pdp_files'] ?? 0 );
        $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_pdp_files WHERE id = %d",
            $file
        ) ) );
    }

    public function test_a_partial_restore_puts_a_missing_row_back(): void {
        $id = 900000 + wp_rand( 1, 99999 );
        $result = PartialRestorer::execute(
            [ 'tables' => [ 'tt_teams' => [
                'columns' => [ 'id', 'club_id', 'name' ],
                'rows'    => [ [ 'id' => $id, 'club_id' => (int) CurrentClub::id(), 'name' => 'Restored team' ] ],
            ] ] ],
            [ 'tt_teams' => [ $id ] ],
            [ 'tt_teams' => [ 'green' => PartialRestorer::ACTION_RESTORE, 'yellow' => PartialRestorer::ACTION_KEEP_CURRENT ] ]
        );

        $this->assertTrue( $result['ok'] );
        $this->assertSame( 1, $result['applied']['tt_teams']['inserted'] ?? 0 );
        $this->assertTrue( $this->teamExists( $id ) );
    }

    // Fixtures

    private function team( string $name ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => (int) CurrentClub::id(), 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function teamExists( int $id ): bool {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_teams WHERE id = %d",
            $id
        ) ) === 1;
    }
}
