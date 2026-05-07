<?php
namespace TT\Modules\Comms\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Recipient (#0066) — one resolved addressee for a Comms send.
 *
 * Comms callers describe who the message is *about* (`subject_player_id`
 * for a child, `subject_user_id` for a coach). The `RecipientResolver`
 * applies the #0042 youth-contact rules and produces one or more
 * `Recipient` value objects that the dispatcher actually delivers to.
 *
 * Example: a "training cancelled" message about U10 Lucas (player_id
 * 42, age 9) resolves to two `Recipient` instances — one per parent
 * — both with `kind = parent` and `subject_player_id = 42`.
 *
 * Immutable. Constructed by the resolver, consumed by the dispatcher.
 */
final class Recipient {

    public const KIND_SELF   = 'self';
    public const KIND_PARENT = 'parent';
    public const KIND_COACH  = 'coach';
    public const KIND_SYSTEM = 'system';

    public function __construct(
        public int $userId,
        public string $kind,                 // self::KIND_*
        public ?int $subjectPlayerId = null, // the player this message is about, if any
        public string $emailAddress = '',
        public string $phoneE164 = '',
        public string $preferredLocale = ''
    ) {}

    public static function self( int $userId, string $email = '', string $phone = '', string $locale = '' ): self {
        return new self( $userId, self::KIND_SELF, null, $email, $phone, $locale );
    }

    public static function parent( int $parentUserId, int $childPlayerId, string $email = '', string $phone = '', string $locale = '' ): self {
        return new self( $parentUserId, self::KIND_PARENT, $childPlayerId, $email, $phone, $locale );
    }

    public static function coach( int $coachUserId, ?int $aboutPlayerId = null, string $email = '', string $phone = '', string $locale = '' ): self {
        return new self( $coachUserId, self::KIND_COACH, $aboutPlayerId, $email, $phone, $locale );
    }
}
