<?php
namespace TT\Tests\Php;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Reports\Admin\ReportsPage;
use WP_UnitTestCase;

/**
 * #4415 — the wp-admin coach activity report counts the current club's
 * evaluations only. A second club's evaluations by the same coach must not
 * reach the count.
 */
final class CoachActivityReportClubScopeTest extends WP_UnitTestCase {

    private int $coach;

    public function set_up(): void {
        parent::set_up();
        $this->coach = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Activity Coach 4415' ] );
        wp_set_current_user( $this->coach );
    }

    public function tear_down(): void {
        unset( $_GET['report'], $_GET['days'] );
        parent::tear_down();
    }

    public function test_counts_only_the_current_clubs_evaluations(): void {
        $this->assertSame( 1, CurrentClub::id(), 'guard: current club is 1' );
        $this->insertEvaluation( 1 );
        $this->insertEvaluation( 1 );
        $this->insertEvaluation( 2 );
        $this->insertEvaluation( 2 );
        $this->insertEvaluation( 2 );

        $_GET['report'] = 'coach_activity';
        $_GET['days']   = '30';
        ob_start();
        ReportsPage::render_page();
        $html = (string) ob_get_clean();

        $this->assertMatchesRegularExpression(
            '#<td>Activity Coach 4415</td>\s*<td[^>]*>2</td>#',
            $html,
            'the coach row counts the two club-1 evaluations, not the club-2 ones'
        );
    }

    private function insertEvaluation( int $club_id ): void {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id'    => $club_id,
            'player_id'  => 0,
            'coach_id'   => $this->coach,
            'eval_date'  => gmdate( 'Y-m-d' ),
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ] );
        $this->assertNotFalse( $ok, 'evaluation insert must succeed' );
    }
}
