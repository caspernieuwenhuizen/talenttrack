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
            '/<a class="tt-chip__clear" href="[^"]*tt_view=minutes-report-team"/',
            $html
        );
    }

    /* ---- step 2: a seeded window is not a filter ---------------------- */

    public function test_the_shared_period_group_declares_its_default_window(): void {
        $defaults = ReportFilters::seasonDefaultWindow();
        $group = ReportFilters::periodGroup( $this->options( '' ), '', $defaults['from'], $defaults['to'] );

        $this->assertSame( $defaults['from'], $group['default_from'] ?? null );
        $this->assertSame( $defaults['to'], $group['default_to'] ?? null );
    }

    public function test_the_seeded_default_window_raises_no_chip(): void {
        $defaults = ReportFilters::seasonDefaultWindow();
        $html = $this->render( [
            ReportFilters::periodGroup( $this->options( '' ), '', $defaults['from'], $defaults['to'] ),
        ] );

        // The whole reason `date_range` was never derived: a chip here would
        // be permanent, and its ✕ would do nothing a reader could see.
        $this->assertStringNotContainsString( 'tt-chip__label', $html );
    }

    public function test_a_reader_chosen_window_raises_a_chip(): void {
        $html = $this->render( [
            ReportFilters::periodGroup( $this->options( '' ), '', '2026-03-01', '2026-03-31' ),
        ] );

        $this->assertStringContainsString( '2026-03-01', $html );
        $this->assertStringContainsString( 'tt-chip__label', $html );
    }

    /**
     * #3293's custom-range chip must survive the retirement of the
     * hand-rolled arrays — losing it is the specific regression this
     * prerequisite exists to prevent.
     */
    public function test_the_custom_range_chip_reads_as_it_did(): void {
        $html = $this->render( [
            ReportFilters::periodGroup( $this->options( '' ), '', '2026-03-01', '2026-03-31' ),
        ] );

        $expected = ReportFilters::customRangeChip( '', '2026-03-01', '2026-03-31' );
        $this->assertNotNull( $expected );
        $this->assertStringContainsString( 'Period: ' . $expected, $html );
    }

    /** Its ✕ returns to the default window, not to an empty range. */
    public function test_the_range_chip_clears_back_to_the_default(): void {
        $html = $this->render( [
            ReportFilters::periodGroup( $this->options( '' ), '', '2026-03-01', '2026-03-31' ),
        ] );

        preg_match( '/<a class="tt-chip__clear" href="([^"]+)"/', $html, $m );
        $this->assertNotEmpty( $m );
        $url = html_entity_decode( $m[1] );
        $this->assertStringNotContainsString( 'from=', $url );
        $this->assertStringNotContainsString( 'period=', $url );
    }

    /**
     * A preset and a window cannot both be chipped: the preset already
     * describes the window, and a second chip would say it twice. `This
     * season` is the trap — its window is the season's own end date while
     * the seeded default ends today, so the two never compare equal.
     */
    public function test_an_active_preset_suppresses_the_window_chip(): void {
        $window = ReportFilters::periodWindow( 'this_season', gmdate( 'Y-m-d' ) );
        if ( $window === null ) {
            $this->markTestSkipped( 'No current season configured in this environment.' );
        }

        $html = $this->render( [
            ReportFilters::periodGroup(
                $this->options( 'this_season' ),
                'this_season',
                $window['from'],
                $window['to']
            ),
        ] );

        $this->assertSame(
            1,
            substr_count( $html, 'tt-chip__label' ),
            'A preset and its own window were chipped separately.'
        );
        $this->assertStringContainsString( 'Period: This season', $html );
    }

    /* ---- step 2: a standalone date_range opts in the same way --------- */

    /**
     * The declaration is the opt-in, not its value. A surface that seeds
     * nothing — the comparison view — declares empty defaults, and every
     * value it holds is then a filter the reader typed.
     */
    public function test_a_date_range_that_declared_no_seeded_default_chips(): void {
        $html = $this->render( [ $this->dateRange( '2026-03-01', '2026-03-31', true ) ] );

        $this->assertStringContainsString( 'Date: 2026-03-01 – 2026-03-31', $html );
    }

    public function test_a_date_range_chip_clears_both_bounds(): void {
        $_SERVER['REQUEST_URI'] = '/dash/?tt_view=compare&date_from=2026-03-01&date_to=2026-03-31';
        $html = $this->render( [ $this->dateRange( '2026-03-01', '2026-03-31', true ) ] );

        preg_match( '/<a class="tt-chip__clear" href="([^"]+)"/', $html, $m );
        $this->assertNotEmpty( $m );
        $url = html_entity_decode( $m[1] );
        $this->assertStringNotContainsString( 'date_from', $url );
        $this->assertStringNotContainsString( 'date_to', $url );
    }

    /**
     * A group that declares nothing stays underived — the constraint
     * `activeChips()` was written around, kept rather than removed.
     */
    public function test_a_date_range_that_declared_nothing_is_not_derived(): void {
        $html = $this->render( [ $this->dateRange( '2026-03-01', '2026-03-31', false ) ] );

        $this->assertStringNotContainsString( '2026-03-01 – 2026-03-31', $html );
    }

    /* ---- step 3: derivation is the only path ------------------------- */

    /**
     * The regression this refactor exists to make impossible. A caller's own
     * list is a second source of truth for "what is filtered", and the
     * fourteen that had one had drifted from the groups beside it.
     */
    public function test_no_surface_passes_its_own_chips(): void {
        $root = dirname( __DIR__, 2 ) . '/src';
        $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );

        $offenders = [];
        foreach ( $files as $file ) {
            if ( ! $file->isFile() || $file->getExtension() !== 'php' ) continue;
            $source = (string) file_get_contents( $file->getPathname() );
            if ( preg_match( "/'(?:chips|active_count)'\s*=>/", $source ) ) {
                $offenders[] = str_replace( $root, 'src', $file->getPathname() );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "FilterBar derives its chips from the groups. Declare `default_value` / "
            . "`default_from` / `default_to` / `selected_label` on the group instead, "
            . "or `chip => false` for a control that is not a filter."
        );
    }

    /** @return array<string,mixed> */
    private function dateRange( string $from, string $to, bool $declare ): array {
        $group = [
            'type'  => 'date_range',
            'key'   => 'date',
            'label' => 'Date',
            'from'  => [ 'name' => 'date_from', 'value' => $from ],
            'to'    => [ 'name' => 'date_to',   'value' => $to ],
        ];
        if ( $declare ) {
            $group['default_from'] = '';
            $group['default_to']   = '';
        }
        return $group;
    }
}
