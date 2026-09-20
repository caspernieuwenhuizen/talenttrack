<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Players\ScoutPlayerLinks;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;

/**
 * #3566 — a scout's `player` scope resolves through their links.
 *
 * Before this, `MatrixGate` knew two ways to hold `player` scope: being
 * the player, or being their guardian. A scout is neither, so every
 * player-scoped scout row in the seed was a dead grant — seeded,
 * documented, and resolving to false. These tests pin the branch that
 * fixes it, and the three things it must NOT do.
 *
 * The negative cases matter as much as the positive one. A scope branch
 * that is too generous here hands one persona another persona's reads
 * over a child's record.
 */
final class ScoutPlayerScopeTest extends WP_UnitTestCase {

    private const PERSONA = 'scout';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
    }

    public function tear_down(): void {
        $repo = new MatrixRepository();
        $repo->removeRow( self::PERSONA, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER );
        $repo->removeRow( 'parent', 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER );
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    /** An active player in club 1. */
    private function makePlayer( string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Scope',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A trial case for a player, with one staff seat for $uid. */
    private function makePanelSeat( int $player_id, int $uid, ?string $unassigned_at = null ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_trial_cases", [
            'club_id'    => 1,
            'player_id'  => $player_id,
            'status'     => 'open',
            'start_date' => '2026-01-01',
        ] );
        $case_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_trial_case_staff", [
            'club_id'       => 1,
            'case_id'       => $case_id,
            'user_id'       => $uid,
            'unassigned_at' => $unassigned_at,
        ] );
        return $case_id;
    }

    private function grantScoutEvaluationsAtPlayerScope(): void {
        ( new MatrixRepository() )->setRow( self::PERSONA, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, '' );
        MatrixRepository::clearCache();
    }

    public function test_active_panel_seat_grants_player_scope(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->assertContains( self::PERSONA, PersonaResolver::effectivePersonas( $uid ) );

        $mine      = $this->makePlayer( 'OnMyPanel' );
        $unrelated = $this->makePlayer( 'Unrelated' );
        $this->makePanelSeat( $mine, $uid );
        $this->grantScoutEvaluationsAtPlayerScope();

        $this->assertTrue(
            MatrixGate::can( $uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $mine ),
            'a scout on an active panel holds player scope for that case\'s player'
        );
        $this->assertFalse(
            MatrixGate::can( $uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $unrelated ),
            'the link must not leak to a player the scout has no connection to'
        );
    }

    public function test_unassigning_the_seat_ends_the_link(): void {
        $uid    = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $player = $this->makePlayer( 'Unassigned' );
        $this->makePanelSeat( $player, $uid, '2026-02-01 10:00:00' );
        $this->grantScoutEvaluationsAtPlayerScope();

        $this->assertFalse(
            MatrixGate::can( $uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $player ),
            'a seat with unassigned_at set is not a link any more'
        );
    }

    public function test_assignment_list_grants_player_scope(): void {
        $uid    = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $player = $this->makePlayer( 'Assigned' );
        update_user_meta( $uid, 'tt_scout_player_ids', wp_json_encode( [ $player ] ) );
        $this->grantScoutEvaluationsAtPlayerScope();

        $this->assertTrue(
            MatrixGate::can( $uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $player ),
            'a player in tt_scout_player_ids is a link, with no panel seat needed'
        );
    }

    public function test_released_player_drops_out_of_scope(): void {
        global $wpdb;
        $uid    = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $player = $this->makePlayer( 'Released' );
        $this->makePanelSeat( $player, $uid );
        $this->grantScoutEvaluationsAtPlayerScope();

        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'status' => 'released' ], [ 'id' => $player ] );

        $this->assertFalse(
            MatrixGate::can( $uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $player ),
            'a release ends the scout link, as #3476 settled it ends the guardian link'
        );
    }

    /**
     * The persona guard. Player scope was persona-blind before #3566; left
     * that way, a coach-and-parent user sitting on a panel would pick up the
     * PARENT rows' player-scoped reads over that trialist.
     */
    public function test_panel_seat_does_not_grant_another_personas_rows(): void {
        $uid    = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $player = $this->makePlayer( 'NotMyChild' );
        $this->makePanelSeat( $player, $uid );

        // The parent persona's row, not the scout's.
        ( new MatrixRepository() )->setRow( 'parent', 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, '' );
        MatrixRepository::clearCache();

        $this->assertFalse(
            MatrixGate::can( $uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $player ),
            'a panel seat is a SCOUT link; it must not satisfy the parent persona\'s player scope'
        );
    }

    public function test_resolver_returns_both_link_kinds_once(): void {
        $uid      = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $panelled = $this->makePlayer( 'Panelled' );
        $assigned = $this->makePlayer( 'Assigned' );

        $this->makePanelSeat( $panelled, $uid );
        // Deliberately list the panelled player too: the union must dedupe.
        update_user_meta( $uid, 'tt_scout_player_ids', wp_json_encode( [ $assigned, $panelled ] ) );

        $ids = ScoutPlayerLinks::playerIds( $uid );
        sort( $ids );
        $expected = [ $panelled, $assigned ];
        sort( $expected );

        $this->assertSame( $expected, $ids, 'both link kinds resolve, de-duplicated' );
        $this->assertTrue( ScoutPlayerLinks::isLinkedTo( $uid, $assigned ) );
        $this->assertTrue( ScoutPlayerLinks::hasAnyLink( $uid ) );
    }

    public function test_scout_with_no_links_holds_no_player_scope(): void {
        $uid    = self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $player = $this->makePlayer( 'Nobody' );
        $this->grantScoutEvaluationsAtPlayerScope();

        $this->assertSame( [], ScoutPlayerLinks::playerIds( $uid ) );
        $this->assertFalse(
            MatrixGate::can( $uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $player ),
            'an unlinked scout holds no player scope at all'
        );
        $this->assertFalse(
            MatrixGate::canAnyScope( $uid, 'evaluations', MatrixGate::READ ),
            'and no any-scope read either'
        );
    }

    /**
     * The regression guard for the branch that already existed. #3476's
     * guardian rule must behave exactly as before.
     */
    public function test_guardian_branch_is_unchanged(): void {
        global $wpdb;
        $uid    = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $child  = $this->makePlayer( 'MyChild' );
        $other  = $this->makePlayer( 'OtherChild' );

        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => 1,
            'player_id'      => $child,
            'parent_user_id' => $uid,
            'is_primary'     => 1,
        ] );
        ( new MatrixRepository() )->setRow( 'parent', 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, '' );
        MatrixRepository::clearCache();

        $this->assertTrue(
            MatrixGate::can( $uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $child ),
            'a guardian still holds player scope over their own child'
        );
        $this->assertFalse(
            MatrixGate::can( $uid, 'evaluations', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $other ),
            'and still holds none over another family\'s child'
        );
    }
}
