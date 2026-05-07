<?php
namespace TT\Modules\Comms\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MessageType (#0066) — discriminator for opt-out + retention scoping.
 *
 * Per spec Q5 lean (per-message-type opt-out): a parent can mute
 * "training schedule" without losing safeguarding broadcasts. Every
 * Comms send carries a message-type that the opt-out registry checks
 * before resolving channel + dispatching.
 *
 * Constants are stable strings (used as `tt_comms_log.message_type`
 * column values + `tt_user_optouts.message_type` keys). Add new types
 * here as use cases ship; never rename existing ones — they're a
 * persisted vocabulary.
 *
 * Two reserved tiers:
 *   - `*_OPERATIONAL` — always sendable; opt-out forbidden.
 *     Safeguarding broadcasts (use case 15) and account-recovery
 *     emails sit here.
 *   - everything else — opt-out honoured.
 */
final class MessageType {

    // v1 use cases (#0066 spec § 1-15)
    public const TRAINING_CANCELLED          = 'training_cancelled';
    public const SELECTION_LETTER            = 'selection_letter';
    public const PDP_READY                   = 'pdp_ready';
    public const PARENT_MEETING_INVITE       = 'parent_meeting_invite';
    public const TRIAL_PLAYER_WELCOME        = 'trial_player_welcome';
    public const GUEST_PLAYER_INVITE         = 'guest_player_invite';
    public const GOAL_NUDGE                  = 'goal_nudge';
    public const ATTENDANCE_FLAG             = 'attendance_flag';
    public const SCHEDULE_CHANGE_FROM_SPOND  = 'schedule_change_from_spond';
    public const METHODOLOGY_DELIVERED       = 'methodology_delivered';
    public const ONBOARDING_NUDGE_INACTIVE   = 'onboarding_nudge_inactive';
    public const STAFF_DEVELOPMENT_REMINDER  = 'staff_development_reminder';
    public const LETTER_DELIVERY             = 'letter_delivery';
    public const MASS_ANNOUNCEMENT           = 'mass_announcement';

    // Operational — opt-out forbidden.
    public const SAFEGUARDING_BROADCAST      = 'safeguarding_broadcast_OPERATIONAL';
    public const ACCOUNT_RECOVERY            = 'account_recovery_OPERATIONAL';

    /**
     * True when the message type is operational (opt-out forbidden).
     * Convention: any constant ending in `_OPERATIONAL`.
     */
    public static function isOperational( string $messageType ): bool {
        return substr( $messageType, -12 ) === '_OPERATIONAL';
    }

    /**
     * Whether the type bypasses quiet-hours. Spec note: emergencies
     * (safeguarding, cancellation within 12h) bypass; everything else
     * defers to next morning. Opt-out + quiet-hours are independent
     * — operational messages still bypass quiet-hours; non-operational
     * use-case-specific types may also opt to bypass when their
     * `urgent` flag is true at send time.
     */
    public static function bypassesQuietHours( string $messageType ): bool {
        return self::isOperational( $messageType )
            || $messageType === self::TRAINING_CANCELLED;
    }
}
