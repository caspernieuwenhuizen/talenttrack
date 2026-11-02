<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Frontend\FrontendPdpPlanningView;

/**
 * The section help on the PDP block detail describes each state in a
 * coach's words. It read "conducted_at" and "scheduled_at", which mean
 * nothing to the coach or head of development reading it.
 */
final class PdpPlanningBlockHelpTextTest extends WP_UnitTestCase {

    private int $user = 0;

    /** @var array<string,mixed> */
    private array $get = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_seasons", [
            'name'       => '2026/27',
            'start_date' => '2026-07-01',
            'end_date'   => '2027-06-30',
            'is_current' => 1,
        ] );
        $season = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Help O15-1' ] );
        $team = (int) $wpdb->insert_id;

        $this->user = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user );

        $this->get = $_GET;
        $_GET      = [ 'tt_view' => 'pdp-planning', 'action' => 'block', 'team_id' => $team, 'block' => 1, 'season_id' => $season ];
    }

    public function tear_down(): void {
        $_GET = $this->get;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_section_help_names_no_column(): void {
        $this->assertTrue( current_user_can( 'tt_view_pdp_planning' ), 'the fixture may open PDP planning' );

        ob_start();
        FrontendPdpPlanningView::render( $this->user, true );
        $html = (string) ob_get_clean();

        preg_match_all( '#<p class="tt-pdp-block-col__help">(.*?)</p>#s', $html, $m );
        $this->assertCount( 4, $m[1], 'one help line per section' );

        foreach ( $m[1] as $help ) {
            $text = html_entity_decode( $help, ENT_QUOTES );
            $this->assertDoesNotMatchRegularExpression( '/\b[a-z]+_(at|id)\b/', $text, "no column name in: {$text}" );
            $this->assertStringNotContainsString( 'status', $text, "no status field in: {$text}" );
        }

        $decoded = html_entity_decode( $html, ENT_QUOTES );
        $this->assertStringContainsString( 'The conversation took place.', $decoded );
        $this->assertStringContainsString( "The conversation has a date but hasn't taken place yet.", $decoded );
    }
}
