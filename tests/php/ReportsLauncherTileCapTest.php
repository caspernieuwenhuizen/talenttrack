<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendReportsLauncherView;

/**
 * #3647 — a launcher tile must not promise a report the viewer cannot open.
 *
 * The Reports launcher gates itself on `tt_view_reports`. Some of its tiles
 * lead to views with a gate of their own, so each tile carries its
 * destination's gate: the two minutes reports read `tt_view_analytics`, and
 * since #4579 the three attendance reports read the matrix's `attendance`
 * read through `AuthorizationService::canOpenAttendanceReports()`.
 */
final class ReportsLauncherTileCapTest extends WP_UnitTestCase {

    /** The tiles whose destination reads `tt_view_analytics`. */
    private const ANALYTICS_TILES = [
        'minutes-report-team',
        'minutes-audit',
    ];

    /** #4579 — the tiles whose destination reads `attendance`. */
    private const ATTENDANCE_TILES = [
        'attendance-report-team',
        'attendance-report-player',
        'attendance-leaderboard',
    ];

    /** @var callable|null */
    private $cap_filter = null;

    public function set_up(): void {
        parent::set_up();
        $_GET = [];

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        ModuleRegistry::setEnabled( 'TT\\Modules\\Reports\\ReportsModule', true );
        foreach ( array_merge( self::ANALYTICS_TILES, self::ATTENDANCE_TILES ) as $slug ) {
            FeatureRegistry::setEnabled( 'report_' . str_replace( '-', '_', $slug ), true );
        }
    }

    public function tear_down(): void {
        if ( null !== $this->cap_filter ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        $_GET = [];
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
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

    /** A head coach of one team, the persona #4579 was reported from. */
    private function headCoach(): int {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => 1, 'name' => 'Launcher JO13-1' ] );
        $team = (int) $wpdb->insert_id;

        $user_id = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => 1, 'first_name' => 'Launcher', 'last_name' => 'Coach',
            'role_type' => 'head_coach', 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => 1, 'team_id' => $team, 'person_id' => $person, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id' => 1, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );
        AuthorizationService::flushCache();
        wp_set_current_user( $user_id );
        return $user_id;
    }

    private function renderFor( int $user_id ): string {
        ob_start();
        FrontendReportsLauncherView::render( $user_id, false );
        return (string) ob_get_clean();
    }

    /**
     * Reports access, no analytics access: neither minutes tile is offered,
     * so nothing on the page leads to a refusal.
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

    /**
     * #4579 — the read-only observer reads reports academy-wide but holds no
     * attendance read, so no attendance tile is offered, and the heading is
     * not left standing over an empty group.
     */
    public function test_a_viewer_without_attendance_read_is_offered_no_attendance_tile(): void {
        $user_id = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        AuthorizationService::flushCache();
        wp_set_current_user( $user_id );
        $this->assertFalse( AuthorizationService::canOpenAttendanceReports( $user_id ), 'precondition: no attendance read' );

        $html = $this->renderFor( $user_id );
        $this->assertStringNotContainsString(
            'Your role does not have access to reports',
            $html,
            'precondition: the viewer passed the launcher gate'
        );
        foreach ( self::ATTENDANCE_TILES as $slug ) {
            $this->assertStringNotContainsString( 'tt_view=' . $slug, $html, $slug );
        }
        $this->assertStringNotContainsString( '>Attendance</h3>', $html );
    }

    /**
     * #4579 — a head coach holds attendance read on their team and not
     * analytics read: the attendance tiles are offered, the minutes tiles
     * are not.
     */
    public function test_a_head_coach_is_offered_the_attendance_tiles_and_not_the_analytics_ones(): void {
        $html = $this->renderFor( $this->headCoach() );

        foreach ( self::ATTENDANCE_TILES as $slug ) {
            $this->assertStringContainsString( 'tt_view=' . $slug, $html, sprintf( 'the coach can open %s', $slug ) );
        }
        foreach ( self::ANALYTICS_TILES as $slug ) {
            $this->assertStringNotContainsString( 'tt_view=' . $slug, $html, 'coaches do not get analytics read' );
        }
    }

    /** A holder of both caps keeps every tile. */
    public function test_a_viewer_with_analytics_still_sees_every_tile(): void {
        $html = $this->renderFor( $this->viewer( [
            'tt_view_reports'   => true,
            'tt_view_analytics' => true,
        ] ) );

        foreach ( array_merge( self::ANALYTICS_TILES, self::ATTENDANCE_TILES ) as $slug ) {
            $this->assertStringContainsString(
                'tt_view=' . $slug,
                $html,
                sprintf( 'the %s tile belongs to a viewer who can open it', $slug )
            );
        }

        $this->assertStringContainsString( '>Attendance</h3>', $html );
    }

    /**
     * The gate the tile names is the gate the destination reads — if a
     * destination ever moves to another one, this fails rather than
     * quietly hiding or over-offering the tile.
     */
    public function test_each_tile_gate_matches_its_destination_gate(): void {
        $launcher = (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/src/Shared/Frontend/FrontendReportsLauncherView.php'
        );

        $analytics = [
            'minutes-report-team' => 'FrontendMinutesTeamReportView',
            'minutes-audit'       => 'FrontendMinutesAuditView',
        ];
        foreach ( $analytics as $slug => $class ) {
            $this->assertStringContainsString(
                "current_user_can( 'tt_view_analytics' )",
                $this->viewSource( $class ),
                sprintf( '%s no longer gates on tt_view_analytics — update the %s tile', $class, $slug )
            );
        }
        $this->assertSame(
            count( $analytics ),
            substr_count( $launcher, "'cap'   => 'tt_view_analytics'" ),
            'every analytics-backed launcher tile names the capability its destination reads'
        );

        $attendance = [
            'attendance-report-team'   => 'FrontendAttendanceTeamReportView',
            'attendance-report-player' => 'FrontendAttendancePlayerReportView',
            'attendance-leaderboard'   => 'FrontendAttendanceLeaderboardView',
        ];
        foreach ( $attendance as $slug => $class ) {
            $source = $this->viewSource( $class );
            $this->assertStringContainsString(
                'AuthorizationService::canOpenAttendanceReports( $user_id )',
                $source,
                sprintf( '%s no longer gates on attendance read — update the %s tile', $class, $slug )
            );
            $this->assertStringNotContainsString( "'tt_view_analytics'", $source, $class );
        }
        $this->assertSame(
            count( $attendance ),
            substr_count( $launcher, "'can'   => \$can_read_attendance" ),
            'every attendance launcher tile asks the attendance gate'
        );
    }

    private function viewSource( string $class ): string {
        return (string) file_get_contents(
            dirname( __DIR__, 2 ) . '/src/Modules/Analytics/Frontend/' . $class . '.php'
        );
    }
}
