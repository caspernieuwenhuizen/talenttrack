<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Shared\Frontend\FrontendReportsLauncherView;

/**
 * #3647 — a launcher tile must not promise a report the viewer cannot open.
 *
 * The Reports launcher gates itself on `tt_view_reports`. Five of its tiles
 * lead to views that gate on `tt_view_analytics` instead, so a role holding
 * the first cap and not the second was offered an attendance tile that
 * answered "not authorized" on arrival. The tiles now carry the destination's
 * capability, which the per-tile filter already honoured for the learning
 * reports.
 */
final class ReportsLauncherTileCapTest extends WP_UnitTestCase {

    /** The five tiles whose destination reads `tt_view_analytics`. */
    private const ANALYTICS_TILES = [
        'attendance-report-team',
        'attendance-report-player',
        'attendance-leaderboard',
        'minutes-report-team',
        'minutes-audit',
    ];

    /** @var callable|null */
    private $cap_filter = null;

    public function set_up(): void {
        parent::set_up();
        $_GET = [];

        ModuleRegistry::setEnabled( 'TT\\Modules\\Reports\\ReportsModule', true );
        foreach ( self::ANALYTICS_TILES as $slug ) {
            FeatureRegistry::setEnabled( 'report_' . str_replace( '-', '_', $slug ), true );
        }
    }

    public function tear_down(): void {
        if ( null !== $this->cap_filter ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        $_GET = [];
        parent::tear_down();
    }

    /**
     * A viewer holding exactly the caps named.
     *
     * `add_cap()` is not enough here: `current_user_can()` runs through the
     * `user_has_cap` filter the capability-matrix bridge hooks, which would
     * hand an administrator `tt_view_analytics` straight back. Filtering at
     * priority 999 runs after the bridge, so this decides.
     *
     * @param array<string,bool> $caps
     */
    private function viewer( array $caps ): int {
        $user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $this->cap_filter = static function ( $allcaps ) use ( $caps ) {
            foreach ( $caps as $cap => $granted ) {
                if ( $granted ) {
                    $allcaps[ $cap ] = true;
                } else {
                    unset( $allcaps[ $cap ] );
                }
            }
            return $allcaps;
        };
        add_filter( 'user_has_cap', $this->cap_filter, 999 );

        wp_set_current_user( $user_id );
        return $user_id;
    }

    private function renderFor( int $user_id ): string {
        ob_start();
        FrontendReportsLauncherView::render( $user_id, false );
        return (string) ob_get_clean();
    }

    /**
     * The board observer: reports access, no analytics access. None of the
     * five tiles is offered, so nothing on the page leads to a refusal.
     */
    public function test_a_reports_only_viewer_is_offered_no_analytics_tile(): void {
        $html = $this->renderFor( $this->viewer( [
            'tt_view_reports'   => true,
            'tt_view_analytics' => false,
        ] ) );

        $this->assertStringContainsString( 'tt-breadcrumbs', $html, 'precondition: the launcher rendered' );
        $this->assertStringNotContainsString(
            'Your role does not have access to reports',
            $html,
            'precondition: the viewer passed the launcher gate'
        );

        foreach ( self::ANALYTICS_TILES as $slug ) {
            $this->assertStringNotContainsString(
                'tt_view=' . $slug,
                $html,
                sprintf( 'the %s tile leads to a tt_view_analytics view and must be hidden', $slug )
            );
        }
    }

    /** The heading is not left standing over an empty group. */
    public function test_the_attendance_heading_does_not_render_without_its_tiles(): void {
        $html = $this->renderFor( $this->viewer( [
            'tt_view_reports'   => true,
            'tt_view_analytics' => false,
        ] ) );

        $this->assertStringNotContainsString(
            '>Attendance</h3>',
            $html,
            'all three Attendance tiles were filtered away, so the section header renders nothing'
        );
    }

    /** A holder of both caps keeps every tile. */
    public function test_a_viewer_with_analytics_still_sees_every_tile(): void {
        $html = $this->renderFor( $this->viewer( [
            'tt_view_reports'   => true,
            'tt_view_analytics' => true,
        ] ) );

        foreach ( self::ANALYTICS_TILES as $slug ) {
            $this->assertStringContainsString(
                'tt_view=' . $slug,
                $html,
                sprintf( 'the %s tile belongs to a viewer who can open it', $slug )
            );
        }

        $this->assertStringContainsString( '>Attendance</h3>', $html );
    }

    /**
     * The cap the tile names is the cap the destination reads — if a
     * destination ever moves to another capability, this fails rather than
     * quietly hiding or over-offering the tile.
     */
    public function test_each_tile_cap_matches_its_destination_gate(): void {
        $views = [
            'attendance-report-team'   => 'FrontendAttendanceTeamReportView',
            'attendance-report-player' => 'FrontendAttendancePlayerReportView',
            'attendance-leaderboard'   => 'FrontendAttendanceLeaderboardView',
            'minutes-report-team'      => 'FrontendMinutesTeamReportView',
            'minutes-audit'            => 'FrontendMinutesAuditView',
        ];

        $launcher = (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/src/Shared/Frontend/FrontendReportsLauncherView.php'
        );

        foreach ( $views as $slug => $class ) {
            $source = (string) file_get_contents(
                dirname( __DIR__, 2 ) . '/src/Modules/Analytics/Frontend/' . $class . '.php'
            );
            $this->assertStringContainsString(
                "current_user_can( 'tt_view_analytics' )",
                $source,
                sprintf( '%s no longer gates on tt_view_analytics — update the %s tile', $class, $slug )
            );
        }

        $this->assertSame(
            count( $views ),
            substr_count( $launcher, "'cap'   => 'tt_view_analytics'" ),
            'every analytics-backed launcher tile names the capability its destination reads'
        );
    }
}
