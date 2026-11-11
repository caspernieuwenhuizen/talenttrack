<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\GoalStatus;
use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Query\GoalLifecycle;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Frontend\FrontendPdpManageView;
use TT\Modules\PersonaDashboard\TableSources\GoalsByPrincipleSource;

/**
 * #5099 — a single goal status is matched in any stored spelling. A goal
 * stored as `completed`, as the seeded `Completed` and as a legacy
 * `COMPLETED` is achieved on every surface that counts or filters completed
 * goals: the goals-by-principle table, the REST `achieved` bucket, the PDP
 * goals filter and the player's completed count. The KPI export and the
 * analytics fact use the same clause (pinned at the source).
 */
final class GoalStatusMatchTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $player = 0;
    private int $viewer = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb;
        $wpdb->hide_errors();
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $this->viewer = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->viewer );

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Gsm JO16' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [ 'club_id' => $this->club, 'team_id' => $team, 'first_name' => 'Gsm', 'last_name' => 'Speler', 'status' => 'active' ] );
        $this->player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_principles", [
            'club_id' => $this->club, 'code' => 'GSM-01',
            'title_json' => wp_json_encode( [ 'nl' => 'Gsm principe', 'en' => 'Gsm principle' ] ),
        ] );
        $principle = (int) $wpdb->insert_id;

        foreach ( [ 'completed', 'Completed', 'COMPLETED', 'Cancelled', 'in_progress' ] as $i => $status ) {
            $wpdb->insert( "{$p}tt_goals", [
                'club_id' => $this->club, 'player_id' => $this->player, 'title' => 'Gsm doel ' . $status . ' ' . $i,
                'status' => $status, 'created_by' => 0, 'linked_principle_id' => $principle,
            ] );
        }
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        unset( $_GET['goals'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_every_spelling_of_completed_is_achieved_everywhere(): void {
        $this->assertSame( 3, ( new GoalsRepository() )->countCompletedForPlayer( $this->player ), 'the player\'s completed count' );

        $table = array_values( array_filter(
            ( new GoalsByPrincipleSource() )->rowsFor( $this->viewer, [] ),
            static fn( array $row ): bool => strpos( (string) $row[0], 'GSM-01' ) === 0
        ) );
        $this->assertCount( 1, $table );
        $this->assertSame( '1', $table[0][1], 'open' );
        $this->assertSame( '3', $table[0][2], 'completed, every spelling' );

        $this->assertSame( 3, $this->restCount( 'achieved' ), 'the REST achieved bucket' );
        $this->assertSame( 1, $this->restCount( 'missed' ), 'the REST missed bucket: Cancelled' );
        $this->assertSame( 3, $this->restCount( 'Completed' ), 'a raw status code, any spelling' );

        $_GET['goals'] = 'completed';
        $render = new \ReflectionMethod( FrontendPdpManageView::class, 'renderGoalsBlock' );
        $render->setAccessible( true );
        ob_start();
        $render->invoke( null, $this->player );
        $html = (string) ob_get_clean();
        foreach ( [ 'Gsm doel completed', 'Gsm doel Completed', 'Gsm doel COMPLETED' ] as $title ) {
            $this->assertStringContainsString( $title, $html, 'the PDP completed filter lists every spelling' );
        }
        $this->assertStringNotContainsString( 'Gsm doel in_progress', $html );
        $this->assertStringNotContainsString( 'Gsm doel Cancelled', $html );
    }

    public function test_the_literal_clause_refuses_anything_but_a_status_name(): void {
        $this->assertSame( '1=0', GoalLifecycle::hasStatusClause( 'g', "x' OR '1'='1" ) );
        $this->assertStringEndsWith( "= 'completed'", GoalLifecycle::hasStatusClause( 'g', GoalStatus::COMPLETED ) );
    }

    public function test_the_kpi_export_and_the_analytics_fact_ask_the_shared_clause(): void {
        $root = dirname( __DIR__, 2 );
        foreach ( [
            'src/Modules/Export/Exporters/KpiSnapshotXlsxExporter.php',
            'src/Modules/Analytics/AnalyticsModule.php',
            'src/Modules/PersonaDashboard/Kpis/GoalCompletionPct.php',
        ] as $file ) {
            $this->assertStringContainsString( 'GoalLifecycle::hasStatusClause(', (string) file_get_contents( $root . '/' . $file ), $file );
        }
    }

    /**
     * The goals the REST status filter keeps for this player: the clause
     * `GET /goals` adds for `filter[status]`, run on its own so the test is
     * about the bucket and not about the caller's list scope.
     */
    private function restCount( string $status ): int {
        $method = new \ReflectionMethod( \TT\Infrastructure\REST\GoalsRestController::class, 'statusWhereClause' );
        $method->setAccessible( true );
        $clause = $method->invoke( null, $status );
        $this->assertIsArray( $clause );
        global $wpdb;
        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}tt_goals g WHERE g.player_id = %d AND {$clause[0]}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $this->player, ...$clause[1] ) );
    }
}
