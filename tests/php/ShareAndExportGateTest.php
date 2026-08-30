<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\UpgradePanel;

/**
 * #3108 — slice 5, and the end of #3017.
 *
 * Six features reached by a URL somebody was handed: three print routes and
 * three signed share links. The check belongs at the **router**, not at the
 * button that generated the link — a gate on the button leaves every link
 * already in circulation working, which is not a gate. That property is
 * what most of this file pins.
 */
final class ShareAndExportGateTest extends WP_UnitTestCase {

    private const SHARES = [
        'match_analysis_sharing'  => 'src/Modules/MatchAnalysis/Frontend/FrontendMatchAnalysisView.php',
        'match_prep_sharing'      => 'src/Modules/MatchPrep/Frontend/FrontendMatchPrepShareView.php',
        'team_blueprints_sharing' => 'src/Modules/TeamDevelopment/Frontend/FrontendTeamBlueprintsView.php',
    ];

    private const EXPORTS = [
        'export_match_analysis_pdf'   => 'src/Modules/MatchAnalysis/Print/MatchAnalysisPrintRouter.php',
        'export_match_prep_pdf'       => 'src/Modules/MatchPrep/Print/MatchPrepPrintRouter.php',
        'export_match_day_team_sheet' => 'src/Modules/MatchPrep/Print/MatchPrepPrintRouter.php',
    ];

    private static function source( string $relative ): string {
        return (string) file_get_contents( TT_PLUGIN_DIR . $relative );
    }

    // ---------------------------------------------------------------
    // the epic's closing condition
    // ---------------------------------------------------------------

    /**
     * The point of the whole epic: the pending list stops carrying the
     * coverage and `FeatureMapGateCoverageTest` carries it alone.
     */
    public function test_every_pro_feature_is_gated_and_only_one_entry_remains(): void {
        /** @var array<string,string> $pending */
        $pending = require TT_PLUGIN_DIR . 'config/license_gate_pending.php';

        $this->assertSame(
            [ 's3_backup' ],
            array_keys( $pending ),
            'the pending list is down to its one justified survivor'
        );
        $this->assertNotSame( '', trim( $pending['s3_backup'] ) );

        $sources = '';
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( TT_PLUGIN_DIR . 'src' )
        );
        foreach ( $rii as $file ) {
            if ( $file->getExtension() === 'php' ) $sources .= file_get_contents( $file->getPathname() );
        }

        $ungated = [];
        foreach ( array_keys( FeatureMap::DEFAULT_MAP[ FeatureMap::TIER_PRO ] ) as $feature ) {
            if ( isset( $pending[ $feature ] ) ) continue;
            $found = false;
            foreach ( [ 'allows', 'can', 'enforceFeatureRest', 'enforceWriteRest' ] as $method ) {
                if ( strpos( $sources, "LicenseGate::{$method}( '{$feature}'" ) !== false ) {
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) $ungated[] = $feature;
        }

        $this->assertSame( [], $ungated, 'every Pro feature has a gate: ' . implode( ', ', $ungated ) );
    }

    // ---------------------------------------------------------------
    // at the router, not at the affordance
    // ---------------------------------------------------------------

    /**
     * The share gate sits in the public render path, **before the token
     * resolves**. Before, so a link that no longer works reveals nothing
     * about whether the record it addressed exists.
     */
    public function test_each_share_link_refuses_before_its_token_resolves(): void {
        $resolvers = [
            'match_analysis_sharing'  => 'MatchAnalysisShareLink::resolve',
            'match_prep_sharing'      => 'MatchPrepShareLink::resolve',
            'team_blueprints_sharing' => 'BlueprintShareToken::verify',
        ];

        foreach ( self::SHARES as $feature => $relative ) {
            $source = self::source( $relative );

            $gate = strpos( $source, "LicenseGate::allows( '{$feature}' )" );
            $this->assertIsInt( $gate, "{$relative} gates on {$feature}" );

            $resolve = strpos( $source, $resolvers[ $feature ] );
            $this->assertIsInt( $resolve, "{$relative} resolves a token" );
            $this->assertLessThan(
                $resolve,
                $gate,
                "{$relative}: the plan is asked before the token, so a revoked link is not an oracle"
            );
        }
    }

    /**
     * A refused link explains itself. It must not 404 — the recipient did
     * nothing wrong — and must not fall through to the shared
     * "not found" wording, which is deliberately identical for a bad token
     * and a missing record and would leave them guessing.
     */
    public function test_a_refused_share_link_explains_itself(): void {
        foreach ( self::SHARES as $feature => $relative ) {
            $source = self::source( $relative );
            $gate   = strpos( $source, "LicenseGate::allows( '{$feature}' )" );
            $this->assertIsInt( $gate );

            $revoked = strpos( $source, 'renderRevokedShareLink()', $gate );
            $this->assertIsInt( $revoked, "{$relative} answers with the revoked-link panel" );

            // The revoked panel is what the gate's own branch reaches, not
            // the shared not-found wording somewhere further down.
            $this->assertLessThan(
                200,
                $revoked - $gate,
                "{$relative}: the revoked panel is inside the plan branch"
            );
        }
    }

    /**
     * The revoked panel addresses somebody outside the club, so it names no
     * plan and links to no account page — neither is theirs to act on — and
     * it names no record, because the point of a link that no longer works
     * is that its contents do not travel.
     */
    public function test_the_revoked_panel_names_no_plan_and_no_record(): void {
        $html = UpgradePanel::renderRevokedShareLink();

        $this->assertStringContainsString( 'tt-upgrade-panel--revoked', $html );
        $this->assertStringNotContainsString( FeatureMap::tierLabel( FeatureMap::TIER_PRO ), $html );
        $this->assertStringNotContainsString( 'tt-upgrade-panel__cta', $html );
        $this->assertStringNotContainsString( 'page=', $html, 'no link into wp-admin for an outside reader' );
    }

    // ---------------------------------------------------------------
    // the exports
    // ---------------------------------------------------------------

    /**
     * The print URLs bypass `ExportService` entirely, which is exactly why
     * the gate has to be on them: a gate anywhere else leaves the URL as
     * the back door. And after the capability check, so someone who could
     * never print this gets the permission answer rather than an upgrade
     * pitch for something they could not use.
     */
    public function test_each_print_route_gates_after_the_capability_check(): void {
        foreach ( self::EXPORTS as $feature => $relative ) {
            $source = self::source( $relative );

            $gate = strpos( $source, "LicenseGate::allows( '{$feature}' )" );
            $this->assertIsInt( $gate, "{$relative} gates on {$feature}" );

            $cap = strpos( $source, 'current_user_can(' );
            $this->assertIsInt( $cap );
            $this->assertLessThan( $gate, $cap, "{$relative}: permission is asked before the plan" );
        }
    }

    /**
     * A plan refusal on a print route is 402, the same status the REST edge
     * uses, so "why did this fail" is answerable from the status line
     * wherever it happened.
     */
    public function test_a_refused_print_route_answers_402(): void {
        foreach ( array_unique( array_values( self::EXPORTS ) ) as $relative ) {
            $source = self::source( $relative );
            $gate   = strpos( $source, 'LicenseGate::allows(' );
            $this->assertIsInt( $gate );
            $this->assertStringContainsString(
                "'response' => 402",
                substr( $source, $gate, 700 ),
                "{$relative}: a plan refusal is 402, not 403"
            );
        }
    }

    /**
     * The team sheet has two doors — the print URL and the export pipeline.
     * Both carry the gate, or the pipeline one is a bypass.
     */
    public function test_the_team_sheet_exporter_carries_the_gate_too(): void {
        $exporter = self::source( 'src/Modules/Export/Exporters/MatchDayTeamSheetPdfExporter.php' );

        $this->assertStringContainsString(
            "LicenseGate::allows( 'export_match_day_team_sheet' )",
            $exporter
        );
        $gate = strpos( $exporter, 'LicenseGate::allows(' );
        $this->assertIsInt( $gate );
        $this->assertStringContainsString( 'ExportException', substr( $exporter, $gate, 400 ) );
    }
}
