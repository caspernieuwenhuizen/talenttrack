<?php
/**
 * GoalPriority — typed constants for the three values stored in
 * `tt_goals.priority`. Backs the `goal_priority` lookup (operator-editable)
 * seeded by migration 0001 in TitleCase form for the admin label
 * (`Low / Medium / High`).
 *
 * Stored values in code-side comparisons are lowercase. The
 * `LabelTranslator::goalPriority()` switch is the canonical reference.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $goal->priority === GoalPriority::HIGH ) { ... }
 *
 * SQL string literals stay as literals — DB is the source of truth.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class GoalPriority {

    public const LOW    = 'low';
    public const MEDIUM = 'medium';
    public const HIGH   = 'high';

    /** @var list<string> */
    public const ALL = [
        self::LOW,
        self::MEDIUM,
        self::HIGH,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
