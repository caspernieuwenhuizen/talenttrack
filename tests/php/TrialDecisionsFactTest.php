<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\FactQuery;
use TT\Modules\Analytics\FactRegistry;

/**
 * #4856 — the trial decisions fact reads the columns `tt_trial_cases` has.
 *
 * It read `decided_at` and `decided_by`; the columns are `decision_made_at`
 * and `decision_made_by`, so every query on the fact failed and every report
 * built on it was empty.
 */
final class TrialDecisionsFactTest extends WP_UnitTestCase {

    private const DECIDER_A = 4851;
    private const DECIDER_B = 4852;

    private int $club = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->club = (int) CurrentClub::id();
        wp_cache_flush();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Trial facts' ] );
        $team   = (int) $wpdb->insert_id;
        $player = $this->player( $team );

        $this->trialCase( $player, 'admit', '2019-03-10 10:00:00', self::DECIDER_A );
        $this->trialCase( $player, 'deny_final', '2019-03-20 10:00:00', self::DECIDER_B );
        $this->trialCase( $player, 'admit', '2019-04-05 10:00:00', self::DECIDER_A );
        $this->trialCase( $player, null, null, null );
    }

    public function test_the_fact_reads_decision_made_at_and_decision_made_by(): void {
        $fact = FactRegistry::find( 'trial_decisions' );
        $this->assertNotNull( $fact );
        $this->assertSame( 'f.decision_made_at', $fact->timeColumn->expression );
        $this->assertNotNull( $fact->dimension( 'decision_made_by' ), 'the decided-by dimension is the column' );
    }

    public function test_the_period_filter_counts_the_decisions_in_the_window(): void {
        global $wpdb;
        $march = FactQuery::run( 'trial_decisions', [], [ 'count' ], [ 'date_after' => '2019-03-01', 'date_before' => '2019-03-31' ], FactScope::unrestricted() );
        $this->assertSame( '', $wpdb->last_error );
        $this->assertSame( 2, (int) ( $march[0]->count ?? 0 ), 'two decisions in March' );

        $by_month = FactQuery::run( 'trial_decisions', [ 'month' ], [ 'count' ], [ 'date_after' => '2019-03-01', 'date_before' => '2019-04-30' ], FactScope::unrestricted() );
        $counts   = [];
        foreach ( $by_month as $r ) $counts[ (string) $r->month ] = (int) $r->count;
        ksort( $counts );
        $this->assertSame( [ '2019-03' => 2, '2019-04' => 1 ], $counts, 'grouped by the decision month; the undecided case is outside every window' );
    }

    public function test_the_decided_by_dimension_groups_and_filters(): void {
        global $wpdb;
        $window = [ 'date_after' => '2019-03-01', 'date_before' => '2019-04-30' ];

        $rows   = FactQuery::run( 'trial_decisions', [ 'decision_made_by' ], [ 'count' ], $window, FactScope::unrestricted() );
        $this->assertSame( '', $wpdb->last_error );
        $counts = [];
        foreach ( $rows as $r ) $counts[ (int) $r->decision_made_by ] = (int) $r->count;
        ksort( $counts );
        $this->assertSame( [ self::DECIDER_A => 2, self::DECIDER_B => 1 ], $counts );

        $only = FactQuery::run( 'trial_decisions', [], [ 'count' ], $window + [ 'decision_made_by_eq' => self::DECIDER_B ], FactScope::unrestricted() );
        $this->assertSame( 1, (int) ( $only[0]->count ?? 0 ), 'filtering by who decided' );
    }

    public function test_the_drilldown_lists_the_decided_rows(): void {
        global $wpdb;
        $window = [ 'date_after' => '2019-03-01', 'date_before' => '2019-04-30' ];

        $rows = FactQuery::rows( 'trial_decisions', $window, FactScope::unrestricted() );
        $this->assertSame( '', $wpdb->last_error );
        $this->assertCount( 3, $rows );
        $this->assertSame( self::DECIDER_A, (int) $rows[0]->decision_made_by, 'newest decision first' );
        $this->assertSame( 3, FactQuery::countRows( 'trial_decisions', $window, FactScope::unrestricted() ) );
    }

    private function player( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $team_id, 'first_name' => 'Trial', 'last_name' => 'Facts', 'status' => 'trial',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function trialCase( int $player_id, ?string $decision, ?string $made_at, ?int $made_by ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_trial_cases", [
            'club_id'          => $this->club,
            'player_id'        => $player_id,
            'track_id'         => 1,
            'start_date'       => '2019-02-01',
            'end_date'         => '2019-02-28',
            'status'           => $decision === null ? 'open' : 'decided',
            'decision'         => $decision,
            'decision_made_at' => $made_at,
            'decision_made_by' => $made_by,
            'created_by'       => 1,
        ] );
    }
}
