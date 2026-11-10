<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\DemoEvaluationWriter;
use TT\Modules\Evaluations\Admin\EvaluationsPage;
use TT\Modules\Import\Excel\ExcelImporter;
use TT\Modules\Import\ImportTagSink;
use TT\Modules\Wizards\Evaluation\EvaluationInserter;
use TT\Modules\Wizards\Evaluation\ReviewStep;

/**
 * #4985 — every evaluation carries its uuid, the portable identity
 * CLAUDE.md §4 asks of a root entity. Migration 0038 filled the column once
 * and no insert path set it, so every evaluation written since had none.
 * Each of the six paths that write a `tt_evaluations` row now sets one, and
 * migration 0322 fills the rows that are empty.
 */
final class EvaluationUuidTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0322_backfill_evaluation_uuid.php';

    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        do_action( 'rest_api_init' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->player = $this->player( 'Bas', 'Willems' );
    }

    public function tear_down(): void {
        remove_all_filters( 'wp_redirect' );
        $_POST    = [];
        $_REQUEST = [];
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_inserter_sets_a_uuid(): void {
        $id = EvaluationInserter::insert( [ 'player_id' => $this->player, 'eval_date' => current_time( 'Y-m-d' ) ] );

        $this->assertIsInt( $id );
        $this->assertUuid( $id );
    }

    public function test_the_rest_create_sets_a_uuid(): void {
        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/evaluations' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( [ 'player_id' => $this->player, 'eval_date' => current_time( 'Y-m-d' ) ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertUuid( (int) ( ( (array) $response->get_data() )['data']['id'] ?? 0 ) );
    }

    public function test_the_wizard_sets_a_uuid(): void {
        $result = ( new ReviewStep() )->submit( [
            '_path'        => 'player-first',
            'player_id'    => $this->player,
            'eval_date'    => current_time( 'Y-m-d' ),
            'ratings_self' => [ $this->mainCategory() => 7 ],
        ] );

        $this->assertIsArray( $result );
        $this->assertUuid( $this->latestFor( $this->player ) );
    }

    public function test_the_wp_admin_form_sets_a_uuid(): void {
        $_POST = [
            'player_id' => (string) $this->player,
            'eval_date' => current_time( 'Y-m-d' ),
            'notes'     => 'Saved in wp-admin.',
            'tt_nonce'  => wp_create_nonce( 'tt_save_evaluation' ),
        ];
        $_REQUEST = $_POST;
        // The save ends in a redirect and `exit`; stop it at the redirect.
        add_filter( 'wp_redirect', static function (): string {
            throw new \RuntimeException( 'redirected' );
        } );

        try {
            EvaluationsPage::handle_save();
            $this->fail( 'the save should end in a redirect' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'redirected', $e->getMessage() );
        }

        $this->assertUuid( $this->latestFor( $this->player ) );
    }

    public function test_the_excel_import_sets_a_uuid(): void {
        $sink = new class implements ImportTagSink {
            public function batchId(): string { return 'test-4985'; }
            public function tag( string $entity_type, int $entity_id, array $extra = [] ): void {}
        };
        $rows = [
            'players'     => [ [ 'auto_key' => 'p1', 'first_name' => 'Noor', 'last_name' => 'Importeval' ] ],
            'evaluations' => [ [ 'auto_key' => 'e1', 'player_key' => 'p1', 'eval_date' => '2026-03-01' ] ],
        ];
        $warnings = [];

        $method = new ReflectionMethod( ExcelImporter::class, 'insertAll' );
        $method->setAccessible( true );
        $method->invokeArgs( new ExcelImporter( static fn() => null ), [ $rows, $sink, &$warnings ] );

        global $wpdb;
        $player = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players WHERE last_name = %s",
            'Importeval'
        ) );
        $this->assertGreaterThan( 0, $player );
        $this->assertUuid( $this->latestFor( $player ) );
    }

    public function test_the_demo_writer_sets_a_uuid(): void {
        $writer = new DemoEvaluationWriter( new DemoBatchRegistry( 'test-4985' ), new DemoCalendar( 8 ) );
        $id     = $writer->write( [
            'club_id'   => (int) CurrentClub::id(),
            'player_id' => $this->player,
            'coach_id'  => get_current_user_id(),
            'eval_date' => current_time( 'Y-m-d' ),
            'notes'     => '',
        ], $this->player, 'steady', [] );

        $this->assertGreaterThan( 0, $id );
        $this->assertUuid( $id );
    }

    public function test_two_evaluations_get_different_uuids(): void {
        $a = EvaluationInserter::insert( [ 'player_id' => $this->player, 'eval_date' => current_time( 'Y-m-d' ) ] );
        $b = EvaluationInserter::insert( [ 'player_id' => $this->player, 'eval_date' => current_time( 'Y-m-d' ) ] );

        $this->assertIsInt( $a );
        $this->assertIsInt( $b );
        $this->assertNotSame( $this->uuidOf( $a ), $this->uuidOf( $b ) );
    }

    public function test_the_migration_fills_every_empty_uuid_and_a_second_run_changes_nothing(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_evaluations";
        $kept  = EvaluationInserter::insert( [ 'player_id' => $this->player, 'eval_date' => current_time( 'Y-m-d' ) ] );
        $this->assertIsInt( $kept );
        $kept_uuid = $this->uuidOf( $kept );

        // Rows as the insert paths left them before this fix: NULL, and empty.
        $empty = [];
        foreach ( [ null, '', null ] as $uuid ) {
            $wpdb->insert( $table, [
                'club_id' => (int) CurrentClub::id(), 'player_id' => $this->player,
                'eval_date' => current_time( 'Y-m-d' ), 'notes' => '', 'uuid' => $uuid,
            ] );
            $empty[] = (int) $wpdb->insert_id;
        }
        $this->assertSame( 3, $this->emptyCount() );

        $this->migration()->up();

        $this->assertSame( 0, $this->emptyCount(), 'no evaluation is left without a uuid' );
        $this->assertSame( $kept_uuid, $this->uuidOf( $kept ), 'a uuid that was set is kept' );
        $filled = array_map( [ $this, 'uuidOf' ], $empty );
        foreach ( $filled as $uuid ) $this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $uuid );
        $this->assertCount( 3, array_unique( $filled ), 'each row gets its own' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $before = $wpdb->get_results( "SELECT id, uuid FROM {$table} ORDER BY id", ARRAY_A );
        $this->migration()->up();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $this->assertSame( $before, $wpdb->get_results( "SELECT id, uuid FROM {$table} ORDER BY id", ARRAY_A ), 'a second run changes nothing' );
    }

    public function test_a_refused_write_fails_the_migration_instead_of_marking_it_applied(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id' => (int) CurrentClub::id(), 'player_id' => $this->player,
            'eval_date' => current_time( 'Y-m-d' ), 'notes' => '', 'uuid' => null,
        ] );
        // The database refuses the backfill's UPDATE.
        $refuse = static function ( string $sql ): string {
            return strpos( $sql, 'tt_evaluations` SET uuid' ) !== false ? 'UPDATE `tt_no_such_table_4985` SET uuid = 1' : $sql;
        };
        add_filter( 'query', $refuse );
        $wpdb->suppress_errors( true );

        try {
            $this->migration()->up();
            $this->fail( 'a refused write should fail the migration' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'Migration statement failed', $e->getMessage() );
        } finally {
            remove_filter( 'query', $refuse );
            $wpdb->suppress_errors( false );
        }
        $this->assertSame( 1, $this->emptyCount(), 'the row is left for the next run' );
    }

    /**
     * The six paths each set the uuid themselves (#4985). A seventh that
     * writes `tt_evaluations` directly would forget it again, so this lists
     * them: adding one fails here until it sets the uuid or goes through
     * `EvaluationInserter::insert()`, and is then added below.
     */
    public function test_every_direct_evaluation_insert_is_one_that_sets_the_uuid(): void {
        $known = [
            'src/Infrastructure/REST/EvaluationsRestController.php',
            'src/Modules/DemoData/DemoEvaluationWriter.php',
            'src/Modules/Evaluations/Admin/EvaluationsPage.php',
            'src/Modules/Import/Excel/ExcelImporter.php',
            'src/Modules/Wizards/Evaluation/EvaluationInserter.php',
            'src/Modules/Wizards/Evaluation/ReviewStep.php',
        ];
        $root  = dirname( __DIR__, 2 );
        $found = [];
        $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $files as $file ) {
            if ( ! $file instanceof \SplFileInfo || $file->getExtension() !== 'php' ) continue;
            $code = (string) file_get_contents( $file->getPathname() );
            if ( preg_match( '/->insert\(\s*[^,;]*tt_evaluations[\'"]\s*,/', $code ) === 1 ) {
                $found[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
            }
        }
        sort( $found );

        $this->assertSame(
            $known,
            $found,
            'A file writes tt_evaluations directly and is not on the list. Set uuid => wp_generate_uuid4() on its insert, or write through EvaluationInserter::insert(), then add it here.'
        );
        foreach ( $known as $path ) {
            $this->assertStringContainsString( 'wp_generate_uuid4()', (string) file_get_contents( $root . '/' . $path ), $path . ' writes an evaluation without a uuid' );
        }
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
    }

    private function assertUuid( int $evaluation_id ): void {
        $this->assertGreaterThan( 0, $evaluation_id, 'an evaluation was written' );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $this->uuidOf( $evaluation_id ) );
    }

    private function uuidOf( int $evaluation_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT uuid FROM {$wpdb->prefix}tt_evaluations WHERE id = %d",
            $evaluation_id
        ) );
    }

    private function emptyCount(): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_evaluations WHERE uuid IS NULL OR uuid = ''" );
    }

    private function latestFor( int $player_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(id) FROM {$wpdb->prefix}tt_evaluations WHERE player_id = %d",
            $player_id
        ) );
    }

    private function mainCategory(): int {
        global $wpdb;
        $id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}tt_eval_categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY id LIMIT 1" );
        if ( $id > 0 ) return $id;
        $wpdb->insert( "{$wpdb->prefix}tt_eval_categories", [
            'club_id' => (int) CurrentClub::id(), 'category_key' => 'uuid_test_main', 'label' => 'Technical', 'is_active' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function player( string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => $first, 'last_name' => $last, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }
}
