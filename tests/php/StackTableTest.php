<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\StackTable;

/**
 * #4810 — StackTable writes the stack-table primitive's classes, roles and
 * cell labels in one place.
 */
final class StackTableTest extends WP_UnitTestCase {

    public function test_the_classes_carry_the_breakpoint_modifier(): void {
        $this->assertSame( 'tt-stack-table tt-stack-table--to-480', StackTable::classes( StackTable::TO_480 ) );
        $this->assertSame( 'tt-stack-table tt-stack-table--to-768', StackTable::classes( StackTable::TO_768 ) );
        $this->assertSame( 'tt-stack-table', StackTable::classes( StackTable::ALWAYS ) );
        $this->assertSame( 'tt-stack-table', StackTable::classes( 600 ), 'only the standard breakpoints' );
    }

    public function test_each_part_gets_its_role(): void {
        $this->assertSame( ' role="table"', StackTable::attrs( 'table' ) );
        $this->assertSame( ' role="rowgroup"', StackTable::attrs( 'rowgroup' ) );
        $this->assertSame( ' role="row"', StackTable::attrs( 'row' ) );
        $this->assertSame( ' role="columnheader"', StackTable::attrs( 'columnheader' ) );
        $this->assertSame( '', StackTable::attrs( 'banner' ), 'an unknown part writes nothing' );
    }

    public function test_a_cell_carries_its_label_escaped(): void {
        $this->assertSame( ' role="cell" data-label="Wat"', StackTable::attrs( 'cell', 'Wat' ) );
        $this->assertSame( ' role="cell" data-label=""', StackTable::attrs( 'cell' ) );
        $this->assertSame( ' role="cell" data-label="&quot;Rol&quot; &amp; team"', StackTable::attrs( 'cell', '"Rol" & team' ) );
    }

    public function test_only_a_cell_takes_a_label(): void {
        $this->assertSame( ' role="row"', StackTable::attrs( 'row', 'ignored' ) );
    }
}
