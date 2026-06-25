<?php
namespace TT\Modules\Invitations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Domain\Recipient;

/**
 * InvitationEmailNotifier (#1902) — emails the accept link when an
 * invitation is created. Listens to `tt_invitation_created` and dispatches
 * the `invitation_email` Comms template to the invite's pre-fill email.
 *
 * Transactional: the `*_OPERATIONAL` message type bypasses opt-out /
 * quiet-hours / rate-limit, so an invitee is never withheld their invite.
 * When the invite carries no usable email it silently no-ops — the
 * copy-link / WhatsApp share path on the UI still stands.
 */
final class InvitationEmailNotifier {

    public static function register(): void {
        add_action( 'tt_invitation_created', [ self::class, 'onCreated' ], 10, 2 );
    }

    public static function onCreated( int $invitation_id, string $kind ): void {
        if ( $invitation_id <= 0 ) return;

        $invitation = ( new InvitationsRepository() )->find( $invitation_id );
        if ( ! $invitation ) return;

        $email = sanitize_email( (string) ( $invitation->prefill_email ?? '' ) );
        if ( $email === '' || ! is_email( $email ) ) return; // link-only fallback.

        $token = (string) ( $invitation->token ?? '' );
        if ( $token === '' ) return;

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
