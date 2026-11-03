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
        $this->addCandidateSourcePersonas();
        $all = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}tt_players WHERE club_id = %d", (int) CurrentClub::id() ) ) );
        $this->assertGreaterThanOrEqual( 5, count( $all ) );

        // Preconditions: each added source is reached, so its branch of the
        // scope is exercised rather than agreeing on "nothing".
        $this->assertTrue( AuthorizationService::canViewPlayers( $this->personas['scout'], [ $this->players['jose'] ] )[ $this->players['jose'] ] ?? false, 'the linked scout reads José' );
        $this->assertContains( $this->players['trialist'], \TT\Infrastructure\Players\ParentChildResolver::childIds( $this->personas['trial_guardian'], \TT\Infrastructure\Players\ParentChildResolver::NEED_SCHEDULE ), 'the trialist is a candidate of their guardian' );
        $this->resetSearchCaches();
        $this->assertTrue( AuthorizationService::canViewPlayers( $this->personas['functional'], [ $this->players['far'] ] )[ $this->players['far'] ] ?? false, 'the functional-role manager reads team B' );
        $this->resetSearchCaches();
        $this->assertFalse(
            \TT\Modules\Authorization\MatrixGate::can( $this->personas['ended_coach'], 'players', \TT\Modules\Authorization\MatrixGate::READ, \TT\Modules\Authorization\MatrixGate::SCOPE_TEAM, $this->team_b ),
            'the coach whose assignment ended holds no coach grant on team B, though the scope row is open'
        );
        $this->resetSearchCaches();

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

    /**
     * The activity confirm (`readableIds()`) is built from the same scope as
     * the SQL, so it is held to the per-record rule (`canRead()`) instead:
     * for every persona and every activity, the two agree. Two activities on
     * team B are reached by a team A player only through the register, one as
     * a squad member and one as a guest.
     */
    public function test_the_activity_confirm_agrees_with_can_read(): void {
        global $wpdb;
        $this->addCandidateSourcePersonas();
        $club = (int) CurrentClub::id();
        $this->activities['register'] = $this->activity( $this->team_b, 'Register only training', '2020-01-20' );
        $this->activities['guest']    = $this->activity( $this->team_b, 'Guest only match', '2020-01-21', 'match' );
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id' => $club, 'activity_id' => $this->activities['register'], 'player_id' => $this->players['jose'],
            'status' => 'Present', 'record_type' => 'actual',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id' => $club, 'activity_id' => $this->activities['guest'], 'player_id' => 0,
            'guest_player_id' => $this->players['boot'], 'is_guest' => 1, 'status' => 'Present', 'record_type' => 'actual',
        ] );

        $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, team_id FROM {$wpdb->prefix}tt_activities WHERE club_id = %d", $club ) );
        $this->assertGreaterThanOrEqual( 4, count( $rows ) );

        $reached = [];
        foreach ( $this->personas as $persona => $uid ) {
            wp_set_current_user( $uid );
            $this->resetSearchCaches();
            $confirmed = \TT\Modules\Activities\ActivityAccess::readableIds( $uid, $rows );
            foreach ( $rows as $row ) {
                $this->resetSearchCaches();
                $id = (int) $row->id;
                $this->assertSame(
                    \TT\Modules\Activities\ActivityAccess::canRead( $uid, $row ),
                    in_array( $id, $confirmed, true ),
                    "{$persona}, activity {$id}: readableIds and canRead disagree"
                );
                if ( in_array( $id, $confirmed, true ) ) $reached[ $persona ][] = $id;
            }
        }
        // Preconditions: the register-only and the guest-only branches answer.
        $this->assertContains( $this->activities['register'], $reached['parent'] ?? [], 'José\'s guardian reads the activity he is on the register of' );
        $this->assertContains( $this->activities['guest'], $reached['player'] ?? [], 'the player reads the activity they were a guest in' );
        $this->assertNotContains( $this->activities['b'], $reached['parent'] ?? [], 'and not team B\'s own training' );
    }

    /**
     * A hit the confirm drops is not counted either: `counts` and `total`
     * follow the confirmed set, on a short page and on a full one.
     */
    public function test_counts_follow_the_confirm(): void {
        $admin = $this->personas['admin'];
        $jose  = $this->players['jose'];
        // A scope defect, simulated: the rule refuses José while the SQL
        // scope (global, for the admin) still fetches him.
        $drop = static fn( $allowed, $user_id, $player_id ): bool => (bool) $allowed && (int) $player_id !== $jose;
        add_filter( 'tt_auth_can_view_player', $drop, 10, 3 );
        try {
            $short = $this->paged( $admin, 'jose', [ 'types' => 'player' ] );
            $this->assertNotContains( $jose, $this->idsOf( $short, 'player' ) );
            $this->assertSame( count( $this->idsOf( $short, 'player' ) ), (int) $short['counts']['player'], 'a short page counts what it kept' );
            $this->assertSame( (int) $short['counts']['player'], (int) $short['total'] );

            // Last names after Pérez, so José ranks inside the first page.
            for ( $i = 0; $i < 3; $i++ ) $this->player( $this->team_a, 'Jose', 'Zcountfill' . $i, 40 + $i );
            $full = $this->paged( $admin, 'jose', [ 'types' => 'player', 'per_page' => '2' ] );
            $this->assertNotContains( $jose, $this->idsOf( $full, 'player' ) );
            $this->assertSame( 3, (int) $full['counts']['player'], 'a full page: the count less what the confirm dropped' );
            $this->assertSame( 3, (int) $full['total'] );
        } finally {
            remove_filter( 'tt_auth_can_view_player', $drop, 10 );
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
