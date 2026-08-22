<?php
namespace TT\Shared\Content;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GateVerdict — why a piece of content is or is not reachable.
 *
 * A boolean would be cheaper and wrong. The four ways content can be out
 * of reach are not interchangeable to the person in front of it:
 *
 *   - **unavailable** — this install does not have it. No amount of
 *     permission changes that; an administrator sees the same nothing.
 *   - **denied** — it exists here and somebody else can see it. You
 *     cannot.
 *   - **locked** — you will be able to see it, once you have done
 *     something first.
 *
 * Rendering the same message for all three is how a product ends up
 * telling a head of academy to "ask your administrator" about a feature
 * their licence does not include.
 *
 * `kind` is the classification a consumer switches on; `reason` is the
 * specific cause within it, and each layer owns its own reason vocabulary
 * — `ContentGate` names the four install-level ones, a course resolver
 * names its learning-state ones. `context` carries whatever the message
 * needs to be specific: which tier, which prerequisite, which capability.
 */
final class GateVerdict {

    public const KIND_AVAILABLE   = 'available';
    public const KIND_UNAVAILABLE = 'unavailable';
    public const KIND_DENIED      = 'denied';
    public const KIND_LOCKED      = 'locked';

    private string $kind;

    private string $reason;

    /** @var array<string, mixed> */
    private array $context;

    /** @param array<string, mixed> $context */
    private function __construct( string $kind, string $reason, array $context ) {
        $this->kind    = $kind;
        $this->reason  = $reason;
        $this->context = $context;
    }

    public static function available(): self {
        return new self( self::KIND_AVAILABLE, '', [] );
    }

    /** @param array<string, mixed> $context */
    public static function unavailable( string $reason, array $context = [] ): self {
        return new self( self::KIND_UNAVAILABLE, $reason, $context );
    }

    /** @param array<string, mixed> $context */
    public static function denied( string $reason, array $context = [] ): self {
        return new self( self::KIND_DENIED, $reason, $context );
    }

    /** @param array<string, mixed> $context */
    public static function locked( string $reason, array $context = [] ): self {
        return new self( self::KIND_LOCKED, $reason, $context );
    }

    public function kind(): string {
        return $this->kind;
    }

    public function reason(): string {
        return $this->reason;
    }

    /** @return array<string, mixed> */
    public function context(): array {
        return $this->context;
    }

    public function isAvailable(): bool {
        return $this->kind === self::KIND_AVAILABLE;
    }

    /** This install does not have it. */
    public function isUnavailable(): bool {
        return $this->kind === self::KIND_UNAVAILABLE;
    }

    /** It is here; this reader may not have it. */
    public function isDenied(): bool {
        return $this->kind === self::KIND_DENIED;
    }

    /** It is here and permitted; something has to happen first. */
    public function isLocked(): bool {
        return $this->kind === self::KIND_LOCKED;
    }

    /**
     * Whether the content should be listed at all.
     *
     * Unavailable and denied content is absent — listing what an install
     * does not have, or what this reader may not open, is advertising.
     * Locked content is listed, because hiding it would make a course look
     * shorter than it is and a reader cannot work towards something they
     * cannot see.
     */
    public function isListable(): bool {
        return $this->isAvailable() || $this->isLocked();
    }

    /**
     * The shape the REST layer sends. Deliberately not a message: copy is
     * the presentation layer's job, and the API has no locale.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'kind'    => $this->kind,
            'reason'  => $this->reason,
            'context' => $this->context,
        ];
    }
}
