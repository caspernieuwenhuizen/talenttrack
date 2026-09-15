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
 * column values + `tt_comms_optouts.message_type` keys). Add new types
 * here as use cases ship; never rename existing ones — they're a
 * persisted vocabulary.
 *
 * (#2638 — this line named `tt_user_optouts`, a table that was never built:
 * the opt-out policy reached for `wp_usermeta` instead, and the docblock was
 * never corrected. Migration 0225 created the table the design had always
 * described, under the name matching the module's other tables.)
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

    /**
     * #2604 — in-product notifications routed through the Push module's
     * dispatcher chain: workflow task assignments, thread replies, trial
     * reminders. Their copy is composed by the calling module rather than
     * by a template, so they share one passthrough template and are told
     * apart in the audit log by the `event` payload key.
     *
     * Deliberately not operational: a coach may reasonably mute these,
     * and they have no business arriving at 23:00.
     */
    public const NOTIFICATION                = 'notification';

    /**
     * #2604 — the reminder to a staff member that their trial input is
     * still outstanding. Fixed, translatable copy, so it carries its own
     * template rather than sharing the notification passthrough.
     */
    public const TRIAL_INPUT_REMINDER        = 'trial_input_reminder';

    /** #2604 — the scheduled analytics export, delivered with the file attached. */
    public const SCHEDULED_REPORT            = 'scheduled_report';

    /**
     * #2604 — a message a staff member composed and sent by hand from the
     * in-product composer. Opt-outable: someone who has asked the academy
     * not to email them means it here too.
     */
    public const DIRECT_MESSAGE              = 'direct_message';

    /** #2604 — a confidential player report sent to an external scout. */
    public const SCOUT_REPORT_DELIVERY       = 'scout_report_delivery';

    /**
     * #2634 — the periodic roll-up of a user's open alerts.
     *
     * Deliberately ONE type for every alert, not one per alert key. A
     * per-key explosion would duplicate `tt_alert_preferences`, which is
     * already the right place to mute a specific alert, and would leave two
     * systems disagreeing about what "muted" means. The division of labour:
     * Comms opt-out governs "do I want digest email at all", the alert
     * matrix governs "which alerts feed it".
     */
    public const ALERT_DIGEST                = 'alert_digest';

    // Operational — opt-out forbidden.
    public const SAFEGUARDING_BROADCAST      = 'safeguarding_broadcast_OPERATIONAL';
    public const ACCOUNT_RECOVERY            = 'account_recovery_OPERATIONAL';

    /**
     * #2604 — the "email me this link" hand-off from the desktop-only
     * prompt on a phone.
     *
     * Operational because the user asked for it seconds ago and is waiting
     * on it: an opt-out they set months back, or a quiet-hours window,
     * would turn a button that appears to work into one that does nothing.
     * It is addressed to the requester's own account and carries no copy
     * about anyone else.
     */
    public const DESKTOP_LINK                = 'desktop_link_OPERATIONAL';

    /**
     * Every message type this build knows about.
     *
     * #2605 — the REST preference routes need the list, and reading it
     * off the class's own constants is the only version that cannot fall
     * behind: a type added above appears here on the same commit. Sorted
     * as declared, so the operational ones stay last.
     *
     * Public constants only. #3382 added a private one holding a *list* of
     * types rather than a type, and `getConstants()` returns those too —
     * which put an array into a `list<string>` and made every caller of
     * this method fatal. Visibility is the right discriminator: a message
     * type is part of this class's contract, anything private is bookkeeping.
     *
     * @return list<string>
     */
    public static function all(): array {
        $out = [];
        foreach ( ( new \ReflectionClass( self::class ) )->getReflectionConstants() as $constant ) {
            if ( $constant->isPublic() ) {
                $out[] = (string) $constant->getValue();
            }
        }
        return $out;
    }

    /**
     * The types a recipient is allowed to mute — everything except the
     * operational tier. This is the list a preferences surface should
     * offer; offering an operational type would render a switch that
     * silently does nothing.
     *
     * @return list<string>
     */
    public static function optOutable(): array {
        return array_values( array_filter( self::all(), static fn ( string $t ): bool => ! self::isOperational( $t ) ) );
    }

    /**
     * Types that are operational without carrying the suffix.
     *
     * #3382 — the `_OPERATIONAL` suffix is a naming convention, not the
     * definition. `TRAINING_CANCELLED` is operational in every sense that
     * matters and has been treated as such by {@see bypassesQuietHours()}
     * since the module shipped: the product will wake a family at 23:00 to
     * tell them a training is off. It simply could not carry the suffix,
     * because the constant's *value* is persisted — in
     * `tt_comms_log.message_type` and `tt_comms_optouts.message_type` — so
     * renaming it would orphan every historical row and rewrite the audit
     * trail to say something it did not say at the time.
     *
     * So the list exists, and the trade is stated rather than implied: a
     * muted cancellation means a child dropped at a pitch nobody came to.
     *
     * @var list<string>
     */
    private const OPERATIONAL_BY_POLICY = [
        self::TRAINING_CANCELLED,
    ];

    /**
     * True when the message type is operational (opt-out forbidden).
     *
     * Either the constant ends in `_OPERATIONAL` — the convention — or it
     * appears in {@see self::OPERATIONAL_BY_POLICY}, for the one type whose
     * stored value predates the convention.
     */
    public static function isOperational( string $messageType ): bool {
        return substr( $messageType, -12 ) === '_OPERATIONAL'
            || in_array( $messageType, self::OPERATIONAL_BY_POLICY, true );
    }

    /**
     * Who each type is addressed to.
     *
     * #3389 — the preferences card rendered every type to every persona,
     * so a player was asked whether they wanted "reminders about your own
     * development review": mail sent to coaches about theirs. The evidence
     * for each row is the send path in `src/`, recorded on the issue.
     *
     * Only the opt-outable types are listed. An operational type reaches
     * whoever it concerns and cannot be refused by any of them, so an
     * audience for it would be a fact nothing reads — and listing one
     * would invite a later change to filter the always-sent block, which
     * would be wrong.
     *
     * Six of these have no sender yet. They take their intended audience
     * now (issue decision 3): while nothing sends them, a wrong guess
     * hides a toggle for mail nobody is receiving, and each send path
     * confirms or corrects its own row when it lands.
     *
     * @var array<string, list<string>>
     */
    private const AUDIENCES = [
        // Reaches the player and the family.
        self::PDP_READY                 => [ MessageAudience::PLAYER, MessageAudience::PARENT ],
        self::GOAL_NUDGE                => [ MessageAudience::PLAYER, MessageAudience::PARENT ],
        self::SELECTION_LETTER          => [ MessageAudience::PLAYER, MessageAudience::PARENT ],
        self::GUEST_PLAYER_INVITE       => [ MessageAudience::PLAYER, MessageAudience::PARENT ],

        // The family only.
        self::ONBOARDING_NUDGE_INACTIVE => [ MessageAudience::PARENT ],
        self::PARENT_MEETING_INVITE     => [ MessageAudience::PARENT ],
        // Sent on intake, before the player has an account of their own.
        self::TRIAL_PLAYER_WELCOME      => [ MessageAudience::PARENT ],

        // Staff only. These are the rows that read as actively misleading
        // on a player's screen: each describes someone else's job.
        self::STAFF_DEVELOPMENT_REMINDER => [ MessageAudience::STAFF ],
        self::METHODOLOGY_DELIVERED      => [ MessageAudience::STAFF ],
        self::ATTENDANCE_FLAG            => [ MessageAudience::STAFF ],
        self::SCHEDULED_REPORT           => [ MessageAudience::STAFF ],
        self::TRIAL_INPUT_REMINDER       => [ MessageAudience::STAFF ],
        self::SCOUT_REPORT_DELIVERY      => [ MessageAudience::STAFF ],

        // Anyone with an account can be the subject or the recipient.
        self::NOTIFICATION               => [ MessageAudience::PLAYER, MessageAudience::PARENT, MessageAudience::STAFF ],
        self::SCHEDULE_CHANGE_FROM_SPOND => [ MessageAudience::PLAYER, MessageAudience::PARENT, MessageAudience::STAFF ],
        self::LETTER_DELIVERY            => [ MessageAudience::PLAYER, MessageAudience::PARENT, MessageAudience::STAFF ],
        self::MASS_ANNOUNCEMENT          => [ MessageAudience::PLAYER, MessageAudience::PARENT, MessageAudience::STAFF ],
        self::ALERT_DIGEST               => [ MessageAudience::PLAYER, MessageAudience::PARENT, MessageAudience::STAFF ],
        self::DIRECT_MESSAGE             => [ MessageAudience::PLAYER, MessageAudience::PARENT, MessageAudience::STAFF ],
    ];

    /**
     * The audiences a message type is addressed to.
     *
     * An unmapped type returns every audience, and `tools/check-message-audiences.php`
     * fails the build when an opt-outable type has no entry. Those two
     * together are the point: the only failure that matters here is a
     * *missing* toggle for mail somebody is receiving, which leaves them
     * unable to refuse it. A spare row is untidy; a lost row is a person
     * who cannot stop mail about their child. So the runtime default is
     * "show it" and the gate catches the omission before it ships.
     *
     * @return list<string>
     */
    public static function audiences( string $messageType ): array {
        return self::AUDIENCES[ $messageType ] ?? MessageAudience::all();
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
        // #3382 — `TRAINING_CANCELLED` used to be named again here. It is
        // now operational (see OPERATIONAL_BY_POLICY), so the first branch
        // already covers it.
        return self::isOperational( $messageType );
    }
}
