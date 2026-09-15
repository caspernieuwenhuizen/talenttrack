<?php
namespace TT\Modules\Comms\Recipient;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Domain\Recipient;

/**
 * RecipientReachability (#3383) — can this addressee be reached at all?
 *
 * One question, one answer, one place. `CommsService::sendOne()` and
 * `preflightOne()` both call this, which is the point: the warning a
 * sender reads before clicking and the row the log keeps afterwards
 * cannot disagree about the same person.
 *
 * ## What it looks at, and what it deliberately does not
 *
 * Contact details on the resolved recipient, and nothing else. An email
 * address, a phone number, or an account — the in-app inbox is keyed on
 * `user_id`, so somebody with an account has somewhere a message can land
 * even with no address on file.
 *
 * It does **not** resolve a channel. Channel resolution is delivery work:
 * it narrows by what the template supports and what the academy allows,
 * and one of its adapters queries the database for push subscriptions.
 * `sendOne()` resolves a channel last, on purpose — a message deferred to
 * tomorrow morning never needs to know which channel it would have used —
 * and establishing this fact must not quietly undo that ordering.
 *
 * So the two answers can differ, and the difference is meaningful rather
 * than a bug: a recipient with an email address is reachable even when a
 * template that only supports SMS cannot reach *them*. The status column
 * says `no_channel_available` for that; this says the person is
 * contactable. Both are true, and a sender needs the second one to know
 * whether the fix is a phone number or a template.
 */
final class RecipientReachability {

    /**
     * True when the academy holds something that could reach this person.
     */
    public static function isReachable( Recipient $recipient ): bool {
        if ( $recipient->userId > 0 ) return true;                 // in-app inbox
        if ( trim( $recipient->emailAddress ) !== '' ) return true;
        if ( trim( $recipient->phoneE164 ) !== '' ) return true;
        return false;
    }
}
