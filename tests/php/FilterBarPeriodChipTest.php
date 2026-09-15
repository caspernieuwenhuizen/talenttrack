<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Shared\Frontend\Components\FilterBar;

/**
 * #3346 — what the bar has to know before it can derive a time filter's chip.
 *
 * `FilterBar::activeChips()` reads a group's options and active state, which
 * is enough for a select or a toggle and not enough for a window. Two things
 * were missing, and both are about telling "the reader chose this" from
 * "this is where the surface opens":
 *
 *  - a `period` group declaring no `default_value` would chip its own default
 *    the moment the page loaded — the inversion #3320 had to fix once already
 *    on the alerts inbox;
 *  - a custom From/To is seeded by every report, so "has a value" does not
 *    mean "filtered". Without a declared default window, a derived chip would
 *    be permanent and unremovable-in-effect, which is why `activeChips()`
 *    deliberately did not derive one.
 */
final class FilterBarPeriodChipTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        $_SERVER['REQUEST_URI'] = '/dash/?tt_view=minutes-report-team';
    }

    /** The preset links every report offers, with one of them marked active. */
    private function options( string $active ): array {
        $out = [];
        foreach ( ReportFilters::periodLabels() as $key => $label ) {
            $out[] = [
                'value'  => (string) $key,
                'label'  => (string) $label,
                'url'    => '/dash/?tt_view=minutes-report-team'
                    . ( $key === '' ? '' : '&period=' . $key ),
                'active' => ( (string) $key === $active ),
            ];
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $groups */
    private function render( array $groups ): string {
        return FilterBar::html( [
            'form_action' => '/dash/',
            'groups'      => $groups,
        ] );
    }

    /* ---- step 1: the default is declared ------------------------------ */

    public function test_the_shared_period_group_declares_its_default(): void {
        $group = ReportFilters::periodGroup( $this->options( '' ), '', '2026-08-01', '2026-09-15' );

        $this->assertArrayHasKey( 'default_value', $group );
        $this->assertSame( '', $group['default_value'], 'These surfaces open on no preset at all.' );
    }

    public function test_a_default_period_raises_no_chip(): void {
        $defaults = ReportFilters::seasonDefaultWindow();
        $html = $this->render( [
            ReportFilters::periodGroup( $this->options( '' ), '', $defaults['from'], $defaults['to'] ),
        ] );

        $this->assertStringNotContainsString( 'tt-chip__label', $html );
    }

    public function test_a_chosen_period_raises_a_removable_chip(): void {
        $window = ReportFilters::periodWindow( 'last_week', gmdate( 'Y-m-d' ) );
        $html = $this->render( [
            ReportFilters::periodGroup(
                $this->options( 'last_week' ),
                'last_week',
                (string) ( $window['from'] ?? '' ),
                (string) ( $window['to'] ?? '' )
            ),
        ] );

        $this->assertStringContainsString( 'Period: Last week', $html );
        // The ✕ goes back to the default option's own URL, not to a
        // param-free URL some other group owns.
        $this->assertMatchesRegularExpression(
            '/tt-chip__clear[^"]*href="[^"]*tt_view=minutes-report-team"/',
            $html
        );
    }

}
