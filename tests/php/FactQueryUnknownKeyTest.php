<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Domain\UnknownFactKeyException;
use TT\Modules\Analytics\FactQuery;
use TT\Modules\Analytics\FactRegistry;

/**
 * #4860 — `FactQuery` refuses a dimension or filter key the fact neither
 * declares nor aliases, instead of skipping it and answering for more rows
 * than were asked about. An alias reads exactly what the new key reads.
 */
final class FactQueryUnknownKeyTest extends WP_UnitTestCase {

    private int $team_a = 0;
    private int $team_b = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Keys U12' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Keys U15' ] );
        $this->team_b = (int) $wpdb->insert_id;
        foreach ( [ $this->team_a, $this->team_a, $this->team_b ] as $i => $team ) {
            $wpdb->insert( "{$wpdb->prefix}tt_activities", [
                'club_id' => $club, 'team_id' => $team, 'title' => 'Keys ' . $i, 'session_date' => '1999-04-1' . $i,
                'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
            ] );
        }
        wp_cache_flush();
    }

    public function tear_down(): void {
        $fact = FactRegistry::find( 'activities' );
        if ( $fact !== null ) unset( $fact->aliases['squad_id'] );
        wp_cache_flush();
        parent::tear_down();
    }

    public function test_a_filter_on_a_dimension_the_fact_does_not_have_is_refused(): void {
        try {
            FactQuery::run( 'goals', [], [ 'count' ], [ 'decided_by_eq' => 5 ], FactScope::unrestricted() );
            $this->fail( 'decided_by_eq on goals ran' );
        } catch ( UnknownFactKeyException $e ) {
            $this->assertSame( [ 'fact' => 'goals', 'key' => 'decided_by', 'kind' => 'filter' ], $e->details() );
            $this->assertStringContainsString( 'decided_by', $e->readerMessage() );
        }
    }

    public function test_an_unknown_dimension_a_filter_without_an_operator_and_every_entry_point_refuse(): void {
        $calls = [
            'group by'      => static fn() => FactQuery::run( 'activities', [ 'no_such_dimension' ], [ 'count' ], [], FactScope::unrestricted() ),
            'no operator'   => static fn() => FactQuery::run( 'activities', [], [ 'count' ], [ 'team_id' => 3 ], FactScope::unrestricted() ),
            'drilldown'     => static fn() => FactQuery::rows( 'activities', [ 'decided_by_eq' => 5 ], FactScope::unrestricted() ),
            'drilldown cnt' => static fn() => FactQuery::countRows( 'activities', [ 'decided_by_eq' => 5 ], FactScope::unrestricted() ),
        ];
        foreach ( $calls as $label => $call ) {
            try {
                $call();
                $this->fail( "{$label} ran" );
            } catch ( UnknownFactKeyException $e ) {
                $this->assertSame( 'activities', $e->factKey, $label );
            }
        }
    }

    public function test_an_aliased_old_key_reads_what_the_new_key_reads(): void {
        $fact = FactRegistry::find( 'activities' );
        $this->assertNotNull( $fact );
        $fact->aliases['squad_id'] = 'team_id';

        $window = [ 'date_after' => '1999-04-01', 'date_before' => '1999-04-30' ];
        $count  = static function ( array $filters ) use ( $window ): int {
            wp_cache_flush();
            $rows = FactQuery::run( 'activities', [], [ 'count' ], $window + $filters, FactScope::unrestricted() );
            return (int) ( $rows[0]->count ?? 0 );
        };

        $new = $count( [ 'team_id_eq' => $this->team_a ] );
        $old = $count( [ 'squad_id_eq' => $this->team_a ] );
        $this->assertSame( 2, $new, 'Precondition: two of team A\'s activities are in the window.' );
        $this->assertSame( $new, $old, 'the alias reads the same rows' );
        $this->assertSame( 3, $count( [] ), 'and both narrow: the window holds three' );

        $grouped = FactQuery::run( 'activities', [ 'squad_id' ], [ 'count' ], $window, FactScope::unrestricted() );
        $this->assertCount( 2, $grouped, 'grouping by the old key groups by the new one' );
    }

    public function test_the_explorer_refuses_a_filter_its_kpi_does_not_offer(): void {
        $admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );
        \TT\Modules\License\LicenseMode::overrideForTests( true );
        \TT\Modules\License\LicenseGate::overrideTierForTests( \TT\Modules\License\FeatureMap::TIER_PRO );
        $_GET = [ 'tt_view' => 'explore', 'kpi' => 'fact_player_goal_completion_rate', 'filter_decided_by_eq' => '5' ];
        try {
            ob_start();
            \TT\Modules\Analytics\Frontend\FrontendExploreView::render( $admin, true );
            $out = (string) ob_get_clean();
        } finally {
            $_GET = [];
            \TT\Modules\License\LicenseMode::overrideForTests( null );
            \TT\Modules\License\LicenseGate::overrideTierForTests( null );
            wp_set_current_user( 0 );
        }
        $this->assertStringContainsString( 'decided_by', $out );
        $this->assertStringContainsString( 'no longer exists', $out, 'the page says why instead of answering for every row' );
        $this->assertStringNotContainsString( 'tt-explore-head', $out );
    }

    /**
     * `ExplorerUrl::build()` writes `filter_player_id=5`, with no operator.
     * The engine used to skip that key, so a player's explore link showed
     * the whole academy; it is an equality filter.
     */
    public function test_an_explorer_link_without_an_operator_narrows(): void {
        $kpi = \TT\Modules\Analytics\KpiRegistry::find( 'evaluations_received' );
        $this->assertNotNull( $kpi );
        $_GET = [ 'tt_view' => 'explore', 'kpi' => 'evaluations_received', 'filter_player_id' => '5', 'filter_team_id' => [ '7', '8' ] ];
        try {
            $method = new \ReflectionMethod( \TT\Modules\Analytics\Frontend\FrontendExploreView::class, 'filtersFromRequest' );
            $method->setAccessible( true );
            $filters = $method->invoke( null, $kpi );
        } finally {
            $_GET = [];
        }
        $this->assertSame( '5', $filters['player_id_eq'] ?? null );
        $this->assertSame( [ '7', '8' ], $filters['team_id_in'] ?? null );
        $this->assertArrayNotHasKey( 'player_id', $filters, 'no key without an operator reaches the engine' );
    }

    public function test_one_of_none_is_no_rows(): void {
        $rows = FactQuery::run( 'activities', [], [ 'count' ], [ 'team_id_in' => [], 'date_after' => '1999-04-01', 'date_before' => '1999-04-30' ], FactScope::unrestricted() );
        $this->assertSame( 0, (int) ( $rows[0]->count ?? 0 ), 'an empty list narrows to nothing, never to everything' );
    }
}
