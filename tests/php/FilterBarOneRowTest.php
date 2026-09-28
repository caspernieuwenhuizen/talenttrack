<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\FilterBar;

/**
 * #4147 — the goals list filter bar on one row.
 *
 * Three markup changes carry it: a status group's seeded default raises no
 * chip, the inline Clear waits until something is filtered, and a
 * `date_range` group can collapse to one pill trigger on the inline bar
 * while the sheet keeps its two inputs.
 */
final class FilterBarOneRowTest extends WP_UnitTestCase {

    /** @return array<string,mixed> */
    private function statusGroup( string $active ): array {
        $options = [];
        foreach ( [ 'active' => 'Active', 'achieved' => 'Achieved', 'missed' => 'Missed' ] as $value => $label ) {
            $options[] = [
                'value'  => $value,
                'label'  => $label,
                'url'    => '/?filter%5Bstatus%5D=' . $value,
                'active' => $value === $active,
                'dot'    => $value,
            ];
        }
        return [
            'type'          => 'status',
            'key'           => 'status',
            'label'         => 'Status',
            'options'       => $options,
            'default_value' => 'active',
        ];
    }

    /** @param array<int,array<string,mixed>> $groups */
    private function render( array $groups ): string {
        return FilterBar::html( [
            'reset_url' => '/?reset=1',
            'groups'    => $groups,
        ] );
    }

    public function test_the_seeded_default_status_raises_no_chip(): void {
        $html = $this->render( [ $this->statusGroup( 'active' ) ] );

        $this->assertStringNotContainsString( 'Status: Active', $html );
        $this->assertStringNotContainsString( 'tt-filterbtn__badge', $html );
    }

    public function test_a_changed_status_chips_and_clears_back_to_the_default(): void {
        $html = $this->render( [ $this->statusGroup( 'achieved' ) ] );

        $this->assertStringContainsString( 'Status: Achieved', $html );
        $this->assertMatchesRegularExpression(
            '/class="tt-chip__clear" href="[^"]*filter%5Bstatus%5D=active"/',
            $html
        );
    }

    public function test_clear_is_hidden_until_something_is_filtered(): void {
        $idle = $this->render( [ $this->statusGroup( 'active' ) ] );
        $this->assertMatchesRegularExpression( '/tt-filterbar__clear"[^>]*data-tt-filter-clear hidden>/', $idle );

        $filtered = $this->render( [ $this->statusGroup( 'achieved' ) ] );
        $this->assertMatchesRegularExpression( '/tt-filterbar__clear"[^>]*data-tt-filter-clear>/', $filtered );
    }

    public function test_the_inline_status_dropdown_names_the_active_status(): void {
        $html = $this->render( [ $this->statusGroup( 'achieved' ) ] );

        $this->assertStringContainsString( 'tt-statdrop', $html );
        $this->assertMatchesRegularExpression(
            '/tt-perdrop--status"[^>]*>.*?<span class="tt-perdrop__label">Achieved<\/span>/s',
            $html
        );
        // The sheet renders the pills only.
        $sheet = (string) strstr( $html, 'tt-filter-sheet__body' );
        $this->assertStringNotContainsString( 'tt-statdrop', $sheet );
        $this->assertStringContainsString( 'tt-statpill', $sheet );
    }

    /** @return array<string,mixed> */
    private function dueGroup( string $from, string $to ): array {
        return [
            'type'         => 'date_range',
            'key'          => 'due',
            'label'        => 'Deadline',
            'from'         => [ 'name' => 'filter[due_from]', 'value' => $from ],
            'to'           => [ 'name' => 'filter[due_to]', 'value' => $to ],
            'default_from' => '',
            'default_to'   => '',
            'compact'      => true,
        ];
    }

    public function test_a_compact_range_is_one_trigger_inline_and_two_inputs_in_the_sheet(): void {
        $html   = $this->render( [ $this->dueGroup( '', '' ) ] );
        $inline = (string) strstr( $html, 'tt-filter-sheet', true );
        $sheet  = (string) strstr( $html, 'tt-filter-sheet__body' );

        $this->assertStringContainsString( 'tt-fildrop', $inline );
        $this->assertStringContainsString( '<span class="tt-perdrop__label">All</span>', $inline );
        $this->assertStringNotContainsString( 'tt-fildaterange', $inline );

        $this->assertStringContainsString( 'tt-fildaterange', $sheet );
        $this->assertSame( 2, substr_count( $sheet, 'tt-fildate__input' ) );
        $this->assertStringNotContainsString( 'tt-fildate__apply', $sheet );
    }

    public function test_a_set_compact_range_reads_on_the_trigger_and_chips(): void {
        $html = $this->render( [ $this->dueGroup( '2026-01-01', '2026-03-31' ) ] );

        $this->assertStringContainsString( '2026-01-01 – 2026-03-31</span>', $html );
        $this->assertStringContainsString( 'Deadline: 2026-01-01 – 2026-03-31', $html );
    }

    public function test_apply_is_the_secondary_button(): void {
        $html = $this->render( [ $this->dueGroup( '', '' ) ] );

        $this->assertStringContainsString( 'tt-btn tt-btn-secondary tt-fildate__apply', $html );
        $this->assertStringNotContainsString( 'tt-btn-primary tt-fildate__apply', $html );
    }
}
