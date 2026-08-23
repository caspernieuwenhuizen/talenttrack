<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Modules\Export\Exporters\MatchDayTeamSheetPdfExporter;

/**
 * #2769 — the referee's team sheet and the coach's own export are separate
 * decisions, so they need separate switches.
 *
 * One flag gated both, which meant an academy that files match forms
 * digitally could only hide the referee sheet by also losing the sheet the
 * coach takes to the touchline.
 *
 * The key name carries weight beyond naming: `ExportService::run()` gates on
 * `export_<exporterKey>`, so `export_match_day_team_sheet` is what makes the
 * server-side exporter on `?tt_view=exports` honour the toggle. Before this
 * it ran ungated, because an unknown key reads as enabled — a name that
 * looked right but did not match would silently restore that.
 */
final class MatchDayTeamSheetToggleTest extends WP_UnitTestCase {

    private const FLAG = 'export_match_day_team_sheet';

    public function tear_down(): void {
        FeatureRegistry::setEnabled( self::FLAG, true );
        FeatureRegistry::setEnabled( 'export_match_prep_pdf', true );
        parent::tear_down();
    }

    /** @return array<string, array<string, mixed>> keyed by feature key */
    private function catalog(): array {
        $out = [];
        foreach ( FeatureRegistry::allWithState() as $row ) {
            $out[ (string) $row['key'] ] = $row;
        }

        return $out;
    }

    public function test_the_feature_is_registered_and_on_by_default(): void {
        $this->assertTrue( FeatureRegistry::exists( self::FLAG ) );

        $catalog = $this->catalog();
        $this->assertArrayHasKey( self::FLAG, $catalog );
        $this->assertTrue( (bool) $catalog[ self::FLAG ]['default_enabled'] );
    }

    /**
     * The key must match the exporter's, or the export page keeps offering
     * a sheet the academy switched off.
     */
    public function test_the_key_matches_the_exporter_export_service_gates_on(): void {
        $this->assertSame(
            'export_' . ( new MatchDayTeamSheetPdfExporter() )->key(),
            self::FLAG
        );
    }

    /** Turning one off must leave the other alone. */
    public function test_the_two_exports_switch_independently(): void {
        FeatureRegistry::setEnabled( self::FLAG, false );
        FeatureRegistry::setEnabled( 'export_match_prep_pdf', true );

        $this->assertFalse( FeatureRegistry::isEnabled( self::FLAG ) );
        $this->assertTrue( FeatureRegistry::isEnabled( 'export_match_prep_pdf' ) );

        FeatureRegistry::setEnabled( self::FLAG, true );
        FeatureRegistry::setEnabled( 'export_match_prep_pdf', false );

        $this->assertTrue( FeatureRegistry::isEnabled( self::FLAG ) );
        $this->assertFalse( FeatureRegistry::isEnabled( 'export_match_prep_pdf' ) );
    }

    /**
     * It belongs to Match prep: the sheet is built from the match-prep
     * line-up, so it is meaningless without that module.
     */
    public function test_it_belongs_to_the_match_prep_module(): void {
        $catalog = $this->catalog();

        $this->assertSame(
            (string) $catalog['export_match_prep_pdf']['module_class'],
            (string) $catalog[ self::FLAG ]['module_class']
        );
    }

    /**
     * Both descriptions have to say the other is separate, because whoever
     * reads one came here to turn it off and needs to know the other
     * survives.
     */
    public function test_both_descriptions_say_the_other_is_a_separate_setting(): void {
        $catalog = $this->catalog();

        $this->assertStringContainsString( 'separate setting', (string) $catalog[ self::FLAG ]['description'] );
        $this->assertStringContainsString( 'separate setting', (string) $catalog['export_match_prep_pdf']['description'] );
    }
}
