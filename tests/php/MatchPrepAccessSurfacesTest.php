<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\MatchPrep\MatchPrepAccess;

/**
 * #4651 — `MatchPrepAccess` is the one place the match prep's read and write
 * rule is written. The REST routes, the print sheet and the PDF export ask
 * it instead of keeping their own copies, so the four surfaces cannot drift
 * apart. `MatchPrepPdfExportScopeTest` pins what each surface answers; this
 * pins that they all ask the same class.
 */
final class MatchPrepAccessSurfacesTest extends WP_UnitTestCase {

    private const SURFACES = [
        'src/Modules/MatchPrep/Rest/MatchPrepRestController.php',
        'src/Modules/MatchPrep/Export/MatchPrepPdfExporter.php',
        'src/Modules/MatchPrep/Print/MatchPrepPrintRouter.php',
    ];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_every_prep_surface_asks_match_prep_access(): void {
        foreach ( self::SURFACES as $relative ) {
            $this->assertStringContainsString( 'MatchPrepAccess::', self::source( $relative ), $relative );
        }
    }

    public function test_the_rest_routes_and_the_pdf_keep_no_copy_of_the_rule(): void {
        $copies = [ 'coversActivityForRequest(', 'ActivityTeamScope::coversActivity(', "current_user_can( 'tt_edit_activities' )", "current_user_can( 'tt_view_activities' )" ];
        foreach ( [ self::SURFACES[0], self::SURFACES[1] ] as $relative ) {
            $source = self::source( $relative );
            foreach ( $copies as $copy ) {
                $this->assertStringNotContainsString( $copy, $source, "{$relative} still writes out {$copy}" );
            }
        }
    }

    public function test_the_prep_sheet_is_built_from_the_prep_rule(): void {
        $source = self::source( self::SURFACES[2] );
        $start  = strpos( $source, 'public static function renderHtml(' );
        $end    = strpos( $source, 'public static function renderTeamSheetHtml(' );
        $this->assertIsInt( $start );
        $this->assertIsInt( $end );
        $render = substr( $source, $start, $end - $start );

        $this->assertStringContainsString( 'self::readablePrepActivityId(', $render );
        $this->assertStringNotContainsString( 'self::scopedActivityId(', $render, 'the prep sheet asks the activity rule instead of the prep\'s' );
    }

    public function test_an_activity_that_is_not_in_this_club_is_neither_read_nor_written(): void {
        global $wpdb;
        $admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $admin );

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => 'Access U11' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $team, 'title' => 'Access match',
            'session_date' => gmdate( 'Y-m-d' ), 'activity_type_key' => 'match', 'activity_status_key' => 'planned', 'plan_state' => 'scheduled',
        ] );
        $ours = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => (int) CurrentClub::id() + 999, 'team_id' => $team, 'title' => 'Elsewhere match',
            'session_date' => gmdate( 'Y-m-d' ), 'activity_type_key' => 'match', 'activity_status_key' => 'planned', 'plan_state' => 'scheduled',
        ] );
        $elsewhere = (int) $wpdb->insert_id;

        $this->assertTrue( MatchPrepAccess::canReadActivity( $admin, $ours ), 'Precondition: the admin reads this club\'s match.' );
        $this->assertTrue( MatchPrepAccess::canWriteActivity( $admin, $ours ) );
        $this->assertFalse( MatchPrepAccess::canReadActivity( $admin, $elsewhere ) );
        $this->assertFalse( MatchPrepAccess::canWriteActivity( $admin, $elsewhere ) );
        $this->assertFalse( MatchPrepAccess::canReadActivity( $admin, $elsewhere + 100000 ), 'an id that does not exist' );
    }

    private static function source( string $relative ): string {
        return (string) file_get_contents( TT_PLUGIN_DIR . $relative );
    }
}
