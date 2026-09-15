<?php
namespace TT\Modules\Comms\Domain;

use TT\Modules\Authorization\PersonaResolver;

/**
 * Who a message type is addressed to.
 *
 * #3389 — the preferences card offered every persona the same fifteen
 * toggles, so a thirteen-year-old was asked whether they wanted reminders
 * about their own development review: a message sent to coaches about
 * theirs. Filtering the card needs a vocabulary for "who receives this",
 * and this is it.
 *
 * Three levels, not ten. The product has ten personas, but mail is
 * addressed along one axis: the player, the family, or the academy's
 * staff. Mapping personas onto that axis here keeps {@see MessageType}'s
 * map readable and means a new persona is one edit away from being
 * classified rather than fifteen.
 *
 * Deliberately NOT constants on `MessageType`. That class derives
 * {@see MessageType::all()} by reflecting over its own public constants,
 * so a public `AUDIENCE_PLAYER = 'player'` there would be returned as a
 * message type and offered as a toggle.
 */
final class MessageAudience {

    public const PLAYER = 'player';
    public const PARENT = 'parent';
    public const STAFF  = 'staff';

    /**
     * Every audience — the default for an unmapped type.
     *
     * @return list<string>
     */
    public static function all(): array {
        return [ self::PLAYER, self::PARENT, self::STAFF ];
    }

    /**
     * Personas that are neither the player nor the family.
     *
     * Listed as the exceptions rather than enumerating staff, so a persona
     * added to `PersonaResolver` lands in `STAFF` by default. That is the
     * safe direction: a new staff seat sees a spare row, where the
     * alternative is a coach silently losing a toggle for mail they get.
     */
    private const NON_STAFF_PERSONAS = [
        'player' => self::PLAYER,
        'parent' => self::PARENT,
    ];

    /**
     * The audiences this user belongs to.
     *
     * A list rather than a single value: a coach who is also a parent at
     * the same academy is both, and must keep every row either one of them
     * receives. The card renders a type when this list intersects the
     * type's own audience list.
     *
     * A user with no persona at all — a bare WordPress account, or an
     * account whose roles were changed out from under it — gets every
     * audience. Same reasoning as an unmapped type in
     * {@see MessageType::audiences()}: the failure mode that matters is a
     * missing toggle for mail somebody is actually receiving, so the
     * unknown case fails towards showing too much.
     *
     * @return list<string>
     */
    public static function forUser( int $user_id ): array {
        $personas = PersonaResolver::personasFor( $user_id );
        if ( $personas === [] ) {
            return self::all();
        }

        $out = [];
        foreach ( $personas as $persona ) {
            $out[] = self::NON_STAFF_PERSONAS[ $persona ] ?? self::STAFF;
        }

        return array_values( array_unique( $out ) );
    }
}
