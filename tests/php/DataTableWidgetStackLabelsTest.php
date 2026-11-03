<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Registry\TableRowSource;
use TT\Modules\PersonaDashboard\Registry\TableRowSourceRegistry;
use TT\Modules\PersonaDashboard\Widgets\DataTableWidget;

/**
 * #4792 — every data-table widget cell carries its column's label, so on a
 * phone the stacked row shows each value with its label (persona-dashboard.css).
 * Checked for the audit events preset and for one other.
 */
final class DataTableWidgetStackLabelsTest extends WP_UnitTestCase {

    /** @var array<string, TableRowSource|null> */
    private array $saved = [];

    public function tear_down(): void {
        foreach ( $this->saved as $preset => $source ) {
            if ( $source !== null ) TableRowSourceRegistry::register( $preset, $source );
        }
        parent::tear_down();
    }

    /** @return array<string, array{string, list<string>, list<string>}> */
    public function presets(): array {
        return [
            'audit events'      => [ 'audit_log_recent', [ 'When', 'Who', 'What' ], [ '2 Nov 10:00', 'Admin', 'Player updated' ] ],
            'behaviour pending' => [ 'behaviour_pending', [ 'Player', 'Team', 'Since' ], [ 'Sem Visser', 'U14', '20 days' ] ],
        ];
    }

    /**
     * @dataProvider presets
     * @param list<string> $labels
     * @param list<string> $row
     */
    public function test_each_cell_is_labelled_with_its_column( string $preset, array $labels, array $row ): void {
        $this->stub( $preset, [ $row, $row ] );

        $html = ( new DataTableWidget() )->render(
            new WidgetSlot( 'data_table', $preset ),
            new RenderContext( 1, 1, 'academy_admin', home_url( '/' ) )
        );

        preg_match_all( '#<td role="cell" data-label="([^"]*)">([^<]*)</td>#', $html, $m );
        $this->assertSame( array_merge( $labels, $labels ), $m[1], 'two rows, each cell with its header as data-label' );
        $this->assertSame( array_merge( $row, $row ), $m[2] );
        // #4810 — the table is the stack-table primitive, with explicit roles.
        $this->assertStringContainsString( '<table class="tt-pd-table tt-stack-table tt-stack-table--to-480" role="table">', $html );
        $this->assertSame( count( $labels ), substr_count( $html, '<th role="columnheader">' ) );
        $this->assertSame( 3, substr_count( $html, '<tr role="row">' ), 'the header row and two data rows' );
    }

    public function test_the_empty_row_carries_no_label(): void {
        $this->stub( 'audit_log_recent', [] );

        $html = ( new DataTableWidget() )->render(
            new WidgetSlot( 'data_table', 'audit_log_recent' ),
            new RenderContext( 1, 1, 'academy_admin', home_url( '/' ) )
        );

        $this->assertStringContainsString( 'class="tt-pd-table-empty" role="cell" data-label=""', $html );
        $this->assertSame( 1, substr_count( $html, 'data-label=' ), 'only the empty cell, with no label' );
    }

    /** @param list<list<string>> $rows */
    private function stub( string $preset, array $rows ): void {
        if ( ! array_key_exists( $preset, $this->saved ) ) {
            $this->saved[ $preset ] = TableRowSourceRegistry::resolve( $preset );
        }
        TableRowSourceRegistry::register( $preset, new class( $rows ) implements TableRowSource {
            /** @var list<list<string>> */
            private array $rows;
            /** @param list<list<string>> $rows */
            public function __construct( array $rows ) { $this->rows = $rows; }
            public function rowsFor( int $user_id, array $config ): array { return $this->rows; }
        } );
    }
}
