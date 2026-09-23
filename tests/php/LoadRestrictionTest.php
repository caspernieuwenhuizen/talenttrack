<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Vct\Rest\VctPhvFlagsRestController;
use TT\Modules\Vct\Services\LoadRestriction;
use TT\Modules\Vct\Services\LoadRestrictionAccess;

/**
 * #4033 — the flag is a load restriction, and one gate decides it.
 *
 * `tt_player_phv_flags` was named after the growth spurt it was first
 * built for, and every screen followed the table: a player recovering
 * from a sprained ankle wore a pill reading "PHV" while the REST route
 * described the same column as "in a growth spurt" and the panel
 * expanded the initials as "Physical / Health / Vitality". Three names,
 * one column, none of them true for six of the seven reasons it offers.
 *
 * Two defects are pinned here. The vocabulary — a growth spurt is one
 * reason among the others, and the reason list the picker offers is the
 * list the write path accepts, because they used to be two hardcoded
 * copies 140 lines apart. And the gate — the REST route asked for
 * `tt_vct_plan` plus team VCT scope while the panel on the same flag
 * asked for `tt_edit_players`, so who could set it depended on which
 * surface you reached it through.
 */
final class LoadRestrictionTest extends WP_UnitTestCase {

    private int $teamId        = 0;
    private int $otherId       = 0;
    private int $playerId      = 0;
    private int $otherPlayerId = 0;
    private int $looseId       = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Restrict Mine' ] );
        $this->teamId = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'Restrict Theirs' ] );
        $this->otherId = (int) $wpdb->insert_id;

        $this->playerId      = $this->player( $this->teamId,  'Mine' );
        $this->otherPlayerId = $this->player( $this->otherId, 'Theirs' );
        $this->looseId       = $this->player( 0,              'Unassigned' );
    }

    private function player( int $team_id, string $last ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'team_id'    => $team_id,
            'first_name' => 'Load',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A head coach with a person row and an active scope on one team. */
    private function coachOn( int $team_id ): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Restrict',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        return $uid;
    }

    // -----------------------------------------------------------------
    // the vocabulary
    // -----------------------------------------------------------------

    /**
     * The name of the flag says what it is. It used to say "PHV", which
     * named one of its seven reasons.
     */
    public function test_the_flag_is_not_named_after_one_of_its_reasons(): void {
        $this->assertStringNotContainsStringIgnoringCase(
            'PHV',
            LoadRestriction::label(),
            'the pill, the panel heading and the banner all read this label'
        );
        $this->assertNotSame( '', LoadRestriction::label() );
    }

    /**
     * The growth spurt keeps its place — as a reason, with the acronym
     * in the label so a coach who learned it as PHV still recognises it.
     */
    public function test_a_growth_spurt_is_one_reason_among_the_others(): void {
        $this->assertTrue( LoadRestriction::isReason( 'growth_spurt' ) );
        $this->assertStringContainsString(
            'PHV',
            LoadRestriction::reasonLabel( 'growth_spurt' ),
            'the acronym belongs on the reason it actually describes'
        );
    }

    /** An injury reason must not carry the growth-spurt vocabulary. */
    public function test_an_injury_is_not_described_as_a_growth_spurt(): void {
        foreach ( [ 'injury_knee', 'injury_ankle' ] as $key ) {
            $label = LoadRestriction::reasonLabel( $key );
            $this->assertNotSame( '', $label, "{$key} must still be offered" );
            $this->assertStringNotContainsStringIgnoringCase( 'PHV', $label );
            $this->assertStringNotContainsStringIgnoringCase( 'growth', $label );
        }
    }

    /**
     * No migration: every key that could already be in the column is
     * still a key the write path accepts.
     */
    public function test_the_stored_reason_keys_are_unchanged(): void {
        foreach ( [ 'injury_knee', 'injury_ankle', 'asthma', 'cardiac', 'other_medical', 'temp_fatigue' ] as $key ) {
            $this->assertTrue(
                LoadRestriction::isReason( $key ),
                "{$key} is already stored in tt_player_phv_flags.reason_key; dropping it would orphan rows"
            );
        }
    }

    /**
     * The picker and the whitelist were two hardcoded arrays. Adding a
     * reason to one and not the other silently discarded the coach's
     * choice on save.
     */
    public function test_the_picker_and_the_write_whitelist_are_one_list(): void {
        $this->assertSame(
            array_keys( LoadRestriction::reasons() ),
            LoadRestriction::reasonKeys(),
            'the list offered and the list accepted must be the same list'
        );
        foreach ( LoadRestriction::reasonKeys() as $key ) {
            $this->assertTrue( LoadRestriction::isReason( $key ) );
        }
    }

    public function test_an_unknown_reason_is_refused_and_unlabelled(): void {
        $this->assertFalse( LoadRestriction::isReason( 'phv' ) );
        $this->assertFalse( LoadRestriction::isReason( '' ) );
        $this->assertSame( '', LoadRestriction::reasonLabel( 'made_up' ) );
    }

    /** The ceilings are the intensity bands, 1-4, lowest first. */
    public function test_the_intensity_ceilings_cover_the_four_bands(): void {
        $this->assertSame( [ 1, 2, 3, 4 ], array_keys( LoadRestriction::ceilings() ) );
    }

    // -----------------------------------------------------------------
    // the gate
    // -----------------------------------------------------------------

    public function test_a_coach_sets_a_restriction_on_their_own_squad(): void {
        $uid = $this->coachOn( $this->teamId );

        $this->assertTrue(
            LoadRestrictionAccess::canEdit( $uid, $this->playerId ),
            'a head coach plans for this team, so they may restrict its players'
        );
    }

    public function test_a_coach_does_not_reach_another_squad(): void {
        $uid = $this->coachOn( $this->teamId );

        $this->assertFalse(
            LoadRestrictionAccess::canEdit( $uid, $this->otherPlayerId ),
            'the grant is team-scoped; another team\'s player is out of reach'
        );
    }

    /**
     * A restriction only means something against a plan, and plans are
     * made per team. The REST route already answered this way; the panel
     * did not ask.
     */
    public function test_a_player_with_no_team_carries_no_restriction(): void {
        $uid = $this->coachOn( $this->teamId );

        $this->assertFalse( LoadRestrictionAccess::canEdit( $uid, $this->looseId ) );
    }

    public function test_a_user_with_no_grants_is_refused(): void {
        $uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $this->assertFalse( LoadRestrictionAccess::canEdit( $uid, $this->playerId ) );
        $this->assertFalse( LoadRestrictionAccess::canEdit( 0, $this->playerId ), 'a logged-out reader too' );
        $this->assertFalse( LoadRestrictionAccess::canEdit( $uid, 0 ) );
    }

    /**
     * The point of the fix: the REST route and the on-screen panel give
     * the same answer, because they ask the same object.
     */
    public function test_the_rest_route_and_the_panel_share_one_gate(): void {
        $uid = $this->coachOn( $this->teamId );
        wp_set_current_user( $uid );

        foreach ( [ $this->playerId, $this->otherPlayerId, $this->looseId ] as $player_id ) {
            $request = new \WP_REST_Request( 'PATCH', "/talenttrack/v1/vct/players/{$player_id}/phv-flag" );
            $request->set_param( 'id', $player_id );

            $this->assertSame(
                LoadRestrictionAccess::canEdit( $uid, $player_id ),
                VctPhvFlagsRestController::can_write( $request ),
                "one flag, one answer — player {$player_id}"
            );
        }

        wp_set_current_user( 0 );
    }
}
