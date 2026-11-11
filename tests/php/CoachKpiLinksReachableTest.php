<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\PersonaDashboard\Defaults\CoreKpis;
use TT\Modules\PersonaDashboard\Defaults\CoreTemplates;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Registry\KpiDataSourceRegistry;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Tiles\TileRegistry;

/**
 * #5048 — the coach dashboard's KPI cards open views a coach may open.
 *
 * "My evaluations this week" opened `my-evaluations`, and the KPI link map
 * still named `my-evaluations`, `my-activities` and `my-team` for coach
 * KPIs: the player and parent views. Those tiles refuse a coach on purpose
 * (the `my_evaluations_panel` entity keeps the player's tile off a coach's
 * dashboard), so a tap on the card landed on "no access". The guard resolves
 * each card's link the way `AbstractWidget::kpiHrefFor()` does — the KPI's
 * `linkUrl()` first, then its `linkView()` — and asks `canAccessViewSlug()`
 * about the slug for a head coach fixture.
 *
 * The cards that open the evaluations list filter it to `filter[coach_id]`,
 * which `GET /evaluations` accepts for the caller's own id and refuses for
 * another coach's without `tt_view_analytics`.
 */
final class CoachKpiLinksReachableTest extends WP_UnitTestCase {

    private const CLUB = 1;

    /** KPIs outside the shipped coach template that are coach-context too. */
    private const EXTRA_COACH_KPIS = [ 'my_players_evaluated_season' ];

    private string $matrix_was = '';

    /** @var list<array<string,mixed>> */
    private array $tiles_were = [];

    private int $team       = 0;
    private int $coach      = 0;
    private int $other      = 0;
    private int $hod        = 0;
    private int $own_eval   = 0;
    private int $other_eval = 0;
    private int $player     = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $p = $wpdb->prefix;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();

        $this->tiles_were = TileRegistry::allRegistered();
        TileRegistry::clear();
        \TT\Shared\CoreSurfaceRegistration::register();

        if ( KpiDataSourceRegistry::get( 'my_evaluations_this_week' ) === null ) {
            CoreKpis::register();
        }

        $config           = new ConfigService();
        $this->matrix_was = (string) $config->get( 'tt_authorization_active', '' );
        $config->set( 'tt_authorization_active', '1' );
        $this->resetMatrixActiveCaches();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => self::CLUB, 'name' => 'Kpilink JO13-1' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => self::CLUB, 'team_id' => $this->team,
            'first_name' => 'Kpilink', 'last_name' => 'Playerx', 'status' => 'active',
        ] );
        $player       = (int) $wpdb->insert_id;
        $this->player = $player;

        $this->coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => self::CLUB, 'first_name' => 'Kpilink', 'last_name' => 'Coach',
            'role_type' => 'head_coach', 'wp_user_id' => $this->coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => self::CLUB, 'team_id' => $this->team, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id' => self::CLUB, 'person_id' => $person, 'role_id' => 1,
            'scope_type' => 'team', 'scope_id' => $this->team,
        ] );

        $this->other = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->hod   = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );

        // A current season around today, so the season card's date window
        // holds the evaluations below whatever day the suite runs.
        $wpdb->update( "{$p}tt_seasons", [ 'is_current' => 0 ], [ 'club_id' => self::CLUB ] );
        $wpdb->insert( "{$p}tt_seasons", [
            'club_id'    => self::CLUB,
            'name'       => 'Kpilink season',
            'start_date' => gmdate( 'Y-m-d', strtotime( '-60 days' ) ),
            'end_date'   => gmdate( 'Y-m-d', strtotime( '+60 days' ) ),
            'is_current' => 1,
        ] );

        $today = current_time( 'Y-m-d' );
        $wpdb->insert( "{$p}tt_evaluations", [
            'club_id' => self::CLUB, 'player_id' => $player, 'coach_id' => $this->coach, 'eval_date' => $today,
        ] );
        $this->own_eval = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_evaluations", [
            'club_id' => self::CLUB, 'player_id' => $player, 'coach_id' => $this->other, 'eval_date' => $today,
        ] );
        $this->other_eval = (int) $wpdb->insert_id;

        $this->flush();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        ( new ConfigService() )->set( 'tt_authorization_active', $this->matrix_was );
        $this->resetMatrixActiveCaches();
        TileRegistry::clear();
        foreach ( $this->tiles_were as $tile ) {
            TileRegistry::register( $tile );
        }
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // The guard

    public function test_every_coach_kpi_links_to_a_view_the_coach_may_open(): void {
        $ids = array_merge( $this->coachTemplateKpiIds(), self::EXTRA_COACH_KPIS );
        $this->assertNotEmpty( $this->coachTemplateKpiIds(), 'precondition: the coach template carries KPI cards' );

        wp_set_current_user( $this->coach );
        foreach ( $ids as $id ) {
            $source = KpiDataSourceRegistry::get( $id );
            $this->assertNotNull( $source, $id . ' is registered' );
            $slug = $this->slugOf( $this->hrefFor( $source ) );
            $this->assertNotSame( '', $slug, $id . ' links somewhere' );
            $this->assertTrue(
                TileRegistry::canAccessViewSlug( $slug, $this->coach ),
                $id . ' links to ' . $slug . ', which refuses a coach'
            );
        }
    }

    /** The guard catches a card that points at a player-only view. */
    public function test_a_kpi_linking_to_a_player_only_view_fails_the_guard(): void {
        $stub = new class extends AbstractKpiDataSource {
            public function id(): string { return 'tt_test_player_only_link'; }
            public function linkView(): string { return 'my-evaluations'; }
            public function compute( int $user_id, int $club_id ): KpiValue { return KpiValue::unavailable(); }
        };

        $slug = $this->slugOf( $this->hrefFor( $stub ) );
        $this->assertSame( 'my-evaluations', $slug );
        $this->assertFalse(
            TileRegistry::canAccessViewSlug( $slug, $this->coach ),
            'my-evaluations stays the player and parent view, so the guard refuses this card'
        );
    }

    // The cards land on rows

    public function test_the_own_evaluations_cards_open_the_coachs_evaluations(): void {
        foreach ( [ 'my_evaluations_this_week', 'my_players_evaluated_season' ] as $id ) {
            $source = KpiDataSourceRegistry::get( $id );
            $this->assertNotNull( $source );
            $filter = $this->filterOf( $this->hrefFor( $source ) );
            $this->assertSame( (string) $this->coach, (string) ( $filter['coach_id'] ?? '' ), $id . ' filters to the coach' );

            $response = $this->listAs( $this->coach, $filter );
            $this->assertSame( 200, $response->get_status(), $id );
            $ids = $this->rowIds( $response );
            $this->assertContains( $this->own_eval, $ids, $id . ' shows the coach\'s evaluation' );
            $this->assertNotContains( $this->other_eval, $ids, $id . ' leaves out another coach\'s' );
        }
    }

    public function test_the_team_rating_card_opens_the_teams_evaluations(): void {
        $source = KpiDataSourceRegistry::get( 'my_team_avg_rating' );
        $this->assertNotNull( $source );
        $filter = $this->filterOf( $this->hrefFor( $source ) );

        $response = $this->listAs( $this->coach, $filter );
        $this->assertSame( 200, $response->get_status() );
        $ids = $this->rowIds( $response );
        $this->assertContains( $this->own_eval, $ids );
        $this->assertContains( $this->other_eval, $ids, 'every evaluation on the coach\'s team' );
    }

    /**
     * The "this week" card counts what its list shows: both go by the
     * evaluation's date, not the day it was entered.
     */
    public function test_the_week_card_and_its_list_count_the_same_evaluations(): void {
        global $wpdb;
        $today   = new \DateTimeImmutable( current_time( 'Y-m-d' ), wp_timezone() );
        $entered = current_time( 'mysql' );

        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id' => self::CLUB, 'player_id' => $this->player, 'coach_id' => $this->coach,
            'eval_date' => $today->sub( new \DateInterval( 'P14D' ) )->format( 'Y-m-d' ), 'created_at' => $entered,
        ] );
        $old = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id' => self::CLUB, 'player_id' => $this->player, 'coach_id' => $this->coach,
            'eval_date' => $today->sub( new \DateInterval( 'P1D' ) )->format( 'Y-m-d' ), 'created_at' => $entered,
        ] );
        $yesterday = (int) $wpdb->insert_id;

        $source = KpiDataSourceRegistry::get( 'my_evaluations_this_week' );
        $this->assertNotNull( $source );
        $ids = $this->rowIds( $this->listAs( $this->coach, $this->filterOf( $this->hrefFor( $source ) ) ) );
        $this->assertContains( $yesterday, $ids, 'an evaluation dated yesterday is on the list' );
        $this->assertNotContains( $old, $ids, 'one dated two weeks ago is not, though it was entered today' );

        $card = $source->compute( $this->coach, self::CLUB );
        $this->assertSame( (string) count( $ids ), $card->current, 'the card counts the rows its list shows' );
        $sparkline = $card->sparkline;
        $this->assertSame( (float) count( $ids ), (float) end( $sparkline ), 'the newest sparkline bucket is the card\'s window' );
    }

    // The author filter

    public function test_a_coach_filters_to_their_own_evaluations_and_not_to_another_coachs(): void {
        $own = $this->listAs( $this->coach, [ 'coach_id' => (string) $this->coach ] );
        $this->assertSame( 200, $own->get_status() );
        $this->assertSame( [ $this->own_eval ], $this->rowIds( $own ) );

        wp_set_current_user( $this->coach );
        $this->assertFalse( current_user_can( 'tt_view_analytics' ), 'precondition: a coach has no analytics read' );
        $theirs = $this->listAs( $this->coach, [ 'coach_id' => (string) $this->other ] );
        $this->assertSame( 403, $theirs->get_status(), 'another coach\'s id needs tt_view_analytics' );
        $this->assertStringNotContainsString( '"rows"', (string) wp_json_encode( $theirs->get_data() ) );
    }

    public function test_the_head_of_development_filters_by_any_coach(): void {
        $response = $this->listAs( $this->hod, [ 'coach_id' => (string) $this->other ] );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ $this->other_eval ], $this->rowIds( $response ) );
    }

    // Helpers

    /** @return list<string> */
    private function coachTemplateKpiIds(): array {
        $ids = [];
        foreach ( CoreTemplates::coach( self::CLUB )->grid->slots() as $slot ) {
            if ( $slot->widget_id === 'kpi_card' && $slot->data_source !== '' ) {
                $ids[] = $slot->data_source;
            }
        }
        return $ids;
    }

    /** Mirrors `AbstractWidget::kpiHrefFor()`: the override first, then the map. */
    private function hrefFor( object $source ): string {
        $ctx = new RenderContext( $this->coach, self::CLUB, 'head_coach', home_url( '/' ) );
        if ( method_exists( $source, 'linkUrl' ) ) {
            $url = (string) $source->linkUrl( $ctx );
            if ( $url !== '' ) return $url;
        }
        if ( method_exists( $source, 'linkView' ) ) {
            $view = (string) $source->linkView();
            if ( $view !== '' ) return $ctx->viewUrl( $view );
        }
        return '';
    }

    private function slugOf( string $url ): string {
        $args = $this->queryOf( $url );
        return (string) ( $args['tt_view'] ?? '' );
    }

    /** @return array<string,string> */
    private function filterOf( string $url ): array {
        $args   = $this->queryOf( $url );
        $filter = is_array( $args['filter'] ?? null ) ? $args['filter'] : [];
        return array_map( 'strval', $filter );
    }

    /** @return array<string,mixed> */
    private function queryOf( string $url ): array {
        $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
        $args  = [];
        wp_parse_str( $query, $args );
        return $args;
    }

    /** @param array<string,string> $filter */
    private function listAs( int $user_id, array $filter ): WP_REST_Response {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/evaluations' );
        $request->set_query_params( [ 'filter' => $filter, 'per_page' => 100 ] );
        $server   = rest_get_server();
        $response = rest_ensure_response( $server->dispatch( $request ) );
        $response = apply_filters( 'rest_post_dispatch', $response, $server, $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        return $response;
    }

    /** @return list<int> */
    private function rowIds( WP_REST_Response $response ): array {
        $data = $response->get_data();
        $rows = $data['data']['rows'] ?? $data['rows'] ?? [];
        $ids  = [];
        foreach ( (array) $rows as $row ) {
            $ids[] = (int) ( is_array( $row ) ? ( $row['id'] ?? 0 ) : ( $row->id ?? 0 ) );
        }
        sort( $ids );
        return $ids;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }

    private function resetMatrixActiveCaches(): void {
        foreach ( [ TileRegistry::class, DashboardShortcode::class ] as $class ) {
            $prop = new \ReflectionProperty( $class, 'matrix_active_cache' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        }
    }
}
