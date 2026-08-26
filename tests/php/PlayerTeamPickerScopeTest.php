<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #2866 — editing a player must never silently unassign them.
 *
 * The team picker was built from `get_teams_for_coach()` for anyone who was
 * not a WordPress administrator. A head of development coaches no team, so
 * the options came back empty, the player's own team was not among them,
 * nothing was selected — and saving posted `team_id = 0`. Someone opening a
 * record to correct a jersey number could remove the player from their
 * squad, with no warning and no read-only field to stop them.
 *
 * Two independent guards, tested separately, because either alone would
 * have prevented the data loss and both should hold:
 *   1. the picker always contains the player's current team;
 *   2. an update that does not mention `team_id` leaves it alone.
 */
final class PlayerTeamPickerScopeTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id' => (int) CurrentClub::id(),
            'name'    => $name,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => (int) CurrentClub::id(),
            'team_id'    => $team_id,
            'first_name' => 'Duco',
            'last_name'  => 'Gunnink',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * The belt-and-braces rule: whatever the scope resolver decides, the
     * record's current team is in the options. This is what makes the data
     * loss impossible even if a scope resolver is wrong again later.
     */
    public function test_the_players_current_team_is_always_offered(): void {
        $team_id = $this->insertTeam( 'Hedel O14-1' );

        // A user with no coach assignment and no global read — the worst
        // case the old code hit.
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $teams = QueryHelpers::get_teams_in_scope( $uid, false, $team_id );
        $ids   = array_map( static fn( $t ): int => (int) $t->id, $teams );

        $this->assertContains(
            $team_id,
            $ids,
            "the player's own team must be selectable even when scope would exclude it"
        );
    }

    /** An administrator still sees every team. */
    public function test_an_administrator_sees_every_team(): void {
        $a = $this->insertTeam( 'Hedel O14-1' );
        $b = $this->insertTeam( 'Hedel O16-1' );

        $uid   = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $ids   = array_map(
            static fn( $t ): int => (int) $t->id,
            QueryHelpers::get_teams_in_scope( $uid, true )
        );

        $this->assertContains( $a, $ids );
        $this->assertContains( $b, $ids );
    }

    /** Archived teams stay out — #2410 stands. */
    public function test_archived_teams_are_not_offered(): void {
        global $wpdb;
        $live     = $this->insertTeam( 'Hedel O14-1' );
        $archived = $this->insertTeam( 'Hedel O19-1' );
        $wpdb->update(
            $wpdb->prefix . 'tt_teams',
            [ 'archived_at' => '2026-07-01 00:00:00' ],
            [ 'id' => $archived ]
        );

        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $ids = array_map(
            static fn( $t ): int => (int) $t->id,
            QueryHelpers::get_teams_in_scope( $uid, true )
        );

        $this->assertContains( $live, $ids );
        $this->assertNotContains( $archived, $ids );
    }

    /**
     * The write-layer guard. A payload that never mentions `team_id` must
     * leave the player where they are.
     */
    public function test_an_update_without_team_id_keeps_the_team(): void {
        $team_id   = $this->insertTeam( 'Hedel O14-1' );
        $player_id = $this->insertPlayer( $team_id );

        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $req = new WP_REST_Request( 'PUT', '/talenttrack/v1/players/' . $player_id );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [ 'jersey_number' => 7 ] ) );
        $res = rest_do_request( $req );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame(
            $team_id,
            (int) QueryHelpers::get_player( $player_id )->team_id,
            'a partial update must not unassign the player'
        );
    }

    /**
     * ...but an explicit empty value still unassigns. Choosing the blank
     * option is how a player is taken off a team, and that has to keep
     * working — the guard is about silence, not about refusing the action.
     */
    public function test_an_explicit_empty_team_id_still_unassigns(): void {
        $team_id   = $this->insertTeam( 'Hedel O14-1' );
        $player_id = $this->insertPlayer( $team_id );

        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $req = new WP_REST_Request( 'PUT', '/talenttrack/v1/players/' . $player_id );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( wp_json_encode( [ 'team_id' => 0 ] ) );
        $res = rest_do_request( $req );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 0, (int) QueryHelpers::get_player( $player_id )->team_id );
    }
}
