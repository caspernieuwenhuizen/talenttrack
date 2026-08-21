<?php
namespace TT\Modules\Comms\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CommsResult (#0066) — outcome of a single send attempt.
 *
 * One result per recipient (a `CommsRequest` with N recipients yields
 * N results). Status maps directly to a `tt_comms_log.status` value.
 *
 * Immutable. Returned by `CommsService::send()` (one per recipient)
 * and by individual `ChannelAdapterInterface::send()` calls.
 */
final class CommsResult {

    public const STATUS_QUEUED       = 'queued';
    public const STATUS_SENT         = 'sent';
    public const STATUS_DELIVERED    = 'delivered';
    public const STATUS_BOUNCED      = 'bounced';
    public const STATUS_FAILED       = 'failed';
    public const STATUS_OPTED_OUT    = 'opted_out';
    public const STATUS_QUIET_HOURS  = 'quiet_hours';
    public const STATUS_RATE_LIMITED = 'rate_limited';

    /** No addressee survived recipient resolution — the send never had a target. */
    public const STATUS_NO_RECIPIENTS = 'no_recipients';

    /** The club switched this template off. Written by the Gate B kill switch. */
    public const STATUS_TEMPLATE_DISABLED = 'template_disabled';

    /** Something threw. The row exists so the failure is never invisible. */
    public const STATUS_EXCEPTION = 'exception';

    public function __construct(
        public string $uuid,
        public string $status,            // self::STATUS_*
        public string $channelUsed,       // 'push' / 'email' / 'sms' / 'whatsapp_link' / 'inapp' / '' on opt-out
        public Recipient $recipient,
        public ?string $errorCode = null,
        public ?string $note = null
    ) {}

    public function isSuccess(): bool {
        return in_array( $this->status, [ self::STATUS_SENT, self::STATUS_DELIVERED ], true );
    }

    /**
     * A deliberate non-send: policy declined it, nothing broke. Distinct
     * from a failure so a caller can word the two differently — "2 parents
     * have opted out" is not an error, "1 has no email address" is.
     */
    public function isSkipped(): bool {
        return in_array( $this->status, [
            self::STATUS_OPTED_OUT,
            self::STATUS_QUIET_HOURS,
            self::STATUS_RATE_LIMITED,
            self::STATUS_TEMPLATE_DISABLED,
        ], true );
    }

    public function isFailure(): bool {
        return ! $this->isSuccess() && ! $this->isSkipped() && $this->status !== self::STATUS_QUEUED;
    }
}
