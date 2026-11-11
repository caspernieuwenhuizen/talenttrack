<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\ActivityLifecycle;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

/**
 * #5116 — `ActivityLifecycle::outstandingClause()` honours a cancellation on
 * either axis. A session the planner cancelled (plan state) while its status
 * still reads planned is not upcoming, does not count, and a NULL plan state
 * is not a cancellation.
 */
final class OutstandingPlanCancelTest extends WP_UnitTestCase {

    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => 'Outstanding U12' ] );
        $this->team = (int) $wpdb->insert_id;
    }

    public function test_a_planner_cancelled_session_is_not_upcoming(): void {
        $kept      = $this->activity( 'game', 'scheduled' );
        $cancelled = $this->activity( 'game', 'cancelled' );
        $training  = $this->activity( 'training', 'cancelled' );

        $repo     = new ActivitiesRepository();
        $upcoming = array_map( static fn( $r ): int => (int) $r->id, $repo->upcomingForTeam( $this->team, 10 ) );
        $matches  = array_map( static fn( $r ): int => (int) $r->id, $repo->upcomingMatchesForTeam( $this->team, 10 ) );

        $this->assertContains( $kept, $upcoming );
        $this->assertNotContains( $cancelled, $upcoming, 'cancelled in the planner' );
        $this->assertNotContains( $training, $upcoming );
        $this->assertContains( $kept, $matches );
        $this->assertNotContains( $cancelled, $matches );

        $counts = $repo->upcomingCountsByTeam( [ $this->team ], 14 );
        $this->assertSame( 1, (int) ( $counts[ $this->team ] ?? 0 ), 'only the session still on counts' );
    }

    public function test_a_null_plan_state_session_is_still_outstanding(): void {
        global $wpdb;
        $id = $this->activity( 'game', 'scheduled' );
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}tt_activities SET plan_state = NULL WHERE id = %d", $id ) );

        $outstanding = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities a WHERE a.id = %d AND " . ActivityLifecycle::outstandingClause( 'a' ),
            $id
        ) );
        $this->assertSame( 1, $outstanding );
        $this->assertContains( $id, array_map( static fn( $r ): int => (int) $r->id, ( new ActivitiesRepository() )->upcomingForTeam( $this->team, 10 ) ) );
    }

    public function test_the_clause_on_each_axis(): void {
        global $wpdb;
        $cases = [
            [ 'planned', 'scheduled', true ],
            [ 'planned', 'cancelled', false ],
            [ 'cancelled', 'scheduled', false ],
            [ 'Cancelled', 'completed', false ],
            [ 'completed', 'scheduled', false ],
        ];
        foreach ( $cases as [ $status, $plan, $expected ] ) {
            $id  = $this->activity( 'game', $plan, $status );
            $got = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities a WHERE a.id = %d AND " . ActivityLifecycle::outstandingClause( 'a' ),
                $id
            ) ) === 1;
            $this->assertSame( $expected, $got, "status {$status}, plan state {$plan}" );
        }
    }

    private function activity( string $type, string $plan_state, string $status = 'planned' ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => (int) CurrentClub::id(),
            'team_id'             => $this->team,
            'title'               => 'Outstanding ' . $type,
            'session_date'        => gmdate( 'Y-m-d', current_time( 'timestamp' ) + 3 * DAY_IN_SECONDS ),
            'activity_type_key'   => $type,
            'activity_status_key' => $status,
            'plan_state'          => $plan_state,
        ] );
        return (int) $wpdb->insert_id;
    }
}
