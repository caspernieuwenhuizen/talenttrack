<?php
namespace TT\Modules\Media;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MediaEntityType (#2590, epic #2589) — what a media item can be attached to.
 *
 * Deliberately a closed set. `tt_media_links.entity_type` is polymorphic,
 * and a polymorphic column with no vocabulary in front of it is how you
 * end up with `player`, `players` and `Player` in the same table. Every
 * write path validates through `isValid()`.
 *
 * Evaluation and PDP are the obvious next entries; they are out of scope
 * for the first pass and are added here when their surfaces are.
 */
final class MediaEntityType {

    public const PLAYER   = 'player';
    public const TEAM     = 'team';
    public const ACTIVITY = 'activity';

    /** @return list<string> */
    public static function all(): array {
        return [ self::PLAYER, self::TEAM, self::ACTIVITY ];
    }

    public static function isValid( string $type ): bool {
        return in_array( $type, self::all(), true );
    }

    /** Table holding the rows this type points at, without the prefix. */
    public static function tableFor( string $type ): string {
        switch ( $type ) {
            case self::PLAYER:
                return 'tt_players';
            case self::TEAM:
                return 'tt_teams';
            case self::ACTIVITY:
                return 'tt_activities';
            default:
                return '';
        }
    }
}
