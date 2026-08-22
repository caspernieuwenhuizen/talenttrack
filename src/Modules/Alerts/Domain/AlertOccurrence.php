<?php
namespace TT\Modules\Alerts\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AlertOccurrence (#2631, epic #2629) — one condition, currently true, for
 * one recipient.
 *
 * Produced by `AlertInterface::evaluate()`, consumed by `AlertEvaluator`.
 * A pure value object: it holds no database identity, because a definition
 * describes what is true, not what is stored. The evaluator decides whether
 * that corresponds to an insert or a bump.
 *
 * Per epic decision 5 a definition resolves its own recipients. It returns
 * one occurrence per recipient, so the recipient is part of the identity —
 * see `dedupeKey()`.
 *
 * `payload` carries whatever the surface needs to render the line: the
 * title arguments and the CTA url. It is deliberately untyped; a definition
 * that wants structured data in there is free to put it there, and nothing
 * outside that definition's own renderer should reach into keys it did not
 * write.
 */
final class AlertOccurrence {

    /** @var string */
    public $alertKey;

    /** @var int */
    public $recipientUserId;

    /** @var string */
    public $subjectType;

    /** @var int */
    public $subjectId;

    /** @var int|null */
    public $playerId;

    /** @var string */
    public $severity;

    /** @var array<string,mixed> */
    public $payload;

    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        string $alertKey,
        int $recipientUserId,
        string $subjectType,
        int $subjectId,
        string $severity = Severity::ATTENTION,
        array $payload = [],
        ?int $playerId = null
    ) {
        $this->alertKey        = $alertKey;
        $this->recipientUserId = $recipientUserId;
        $this->subjectType     = $subjectType;
        $this->subjectId       = $subjectId;
        $this->severity        = Severity::normalise( $severity );
        $this->payload         = $payload;
        $this->playerId        = $playerId !== null && $playerId > 0 ? $playerId : null;
    }

    /**
     * Stable identity of this occurrence: alert + subject + recipient.
     *
     * Hashed rather than concatenated so a long subject type can never
     * overflow the 191-byte unique index, and so the key length is constant
     * whatever a future definition names its subjects. The readable parts
     * stay in their own columns for querying — this value is only ever
     * compared for equality, never parsed.
     */
    public function dedupeKey(): string {
        return substr( hash( 'sha256', implode( '|', [
            $this->alertKey,
            $this->subjectType,
            (string) $this->subjectId,
            (string) $this->recipientUserId,
        ] ) ), 0, 64 );
    }

    /** Human-readable title for the surfaces, from the payload. */
    public function title(): string {
        return isset( $this->payload['title'] ) ? (string) $this->payload['title'] : '';
    }

    /** Where clicking the occurrence should take the recipient. */
    public function url(): string {
        return isset( $this->payload['url'] ) ? (string) $this->payload['url'] : '';
    }
}
