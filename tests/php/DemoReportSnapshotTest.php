<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\DemoData\DemoCoverage;
use TT\Modules\DemoData\Generators\TeamReportSnapshotGenerator;

/**
 * #3539 — the demo academy carries one frozen monthly report.
 *
 * The substance is that the payload is **composed**, not hand-written. A
 * hand-written `data_json` drifts from the real report shape the moment a block
 * changes, and a demo that has quietly stopped matching production is worse
 * than no demo. The manifest wiring is asserted alongside it, because a
 * generated table that no cascade removes is a table a demo wipe leaves behind.
 */
final class DemoReportSnapshotTest extends WP_UnitTestCase {

    public function test_the_table_is_generated_rather_than_planned(): void {
        $entry = DemoCoverage::MANIFEST['tt_team_report_snapshots'] ?? [];

        $this->assertArrayNotHasKey( 'planned', $entry );
        $this->assertArrayNotHasKey( 'exempt', $entry );
        $this->assertSame( TeamReportSnapshotGenerator::class, $entry['written_by'] ?? '' );
        $this->assertSame( 'team_report_snapshot', $entry['entity_type'] ?? '' );
    }

    /**
     * The payload is a composed report, so it is empty without the rows it
     * summarises. Declaring that is what keeps the generator behind them.
     */
    public function test_it_depends_on_what_the_report_summarises(): void {
        $depends = DemoCoverage::MANIFEST['tt_team_report_snapshots']['depends_on'] ?? [];

        foreach ( [ 'team', 'activity', 'attendance', 'evaluation' ] as $needed ) {
            $this->assertContains( $needed, $depends );
        }
    }

    public function test_a_cascade_can_wipe_it(): void {
        // A generated table with no cascade entry can never be removed by a
        // demo wipe — the coverage gate checks this separately, and so does
        // this, because it is the kind of thing that only shows up on a
        // second demo run.
        $category = DemoCoverage::MANIFEST['tt_team_report_snapshots']['category'] ?? '';
        $cascade  = DemoCoverage::CATEGORIES[ $category ]['cascade'] ?? [];

        $this->assertContains( 'team_report_snapshot', $cascade );
    }

    /**
     * Appended, not inserted. Every generator before it must keep drawing the
     * same values from the seeded stream, or the same (seed, preset) stops
     * reproducing the same academy (#3242).
     */
    public function test_it_runs_last(): void {
        $category = DemoCoverage::MANIFEST['tt_team_report_snapshots']['category'] ?? '';
        $mine     = (int) ( DemoCoverage::CATEGORIES[ $category ]['run_order'] ?? 0 );

        foreach ( DemoCoverage::CATEGORIES as $key => $spec ) {
            if ( $key === $category ) continue;
            $this->assertLessThan(
                $mine,
                (int) ( $spec['run_order'] ?? 0 ),
                "{$key} must run before the snapshot it would otherwise be missing from"
            );
        }
    }

    public function test_the_category_is_named_for_an_operator(): void {
        // The wipe form lists categories by label; an unlabelled one shows up
        // as a bare key next to a checkbox nobody can interpret.
        $category = DemoCoverage::MANIFEST['tt_team_report_snapshots']['category'] ?? '';
        $label    = DemoCoverage::categoryLabel( $category );

        $this->assertNotSame( '', $label );
        $this->assertNotSame( $category, $label );
    }
}
