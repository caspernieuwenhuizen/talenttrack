<?php
/**
 * ActivityStatusKey — typed constants for the three values stored in
 * `tt_activities.activity_status_key`. Backs the `activity_status` lookup
 * (operator-editable) seeded by migration 0040.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $activity->activity_status_key === ActivityStatusKey::CANCELLED ) { ... }
 *
 * SQL string literals stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ActivityStatusKey {

    public const PLANNED   = 'planned';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const ALL = [
        self::PLANNED,
        self::COMPLETED,
        self::CANCELLED,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
