<?php
namespace TT\Modules\Comms\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CommsRequest (#0066) — value object describing one logical message
 * the caller wants Comms to deliver.
 *
 * The caller specifies *what* (`templateKey` + `messageType` +
 * `payload` for variable substitution) and *who* (`recipients`, post
 * #0042 youth-contact resolution); Comms decides the channel based on
 * recipient preference + opt-outs + quiet-hours.
 *
 * Channels can be forced via `forceChannel` — used by the
 * `LetterDeliveryUseCase` (formal letters always email-with-attachment)
 * and by automated tests. Most use cases leave it null and let the
 * dispatcher pick.
 *
 * `urgent = true` bypasses quiet hours for non-operational types.
 * Operational types (safeguarding, account-recovery) bypass regardless.
 *
 * `attachedExportId` is the optional hand-off to #0063 Export — the
 * caller renders the file via Export, gets back an export id, and
 * passes it here so the dispatcher attaches the rendered bytes.
 *
 * Immutable.
 */
final class CommsRequest {

    /**
     * @param Recipient[] $recipients
     * @param array<string,scalar|null> $payload  template variable bag
     */
    public function __construct(
        public string $templateKey,
        public string $messageType,
        public int $clubId,
        public int $senderUserId,             // 0 for system sends
        public array $recipients,
        public array $payload = [],
        public ?string $forceChannel = null,
        public bool $urgent = false,
        public ?int $attachedExportId = null,
        public ?string $localeOverride = null
    ) {}
}
