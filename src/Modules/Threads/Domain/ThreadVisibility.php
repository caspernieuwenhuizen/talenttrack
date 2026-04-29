<?php
namespace TT\Modules\Threads\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Visibility levels for a thread message (#0028).
 *
 *   PUBLIC           — every participant who can read the thread sees it.
 *   PRIVATE_TO_COACH — only coaches (owner of the entity) and admins.
 */
final class ThreadVisibility {
    public const PUBLIC_LEVEL  = 'public';
    public const PRIVATE_COACH = 'private_to_coach';

    public static function isValid( string $value ): bool {
        return in_array( $value, [ self::PUBLIC_LEVEL, self::PRIVATE_COACH ], true );
    }
}
