<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\AlertEvaluator;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Definitions\GoalPastTargetDateAlert;
use TT\Modules\Alerts\Domain\AlertAudience;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;
use TT\Modules\Alerts\Repositories\AlertPreferencesRepository;

/**
 * #4262 — a family's alert must open the child's record on the Me-slug
 * `overview`, not the staff-only `players` slug that refuses a parent. The
 * staff copy of the same alert keeps its own destination.
 */
final class FamilyAlertUrlTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    /** @var int */
    private $staff;

    /** @var int */
    private $page;

    /** @var mixed */
    private $requestUri;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $this->p = $wpdb->prefix;
        AlertPreferencesRepository::flushTableCache();
        AlertOccurrencesRepository::flushTableCache();
        AlertRegistry::flush();
        AlertAudience::flush();

        $wpdb->query( "DELETE FROM {$this->p}tt_alert_occurrences" );
        $wpdb->query( "DELETE FROM {$this->p}tt_player_parents" );

        $this->staff = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $this->page = self::factory()->post->create( [
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Dashboard',
            'post_content' => '[talenttrack_dashboard]',
        ] );
        QueryHelpers::set_config( 'dashboard_page_id', (string) $this->page );

        // The sweep runs in cron; a URL built on the current request would
        // point at the cron endpoint.
        $this->requestUri       = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/wp-cron.php?doing_wp_cron=1';

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        if ( $this->requestUri === null ) {
            unset( $_SERVER['REQUEST_URI'] );
        } else {
            $_SERVER['REQUEST_URI'] = $this->requestUri;
        }
        AlertAudience::flush();
        AlertRegistry::flush();
        parent::tear_down();
    }

    public function test_the_family_copy_opens_the_child_overview_on_the_dashboard_page(): void {
        $player = $this->insertPlayer();
        $parent = $this->linkParent( $player );
        $goal   = $this->insertOverdueGoal( $player );

        ( new AlertEvaluator() )->run( new GoalPastTargetDateAlert(), new AlertContext( $this->club ) );

        wp_set_current_user( $parent );
        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/alerts' );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $rows = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : [];
        $mine = array_values( array_filter( $rows, static function ( $row ) use ( $goal ) {
            return ( $row['alert_key'] ?? '' ) === 'goals.past_target_date'
                && (int) ( $row['subject_id'] ?? 0 ) === $goal;
        } ) );
        $this->assertCount( 1, $mine, 'the guardian receives the overdue-goal alert about their child' );

        $url = (string) $mine[0]['url'];
        $this->assertStringContainsString( 'tt_view=overview', $url );
        $this->assertStringContainsString( 'player_id=' . $player, $url );
        $this->assertStringNotContainsString( 'tt_view=players', $url, 'the staff-only slug refuses a parent' );
        $this->assertStringNotContainsString( 'wp-cron', $url, 'the URL is not built on the cron request' );
        $this->assertStringStartsWith( (string) get_permalink( $this->page ), $url );
    }

    public function test_the_staff_copy_keeps_the_goal_destination(): void {
        $player = $this->insertPlayer();
        $parent = $this->linkParent( $player );
        $goal   = $this->insertOverdueGoal( $player );

        $out = ( new GoalPastTargetDateAlert() )->evaluate( new AlertContext( $this->club ) );

        $staff_urls  = [];
        $family_urls = [];
        foreach ( $out as $occ ) {
            if ( $occ->subjectId !== $goal ) continue;
            if ( $occ->audience === AlertAudience::PARENT ) {
                $this->assertSame( $parent, $occ->recipientUserId );
                $family_urls[] = $occ->url();
            } else {
                $staff_urls[] = $occ->url();
            }
        }

        $this->assertNotEmpty( $staff_urls, 'fixture sanity: the goal author gets the staff copy' );
        $this->assertCount( 1, $family_urls );
        foreach ( $staff_urls as $url ) {
            $this->assertStringNotContainsString( 'tt_view=overview', $url );
            $this->assertStringContainsString( 'goals', $url );
        }
    }

    private function insertPlayer(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U13' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'     => $this->club,
            'team_id'     => $team,
            'first_name'  => 'Family',
            'last_name'   => 'Fixture',
            'status'      => 'active',
            'date_joined' => gmdate( 'Y-m-d', strtotime( '-400 days' ) ),
        ] );
        return (int) $wpdb->insert_id;
    }

    private function linkParent( int $player_id ): int {
        global $wpdb;
        $user = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$this->p}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $player_id,
            'parent_user_id' => $user,
            'is_primary'     => 1,
        ] );
        AlertAudience::flush();
        return (int) $user;
    }

    private function insertOverdueGoal( int $player_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_goals", [
            'club_id'    => $this->club,
            'player_id'  => $player_id,
            'title'      => 'Improve weak foot',
            'status'     => 'in_progress',
            'due_date'   => gmdate( 'Y-m-d', strtotime( '-10 days', (int) current_time( 'timestamp' ) ) ),
            'created_by' => $this->staff,
        ] );
        return (int) $wpdb->insert_id;
    }
}
