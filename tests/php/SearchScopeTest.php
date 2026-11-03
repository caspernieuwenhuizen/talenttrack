<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerVisibility;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #4671 — every kind returns only what the viewer may open, for each
 * persona, and `counts` and `total` say nothing about the rest.
 *
 * The SQL scope is the gate; the per-row confirm is the second check. The
 * parity test holds `PlayerVisibility::sqlClause()` and
 * `AuthorizationService::canViewPlayers()` to one rule.
 */
final class SearchScopeTest extends WP_UnitTestCase {

    use SearchFixtures;

    public function set_up(): void {
        parent::set_up();
        $this->searchSetUp();
    }

    public function tear_down(): void {
        $this->searchTearDown();
        parent::tear_down();
    }

    public function test_a_coach_of_team_a_never_sees_a_team_b_player_team_or_activity(): void {
        foreach ( [ 'coach', 'assistant' ] as $persona ) {
            $data = $this->paged( $this->personas[ $persona ], 'far' );
            $this->assertNotContains( $this->players['far'], $this->idsOf( $data, 'player' ), "{$persona}: a team B player" );
            $this->assertNotContains( $this->team_b, $this->idsOf( $data, 'team' ), "{$persona}: team B" );
            $this->assertNotContains( $this->activities['b'], $this->idsOf( $data, 'activity' ), "{$persona}: a team B activity" );
            foreach ( [ 'player', 'team', 'activity' ] as $kind ) {
                $this->assertSame( 0, (int) ( $data['counts'][ $kind ] ?? 0 ), "{$persona}: the {$kind} count says nothing about team B" );
            }

            $own = $this->paged( $this->personas[ $persona ], 'searchside' );
            $this->assertContains( $this->team_a, $this->idsOf( $own, 'team' ), "{$persona}: their own team" );
            $this->assertContains( $this->activities['a'], $this->idsOf( $own, 'activity' ), "{$persona}: their own team's activity" );
        }
    }

    public function test_an_out_of_scope_match_answers_exactly_like_no_match(): void {
        $coach   = $this->personas['coach'];
        $hidden  = $this->paged( $coach, 'farplayer' );
        $nothing = $this->paged( $coach, 'zzqqnotaname' );
        $this->assertSame( $nothing['items'], $hidden['items'] );
        $this->assertSame( $nothing['total'], $hidden['total'] );
        $this->assertSame( array_sum( $nothing['counts'] ), array_sum( $hidden['counts'] ) );
    }

    public function test_global_readers_see_both_teams(): void {
        foreach ( [ 'admin', 'hod' ] as $persona ) {
            $data = $this->paged( $this->personas[ $persona ], 'training' );
            $this->assertContains( $this->activities['a'], $this->idsOf( $data, 'activity' ), "{$persona}: team A activity" );
            $this->assertContains( $this->activities['b'], $this->idsOf( $data, 'activity' ), "{$persona}: team B activity" );
            $players = $this->idsOf( $this->paged( $this->personas[ $persona ], 'farplayer' ), 'player' );
            $this->assertContains( $this->players['far'], $players, "{$persona}: a team B player" );
        }
    }

    public function test_a_parent_and_a_player_see_only_their_own(): void {
        $parent = $this->paged( $this->personas['parent'], 'jose' );
        $this->assertSame( [ $this->players['jose'] ], $this->idsOf( $parent, 'player' ), 'the parent finds their child' );
        foreach ( [ 'boot', 'besten', 'far' ] as $other ) {
            $this->assertNotContains( $this->players[ $other ], $this->idsOf( $this->paged( $this->personas['parent'], $other === 'besten' ? 'den besten' : $other ), 'player' ), "the parent finds no other child ({$other})" );
        }
        $this->assertNotContains( $this->activities['b'], $this->idsOf( $this->paged( $this->personas['parent'], 'farside' ), 'activity' ) );
        $this->assertSame( [], $this->idsOf( $this->paged( $this->personas['parent'], 'farside' ), 'team' ), 'a parent searches no teams' );

        $player = $this->paged( $this->personas['player'], 'boot' );
        $this->assertSame( [ $this->players['boot'] ], $this->idsOf( $player, 'player' ), 'the player finds themselves' );
        $this->assertSame( [], $this->idsOf( $this->paged( $this->personas['player'], 'farplayer' ), 'player' ) );
        $this->assertNotContains( $this->activities['b'], $this->idsOf( $this->paged( $this->personas['player'], 'farside' ), 'activity' ) );
    }

    public function test_no_search_text_carries_contact_details(): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'guardian_email' => 'family.secret@example.test', 'guardian_phone' => '0612345678' ], [ 'id' => $this->players['jose'] ] );
        \TT\Infrastructure\Search\SearchText::refresh( 'player', $this->players['jose'] );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT search_title, search_text FROM {$wpdb->prefix}tt_players WHERE id = %d", $this->players['jose'] ), ARRAY_A );
        $this->assertStringNotContainsString( 'secret', (string) $row['search_title'] . (string) $row['search_text'] );
        $this->assertSame( [], $this->idsOf( $this->paged( $this->personas['admin'], 'secret' ), 'player' ), 'nobody finds a player by a guardian address' );
    }

    /**
     * The parity test: for every persona and every player, the SQL clause
     * and `canViewPlayers()` agree.
     */
    public function test_the_player_sql_clause_agrees_with_can_view_players(): void {
        global $wpdb;
        $all = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}tt_players WHERE club_id = %d", (int) CurrentClub::id() ) ) );
        $this->assertGreaterThanOrEqual( 4, count( $all ) );

        foreach ( $this->personas as $persona => $uid ) {
            wp_set_current_user( $uid );
            $this->resetSearchCaches();
            $clause = PlayerVisibility::sqlClause( $uid, 'p' );
            $sql    = "SELECT p.id FROM {$wpdb->prefix}tt_players p WHERE p.club_id = %d AND {$clause['sql']}";
            $by_sql = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, ...array_merge( [ (int) CurrentClub::id() ], $clause['params'] ) ) ) );
            $rule   = AuthorizationService::canViewPlayers( $uid, $all );
            foreach ( $all as $pid ) {
                $this->assertSame( ! empty( $rule[ $pid ] ), in_array( $pid, $by_sql, true ), "{$persona}, player {$pid}: the SQL clause and canViewPlayers disagree" );
            }
        }
    }

    public function test_an_unreadable_filter_is_refused_not_dropped(): void {
        $admin = $this->personas['admin'];
        foreach ( [
            [ [ 'types' => 'player,nonsense' ], 'bad_filter' ],
            [ [ 'team_id' => 'abc' ], 'bad_filter' ],
            [ [ 'team_id' => '0' ], 'bad_filter' ],
            [ [ 'from' => '12-10-2026' ], 'bad_date' ],
            [ [ 'to' => '2026-02-31' ], 'bad_date' ],
            [ [ 'per_page' => '500' ], 'bad_filter' ],
        ] as [ $params, $code ] ) {
            $res = $this->searchRequest( $admin, array_merge( [ 'q' => 'searchside', 'page' => '1' ], $params ) );
            $this->assertSame( 400, $res->get_status(), (string) wp_json_encode( $params ) );
            $this->assertSame( $code, (string) ( $res->get_data()['errors'][0]['code'] ?? '' ), (string) wp_json_encode( $params ) );
        }
    }

    public function test_a_team_filter_narrows_the_records(): void {
        $data = $this->paged( $this->personas['admin'], 'training', [ 'team_id' => (string) $this->team_a ] );
        $this->assertSame( [ $this->activities['a'] ], $this->idsOf( $data, 'activity' ) );
    }
}
