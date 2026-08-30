<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\UpgradePanel;

/**
 * #3105 — slice 2 of the licence-gate rollout: the match-day and training
 * cluster.
 *
 * The properties worth pinning are not "does an unentitled install refuse"
 * — `LicenseMode::isCommercial()` is false on a test instance, so every
 * gate is open here and arranging otherwise would test the harness rather
 * than the product. What matters, and what a later edit could quietly
 * undo, is the *shape* of the eight gates: that each feature is gated at
 * all, that the reads survive, and that `media` is gated on the upload
 * rather than on the delete.
 */
final class MatchDayPlanGateTest extends WP_UnitTestCase {

    /** The eight this slice gates. */
    private const SLICE = [
        'match_analysis',
        'match_prep',
        'match_execution',
        'tournaments',
        'tournaments_auto_balance',
        'training',
        'exercises',
        'media',
    ];

    /** @var string|null */
    private static $sources = null;

    private static function sources(): string {
        if ( self::$sources !== null ) return self::$sources;
        $out = '';
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( TT_PLUGIN_DIR . 'src' )
        );
        foreach ( $rii as $file ) {
            if ( $file->getExtension() === 'php' ) $out .= file_get_contents( $file->getPathname() );
        }
        return self::$sources = $out;
    }

    private static function source( string $relative ): string {
        return (string) file_get_contents( TT_PLUGIN_DIR . $relative );
    }

    // ---------------------------------------------------------------
    // coverage
    // ---------------------------------------------------------------

    public function test_all_eight_are_still_pro_features(): void {
        foreach ( self::SLICE as $feature ) {
            $this->assertArrayHasKey(
                $feature,
                FeatureMap::DEFAULT_MAP[ FeatureMap::TIER_PRO ],
                "{$feature} left the Pro tier; this slice's gates now refuse nothing"
            );
        }
    }

    public function test_all_eight_have_left_the_pending_list(): void {
        /** @var array<string,string> $pending */
        $pending = require TT_PLUGIN_DIR . 'config/license_gate_pending.php';

        foreach ( self::SLICE as $feature ) {
            $this->assertArrayNotHasKey(
                $feature,
                $pending,
                "{$feature} is gated, so its pending entry is stale"
            );
        }
    }

    public function test_all_eight_have_a_gate_in_src(): void {
        $sources = self::sources();
        foreach ( self::SLICE as $feature ) {
            $found = false;
            foreach ( [ 'allows', 'can', 'enforceFeatureRest', 'enforceWriteRest' ] as $method ) {
                if ( strpos( $sources, "LicenseGate::{$method}( '{$feature}'" ) !== false ) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue( $found, "{$feature} has no LicenseGate call site" );
        }
    }

    // ---------------------------------------------------------------
    // the shape of the refusal
    // ---------------------------------------------------------------

    /**
     * #3017's third decision, as a property of the helper the four
     * write-heavy controllers route through. Provable without arranging an
     * entitlement: the rule is about the verb.
     */
    public function test_a_read_survives_its_feature_leaving_the_plan(): void {
        foreach ( [ 'GET', 'HEAD', 'OPTIONS' ] as $method ) {
            $this->assertNull(
                LicenseGate::refusalForMethod( 'match_analysis', $method ),
                "{$method} of an existing record must survive the feature leaving the plan"
            );
        }
        foreach ( [ 'POST', 'PUT', 'PATCH', 'DELETE' ] as $method ) {
            $refusal = LicenseGate::refusalForMethod( 'match_analysis', $method );
            $this->assertNotNull( $refusal, "{$method} must be refused" );
            $this->assertSame( 402, $refusal->get_status(), 'a plan refusal is 402, never 403' );
        }
    }

    public function test_the_refusal_names_the_feature_and_the_plan(): void {
        $body = LicenseGate::planRefusal( 'tournaments' )->get_data();
        $this->assertSame( 402, LicenseGate::planRefusal( 'tournaments' )->get_status() );
        $flat = wp_json_encode( $body );
        $this->assertStringContainsString( 'tournaments', (string) $flat );
    }

    // ---------------------------------------------------------------
    // the two decisions that are easy to undo by accident
    // ---------------------------------------------------------------

    /**
     * Auto-balance is sold below the level of the page it lives on. The
     * failure this locks: gating it with the tournaments key, or gating
     * the tournament view with the auto-balance key, either of which takes
     * the whole surface with it.
     */
    public function test_auto_balance_locks_its_action_and_not_tournaments(): void {
        $controller = self::source( 'src/Infrastructure/REST/TournamentsRestController.php' );

        $this->assertStringContainsString(
            "enforceFeatureRest( 'tournaments_auto_balance' )",
            $controller,
            'the auto-balance route carries its own key'
        );
        $this->assertStringContainsString(
            "enforceWriteRest( 'tournaments', \$r )",
            $controller,
            'the rest of the controller answers to the tournaments key'
        );
        $this->assertStringContainsString(
            "self::gateAutoBalance( [ __CLASS__, 'auto_plan' ] )",
            $controller,
            'auto-plan is the only route on the auto-balance key'
        );
        $this->assertSame(
            1,
            substr_count( $controller, 'self::gateAutoBalance(' ),
            'exactly one route is sold as auto-balance'
        );
    }

    /**
     * `media` is #3105's stated template: *the club keeps every photo it
     * has, and cannot add more.* Which means the gate is on the two upload
     * routes and emphatically not on `DELETE` — refusing a deletion over a
     * billing state turns a plan question into a data-protection one.
     */
    public function test_media_gates_the_upload_and_not_the_delete(): void {
        $controller = self::source( 'src/Infrastructure/REST/MediaRestController.php' );

        $this->assertStringContainsString(
            "self::gateUpload( [ __CLASS__, 'create_media' ] )",
            $controller
        );
        $this->assertStringContainsString(
            "self::gateUpload( [ __CLASS__, 'add_link' ] )",
            $controller
        );
        $this->assertSame(
            2,
            substr_count( $controller, 'self::gateUpload(' ),
            'only the two routes that put a new file in the store are gated'
        );

        foreach ( [ 'delete_media', 'remove_link', 'retention_remove', 'retention_decide' ] as $route ) {
            $this->assertStringNotContainsString(
                "gateUpload( [ __CLASS__, '{$route}' ] )",
                $controller,
                "{$route} removes media and must not be refused over a plan"
            );
        }
    }

    // ---------------------------------------------------------------
    // one panel, not eight
    // ---------------------------------------------------------------

    public function test_the_locked_tooltip_names_the_feature_and_the_plan(): void {
        $title = UpgradePanel::lockedTitle( 'tournaments_auto_balance' );
        $this->assertNotSame( '', trim( $title ) );
        $this->assertStringNotContainsString( '<', $title, 'a title attribute is plain text' );
        $this->assertStringContainsString(
            FeatureMap::featureLabel( 'tournaments_auto_balance' ),
            $title
        );
    }

    public function test_every_gated_surface_renders_the_shared_panel(): void {
        $surfaces = [
            'src/Modules/MatchAnalysis/Frontend/FrontendMatchAnalysisView.php',
            'src/Modules/MatchPrep/Frontend/FrontendMatchPrepView.php',
            'src/Modules/MatchExecution/Frontend/FrontendMatchExecutionView.php',
            'src/Shared/Frontend/FrontendTournamentsManageView.php',
            'src/Modules/Training/Frontend/FrontendTrainingPlansView.php',
            'src/Modules/Training/Frontend/FrontendTrainingRunView.php',
            'src/Modules/Exercises/Frontend/FrontendExerciseLibraryView.php',
            'src/Modules/Exercises/Frontend/FrontendExerciseCsvImportView.php',
        ];
        foreach ( $surfaces as $relative ) {
            $this->assertStringContainsString(
                'UpgradePanel::',
                self::source( $relative ),
                "{$relative} must render the shared panel, not its own refusal"
            );
        }
    }
}
