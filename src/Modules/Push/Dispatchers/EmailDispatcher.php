<?php
namespace TT\Modules\Push\Dispatchers;

if ( ! defined( 'ABSPATH' ) ) exit;

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
        $user = get_userdata( $user_id );
        return $user && ! empty( $user->user_email );
    }

    public function deliver( array $context ): bool {
        $user_id = (int) ( $context['user_id'] ?? 0 );
        if ( $user_id <= 0 ) return false;
        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_email ) ) return false;

        $recipient = Recipient::self(
            $user_id,
            (string) $user->user_email,
            (string) get_user_meta( $user_id, 'tt_phone', true ),
            (string) get_user_meta( $user_id, 'locale', true )
        );

        return NotificationDelivery::send( $context, [ $recipient ] );
    }
}
