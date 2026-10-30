<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Journey\InjuryStatus;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\AttendanceDateRule;
use TT\Modules\Activities\Services\PlayerAvailability;
use TT\Modules\Journey\Frontend\FrontendInjuriesView;

/**
 * #4281 — one class answers what an injury record means.
 *
 * The risk this guards is the one that produced the bug: the same rule
 * written twice, once in PHP and once in SQL, drifting apart. So every case
 * is asked both ways, on every day around its dates, and the two must give
 * the same answer. The status cases pin the record state the player file
 * and the overview show, and the last tests check that the surfaces read
 * that one answer.
 */
final class InjuryStatusTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;

    /** @var array<string, int> case => player id */
    private array $players = [];

    /** @var array<string, int> case => injury id */
    private array $injuries = [];

    private const CASES = [
        // case          started_on    expected      actual        archived
        'open'       => [ '2026-09-01', '2026-10-20', null,         false ],
        'overdue'    => [ '2026-09-01', '2026-09-10', null,         false ],
        'incomplete' => [ '2026-09-01', null,         null,         false ],
        'closed'     => [ '2026-09-01', null,         '2026-09-15', false ],
        'future_ret' => [ '2026-09-01', '2026-10-03', '2026-10-05', false ],
        'starts_late'=> [ '2026-10-10', null,         null,         false ],
        'archived'   => [ '2026-09-01', null,         null,         true ],
    ];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Status XI' ] );
        $this->team = (int) $wpdb->insert_id;

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_php_and_sql_forms_agree_on_every_day(): void {
        $this->seedCases();
        $repo = new InjuryRepository();
        $days = [ '2026-08-31', '2026-09-01', '2026-09-10', '2026-09-11', '2026-09-14', '2026-09-15',
                  '2026-10-01', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-10', '2026-10-20', '2026-10-21' ];

        foreach ( $days as $day ) {
            $sql = $repo->coveredSet( array_values( $this->players ), $day );
            foreach ( $this->injuries as $case => $injury_id ) {
                $row = $repo->find( $injury_id );
                $this->assertNotNull( $row );
                $this->assertSame(
                    InjuryStatus::coversDay( $row, $day ),
                    isset( $sql[ $this->players[ $case ] ] ),
                    "PHP and SQL disagree for {$case} on {$day}"
                );
            }
        }
    }

    public function test_covering_a_day_keeps_the_planning_rule(): void {
        $this->seedCases();
        $covered = ( new InjuryRepository() )->coveredSet( array_values( $this->players ), '2026-10-01' );

        $expect = [
            'open'        => true,  // expected return still ahead
            'overdue'     => false, // expected return passed: stale, not planning's business yet
            'incomplete'  => true,  // no expected return
            'closed'      => false, // back on 09-15
            'future_ret'  => true,  // back only on 10-05
            'starts_late' => false, // not hurt yet
            'archived'    => false, // in the recycle bin
        ];
        foreach ( $expect as $case => $out ) {
            $this->assertSame( $out, isset( $covered[ $this->players[ $case ] ] ), $case );
        }

        // PlayerAvailability no longer has a rule of its own: it is this set.
        $this->assertSame(
            $covered,
            PlayerAvailability::unavailableSet( array_values( $this->players ), '2026-10-01' )
        );
    }

    public function test_the_record_status_as_of_a_day(): void {
        $this->seedCases();
        $repo   = new InjuryRepository();
        $expect = [
            'open'        => InjuryStatus::OPEN,
            'overdue'     => InjuryStatus::OVERDUE,
            'incomplete'  => InjuryStatus::INCOMPLETE,
            'closed'      => InjuryStatus::CLOSED,
            'future_ret'  => InjuryStatus::CLOSED,
            'starts_late' => InjuryStatus::OPEN,
        ];
        foreach ( $expect as $case => $status ) {
            $this->assertSame( $status, InjuryStatus::status( $repo->find( $this->injuries[ $case ] ), '2026-10-01' ), $case );
        }
    }

    public function test_incomplete_starts_after_the_configured_number_of_days(): void {
        $row = [ 'started_on' => '2026-09-01', 'expected_return' => null, 'actual_return' => null ];
        $n   = InjuryStatus::incompleteAfterDays();
        $this->assertSame( InjuryStatus::INCOMPLETE_AFTER_DEFAULT, $n, 'no seed: the fallback applies' );

        $this->assertSame( InjuryStatus::OPEN, InjuryStatus::status( $row, '2026-09-14' ), '13 days: still open' );
        $this->assertSame( InjuryStatus::INCOMPLETE, InjuryStatus::status( $row, '2026-09-15' ), '14 days: incomplete' );
    }

    public function test_days_out_runs_to_the_return_or_to_today(): void {
        $this->assertSame( 14, InjuryStatus::daysOut( [ 'started_on' => '2026-09-01', 'actual_return' => '2026-09-15' ], '2026-10-01' ) );
        $this->assertSame( 30, InjuryStatus::daysOut( [ 'started_on' => '2026-09-01' ], '2026-10-01' ) );
        $this->assertSame( 0, InjuryStatus::daysOut( [ 'started_on' => '' ], '2026-10-01' ) );
        $this->assertSame( 0, InjuryStatus::daysOut( [ 'started_on' => '2026-10-10' ], '2026-10-01' ) );
    }

    public function test_the_overview_the_list_and_the_rest_route_read_the_same_answer(): void {
        $today = AttendanceDateRule::today();
        $ago   = static fn ( int $d ): string => gmdate( 'Y-m-d', (int) strtotime( $today . ' -' . $d . ' days' ) );
        $ahead = static fn ( int $d ): string => gmdate( 'Y-m-d', (int) strtotime( $today . ' +' . $d . ' days' ) );

        $pid    = $this->player( 'surfaces' );
        $repo   = new InjuryRepository();
        $open   = $repo->create( [ 'player_id' => $pid, 'started_on' => $ago( 5 ), 'expected_return' => $ahead( 5 ) ] );
        $late   = $repo->create( [ 'player_id' => $pid, 'started_on' => $ago( 30 ), 'expected_return' => $ago( 3 ) ] );
        $closed = $repo->create( [ 'player_id' => $pid, 'started_on' => $ago( 60 ), 'actual_return' => $ago( 40 ) ] );

        // The overview's "currently out" list is exactly the records that are not closed.
        $listed = array_map( static fn ( $r ) => (int) ( (array) $r )['id'], $repo->listForTeams( [ $this->team ], [ 'status' => 'open' ] ) );
        sort( $listed );
        $this->assertSame( [ $open, $late ], $listed );

        // The REST list carries the same derived status.
        $res = rest_do_request( new WP_REST_Request( 'GET', "/talenttrack/v1/players/{$pid}/injuries" ) );
        $this->assertSame( 200, $res->get_status() );
        $data   = (array) $res->get_data();
        $byId   = [];
        foreach ( (array) ( $data['data']['injuries'] ?? [] ) as $item ) {
            $item = (array) $item;
            $byId[ (int) $item['id'] ] = (string) $item['status'];
        }
        $this->assertSame( InjuryStatus::OPEN, $byId[ $open ] );
        $this->assertSame( InjuryStatus::OVERDUE, $byId[ $late ] );
        $this->assertSame( InjuryStatus::CLOSED, $byId[ $closed ] );

        // The overview flags the overdue record, and only that one.
        ob_start();
        FrontendInjuriesView::render( get_current_user_id(), true );
        $html = (string) ob_get_clean();
        $this->assertSame( 1, substr_count( $html, 'tt-injury-row--overdue' ) );
    }

    private function seedCases(): void {
        global $wpdb;
        foreach ( self::CASES as $case => [ $started, $expected, $actual, $archived ] ) {
            $pid = $this->player( $case );
            $ok  = $wpdb->insert( "{$wpdb->prefix}tt_player_injuries", array_filter( [
                'club_id'         => $this->club,
                'player_id'       => $pid,
                'started_on'      => $started,
                'expected_return' => $expected,
                'actual_return'   => $actual,
                'archived_at'     => $archived ? '2026-09-20 10:00:00' : null,
            ], static fn ( $v ) => $v !== null ) );
            $this->assertNotFalse( $ok, "injury fixture {$case} must be written" );
            $this->injuries[ $case ] = (int) $wpdb->insert_id;
        }
    }

    private function player( string $case ): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Case',
            'last_name'  => $case,
            'status'     => 'active',
        ] );
        $this->assertNotFalse( $ok, 'player insert must succeed' );
        $this->players[ $case ] = (int) $wpdb->insert_id;
        return $this->players[ $case ];
    }
}
