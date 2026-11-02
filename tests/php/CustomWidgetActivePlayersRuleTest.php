<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\CustomWidgets\DataSources\PlayersActive;

/**
 * #4638 — the "Active players" widget source counts the players the
 * players list calls active: status `active`, not archived, not trashed.
 *
 * It filtered `status <> 'archived'`, so released, trial and inactive
 * players were counted and listed as active.
 */
final class CustomWidgetActivePlayersRuleTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;
    private int $viewer = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->hide_errors();
        $this->club   = (int) CurrentClub::id();
        $this->viewer = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->viewer );

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Rule JO15' ] );
        $this->team = (int) $wpdb->insert_id;

        $this->insertPlayer( 'Actief' );
        $this->insertPlayer( 'Vrijgegeven', 'released' );
        $this->insertPlayer( 'Proef', 'trial' );
        $this->insertPlayer( 'Inactief', 'inactive' );
        $this->insertPlayer( 'Gearchiveerd', 'archived' );
        $this->insertPlayer( 'Archief', 'active', [ 'archived_at' => current_time( 'mysql' ) ] );
        $this->insertPlayer( 'Prullenbak', 'active', [ 'trashed_at' => current_time( 'mysql' ) ] );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_released_and_archived_players_are_not_counted(): void {
        $count = ( new PlayersActive() )->aggregate( $this->viewer, [ 'team_id' => $this->team ], 'count' );

        $this->assertSame( 1.0, $count, 'only the active player; released, trial, inactive, archived and trashed are out' );
    }

    public function test_released_and_archived_players_are_not_listed(): void {
        $names = array_column( ( new PlayersActive() )->fetch( $this->viewer, [ 'team_id' => $this->team ], [ 'name' ] ), 'name' );

        $this->assertSame( [ 'Rule Actief' ], $names );
    }

    public function test_the_count_matches_the_players_lists_active_count(): void {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players' );
        $request->set_param( 'per_page', 100 );
        $request->set_param( 'filter', [ 'status' => 'active' ] );
        $envelope = rest_get_server()->dispatch( $request )->get_data();
        $list_total = (int) ( $envelope['data']['total'] ?? -1 );

        $widget = ( new PlayersActive() )->aggregate( $this->viewer, [ 'team_id' => 0 ], 'count' );

        $this->assertSame( (float) $list_total, $widget, 'the club-wide widget count is the players list\'s active count' );
    }

    /** @param array<string,mixed> $extra */
    private function insertPlayer( string $last, string $status = 'active', array $extra = [] ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Rule',
            'last_name'  => $last,
            'status'     => $status,
        ] + $extra );
    }
}
