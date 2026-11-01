<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Evaluations\EvalRatingsRepository;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\CustomWidgets\CustomDataSourceRegistry;
use TT\Modules\CustomWidgets\DataSources\ActivitiesRecent;
use TT\Modules\CustomWidgets\DataSources\EvaluationsRecent;
use TT\Modules\CustomWidgets\DataSources\GoalsOpen;
use TT\Modules\CustomWidgets\DataSources\PdpFiles;
use TT\Modules\CustomWidgets\DataSources\PlayersActive;
use TT\Modules\CustomWidgets\Domain\CustomWidget;
use TT\Modules\CustomWidgets\Renderer\CustomWidgetRenderer;

/**
 * #4566 — a custom KPI widget shows the aggregation it was built with.
 *
 * It used to fetch one row and show its first number, so "Number of
 * active players" read a player's age. Every source now computes its
 * declared aggregations over every row the filters select, the renderer
 * shows that, and the `age_range` filter on active players is applied.
 */
final class CustomWidgetKpiAggregateTest extends WP_UnitTestCase {

    private string $p = '';
    private int $club = 0;
    private int $team = 0;
    private int $other_team = 0;
    /** The viewer the totals are computed for: a club-wide reader. */
    private int $viewer = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb;
        $wpdb->hide_errors();
        $this->p      = $wpdb->prefix;
        $this->club   = (int) CurrentClub::id();
        $this->viewer = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->viewer );

        CustomDataSourceRegistry::register( new PlayersActive() );

        $this->team       = $this->insertTeam( 'KPI JO13' );
        $this->other_team = $this->insertTeam( 'KPI JO17' );
        // Ages 10, 12 and 16 today, ordered by last name A, B, C.
        $this->insertPlayer( $this->team, 'Aalders', 10 );
        $this->insertPlayer( $this->team, 'Bakker', 12 );
        $this->insertPlayer( $this->other_team, 'Claes', 16 );
        // Out of scope: archived, and another club.
        $this->insertPlayer( $this->team, 'Dekker', 11, 'archived' );
        $this->insertPlayer( $this->team, 'Elzen', 11, 'active', $this->club + 1 );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── players_active ─────────────────────────────────────────────────

    public function test_count_of_active_players_counts_them(): void {
        $source = new PlayersActive();

        $this->assertSame( 2.0, $source->aggregate( $this->viewer,[ 'team_id' => $this->team ], 'count' ), 'two active players on the team; the archived one and another club\'s are out' );
        $this->assertSame( 3.0, $source->aggregate( $this->viewer,[ 'team_id' => 0 ], 'count' ) - $this->countOutsideFixture(), 'all three of this fixture\'s active players' );
    }

    public function test_distinct_teams_counts_teams(): void {
        $source = new PlayersActive();

        $this->assertSame( 1.0, $source->aggregate( $this->viewer,[ 'team_id' => $this->team ], 'distinct_teams' ) );
    }

    public function test_an_undeclared_aggregation_is_null(): void {
        $this->assertNull( ( new PlayersActive() )->aggregate( $this->viewer,[], 'avg_age' ) );
    }

    public function test_age_range_narrows_the_players(): void {
        $source = new PlayersActive();

        $this->assertSame( 2.0, $source->aggregate( $this->viewer,[ 'team_id' => $this->team, 'age_range' => 'u13' ], 'count' ), 'under 13: the 10- and 12-year-old' );
        $this->assertSame( 1.0, $source->aggregate( $this->viewer,[ 'team_id' => $this->team, 'age_range' => 'u11' ], 'count' ), 'under 11: the 10-year-old' );
        $this->assertSame( 0.0, $source->aggregate( $this->viewer,[ 'team_id' => $this->team, 'age_range' => 'u9' ], 'count' ) );

        $names = array_column( $source->fetch( $this->viewer,[ 'team_id' => $this->team, 'age_range' => 'u11' ], [ 'name' ] ), 'name' );
        $this->assertSame( [ 'KPI Aalders' ], $names, 'the rows a table widget shows are narrowed the same way' );
    }

    // ── the other four sources ─────────────────────────────────────────

    public function test_evaluations_count_and_average_overall(): void {
        global $wpdb;
        $category = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_eval_categories WHERE club_id = %d AND parent_id IS NULL AND is_active = 1 ORDER BY display_order, id LIMIT 1",
            $this->club
        ) );
        if ( $category <= 0 ) {
            $wpdb->insert( "{$this->p}tt_eval_categories", [
                'club_id' => $this->club, 'category_key' => 'zz_kpi_main', 'label' => 'KPI main',
                'parent_id' => null, 'display_order' => 1, 'is_active' => 1,
            ] );
            $category = (int) $wpdb->insert_id;
        }
        $player = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->p}tt_players WHERE last_name = %s", 'Aalders' ) );
        $a = $this->insertEvaluation( $player, '2031-05-04 10:00:00', $category, 6.0 );
        $b = $this->insertEvaluation( $player, '2031-05-11 10:00:00', $category, 8.0 );
        $window = [ 'date_from' => '2031-05-01', 'date_to' => '2031-05-31 23:59:59' ];
        $source = new EvaluationsRecent();

        $this->assertSame( 2.0, $source->aggregate( $this->viewer,$window, 'count' ) );

        $shared = array_column( ( new EvalRatingsRepository() )->overallRatingsForEvaluations( [ $a, $b ] ), 'value' );
        $this->assertSame( round( array_sum( $shared ) / count( $shared ), 1 ), $source->aggregate( $this->viewer,$window, 'avg_overall' ), 'the overall rating as the profile computes it' );
        $this->assertSame( 7.0, $source->aggregate( $this->viewer,$window, 'avg_overall' ), 'the average of 6 and 8, not one evaluation\'s rating' );
        $this->assertNull( $source->aggregate( $this->viewer,[ 'date_from' => '2032-01-01', 'date_to' => '2032-01-31' ], 'avg_overall' ), 'nothing to average' );
    }

    public function test_goals_count_and_distinct_players(): void {
        global $wpdb;
        $players = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->p}tt_players WHERE team_id = %d AND club_id = %d AND status = 'active'", $this->team, $this->club ) );
        $source  = new GoalsOpen();
        $before  = (float) $source->aggregate( $this->viewer,[ 'status' => 'completed' ], 'count' );
        $before_players = (float) $source->aggregate( $this->viewer,[ 'status' => 'completed' ], 'distinct_players' );

        foreach ( [ $players[0], $players[0], $players[1] ] as $pid ) {
            $wpdb->insert( "{$this->p}tt_goals", [
                'club_id' => $this->club, 'player_id' => (int) $pid, 'title' => 'KPI goal',
                'status' => 'completed', 'created_by' => 0,
            ] );
        }

        $this->assertSame( $before + 3, $source->aggregate( $this->viewer,[ 'status' => 'completed' ], 'count' ) );
        $this->assertSame( $before_players + 2, $source->aggregate( $this->viewer,[ 'status' => 'completed' ], 'distinct_players' ) );
    }

    public function test_activities_count_reads_the_session_date(): void {
        global $wpdb;
        foreach ( [ '2031-06-02', '2031-06-09', '2031-07-01' ] as $date ) {
            $wpdb->insert( "{$this->p}tt_activities", [
                'club_id' => $this->club, 'team_id' => $this->team, 'title' => 'KPI training',
                'session_date' => $date, 'activity_type_key' => 'training',
            ] );
        }

        $this->assertSame( 2.0, ( new ActivitiesRecent() )->aggregate( $this->viewer,[ 'date_from' => '2031-06-01', 'date_to' => '2031-06-30', 'team_id' => $this->team ], 'count' ) );
    }

    public function test_pdp_files_count(): void {
        global $wpdb;
        $table = "{$this->p}tt_pdp_files";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->assertNull( ( new PdpFiles() )->aggregate( $this->viewer,[], 'count' ) );
            return;
        }
        $source = new PdpFiles();
        $before = (float) $source->aggregate( $this->viewer,[ 'season_id' => 940123 ], 'count' );
        $player = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->p}tt_players WHERE last_name = %s", 'Aalders' ) );
        $wpdb->insert( $table, [ 'club_id' => $this->club, 'player_id' => $player, 'season_id' => 940123, 'status' => 'active' ] );

        $this->assertSame( $before + 1, $source->aggregate( $this->viewer,[ 'season_id' => 940123 ], 'count' ) );
    }

    // ── the renderer ───────────────────────────────────────────────────

    public function test_a_kpi_widget_shows_the_aggregate_not_a_players_age(): void {
        $html = CustomWidgetRenderer::renderWidget( $this->kpiWidget( [ 'key' => 'count', 'kind' => 'count', 'column' => '' ] ), get_current_user_id() );

        $this->assertStringContainsString( '<div class="tt-cw-kpi-value">2</div>', $html, 'the number of active players on the team' );
        $this->assertStringNotContainsString( '<div class="tt-cw-kpi-value">10</div>', $html, 'not the first player\'s age' );
    }

    public function test_a_kpi_widget_without_an_aggregation_renders_as_before(): void {
        $html = CustomWidgetRenderer::renderWidget( $this->kpiWidget( null ), get_current_user_id() );

        $this->assertStringContainsString( '<div class="tt-cw-kpi-value">10</div>', $html, 'the first row\'s first number, as before' );
    }

    public function test_the_cache_is_kept_apart_per_aggregation(): void {
        $count = CustomWidgetRenderer::renderWidget( $this->kpiWidget( [ 'key' => 'count', 'kind' => 'count', 'column' => '' ], 5 ), get_current_user_id() );
        $teams = CustomWidgetRenderer::renderWidget( $this->kpiWidget( [ 'key' => 'distinct_teams', 'kind' => 'distinct', 'column' => 'team_id' ], 5 ), get_current_user_id() );

        $this->assertStringContainsString( '<div class="tt-cw-kpi-value">2</div>', $count );
        $this->assertStringContainsString( '<div class="tt-cw-kpi-value">1</div>', $teams, 'a changed aggregation is not served the old figure' );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /** @param array<string,string>|null $aggregation */
    private function kpiWidget( ?array $aggregation, int $ttl = 0 ): CustomWidget {
        $definition = [
            'columns'           => [],
            'filters'           => [ 'team_id' => $this->team ],
            'cache_ttl_minutes' => $ttl,
        ];
        if ( $aggregation !== null ) $definition['aggregation'] = $aggregation;

        // One uuid whatever the aggregation: switching it on a saved widget
        // keeps the uuid, which is what the cache test needs to show.
        return new CustomWidget( 0, $this->club, 'kpi-4566-widget', 'Number of active players', 'players_active', CustomWidget::CHART_KPI, $definition );
    }

    /** Active players in this club that this fixture did not create. */
    private function countOutsideFixture(): float {
        global $wpdb;
        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->p}tt_players WHERE club_id = %d AND status <> 'archived' AND first_name <> 'KPI'",
            $this->club
        ) );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $last, int $age, string $status = 'active', ?int $club = null ): void {
        global $wpdb;
        $dob = gmdate( 'Y-m-d', (int) strtotime( '-' . $age . ' years -60 days' ) );
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $club ?? $this->club,
            'team_id'       => $team_id,
            'first_name'    => 'KPI',
            'last_name'     => $last,
            'date_of_birth' => $dob,
            'status'        => $status,
        ] );
    }

    private function insertEvaluation( int $player_id, string $created_at, int $category_id, float $rating ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id'    => $this->club,
            'player_id'  => $player_id,
            'coach_id'   => get_current_user_id(),
            'eval_date'  => substr( $created_at, 0, 10 ),
            'notes'      => '',
            'created_at' => $created_at,
        ] );
        $id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_eval_ratings", [
            'club_id'       => $this->club,
            'evaluation_id' => $id,
            'category_id'   => $category_id,
            'rating'        => $rating,
        ] );
        return $id;
    }
}
