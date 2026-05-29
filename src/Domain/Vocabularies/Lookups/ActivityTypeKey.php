<?php
/**
 * ActivityTypeKey — typed constants for the five values stored in
 * `tt_activities.activity_type_key`. Backs the `activity_type` lookup
 * (operator-editable via the lookups admin) but the seeded values below
 * are the canonical set used by every consumer (analytics dimensions,
 * workflow templates that filter `activity_type_key = 'game'`,
 * match-prep gating, etc.).
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $activity->activity_type_key === ActivityTypeKey::GAME ) { ... }
 *
 * SQL string literals (`activity_type_key IN ('match','game')`) stay as
 * literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ActivityTypeKey {

    public const TRAINING   = 'training';
    public const GAME       = 'game';
    public const OTHER      = 'other';
    public const TOURNAMENT = 'tournament';
    public const MEETING    = 'meeting';

    /** @var list<string> */
    public const ALL = [
        self::TRAINING,
        self::GAME,
        self::OTHER,
        self::TOURNAMENT,
        self::MEETING,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
