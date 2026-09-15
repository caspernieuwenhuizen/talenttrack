<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Comms\Domain\MessageAudience;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\OptOut\OptOutPolicy;
use TT\Shared\Frontend\FrontendMySettingsView;

/**
 * #3389 — the preferences card offered every persona the same rows.
 *
 * A thirteen-year-old was asked whether they wanted "reminders about your
 * own development review" — a message sent to coaches about theirs — plus
 * invitations to parent meetings, welcome mail for trial players, and
 * alerts about repeated absence that go to club administrators.
 *
 * The card's own docblock had argued the opposite case, and it was right
 * at the time: a spare row costs nothing, a missing row for mail you do
 * receive is the gap the card exists to close. So these tests pin both
 * directions. The filter must remove the staff rows from a player's card
 * AND leave every row that reaches them, and the unknown cases — an
 * unmapped type, a user with no persona — must fail towards showing too
 * much rather than too little.
 */
final class MessageAudienceFilterTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
    }

    /* ---- the map ---------------------------------------------------- */

    public function test_every_opt_outable_type_declares_an_audience(): void {
        // The runtime default is "everyone", so a missing entry is silent.
        // `tools/check-message-audiences.php` is the real gate; this is the
        // same assertion where a developer will actually see it fail.
        foreach ( MessageType::all() as $type ) {
            if ( MessageType::isOperational( $type ) ) continue;

            $this->assertNotEquals(
                MessageAudience::all(),
                MessageType::audiences( $type ),
                sprintf( 'every opt-outable type declares an audience; "%s" falls through to the default', $type )
            );
        }
    }

    public function test_an_unmapped_type_is_visible_to_everyone(): void {
        // Decision 5: the safe failure direction is a spare row, never a
        // lost toggle. Someone unable to mute mail they are receiving is a
        // GDPR-shaped problem; a redundant checkbox is not.
        $this->assertSame(
            MessageAudience::all(),
            MessageType::audiences( 'a_type_that_does_not_exist' )
        );
    }

    public function test_an_operational_type_is_not_in_the_map(): void {
        // An audience for something nobody can refuse is a fact nothing
        // reads, and having one invites filtering the always-sent block.
        $this->assertSame( MessageAudience::all(), MessageType::audiences( MessageType::TRAINING_CANCELLED ) );
        $this->assertSame( MessageAudience::all(), MessageType::audiences( MessageType::SAFEGUARDING_BROADCAST ) );
    }

    /* ---- persona resolution ------------------------------------------ */

    public function test_a_player_resolves_to_the_player_audience(): void {
        $this->assertSame(
            [ MessageAudience::PLAYER ],
            MessageAudience::forUser( $this->userWith( 'tt_player' ) )
        );
    }

    public function test_a_parent_resolves_to_the_parent_audience(): void {
        $this->assertSame(
            [ MessageAudience::PARENT ],
            MessageAudience::forUser( $this->userWith( 'tt_parent' ) )
        );
    }

    public function test_every_other_persona_resolves_to_staff(): void {
        foreach ( [ 'tt_club_admin', 'tt_head_dev', 'tt_scout', 'tt_team_manager', 'tt_staff' ] as $role ) {
            $this->assertSame(
                [ MessageAudience::STAFF ],
                MessageAudience::forUser( $this->userWith( $role ) ),
                $role . ' is staff for the purposes of who mail is addressed to'
            );
        }
    }

    public function test_a_user_with_no_persona_gets_every_audience(): void {
        // A bare WordPress account, or one whose roles were changed out
        // from under it. Same reasoning as an unmapped type.
        $this->assertSame( MessageAudience::all(), MessageAudience::forUser( $this->userWith( 'subscriber' ) ) );
        $this->assertSame( MessageAudience::all(), MessageAudience::forUser( 0 ) );
    }

    public function test_a_coach_who_is_also_a_parent_keeps_both(): void {
        // The reason forUser() returns a list. This person must keep every
        // row either persona receives, or the filter costs them a toggle.
        $user = $this->userWith( 'tt_parent' );
        get_user_by( 'id', $user )->add_role( 'tt_scout' );

        $mine = MessageAudience::forUser( $user );

        $this->assertContains( MessageAudience::PARENT, $mine );
        $this->assertContains( MessageAudience::STAFF, $mine );
    }

    /* ---- what each persona actually sees ----------------------------- */

    public function test_a_player_is_not_offered_staff_rows(): void {
        $html = $this->renderFor( $this->userWith( 'tt_player' ) );

        foreach ( [
            MessageType::STAFF_DEVELOPMENT_REMINDER,
            MessageType::METHODOLOGY_DELIVERED,
            MessageType::ATTENDANCE_FLAG,
            MessageType::PARENT_MEETING_INVITE,
            MessageType::TRIAL_PLAYER_WELCOME,
            MessageType::SCOUT_REPORT_DELIVERY,
            MessageType::SCHEDULED_REPORT,
            MessageType::TRIAL_INPUT_REMINDER,
            MessageType::ONBOARDING_NUDGE_INACTIVE,
        ] as $type ) {
            $this->assertStringNotContainsString(
                'value="' . $type . '"',
                $html,
                sprintf( 'a player does not receive "%s"; offering a switch for it is noise at best', $type )
            );
        }
    }

    public function test_a_player_keeps_every_row_that_reaches_them(): void {
        // The direction that matters more. #3389's constraint is that the
        // filter must never cost somebody a toggle for mail they get.
        $html = $this->renderFor( $this->userWith( 'tt_player' ) );

        foreach ( [
            MessageType::PDP_READY,
            MessageType::GOAL_NUDGE,
            MessageType::SELECTION_LETTER,
            MessageType::GUEST_PLAYER_INVITE,
            MessageType::NOTIFICATION,
            MessageType::SCHEDULE_CHANGE_FROM_SPOND,
            MessageType::LETTER_DELIVERY,
            MessageType::MASS_ANNOUNCEMENT,
            MessageType::ALERT_DIGEST,
            MessageType::DIRECT_MESSAGE,
        ] as $type ) {
            $this->assertStringContainsString( 'value="' . $type . '"', $html, $type );
        }
    }

    public function test_the_five_types_that_had_no_toggle_now_have_one(): void {
        // These were opt-outable with no switch on any screen — the
        // failure the card's docblock warned about, already shipped.
        // Three reach players, two are staff-only.
        $player = $this->renderFor( $this->userWith( 'tt_player' ) );
        $this->assertStringContainsString( 'value="' . MessageType::ALERT_DIGEST . '"', $player );
        $this->assertStringContainsString( 'value="' . MessageType::DIRECT_MESSAGE . '"', $player );

        $staff = $this->renderFor( $this->userWith( 'tt_head_dev' ) );
        $this->assertStringContainsString( 'value="' . MessageType::SCHEDULED_REPORT . '"', $staff );
        $this->assertStringContainsString( 'value="' . MessageType::TRIAL_INPUT_REMINDER . '"', $staff );
        $this->assertStringContainsString( 'value="' . MessageType::SCOUT_REPORT_DELIVERY . '"', $staff );
    }

    public function test_a_coach_still_sees_their_own_rows(): void {
        $html = $this->renderFor( $this->userWith( 'tt_head_dev' ) );

        $this->assertStringContainsString( 'value="' . MessageType::STAFF_DEVELOPMENT_REMINDER . '"', $html );
        $this->assertStringContainsString( 'value="' . MessageType::METHODOLOGY_DELIVERED . '"', $html );
        $this->assertStringContainsString( 'value="' . MessageType::ATTENDANCE_FLAG . '"', $html );
    }

    public function test_a_parent_sees_the_family_rows_and_not_the_staff_ones(): void {
        $html = $this->renderFor( $this->userWith( 'tt_parent' ) );

        $this->assertStringContainsString( 'value="' . MessageType::PARENT_MEETING_INVITE . '"', $html );
        $this->assertStringContainsString( 'value="' . MessageType::ONBOARDING_NUDGE_INACTIVE . '"', $html );
        $this->assertStringNotContainsString( 'value="' . MessageType::STAFF_DEVELOPMENT_REMINDER . '"', $html );
    }

    public function test_a_player_sees_fewer_rows_than_a_coach(): void {
        // The pilot report this came from was about volume, not taxonomy:
        // "as a player, there are too many settings available under my
        // settings". Worth asserting the outcome directly.
        $player = $this->countToggles( $this->renderFor( $this->userWith( 'tt_player' ) ) );
        $staff  = $this->countToggles( $this->renderFor( $this->userWith( 'tt_head_dev' ) ) );

        $this->assertGreaterThan( 0, $player );
        $this->assertLessThan( $staff, $player );
    }

    /* ---- the write path ---------------------------------------------- */

    public function test_saving_does_not_mute_a_hidden_type(): void {
        // The bug this guards is subtle and total: a hidden type has no
        // checkbox, so it reads as unticked in the POST. A player pressing
        // Save on a card that never offered STAFF_DEVELOPMENT_REMINDER
        // would mute it — and worse, a coach who is also a parent could
        // have rows muted by the other persona's card.
        $user = $this->userWith( 'tt_player' );
        wp_set_current_user( $user );

        $policy = new OptOutPolicy();
        $this->assertFalse( $policy->isOptedOut( $user, MessageType::STAFF_DEVELOPMENT_REMINDER ) );

        $_POST = [
            'tt_my_settings_action'           => 'update_comms_optout',
            'tt_my_settings_comms_nonce'      => wp_create_nonce( 'tt_my_settings_comms' ),
            // Everything the player's own card offers, ticked.
            'comms_opt_in'                    => [ MessageType::PDP_READY, MessageType::GOAL_NUDGE ],
        ];

        $this->renderFor( $user );
        $_POST = [];

        $this->assertFalse(
            $policy->isOptedOut( $user, MessageType::STAFF_DEVELOPMENT_REMINDER ),
            'a type the card never rendered must not be written by a save'
        );
    }

    public function test_an_existing_opt_out_for_a_hidden_type_survives(): void {
        // The filter is a render concern, not a data migration. Somebody
        // who muted a row before this shipped, and whose persona no longer
        // sees it, keeps the stored preference — if their roles change back
        // the card must not silently have un-muted it.
        $user   = $this->userWith( 'tt_player' );
        $policy = new OptOutPolicy();
        $policy->setOptedOut( $user, MessageType::ATTENDANCE_FLAG, true );

        wp_set_current_user( $user );
        $_POST = [
            'tt_my_settings_action'      => 'update_comms_optout',
            'tt_my_settings_comms_nonce' => wp_create_nonce( 'tt_my_settings_comms' ),
            'comms_opt_in'               => [ MessageType::PDP_READY ],
        ];
        $this->renderFor( $user );
        $_POST = [];

        $this->assertTrue( $policy->isOptedOut( $user, MessageType::ATTENDANCE_FLAG ) );
    }

    /* ---- helpers ------------------------------------------------------ */

    private function userWith( string $role ): int {
        return (int) self::factory()->user->create( [ 'role' => $role ] );
    }

    private function renderFor( int $user_id ): string {
        wp_set_current_user( $user_id );
        ob_start();
        FrontendMySettingsView::render();
        return (string) ob_get_clean();
    }

    private function countToggles( string $html ): int {
        return (int) preg_match_all( '/name="comms_opt_in\[\]"/', $html );
    }
}
