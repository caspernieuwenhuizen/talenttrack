<?php
namespace TT\Modules\PersonaDashboard\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PersonaContext — coarse grouping used to filter the editor pickers.
 *
 *   academy        — KPIs that read across the whole academy
 *                    (HoD, Observer, Admin views).
 *   coach          — KPIs scoped to the current user's coached teams.
 *   player_parent  — KPIs scoped to a single player record.
 */
final class PersonaContext {
    public const ACADEMY       = 'academy';
    public const COACH         = 'coach';
    public const PLAYER_PARENT = 'player_parent';

    /** @return list<string> */
    public static function all(): array {
        return [ self::ACADEMY, self::COACH, self::PLAYER_PARENT ];
    }
}
