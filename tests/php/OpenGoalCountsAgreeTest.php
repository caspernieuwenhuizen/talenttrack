<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Query\GoalLifecycle;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\CustomWidgets\DataSources\GoalsOpen;
use TT\Modules\PersonaDashboard\TableSources\GoalsByPrincipleSource;
use TT\Shared\Frontend\FrontendMyProfileView;

/**
 * #5074 — one player with a goal in every status, both stored spellings, and
 * one with no status. Every open-goal count reads the same five: the Goals
 * tab and profile KPI, the player's own profile, the custom widget, the
 * goals-by-principle table and the PDP evidence rule.
 *
 * The past-due alert, the KPI export and the Admin Center payload run the
 * same clause inside statements that need a schedule, a file or a remote
 * call; they are pinned to `GoalLifecycle::openClause()` at the source, and
 * `GoalLifecycleGuardTest` keeps a list of their own from coming back.
 */
final class OpenGoalCountsAgreeTest extends WP_UnitTestCase {

    /** The open ones: pending, waiting for approval, in progress, on hold, approved. */
    private const OPEN = 5;

    private int $club = 0;
    private int $player = 0;
    private int $principle = 0;
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

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Ogc JO15' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [ 'club_id' => $this->club, 'team_id' => $team, 'first_name' => 'Ogc', 'last_name' => 'Speler', 'status' => 'active' ] );
        $this->player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_principles", [
            'club_id' => $this->club, 'code' => 'OGC-01',
            'title_json' => wp_json_encode( [ 'nl' => 'Ogc principe', 'en' => 'Ogc principle' ] ),
        ] );
        $this->principle = (int) $wpdb->insert_id;

        foreach ( [ 'pending', 'Pending Approval', 'in_progress', 'On Hold', 'Approved', 'completed', 'Cancelled', 'rejected', null ] as $i => $status ) {
            $wpdb->insert( "{$p}tt_goals", [
                'club_id' => $this->club, 'player_id' => $this->player, 'title' => 'Ogc doel ' . $i,
                'status' => $status, 'created_by' => 0, 'linked_principle_id' => $this->principle,
            ] );
        }
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_every_open_count_reads_the_same_number(): void {
        $this->assertSame( self::OPEN, ( new GoalsRepository() )->countActiveForPlayer( $this->player ), 'the Goals tab and the profile KPI' );

        $profile = new \ReflectionMethod( FrontendMyProfileView::class, 'countActiveGoalsForPlayer' );
        $profile->setAccessible( true );
        $this->assertSame( self::OPEN, $profile->invoke( null, $this->player ), 'the player\'s own profile' );

        $widget = array_filter(
            ( new GoalsOpen() )->fetch( $this->viewer, [], [] ),
            static fn( array $row ): bool => ( $row['player'] ?? '' ) === 'Ogc Speler'
        );
        $this->assertCount( self::OPEN, $widget, 'the custom widget' );

        $table = array_values( array_filter(
            ( new GoalsByPrincipleSource() )->rowsFor( $this->viewer, [] ),
            static fn( array $row ): bool => strpos( (string) $row[0], 'OGC-01' ) === 0
        ) );
        $this->assertCount( 1, $table );
        $this->assertSame( (string) self::OPEN, $table[0][1], 'the goals-by-principle table' );

        $open = 0;
        foreach ( ( new GoalsRepository() )->listForPlayer( $this->player ) as $goal ) {
            if ( GoalLifecycle::isOpen( isset( $goal->status ) ? (string) $goal->status : null ) ) $open++;
        }
        $this->assertSame( self::OPEN, $open, 'the PHP rule the PDP evidence uses' );
    }

    /** A goal written with an empty status is stored as the default, so it stays open. */
    public function test_post_goals_with_an_empty_status_stores_pending(): void {
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new \WP_REST_Server();
        do_action( 'rest_api_init' );

        $request = new \WP_REST_Request( 'POST', '/talenttrack/v1/goals' );
        $request->set_body_params( [ 'player_id' => $this->player, 'title' => 'Ogc leeg', 'status' => '' ] );
        $response = rest_do_request( $request );
        $wp_rest_server = null;

        $this->assertLessThan( 300, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
        $stored = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}tt_goals WHERE player_id = %d AND title = %s",
            $this->player, 'Ogc leeg'
        ) );
        $this->assertSame( 'pending', $stored );
    }

    /** PUT with an empty status is refused, as PATCH .../status is, and writes nothing. */
    public function test_put_goal_with_an_empty_status_is_refused(): void {
        global $wp_rest_server, $wpdb;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_goals WHERE player_id = %d AND status = %s",
            $this->player, 'in_progress'
        ) );
        $wp_rest_server = new \WP_REST_Server();
        do_action( 'rest_api_init' );

        $request = new \WP_REST_Request( 'PUT', '/talenttrack/v1/goals/' . $id );
        $request->set_body_params( [ 'status' => '' ] );
        $response = rest_do_request( $request );
        $wp_rest_server = null;

        $this->assertSame( 400, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
        $body = (array) $response->get_data();
        $this->assertSame( 'invalid_field', (string) ( $body['errors'][0]['code'] ?? '' ) );
        $this->assertSame( 'in_progress', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}tt_goals WHERE id = %d", $id ) ) );
    }

    /** #5099 — the Admin Center's open-goal signal counts the same five. */
    public function test_the_admin_center_value_signal_counts_the_same_open_goals(): void {
        global $wpdb;
        $before = \TT\Modules\AdminCenterClient\PayloadBuilder::valueSignals();
        $wpdb->insert( "{$wpdb->prefix}tt_players", [ 'club_id' => $this->club, 'first_name' => 'Ogc', 'last_name' => 'Tweede', 'status' => 'active' ] );
        $second = (int) $wpdb->insert_id;
        foreach ( [ 'pending', 'Pending Approval', 'in_progress', 'On Hold', 'Approved', 'completed', 'Cancelled', 'rejected', null ] as $i => $status ) {
            $wpdb->insert( "{$wpdb->prefix}tt_goals", [ 'club_id' => $this->club, 'player_id' => $second, 'title' => 'Ogc tweede ' . $i, 'status' => $status, 'created_by' => 0 ] );
        }
        $after = \TT\Modules\AdminCenterClient\PayloadBuilder::valueSignals();

        $this->assertSame( self::OPEN, $after['goals_active'] - $before['goals_active'] );
        $this->assertSame( self::OPEN, ( new GoalsRepository() )->countActiveForPlayer( $second ), 'the same five as the Goals tab' );
    }

    public function test_open_and_not_open_split_every_goal(): void {
        $repo = new GoalsRepository();
        $this->assertSame( 9, $repo->countActiveForPlayer( $this->player ) + $repo->countClosedForPlayer( $this->player ), 'every goal is in exactly one of the two' );
        $this->assertSame( 4, $repo->countClosedForPlayer( $this->player ), 'completed, cancelled, rejected and no status' );
    }

    public function test_the_other_counts_ask_the_shared_clause(): void {
        $root = dirname( __DIR__, 2 );
        foreach ( [
            'src/Modules/Alerts/Definitions/GoalPastTargetDateAlert.php',
            'src/Modules/Export/Exporters/KpiSnapshotXlsxExporter.php',
            'src/Modules/AdminCenterClient/PayloadBuilder.php',
            'src/Modules/Pdp/Frontend/FrontendPdpManageView.php',
            'src/Modules/Pdp/Carryover/SeasonCarryover.php',
        ] as $file ) {
            $this->assertStringContainsString( 'GoalLifecycle::openClause(', (string) file_get_contents( $root . '/' . $file ), $file );
        }
    }
}
