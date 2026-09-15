<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Alerts\Definitions\InvitationNeverSentAlert;
use TT\Modules\Alerts\Definitions\InvitationStaleAlert;
use TT\Modules\Alerts\Domain\AlertContext;

/**
 * #3387 — `onboarding.invitation_never_sent`.
 *
 * The condition is an absence: a row exists, nothing was mailed, and no
 * screen outside Setup says so. Every test therefore asserts both
 * directions — that the alert appears for a held invitation, and that it
 * does not appear for each of the states that look similar and are fine
 * (just created, already sent, accepted, revoked, deleted).
 *
 * The last test is the one that matters most: the two invitation
 * definitions must partition the invitations between them, never both
 * report the same row.
 */
final class AlertsInvitationNeverSentTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    /** @var int */
    private $admin;

    /** @var ConfigService */
    private $config;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p     = $wpdb->prefix;
        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->config = new ConfigService();
        $wpdb->query( "DELETE FROM {$this->p}tt_invitations" );
    }

    public function test_it_is_registered_in_the_catalogue(): void {
        $keys = array_map(
            static fn( $a ): string => $a->key(),
            \TT\Modules\Alerts\AlertsModule::registerCoreAlerts( [] )
        );
        $this->assertContains( 'onboarding.invitation_never_sent', $keys );
    }

    public function test_a_held_invitation_raises_the_alert(): void {
        $this->heldInvitation( 5 );

        $out = ( new InvitationNeverSentAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertNotSame( [], $out );
        $this->assertSame( 'invitation_backlog', $out[0]->subjectType );
        $this->assertNull( $out[0]->playerId );
        $this->assertContains( $this->admin, array_map( static fn( $o ) => $o->recipientUserId, $out ) );
    }

    /** The count is the point — it says whether one person or a staff. */
    public function test_the_title_and_payload_carry_the_count(): void {
        $this->heldInvitation( 5 );
        $this->heldInvitation( 5 );
        $this->heldInvitation( 5 );

        $out = ( new InvitationNeverSentAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertStringContainsString( '3', $out[0]->title() );
        $this->assertSame( 3, (int) $out[0]->payload['unsent_count'] );
    }

    /** One occurrence for the backlog, not one per invitation. */
    public function test_three_held_invitations_produce_one_occurrence_per_recipient(): void {
        $this->heldInvitation( 5 );
        $this->heldInvitation( 5 );
        $this->heldInvitation( 5 );

        $out        = ( new InvitationNeverSentAlert() )->evaluate( new AlertContext( $this->club ) );
        $recipients = array_map( static fn( $o ) => $o->recipientUserId, $out );

        $this->assertSame( $recipients, array_unique( $recipients ) );
    }

    /** Held for the first hours is the feature working, not a fault. */
    public function test_an_invitation_created_moments_ago_produces_nothing(): void {
        $this->heldInvitation( 0 );

        $this->assertSame( [], ( new InvitationNeverSentAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_sending_it_resolves_the_condition(): void {
        global $wpdb;
        $id = $this->heldInvitation( 5 );

        $this->assertNotSame( [], ( new InvitationNeverSentAlert() )->evaluate( new AlertContext( $this->club ) ) );

        $wpdb->update( "{$this->p}tt_invitations", [ 'sent_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );

        $this->assertSame( [], ( new InvitationNeverSentAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_deleting_it_resolves_the_condition(): void {
        global $wpdb;
        $id = $this->heldInvitation( 5 );

        $this->assertNotSame( [], ( new InvitationNeverSentAlert() )->evaluate( new AlertContext( $this->club ) ) );

        $wpdb->delete( "{$this->p}tt_invitations", [ 'id' => $id ] );

        $this->assertSame( [], ( new InvitationNeverSentAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /** Revoking it takes it out of the set `send()` would act on. */
    public function test_a_revoked_held_invitation_produces_nothing(): void {
        global $wpdb;
        $id = $this->heldInvitation( 5 );
        $wpdb->update(
            "{$this->p}tt_invitations",
            [ 'status' => 'revoked', 'revoked_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ]
        );

        $this->assertSame( [], ( new InvitationNeverSentAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_the_threshold_comes_from_config_not_from_code(): void {
        $this->heldInvitation( 3 );
        $this->config->set( InvitationNeverSentAlert::CONFIG_KEY_UNSENT_DAYS, '7' );

        $this->assertSame( [], ( new InvitationNeverSentAlert() )->evaluate( new AlertContext( $this->club ) ) );

        $this->config->set( InvitationNeverSentAlert::CONFIG_KEY_UNSENT_DAYS, '2' );

        $this->assertNotSame( [], ( new InvitationNeverSentAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    /**
     * A whole-install verdict must not be reached inside a run narrowed to
     * one subject — the reconcile would resolve what it never looked at.
     */
    public function test_a_narrowed_run_does_not_evaluate_the_backlog(): void {
        $this->heldInvitation( 5 );

        $narrowed = new AlertContext( $this->club, 'invitation', [ 1 ] );

        $this->assertSame( [], ( new InvitationNeverSentAlert() )->evaluate( $narrowed ) );
    }

    /**
     * The partition. One invitation is either waiting to be sent or waiting
     * to be accepted, never reported as both.
     */
    public function test_the_two_invitation_definitions_never_report_the_same_row(): void {
        global $wpdb;
        $team   = $this->insertTeam();
        $player = $this->insertPlayer( $team );

        // Held: this definition's business, not its sibling's.
        $this->heldInvitation( 30, $player );

        $never_sent = ( new InvitationNeverSentAlert() )->evaluate( new AlertContext( $this->club ) );
        $stale      = ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) );
        $this->assertNotSame( [], $never_sent );
        $this->assertSame( [], $stale, 'A held invitation must not read as never accepted.' );

        // Sent and unaccepted: the sibling's business, not this one's.
        $wpdb->query( "UPDATE {$this->p}tt_invitations SET sent_at = created_at" );

        $never_sent = ( new InvitationNeverSentAlert() )->evaluate( new AlertContext( $this->club ) );
        $stale      = ( new InvitationStaleAlert() )->evaluate( new AlertContext( $this->club ) );
        $this->assertSame( [], $never_sent );
        $this->assertNotSame( [], $stale, 'A sent, unaccepted invitation is still stale.' );
    }

    // -- fixtures --------------------------------------------------------

    private function daysAgo( int $n ): string {
        return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - $n * DAY_IN_SECONDS );
    }

    private function daysAhead( int $n ): string {
        return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) + $n * DAY_IN_SECONDS );
    }

    /** A pending invitation nobody has been mailed about. */
    private function heldInvitation( int $days_old, int $player_id = 0 ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_invitations", [
            'club_id'            => $this->club,
            'token'              => wp_generate_password( 32, false ),
            'kind'               => $player_id > 0 ? 'player' : 'staff',
            'target_player_id'   => $player_id > 0 ? $player_id : null,
            'prefill_first_name' => 'Nieuwe',
            'prefill_last_name'  => 'Trainer',
            'prefill_email'      => 'trainer@example.test',
            'created_by'         => $this->admin,
            'created_at'         => $this->daysAgo( $days_old ),
            'sent_at'            => null,
            'expires_at'         => $this->daysAhead( 7 ),
            'status'             => 'pending',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U13 invites' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'     => $this->club,
            'team_id'     => $team_id,
            'first_name'  => 'Invite',
            'last_name'   => 'Fixture',
            'status'      => 'active',
            'date_joined' => gmdate( 'Y-m-d', current_time( 'timestamp', true ) - 400 * DAY_IN_SECONDS ),
        ] );
        return (int) $wpdb->insert_id;
    }
}
