<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Frontend\FrontendPdpPlanningView;

/**
 * #4305 — the block detail showed the window of whichever player's surname
 * sorted first, while the matrix cell it opens from showed the earliest start
 * to the latest end. Both now read FrontendPdpPlanningView::blockWindows().
 */
final class PdpPlanningBlockWindowTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $season = 0;
    private int $team = 0;
    private int $first_sorted = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_seasons", [
            'name'       => '2026/27',
            'start_date' => '2026-07-01',
            'end_date'   => '2027-06-30',
            'is_current' => 1,
        ] );
        $this->season = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Window JO14' ] );
        $this->team = (int) $wpdb->insert_id;

        // The surname that sorts first carries the narrowest window.
        $this->first_sorted = $this->playerWithWindow( 'Aalders', '2026-08-20', '2026-08-30' );
        $this->playerWithWindow( 'Bakker', '2026-08-10', '2026-09-15' );
        $this->playerWithWindow( 'Claassen', '2026-08-25', '2026-10-10' );
    }

    public function test_block_detail_shows_the_window_the_matrix_cell_uses(): void {
        $cell   = $this->matrixCell();
        $detail = $this->detail();

        $this->assertSame( '2026-08-10', $cell['window_start'] );
        $this->assertSame( '2026-10-10', $cell['window_end'] );
        $this->assertSame( $cell['window_start'], $detail['window_start'] );
        $this->assertSame( $cell['window_end'], $detail['window_end'] );
    }

    public function test_renaming_the_first_sorted_player_does_not_move_the_window(): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'last_name' => 'Zwart' ], [ 'id' => $this->first_sorted ] );

        $detail = $this->detail();

        $this->assertSame( '2026-08-10', $detail['window_start'] );
        $this->assertSame( '2026-10-10', $detail['window_end'] );
    }

    public function test_the_first_sorted_player_leaving_does_not_move_the_window(): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'team_id' => 0 ], [ 'id' => $this->first_sorted ] );

        $cell   = $this->matrixCell();
        $detail = $this->detail();

        $this->assertSame( '2026-08-10', $detail['window_start'] );
        $this->assertSame( '2026-10-10', $detail['window_end'] );
        $this->assertSame( $cell['window_end'], $detail['window_end'] );
    }

    public function test_helper_scopes_to_the_requested_block(): void {
        $windows = FrontendPdpPlanningView::blockWindows( $this->season, [ $this->team ], 2 );

        $this->assertSame( [], $windows, 'no conversation sits in block 2' );
    }

    /** @return array<string,mixed> */
    private function matrixCell(): array {
        $matrix = FrontendPdpPlanningView::buildMatrix( $this->season, [ $this->team ] );
        $cell   = $matrix['teams'][ $this->team ]['blocks'][1] ?? null;
        $this->assertIsArray( $cell );
        return $cell;
    }

    /** @return array<string,mixed> */
    private function detail(): array {
        $method = new ReflectionMethod( FrontendPdpPlanningView::class, 'loadBlockDetail' );
        $method->setAccessible( true );
        return (array) $method->invoke( null, $this->team, 1, $this->season );
    }

    private function playerWithWindow( string $last_name, string $start, string $end ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Speler',
            'last_name'  => $last_name,
            'status'     => 'active',
        ] );
        $player_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_pdp_files", [
            'club_id'   => $this->club,
            'player_id' => $player_id,
            'season_id' => $this->season,
            'status'    => 'open',
        ] );
        $file_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_pdp_conversations", [
            'club_id'               => $this->club,
            'pdp_file_id'           => $file_id,
            'sequence'              => 1,
            'template_key'          => 'start',
            'planning_window_start' => $start,
            'planning_window_end'   => $end,
        ] );
        return $player_id;
    }
}
