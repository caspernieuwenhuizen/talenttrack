<?php
namespace TT\Modules\Invitations\GuardianContact;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Invitations\InvitationsRepository;

/**
 * GuardianContactEmailNotifier (#3794) — mails the link when the office
 * asks a family for their contact details.
 *
 * Operational, like the invitation email it sits beside: the academy
 * just asked for this on the family's behalf and cannot reach them any
 * other way — that is the whole problem — so an opt-out set months ago
 * or a quiet-hours window must not swallow it.
 *
 * The message names the child and the academy, and nothing else about
 * the record. So does the page the link opens.
 */
final class GuardianContactEmailNotifier {

    public static function register(): void {
        add_action( 'tt_guardian_contact_requested', [ self::class, 'onRequested' ], 10, 3 );
    }

    public static function onRequested( int $request_id, int $player_id, string $email ): void {
        if ( $request_id <= 0 ) return;

        $email = sanitize_email( $email );
        if ( $email === '' || ! is_email( $email ) ) {
            Logger::info( 'Guardian contact request not mailed — no usable address', [
                'request_id' => $request_id,
                'player_id'  => $player_id,
            ] );
            return;
        }

        $request = ( new InvitationsRepository() )->find( $request_id );
        if ( ! $request ) {
            Logger::error( 'Guardian contact request not mailed — row not found', [
                'request_id' => $request_id,
            ] );
            return;
        }

        $row   = (array) $request;
        $token = (string) ( $row['token'] ?? '' );
        if ( $token === '' ) {
            Logger::error( 'Guardian contact request not mailed — no token', [
                'request_id' => $request_id,
            ] );
            return;
        }

        $recipient = new Recipient(
            0,                      // Not a WP user — that is why this link exists.
            Recipient::KIND_SYSTEM,
            null,
            $email,
            '',
            (string) ( $row['locale'] ?? '' )
        );

        do_action(
            'tt_comms_dispatch',
            'guardian_contact_request',
            [
                'player_name'  => GuardianContactRequest::playerName( $player_id ),
                'academy_name' => (string) get_bloginfo( 'name' ),
                'form_url'     => GuardianContactRequest::url( $token ),
                'ttl_days'     => self::ttlDays( (string) ( $row['expires_at'] ?? '' ) ),
            ],
            [ $recipient ],
            [ 'message_type' => 'guardian_contact_request_OPERATIONAL' ]
        );
    }

    private static function ttlDays( string $expires_at ): string {
        $stamp = strtotime( $expires_at );
        if ( ! $stamp ) return '14';
        return (string) max( 1, (int) ceil( ( $stamp - time() ) / 86400 ) );
    }
}
