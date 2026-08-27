<?php
namespace TT\Modules\Push\Dispatchers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Identity\ContactResolver;
use TT\Modules\Comms\Domain\Recipient;

/**
 * EmailDispatcher — sends to the target user's WP `user_email`
 * (#0042). Lives in the Push module so the dispatcher chain can
 * address all three channels (push / parent_email / email) through
 * one interface.
 *
 * #2604 — delivery goes through `CommsService` rather than calling
 * `wp_mail()` directly. The chain still decides *who* (that is its job,
 * and the preset is stored per workflow template); Comms decides
 * *whether and how*, which is what brings opt-out, quiet hours, the
 * rate limit and the audit trail to a path that had none of them.
 */
final class EmailDispatcher implements DispatcherInterface {

    public function key(): string { return 'email'; }

    public function applicableTo( array $context ): bool {
        $user_id = (int) ( $context['user_id'] ?? 0 );
        if ( $user_id <= 0 ) return false;
        return ContactResolver::emailForUser( $user_id ) !== null;
    }

    public function deliver( array $context ): bool {
        $user_id = (int) ( $context['user_id'] ?? 0 );
        if ( $user_id <= 0 ) return false;
        $email = ContactResolver::emailForUser( $user_id );
        if ( $email === null ) return false;

        $recipient = Recipient::self(
            $user_id,
            $email,
            (string) ( ContactResolver::phoneForUser( $user_id ) ?? '' ),
            (string) get_user_meta( $user_id, 'locale', true )
        );

        return NotificationDelivery::send( $context, [ $recipient ] );
    }
}
