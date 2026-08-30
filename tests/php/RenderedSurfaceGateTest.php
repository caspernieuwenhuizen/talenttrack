<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;

/**
 * #3107 — slice 4: the seven rendered surfaces.
 *
 * This is the slice where "locked, not hidden" is most visible — seven
 * tiles a Standard club can see and not open — so what is pinned here is
 * that each one renders the shared panel rather than dying, that the three
 * grids say the per-player path still works, and that plan and permission
 * stay independent axes.
 */
final class RenderedSurfaceGateTest extends WP_UnitTestCase {

    private const SLICE = [
        'analytics_explorer',
        'custom_widgets',
        'persona_dashboard_editor',
        'knowledge_courses',
        'attendance_grid',
        'minutes_grid',
        'ratings_grid',
    ];

    private const GRIDS = [ 'attendance_grid', 'minutes_grid', 'ratings_grid' ];

    private static function source( string $relative ): string {
        return (string) file_get_contents( TT_PLUGIN_DIR . $relative );
    }

    // ---------------------------------------------------------------
    // coverage
    // ---------------------------------------------------------------

    public function test_the_seven_are_gated_and_off_the_pending_list(): void {
        /** @var array<string,string> $pending */
        $pending = require TT_PLUGIN_DIR . 'config/license_gate_pending.php';

        $sources = '';
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( TT_PLUGIN_DIR . 'src' )
        );
        foreach ( $rii as $file ) {
            if ( $file->getExtension() === 'php' ) $sources .= file_get_contents( $file->getPathname() );
        }

        foreach ( self::SLICE as $feature ) {
            $this->assertArrayHasKey( $feature, FeatureMap::DEFAULT_MAP[ FeatureMap::TIER_PRO ] );
            $this->assertArrayNotHasKey( $feature, $pending, "{$feature} is gated; its pending entry is stale" );

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
    // locked, not hidden — and not wp_die()
    // ---------------------------------------------------------------

    public function test_each_surface_renders_the_shared_panel(): void {
        $surfaces = [
            'src/Modules/Analytics/Frontend/FrontendExploreView.php',
            'src/Modules/CustomWidgets/Admin/CustomWidgetsAdminPage.php',
            'src/Modules/PersonaDashboard/Admin/EditorPage.php',
            'src/Modules/Activities/Frontend/FrontendAttendanceGridView.php',
            'src/Modules/Activities/Frontend/FrontendMinutesGridView.php',
            'src/Modules/Activities/Frontend/FrontendRatingsGridView.php',
        ];
        foreach ( $surfaces as $relative ) {
            $this->assertStringContainsString(
                'UpgradePanel::render(',
                self::source( $relative ),
                "{$relative} must render the shared panel"
            );
        }
    }

    /**
     * The two admin pages previously answered a missing capability with
     * `wp_die()`. A plan refusal must not: an operator who followed a menu
     * item to a white error page cannot tell a paid feature from a broken
     * one, which is the whole reason #3017 chose locked over hidden.
     */
    public function test_the_admin_pages_do_not_wp_die_on_a_plan_refusal(): void {
        $entry_points = [
            'src/Modules/CustomWidgets/Admin/CustomWidgetsAdminPage.php'
                => \TT\Modules\CustomWidgets\Admin\CustomWidgetsAdminPage::class,
            'src/Modules/PersonaDashboard/Admin/EditorPage.php'
                => \TT\Modules\PersonaDashboard\Admin\EditorPage::class,
        ];

        foreach ( $entry_points as $relative => $class ) {
            $render = new \ReflectionMethod( $class, 'render' );
            $lines  = array_slice(
                explode( "\n", self::source( $relative ) ),
                (int) $render->getStartLine() - 1,
                (int) $render->getEndLine() - (int) $render->getStartLine() + 1
            );
            $body = implode( "\n", $lines );

            $gate = strpos( $body, 'LicenseGate::allows(' );
            $this->assertIsInt( $gate, "{$relative}::render() asks the plan" );

            $this->assertStringContainsString(
                'UpgradePanel::render(',
                $body,
                "{$relative} answers a plan refusal with the shared panel"
            );

            // Every `wp_die()` in `render()` belongs to a capability or
            // feature-switch refusal, both of which are asked before the
            // plan. Nothing after the plan gate dies. (The other `wp_die`s
            // in these files are in unrelated action handlers.)
            $last_die = strrpos( $body, 'wp_die' );
            $this->assertTrue(
                $last_die === false || $last_die < $gate,
                "{$relative}: a plan refusal renders, it does not wp_die()"
            );
        }
    }

    // ---------------------------------------------------------------
    // the grids say what still works
    // ---------------------------------------------------------------

    /**
     * A Standard club is losing the fast way to enter a squad's worth of
     * data, not the ability to record attendance. A panel that did not say
     * so would read as "attendance is a paid feature", which is false — and
     * that is a materially different message from the other four.
     */
    public function test_each_grid_panel_names_the_path_that_still_works(): void {
        $views = [
            'attendance_grid' => 'src/Modules/Activities/Frontend/FrontendAttendanceGridView.php',
            'minutes_grid'    => 'src/Modules/Activities/Frontend/FrontendMinutesGridView.php',
            'ratings_grid'    => 'src/Modules/Activities/Frontend/FrontendRatingsGridView.php',
        ];
        foreach ( $views as $feature => $relative ) {
            $source = self::source( $relative );
            $gate   = strpos( $source, "LicenseGate::allows( '{$feature}' )" );
            $this->assertIsInt( $gate, "{$relative} gates on its own feature key" );

            $branch = substr( $source, $gate, 700 );
            $this->assertStringContainsString(
                "'note' =>",
                $branch,
                "{$relative} must pass a note saying what still works"
            );
            $this->assertStringContainsString( 'not affected', $branch );
        }
    }

    /**
     * The grid gate is on the grid routes only. The per-activity paths
     * through the same controller are how a Standard club still records
     * attendance, minutes and ratings, so gating the controller wholesale
     * would take the ungated path with it.
     */
    public function test_only_the_grid_routes_are_gated_on_the_activities_controller(): void {
        $controller = self::source( 'src/Infrastructure/REST/ActivitiesRestController.php' );

        foreach ( [ 'gateAttendanceGrid', 'gateMinutesGrid', 'gateRatingsGrid' ] as $wrapper ) {
            $this->assertStringContainsString( "private static function {$wrapper}(", $controller );
        }

        // Five grid routes, and no more.
        $wrapped = substr_count( $controller, 'self::gateAttendanceGrid( [' )
                 + substr_count( $controller, 'self::gateMinutesGrid( [' )
                 + substr_count( $controller, 'self::gateRatingsGrid( [' );
        $this->assertSame( 5, $wrapped, 'exactly the two attendance, two minutes and one ratings grid routes' );

        $this->assertStringContainsString(
            "[ __CLASS__, 'create_session' ]",
            $controller,
            'the per-activity routes are still plain callbacks'
        );
    }

    // ---------------------------------------------------------------
    // knowledge gates at the resolver
    // ---------------------------------------------------------------

    /**
     * `CourseAccessResolver` is the module's existing chokepoint, so every
     * consumer inherits the plan check — the list, a lesson page, the REST
     * routes, and any surface written later. A view-level check would have
     * left the routes open.
     */
    public function test_knowledge_gates_at_the_resolver_not_at_a_view(): void {
        $resolver = self::source( 'src/Modules/Knowledge/CourseAccessResolver.php' );

        $this->assertStringContainsString(
            "LicenseGate::allows( 'knowledge_courses' )",
            $resolver
        );
        $this->assertStringContainsString( 'private function planVerdict()', $resolver );

        // Both public entry points ask it; `forLesson` and
        // `listableCourses` delegate to them.
        foreach ( [ 'function forCourse(', 'function forLessons(' ] as $entry ) {
            $at = strpos( $resolver, $entry );
            $this->assertIsInt( $at );
            $this->assertStringContainsString(
                'planVerdict()',
                substr( $resolver, $at, 400 ),
                "{$entry} asks the plan first"
            );
        }
    }

    /**
     * A locked verdict stays listable, so a Standard club sees the courses
     * exist and what opens them — "locked, not hidden" in the vocabulary
     * this module already has.
     */
    public function test_the_course_plan_verdict_is_locked_not_unavailable(): void {
        $resolver = self::source( 'src/Modules/Knowledge/CourseAccessResolver.php' );
        $at       = strpos( $resolver, 'private function planVerdict()' );
        $this->assertIsInt( $at );

        $body = substr( $resolver, $at, 700 );
        $this->assertStringContainsString( 'GateVerdict::locked(', $body );
        $this->assertStringNotContainsString( 'GateVerdict::unavailable(', $body );
        $this->assertStringContainsString( 'ContentGate::REASON_TIER', $body );
    }

    // ---------------------------------------------------------------
    // plan and permission are independent axes
    // ---------------------------------------------------------------

    /**
     * The capability check comes first everywhere, so someone who would
     * never have had the surface on any plan gets the permission answer
     * rather than an upgrade pitch for something they could not use.
     */
    public function test_the_capability_check_precedes_the_plan_check(): void {
        $pairs = [
            'src/Modules/Analytics/Frontend/FrontendExploreView.php'        => "current_user_can( 'tt_view_analytics' )",
            'src/Modules/Activities/Frontend/FrontendAttendanceGridView.php' => "current_user_can( 'tt_edit_activities' )",
            'src/Modules/Activities/Frontend/FrontendMinutesGridView.php'    => "current_user_can( 'tt_edit_activities' )",
            'src/Modules/Activities/Frontend/FrontendRatingsGridView.php'    => "current_user_can( 'tt_edit_activities' )",
        ];
        foreach ( $pairs as $relative => $cap ) {
            $source = self::source( $relative );
            $cap_at = strpos( $source, $cap );
            $plan_at = strpos( $source, 'LicenseGate::allows(' );
            $this->assertIsInt( $cap_at, "{$relative} still checks {$cap}" );
            $this->assertIsInt( $plan_at );
            $this->assertLessThan( $plan_at, $cap_at, "{$relative}: permission is asked before the plan" );
        }
    }

    public function test_a_rest_refusal_on_these_is_402(): void {
        foreach ( self::GRIDS as $feature ) {
            $this->assertSame( 402, LicenseGate::planRefusal( $feature )->get_status() );
        }
    }
}
