<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\REST\StravaRestController;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\MatrixGate;

/**
 * #3468 / #3481 — the unified profile read from the seats it now serves.
 *
 * #2107 pointed a player and a parent at the same `FrontendPlayerDetailView`
 * a coach uses. Two of its gates had not been re-read from those seats:
 *
 *   - **Injuries** showed the tab on `canAnyScope( 'player_injuries' )`, which
 *     a player holds at `self` and a parent at `player`, and then denied the
 *     panel through `canRecordInjury()`, which only ever checked `global` and
 *     `team`. A guardian met a tab whose entire content was a refusal.
 *   - **Strava** was added with no capability check at all.
 *
 * What is pinned here is the authorization, not the markup: whether the tab
 * strip shows a tab is a rendering detail, but whether the domain says yes is
 * the contract the tab and the panel must now share.
 */
final class PlayerProfileSeatAccessTest extends WP_UnitTestCase {

    private int $team_id  = 0;
    private int $player_id = 0;
    private int $player_uid = 0;
    private int $parent_uid = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [
            'club_id' => 1,
            'name'    => 'Seat Test U13',
        ] );
        $this->team_id = (int) $wpdb->insert_id;

        $this->player_uid = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Seat',
            'last_name'  => 'Subject',
            'team_id'    => $this->team_id,
            'wp_user_id' => $this->player_uid,
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $this->parent_uid = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => 1,
            'player_id'      => $this->player_id,
            'parent_user_id' => $this->parent_uid,
            'is_primary'     => 1,
        ] );
    }

    /**
     * The regression: the seed grants the player `player_injuries` at `self`,
     * and the gate behind the tab never asked for `self`.
     */
    public function test_a_player_may_read_their_own_injuries(): void {
        $this->assertTrue(
            MatrixGate::canAnyScope( $this->player_uid, 'player_injuries', MatrixGate::READ ),
            'Precondition: the player persona holds player_injuries read.'
        );
        $this->assertTrue(
            AuthorizationService::canRecordInjury( $this->player_uid, $this->player_id, MatrixGate::READ ),
            'A player must be able to read the injury record the matrix grants them.'
        );
    }

    /** The parent's grant is at `player` scope, on their own child only. */
    public function test_a_parent_may_read_their_own_childs_injuries(): void {
        $this->assertTrue(
            AuthorizationService::canRecordInjury( $this->parent_uid, $this->player_id, MatrixGate::READ ),
            'A guardian must be able to read their own child\'s injury record (CLAUDE.md §1).'
        );
    }

    /** …and nobody else's. The widened gate must not widen the scope. */
    public function test_a_parent_may_not_read_another_familys_injuries(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Someone',
            'last_name'  => 'Else',
            'team_id'    => $this->team_id,
            'wp_user_id' => null,
        ] );
        $other_player = (int) $wpdb->insert_id;

        $this->assertFalse(
            AuthorizationService::canRecordInjury( $this->parent_uid, $other_player, MatrixGate::READ ),
            'A guardian must not reach another family\'s injury record.'
        );
    }

    /** A player reads; a player does not write. The activity argument still bites. */
    public function test_a_player_may_not_change_their_own_injury_record(): void {
        $this->assertFalse(
            AuthorizationService::canRecordInjury( $this->player_uid, $this->player_id, MatrixGate::CHANGE ),
            'Read access to an injury record is not permission to edit it.'
        );
    }

    /** A stranger is unaffected by the two new branches. */
    public function test_an_unrelated_user_reads_nothing(): void {
        $stranger = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $this->assertFalse(
            AuthorizationService::canRecordInjury( $stranger, $this->player_id, MatrixGate::READ )
        );
    }

    /**
     * #3481 — the Strava tab was ungated, and the panel behind it offers a
     * consent checkbox and a connect button. The endpoint already refused a
     * guardian; this pins that, because a hidden tab is not a boundary.
     */
    public function test_a_parent_may_not_manage_their_childs_strava_connection(): void {
        wp_set_current_user( $this->parent_uid );

        $this->assertFalse(
            StravaRestController::canManagePlayer( $this->player_id ),
            'A guardian holds no strava_integration grant and must not be able to connect their child\'s account.'
        );
    }

    /** The player themselves still can — that is the whole point of the self path. */
    public function test_a_player_may_manage_their_own_strava_connection(): void {
        wp_set_current_user( $this->player_uid );

        $this->assertTrue(
            StravaRestController::canManagePlayer( $this->player_id ),
            'A player must still be able to connect their own Strava account.'
        );
    }

    /** And the tab they are not offered is one the matrix agrees they lack. */
    public function test_a_parent_holds_no_strava_integration_grant(): void {
        $this->assertFalse(
            MatrixGate::canAnyScope( $this->parent_uid, 'strava_integration', MatrixGate::READ ),
            'The parent persona holds no strava_integration row; the tab gate reads this.'
        );
    }
}
