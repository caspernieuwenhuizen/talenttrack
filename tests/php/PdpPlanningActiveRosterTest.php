<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Frontend\FrontendPdpPlanningView;
use TT\Modules\Pdp\Services\PdpPlanningService;

/**
 * #4248 — the PDP planning matrix and its block detail count one
 * population: active, not archived players on the team.
 *
 * The planned and conducted figures summed every conversation of a player
 * whose team_id was still the team, released players included, while the
 * expected figure counted active players only. A team read "20/17".
 */
final class PdpPlanningActiveRosterTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $season = 0;
    private int $team = 0;

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

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Planning O12-1' ] );
        $this->team = (int) $wpdb->insert_id;

        // Three active players: one conducted, two scheduled.
        $this->playerWithConversation( 'Anna', 'active', null, true );
        $this->playerWithConversation( 'Bram', 'active', null, false );
        $this->playerWithConversation( 'Cas', 'active', null, false );
        // Still on the team, but no longer part of its roster.
        $this->playerWithConversation( 'Daan', 'released', null, true );
        $this->playerWithConversation( 'Eva', 'active', '2026-09-01 10:00:00', false );
    }

    public function test_matrix_counts_only_the_active_roster(): void {
        $matrix = PdpPlanningService::buildMatrix( $this->season, [ $this->team ] );
        $cell   = $matrix['teams'][ $this->team ]['blocks'][1] ?? null;

        $this->assertIsArray( $cell );
        $this->assertSame( 3, $cell['expected'] );
        $this->assertSame( 3, $cell['planned'] );
        $this->assertSame( 1, $cell['conducted'] );
    }

    public function test_block_detail_lists_only_the_active_roster(): void {
        $method = new ReflectionMethod( FrontendPdpPlanningView::class, 'loadBlockDetail' );
        $method->setAccessible( true );
        $detail = (array) $method->invoke( null, $this->team, 1, $this->season );

        $names = [];
        foreach ( [ 'conducted', 'planned', 'missing' ] as $list ) {
            foreach ( (array) ( $detail[ $list ] ?? [] ) as $row ) {
                $names[] = (string) $row['first_name'];
            }
        }
        sort( $names );

        $this->assertSame( [ 'Anna', 'Bram', 'Cas' ], $names );
        $this->assertCount( 1, (array) $detail['conducted'] );
        $this->assertCount( 2, (array) $detail['planned'] );
        $this->assertCount( 0, (array) $detail['missing'] );
    }

    private function playerWithConversation( string $first_name, string $status, ?string $archived_at, bool $conducted ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_players", [
            'club_id'     => $this->club,
            'team_id'     => $this->team,
            'first_name'  => $first_name,
            'last_name'   => 'Planning',
            'status'      => $status,
            'archived_at' => $archived_at,
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
            'club_id'      => $this->club,
            'pdp_file_id'  => $file_id,
            'sequence'     => 1,
            'template_key' => 'start',
            'scheduled_at' => '2026-09-15 09:00:00',
            'conducted_at' => $conducted ? '2026-09-15 09:00:00' : null,
        ] );
    }
}
