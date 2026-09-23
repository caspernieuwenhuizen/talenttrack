<?php
namespace TT\Modules\TeamDevelopment\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;

/**
 * PlayerAttributeAudience — who may read which part of a player's
 * attribute set.
 *
 * #4030 — `GET /players/{id}/attributes` returned the whole catalogue to
 * anybody who could view the player, and the catalogue's `development`
 * group is Potential, Development forecast and Ceiling estimate: the
 * academy's judgement of how far a child will go. #3978 already decided
 * that judgement is staff-only — neither the player nor a guardian holds
 * `player_potential` in the seed, and the status verdict is withheld from
 * them for the same reason — but this route never asked. A parent reading
 * their own child's attributes was handed a ceiling estimate nobody
 * intended them to see.
 *
 * The other five groups (physical, technical, tactical, mental,
 * behaviour) are recorded observations rather than a forecast, and stay
 * where `canViewPlayer` put them.
 *
 * The decision lives here rather than in the controller so the REST
 * payload and the rendered editor cannot drift apart, and so a future
 * non-WordPress front end gets the same answer (CLAUDE.md §4).
 */
final class PlayerAttributeAudience {

    /** The attribute group holding the academy's forecast for a player. */
    public const DEVELOPMENT_GROUP = 'development';

    /**
     * The matrix entity that names potential. Held by the head coach at
     * team scope and by the HoD / academy admin globally; by no family
     * persona at any scope.
     */
    private const ENTITY = 'player_potential';

    /**
     * May this reader see the potential / forecast / ceiling group for
     * this player?
     */
    public static function canReadDevelopment( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;
        return AuthorizationService::canReadPlayerSection( $user_id, $player_id, self::ENTITY );
    }

    /**
     * The attribute set as this reader may have it. Withholding drops the
     * group rather than blanking its values: a `null` score reads as "not
     * recorded yet", which would be a lie, and an empty group still tells
     * the reader the forecast exists.
     *
     * @param array<string, array<int, array<string, mixed>>> $grouped
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function filterGroups( array $grouped, int $user_id, int $player_id ): array {
        if ( self::canReadDevelopment( $user_id, $player_id ) ) return $grouped;
        unset( $grouped[ self::DEVELOPMENT_GROUP ] );
        return $grouped;
    }
}
