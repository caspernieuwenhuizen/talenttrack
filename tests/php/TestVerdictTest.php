<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Measurements\Services\TestVerdict;
use TT\Modules\Measurements\Units\UnitContext;
use TT\Modules\Measurements\Units\UnitRegistry;

/**
 * #4093 — one helper words and formats a test's target and each reading's
 * standing, for the player profile and the team monthly report alike.
 *
 * Pinned: every flag against each direction; no verdict without a direction
 * or on a pass/fail test; a level as its own verdict; the target as the
 * band's better-side edge, in mm:ss on a timed test (not decimal minutes);
 * "no target" on a test without a direction; nothing when the age group has
 * no band.
 */
final class TestVerdictTest extends WP_UnitTestCase {

    public function test_each_flag_reads_the_way_its_direction_says(): void {
        $this->assertSame( [ 'label' => 'on target', 'tone' => 'ok' ], TestVerdict::verdict( 'numeric', 'lower', 'ok' ) );
        $this->assertSame( [ 'label' => 'on target', 'tone' => 'ok' ], TestVerdict::verdict( 'numeric', 'higher', 'ok' ) );

        $this->assertSame( [ 'label' => 'just over target', 'tone' => 'warn' ], TestVerdict::verdict( 'numeric', 'lower', 'warn' ) );
        $this->assertSame( [ 'label' => 'just under target', 'tone' => 'warn' ], TestVerdict::verdict( 'numeric', 'higher', 'warn' ) );

        $this->assertSame( [ 'label' => 'well over target', 'tone' => 'bad' ], TestVerdict::verdict( 'numeric', 'lower', 'bad' ) );
        $this->assertSame( [ 'label' => 'well under target', 'tone' => 'bad' ], TestVerdict::verdict( 'numeric', 'higher', 'bad' ) );

        $this->assertSame( 'tt-meas-chip--warn', TestVerdict::chipClass( 'warn' ) );
    }

    public function test_no_verdict_without_a_better_or_worse(): void {
        $this->assertSame( '', TestVerdict::verdict( 'numeric', 'neutral', 'ok' )['label'], 'a neutral band still flags; the words do not judge it' );
        $this->assertSame( '', TestVerdict::verdict( 'numeric', '', 'bad' )['label'] );
        $this->assertSame( '', TestVerdict::verdict( 'passfail', 'higher', 'ok' )['label'] );
        $this->assertSame( '', TestVerdict::verdict( 'numeric', 'lower', '' )['label'], 'no band for the age group: no chip' );
    }

    public function test_a_level_is_its_own_verdict_and_a_blank_test_says_so(): void {
        $this->assertSame( [ 'label' => 'Gevorderd', 'tone' => 'level' ], TestVerdict::verdict( 'status', '', '', false, 'Gevorderd' ) );
        $this->assertSame( 'not measured yet', TestVerdict::verdict( 'numeric', 'lower', '', true )['label'] );
    }

    public function test_a_timed_target_reads_as_minutes_and_seconds(): void {
        $minutes = ( new UnitRegistry() )->bySymbol( 'min' );
        $this->assertNotNull( $minutes, 'the unit registry carries minutes' );
        $units = UnitContext::forDefinition( (object) [
            'unit' => 'min', 'dimension' => $minutes->dimension, 'entry_unit_id' => (int) $minutes->id,
            'numeric_format' => 'duration', 'value_type' => 'numeric',
        ] );

        // Bands are canonical: 750 seconds is 12:30, never "12,5 min".
        $target = (object) [ 'green_min' => null, 'green_max' => 750.0 ];
        $this->assertSame( [ 'text' => '≤ 12:30', 'absent' => false ], TestVerdict::target( 'numeric', 'lower', $target, $units ) );
    }

    public function test_the_target_is_the_better_side_edge_with_its_unit(): void {
        $cm = ( new UnitRegistry() )->bySymbol( 'cm' );
        $this->assertNotNull( $cm );
        $units = UnitContext::forDefinition( (object) [
            'unit' => 'cm', 'dimension' => $cm->dimension, 'entry_unit_id' => (int) $cm->id,
            'numeric_format' => 'plain', 'value_type' => 'numeric',
        ] );

        // The band stores metres; the reader reads centimetres.
        $target = (object) [ 'green_min' => 0.45, 'green_max' => 0.60 ];
        $this->assertSame( '≥ 45 cm', TestVerdict::target( 'numeric', 'higher', $target, $units )['text'] );
    }

    public function test_no_target_and_no_band_are_different_answers(): void {
        $units = UnitContext::forDefinition( null );

        $this->assertSame( [ 'text' => 'no target', 'absent' => true ], TestVerdict::target( 'numeric', 'neutral', null, $units ) );
        $this->assertSame( [ 'text' => '', 'absent' => false ], TestVerdict::target( 'numeric', 'lower', null, $units ), 'no band for this age group' );
        $this->assertSame( [ 'text' => '', 'absent' => false ], TestVerdict::target( 'status', 'higher', null, $units ) );
    }
}
