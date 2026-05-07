<?php
namespace TT\Modules\Comms\OptOut;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Domain\MessageType;

/**
 * OptOutPolicy (#0066) — per-recipient × per-message-type opt-out.
 *
 * Per spec Q5 lean ("per-message-type opt-out"): a parent can mute
 * "training_schedule" updates without losing safeguarding broadcasts.
 * Stored as a `wp_user_meta` row keyed `tt_comms_optout_<message_type>`
 * with value `'1'` for opted-out, absent for opted-in (the default).
 *
 * Operational message types (`*_OPERATIONAL` per `MessageType`) bypass
 * the check unconditionally — accounts can't mute account-recovery or
 * safeguarding broadcasts. The check still records "would have been
 * opt-out" in the comms log so retention reports show the override.
 *
 * The full opt-out preferences UI lands with the use cases (each use
 * case names the message types it ships and the operator's account
 * settings page surfaces them as togglable). v1 foundation only ships
 * the policy + storage; UI is opportunistic.
 */
final class OptOutPolicy {

    /**
     * True when the user has opted out of receiving messages of the
     * given type. Operational types always return false.
     */
    public function isOptedOut( int $userId, string $messageType ): bool {
        if ( $userId <= 0 ) return false;
        if ( MessageType::isOperational( $messageType ) ) return false;
        $key = 'tt_comms_optout_' . $messageType;
        $value = get_user_meta( $userId, $key, true );
        return $value === '1';
    }

    public function setOptedOut( int $userId, string $messageType, bool $optedOut ): void {
        if ( $userId <= 0 ) return;
        if ( MessageType::isOperational( $messageType ) ) return;  // ignore — can't opt out
        $key = 'tt_comms_optout_' . $messageType;
        if ( $optedOut ) {
            update_user_meta( $userId, $key, '1' );
        } else {
            delete_user_meta( $userId, $key );
        }
    }
}
