<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoGenerator;
use TT\Modules\DemoData\DemoRunState;
use TT\Modules\DemoData\Generators\GeneratorContext;

/**
 * #3775 — one demo run, one clock.
 *
 * A run is a list of steps spread over as many requests, and every date the
 * generators write is derived from a single instant. Each chunk used to build
 * its own calendar from `time()`, so a run that straddled midnight — or a slow
 * one near a week boundary — laid its later steps out against a window one day
 * further on than its earlier ones, and a fixture could end up in a different
 * week from the training that precedes it.
 */
final class DemoRunClockTest extends WP_UnitTestCase {

    /** Tuesday 2026-09-15 00:00 UTC, the instant the other demo tests pin. */
    private const NOW = 1789430400;

    /**
     * A run state with nothing to do, standing in for one mid-flight.
     *
     * @param array<string,mixed> $context
     */
    private function state( array $context = [] ): DemoRunState {
        return DemoRunState::create( 'batch-clock', [ 'teams', 'activities' ], array_merge( [
            'source' => 'procedural',
            'config' => [ 'teams' => 1, 'players_per_team' => 1, 'weeks' => 8 ],
        ], $context ) );
    }

    /** The context `DemoGenerator` hands a generator for one chunk. */
    private function chunkContext( DemoRunState $state ): GeneratorContext {
        $method = new ReflectionMethod( DemoGenerator::class, 'contextFor' );
        $method->setAccessible( true );

        /** @var GeneratorContext $ctx */
        $ctx = $method->invoke( null, $state, new DemoBatchRegistry( $state->batchId() ) );
        return $ctx;
    }

    public function test_a_run_writes_its_clock_down_when_it_starts(): void {
        $state = $this->state();
        $now   = $state->now();
        $state->persist();

        $this->assertGreaterThan( 0, $now );

        $reloaded = DemoRunState::load();
        $this->assertNotNull( $reloaded );
        $this->assertSame( $now, $reloaded->now(), 'the next request reads the clock the run started with' );
    }

    public function test_a_run_that_predates_the_pin_acquires_a_clock_and_keeps_it(): void {
        $state = $this->state( [ 'now' => 0 ] );

        $first = $state->now();
        $this->assertGreaterThan( 0, $first );
        $this->assertSame( $first, $state->now(), 'pinned on first read, not re-read afterwards' );
    }

    public function test_two_chunks_of_one_run_lay_out_the_same_calendar(): void {
        $state = $this->state( [ 'now' => self::NOW ] );

        // Two requests, each rebuilding the context from the same run state —
        // which is exactly what `DemoGenerator::advance()` does per step.
        $first  = $this->chunkContext( $state );
        $second = $this->chunkContext( $state );

        $this->assertSame( self::NOW, $first->calendar()->now(), 'the run\'s clock, not the request\'s' );
        $this->assertSame( self::NOW, $second->calendar()->now() );
        $this->assertSame(
            $first->calendar()->activitySlots(),
            $second->calendar()->activitySlots(),
            'the same slot on the same date in both chunks'
        );

        // The pinned instant is years from whenever this test actually runs,
        // so reaching `time()` anywhere on that path would fail the two
        // assertions above rather than merely making them flaky.
        $this->assertSame( self::NOW, $state->now(), 'the wall clock never re-enters the run' );
    }
}
