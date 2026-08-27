<?php
namespace TT\Modules\Invitations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Comms\Domain\Recipient;

/**
 * InvitationEmailNotifier (#1902) — emails the accept link when an
 * invitation is created. Listens to `tt_invitation_created` and dispatches
 * the `invitation_email` Comms template to the invite's pre-fill email.
 *
 * Transactional: the `*_OPERATIONAL` message type bypasses opt-out /
 * quiet-hours / rate-limit, so an invitee is never withheld their invite.
 *
 * An invite with no usable email is a deliberate path, not a failure —
 * the copy-link / WhatsApp share flow still stands — but it is recorded
 * at info level so support can tell "no email was ever intended" from
 * "the email didn't arrive" (#2602). A missing token is a genuine data
 * anomaly and logs as an error.
 */
final class InvitationEmailNotifier {

    public static function register(): void {
        add_action( 'tt_invitation_created', [ self::class, 'onCreated' ], 10, 2 );
    }

    public static function onCreated( int $invitation_id, string $kind ): void {
        if ( $invitation_id <= 0 ) return;

        $invitation = ( new InvitationsRepository() )->find( $invitation_id );

        // #2964 — a deferred invitation carries no `sent_at`. Nobody is
        // mailed until someone explicitly sends it, which is the point:
        // an admin setting a club up wants to check the place works before
        // credentials reach their coaches. Reuses the same tolerance the
        // link-only path already relies on — an invitation that exists
        // without an email having gone out is a supported state, not a
        // failure.
        if ( $invitation && (string) ( $invitation->sent_at ?? '' ) === '' ) {
            Logger::info( 'Invitation email held — send deferred', [
                'invitation_id' => $invitation_id,
                'kind'          => $kind,
            ] );
            return;
        }

        self::deliver( $invitation, $invitation_id, $kind );
    }

    /**
     * Dispatch for an invitation whose send has just been authorised.
     *
     * Separate entry point so `InvitationService::send()` does not have to
     * fake the creation hook to get a deferred invitation delivered.
     */
    public static function dispatch( int $invitation_id, string $kind ): void {
        if ( $invitation_id <= 0 ) return;
        self::deliver( ( new InvitationsRepository() )->find( $invitation_id ), $invitation_id, $kind );
    }

    /** @param object|null $invitation */
    private static function deliver( $invitation, int $invitation_id, string $kind ): void {
        if ( ! $invitation ) {
            Logger::error( 'Invitation email skipped — invitation row not found', [
                'invitation_id' => $invitation_id,
                'kind'          => $kind,
            ] );
            return;
        }

        // Link-only invite: no email was ever intended. Recorded, not
        // flagged — the share-a-link flow is a supported path.
        $email = sanitize_email( (string) ( $invitation->prefill_email ?? '' ) );
        if ( $email === '' || ! is_email( $email ) ) {
            Logger::info( 'Invitation email skipped — no usable pre-fill email, link-only invite', [
                'invitation_id' => $invitation_id,
                'kind'          => $kind,
            ] );
            return;
        }

        // A token-less invitation cannot produce a working accept link.
        // That is a data anomaly, not a supported path.
        $token = (string) ( $invitation->token ?? '' );
        if ( $token === '' ) {
            Logger::error( 'Invitation email skipped — invitation has no token', [
                'invitation_id' => $invitation_id,
                'kind'          => $kind,
            ] );
            return;
        }

        $recipient = new Recipient(
            0,                          // not a WP user yet
            Recipient::KIND_SYSTEM,
            null,
            $email,
            '',
            (string) ( $invitation->locale ?? '' )
        );

        $inviter      = (int) ( $invitation->created_by ?? 0 );
        $inviter_name = $inviter > 0 ? self::displayName( $inviter ) : '';
        $academy_name = get_bloginfo( 'name' );

        do_action(
            'tt_comms_dispatch',
            'invitation_email',
            [
                'first_name'   => (string) ( $invitation->prefill_first_name ?? '' ),
                'inviter_name' => $inviter_name !== '' ? $inviter_name : (string) $academy_name,
                'academy_name' => (string) $academy_name,
                'accept_url'   => ( new InvitationService() )->acceptanceUrl( $token ),
                'ttl_days'     => self::ttlDays( (string) ( $invitation->expires_at ?? '' ) ),
            ],
            [ $recipient ],
            [ 'message_type' => 'invitation_email_OPERATIONAL' ]
        );
    }

    private static function displayName( int $user_id ): string {
        $u = get_userdata( $user_id );
        return $u ? (string) $u->display_name : '';
    }

    private static function ttlDays( string $expires_at ): int {
        if ( $expires_at === '' ) return 7;
        $ts = strtotime( $expires_at );
        if ( $ts === false ) return 7;
        return max( 1, (int) ceil( ( $ts - time() ) / DAY_IN_SECONDS ) );
    }
}
