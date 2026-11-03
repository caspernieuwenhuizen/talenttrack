<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Domain\UnknownFactKeyException;
use TT\Modules\Analytics\FactQuery;
use TT\Modules\Analytics\FactRegistry;
use TT\Modules\Analytics\KpiRegistry;

/**
 * #4860 — a fact's dimension keys are a contract. Saved Report Studio
 * blocks, explorer links and KPI filters name them, so a key is never
 * renamed in place: a rename declares an alias (old key => new key) on the
 * fact, and this test then accepts it.
 *
 * - Every key pinned here must still be readable: declared, or aliased to a
 *   declared key. An unaliased rename fails.
 * - Every declared key must be pinned. Adding a key means adding it here,
 *   which is the moment to decide it is public.
 * - Every KPI's own filters and dimensions must be ones its fact knows.
 */
final class FactKeyContractTest extends WP_UnitTestCase {

    /** @var array<string, list<string>> fact key => its public dimension keys */
    private const PINNED = [
        'attendance'              => [ 'player_id', 'team_id', 'activity_type', 'status', 'month', 'week' ],
        'activities'              => [ 'team_id', 'activity_type', 'status', 'plan_state', 'month' ],
        'evaluations'             => [ 'player_id', 'evaluator_id', 'team_id', 'month' ],
        'goals'                   => [ 'player_id', 'status', 'priority', 'month' ],
        'trial_decisions'         => [ 'decision', 'decided_by', 'month' ],
        'prospects'               => [ 'discovered_by_user_id', 'current_club', 'month' ],
        'journey_events'          => [ 'event_type', 'player_id', 'month' ],
        'evaluations_per_session' => [ 'evaluator_id', 'activity_id', 'month' ],
    ];

    public function test_every_pinned_key_is_still_readable(): void {
        foreach ( self::PINNED as $fact_key => $keys ) {
            $fact = FactRegistry::find( $fact_key );
            $this->assertNotNull( $fact, "the fact {$fact_key} is gone; retire it with its readers, not by deleting it" );
            foreach ( $keys as $key ) {
                $this->assertNotNull(
                    $fact->dimension( $fact->resolveKey( $key ) ),
                    "{$fact_key}.{$key} was renamed or removed without an alias; declare `aliases: [ '{$key}' => '<new key>' ]` on the fact"
                );
            }
        }
    }

    public function test_every_declared_key_is_pinned(): void {
        foreach ( FactRegistry::all() as $fact ) {
            $pinned = self::PINNED[ $fact->key ] ?? null;
            $this->assertNotNull( $pinned, "the fact {$fact->key} is not pinned in " . self::class );
            foreach ( $fact->dimensions as $dimension ) {
                $this->assertContains( $dimension->key, $pinned, "{$fact->key}.{$dimension->key} is new; pin it here once it is meant to be public" );
            }
        }
    }

    public function test_an_alias_points_at_a_declared_key(): void {
        foreach ( FactRegistry::all() as $fact ) {
            foreach ( $fact->aliases as $old => $new ) {
                $this->assertNotNull( $fact->dimension( $new ), "{$fact->key}: the alias {$old} points at {$new}, which the fact does not declare" );
                $this->assertNull( $fact->dimension( $old ), "{$fact->key}: {$old} is both declared and aliased" );
            }
        }
    }

    public function test_every_kpi_names_only_keys_its_fact_knows(): void {
        foreach ( KpiRegistry::all() as $kpi ) {
            $fact = FactRegistry::find( $kpi->factKey );
            $this->assertNotNull( $fact, "{$kpi->key}: no fact {$kpi->factKey}" );
            try {
                FactQuery::resolveKeys( $fact, $kpi->exploreDimensions, $kpi->defaultFilters );
            } catch ( UnknownFactKeyException $e ) {
                $this->fail( "{$kpi->key}: " . $e->getMessage() );
            }
            if ( $kpi->primaryDimension !== null && $kpi->primaryDimension !== '' ) {
                $this->assertNotNull( $fact->dimension( $fact->resolveKey( $kpi->primaryDimension ) ), "{$kpi->key}: primary dimension {$kpi->primaryDimension}" );
            }
        }
    }
}
