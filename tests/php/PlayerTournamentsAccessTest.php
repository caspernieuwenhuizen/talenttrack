<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\MatrixGate;

/**
 * #3560 (epic #3558) — a player's tournament history reaches the coaches
 * of their teams, the player, and their parents unless the player has
 * closed the section. It does **not** reach them through the planner.
 *
 * The distinction is the whole child: `tournaments` is the rotation board
 * for every squad, and granting it more widely would have shown a family
 * every other child's minutes. `player_tournaments` is one player's own
 * record, and it is a separate entity so the two questions can have
 * different answers.
 */
final class PlayerTournamentsAccessTest extends WP_UnitTestCase {

    private const TEAM_A = 3561;
    private const TEAM_B = 3562;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
    }

    /** The seed is what the top-up migration copies, so the seed is pinned. */
    public function test_the_seed_grants_the_entity_to_six_personas_at_the_right_scopes(): void {
        $granted = [];
        foreach ( $this->seedRows() as $row ) {
            if ( ( $row['entity'] ?? '' ) !== 'player_tournaments' ) continue;
            $this->assertSame(
                'read',
                $row['activity'] ?? '',
                'player_tournaments is a read of somebody else\'s record; it never carries change or create_delete'
            );
            $granted[ (string) $row['persona'] ] = (string) $row['scope_kind'];
        }

        $this->assertSame( 'self',   $granted['player'] ?? null );
        $this->assertSame( 'player', $granted['parent'] ?? null );
        $this->assertSame( 'team',   $granted['assistant_coach'] ?? null );
        $this->assertSame( 'team',   $granted['head_coach'] ?? null );
        $this->assertSame( 'global', $granted['head_of_development'] ?? null );
        $this->assertSame( 'global', $granted['academy_admin'] ?? null );
        $this->assertCount( 6, $granted, 'nobody else reads a player\'s tournament record' );
    }

    /** The planner stays where #3703 left it. This is the AC that guards the whole design. */
    public function test_the_planner_entity_is_unchanged(): void {
        $tournaments = [];
        foreach ( $this->seedRows() as $row ) {
            if ( ( $row['entity'] ?? '' ) !== 'tournaments' ) continue;
            $tournaments[ (string) $row['persona'] ][] = (string) $row['activity'];
        }

        foreach ( [ 'player', 'parent', 'readonly_observer', 'staff' ] as $never ) {
            $this->assertArrayNotHasKey(
                $never,
                $tournaments,
                'the planner must not reach a family or an observer — that is why player_tournaments exists'
            );
        }
        $this->assertArrayHasKey( 'head_coach', $tournaments );
        $this->assertArrayHasKey( 'academy_admin', $tournaments );
    }

    /** A coach reads their own squad's player and not another squad's. */
    public function test_a_coach_reads_their_own_teams_player_and_not_another_teams(): void {
        $this->seedTeam( self::TEAM_A );
        $this->seedTeam( self::TEAM_B );
        $mine   = $this->seedPlayer( self::TEAM_A );
        $theirs = $this->seedPlayer( self::TEAM_B );

        $coach = $this->seedCoachOnTeam( self::TEAM_A );

        $this->assertTrue(
            MatrixGate::can( $coach, 'player_tournaments', MatrixGate::READ, MatrixGate::SCOPE_TEAM, self::TEAM_A ),
            'a coach of this team reads its players\' tournament record'
        );
        $this->assertFalse(
            MatrixGate::can( $coach, 'player_tournaments', MatrixGate::READ, MatrixGate::SCOPE_TEAM, self::TEAM_B ),
            'and not another squad\'s'
        );

        // Guard: the fixture is a real pair of players on two teams, so the
        // assertions above are about scope rather than about nothing.
        $this->assertGreaterThan( 0, $mine );
        $this->assertGreaterThan( 0, $theirs );
    }

    /**
     * A player reads their own record and nobody else's.
     *
     * The persona row is `self`-scoped, so the scope target is the WP user
     * and not the player id — the same shape `my_evaluations` and
     * `my_journey` carry.
     */
    public function test_a_player_reads_their_own_record_only(): void {
        $this->seedTeam( self::TEAM_A );
        $mine = $this->seedPlayer( self::TEAM_A );

        $uid       = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $other_uid = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $this->linkPlayerToUser( $mine, $uid );

        $this->assertTrue(
            MatrixGate::can( $uid, 'player_tournaments', MatrixGate::READ, MatrixGate::SCOPE_SELF, $uid )
        );
        $this->assertFalse(
            MatrixGate::can( $uid, 'player_tournaments', MatrixGate::READ, MatrixGate::SCOPE_SELF, $other_uid ),
            'self scope is exactly one person'
        );
    }

    /** A parent reads their child's record, and not another family's. */
    public function test_a_parent_reads_their_own_child_and_no_other(): void {
        global $wpdb;
        $this->seedTeam( self::TEAM_A );
        $child       = $this->seedPlayer( self::TEAM_A );
        $other_child = $this->seedPlayer( self::TEAM_A );

        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => 1,
            'player_id'      => $child,
            'parent_user_id' => $parent,
        ] );

        $this->assertTrue(
            MatrixGate::can( $parent, 'player_tournaments', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $child ),
            'the outer gate admits a linked parent'
        );
        $this->assertFalse(
            MatrixGate::can( $parent, 'player_tournaments', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $other_child ),
            'and reaches no other family'
        );
        $this->assertTrue(
            AuthorizationService::parentCanViewSection( $parent, $child, 'tournaments' ),
            'and the section is shared by default, so no family loses access when this ships'
        );

        ( new PlayerParentVisibilityRepository() )->setVisibility( $child, 'tournaments', false );

        $this->assertFalse(
            AuthorizationService::parentCanViewSection( $parent, $child, 'tournaments' ),
            'a child who closes the section closes it'
        );
    }

    /** The child's choice never reaches the child, nor the staff. */
    public function test_the_section_choice_binds_only_the_parent(): void {
        $this->seedTeam( self::TEAM_A );
        $child = $this->seedPlayer( self::TEAM_A );
        $uid   = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $this->linkPlayerToUser( $child, $uid );

        ( new PlayerParentVisibilityRepository() )->setVisibility( $child, 'tournaments', false );

        $this->assertTrue(
            AuthorizationService::parentCanViewSection( $uid, $child, 'tournaments' ),
            'a player always reads their own record'
        );
        $this->assertTrue(
            AuthorizationService::parentCanViewSection( $this->seedCoachOnTeam( self::TEAM_A ), $child, 'tournaments' ),
            'and the coaches are governed by the matrix, not by the family setting'
        );
    }

    /** `tournaments` is a real, listed section — not an unknown key that silently answers true. */
    public function test_tournaments_is_a_listed_section(): void {
        $this->assertContains( 'tournaments', PlayerParentVisibilityRepository::SECTIONS );
    }

    /** @return array<int, array<string,string>> */
    private function seedRows(): array {
        $rows = require TT_PLUGIN_DIR . 'config/authorization_seed.php';
        $this->assertIsArray( $rows );
        return $rows;
    }

    private function seedTeam( int $team_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [
            'id'      => $team_id,
            'name'    => 'Team ' . $team_id,
            'club_id' => 1,
        ] );
    }

    private function seedPlayer( int $team_id ): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'first_name' => 'Tournament',
            'last_name'  => 'Player',
            'team_id'    => $team_id,
            'club_id'    => 1,
            'status'     => 'active',
        ] );
        $this->assertNotFalse( $ok, 'player insert must succeed' );
        return (int) $wpdb->insert_id;
    }

    private function linkPlayerToUser( int $player_id, int $user_id ): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'wp_user_id' => $user_id ], [ 'id' => $player_id ] );
    }

    private function seedCoachOnTeam( int $team_id ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Squad',
            'last_name'  => 'Coach',
            'role_type'  => 'coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => 1,
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        return $uid;
    }
}
