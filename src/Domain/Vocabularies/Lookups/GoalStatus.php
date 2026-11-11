<?php
/**
 * GoalStatus — typed constants for the values stored in `tt_goals.status`.
 * Backs the `goal_status` lookup (operator-editable via the lookups admin)
 * seeded by migration 0001 in TitleCase form for the admin label and by
 * migration 0058 (`Pending Approval`) for the player-self-create approval
 * flow.
 *
 * Stored values in code-side comparisons are lowercase snake_case. The
 * `LabelTranslator::goalStatus()` switch and the REST controller defaults
 * (`'pending'`, `'pending_approval'`) are the canonical reference.
 *
 * Use the constants in PHP comparisons of one status:
 *
 *     if ( $goal->status === GoalStatus::PENDING_APPROVAL ) { ... }
 *
 * In SQL, never compare the column literally (`status = 'completed'`): the
 * column holds the snake_case code and the TitleCase lookup name, so a
 * literal misses one of them. Ask `GoalLifecycle` (#5074, #5099):
 * `openClause()` / `notOpenClause()` for open goals (`self::OPEN`),
 * `statusClause()` / `hasStatusClause()` for one status, `isOpen()` in PHP.
 * `GoalLifecycleGuardTest` fails on a literal comparison or status list.
 *
 * REST endpoints accept BOTH the literal AND the constant for one release
 * per #988's backward-compat allowlist; see docs/rest-api.md for the
 * deprecation timeline.
 */

namespace TT\Domain\Vocabularies\Lookups;

if ( ! defined( 'ABSPATH' ) ) exit;

final class GoalStatus {

    public const PENDING          = 'pending';
    public const PENDING_APPROVAL = 'pending_approval';
    public const IN_PROGRESS      = 'in_progress';
    public const COMPLETED        = 'completed';
    public const ON_HOLD          = 'on_hold';
    public const CANCELLED        = 'cancelled';

    /** @var list<string> */
    public const ALL = [
        self::PENDING,
        self::PENDING_APPROVAL,
        self::IN_PROGRESS,
        self::COMPLETED,
        self::ON_HOLD,
        self::CANCELLED,
    ];

    /**
     * #4596 — the goals that count as open (owner decision): not started,
     * waiting for the coach's approval of a goal a player set, in progress,
     * on hold, and approved. `approved` is a `goal_status` lookup value with
     * no code of its own. Compare through `GoalLifecycle`, which normalises
     * both stored spellings.
     *
     * @var list<string>
     */
    public const OPEN = [
        self::PENDING,
        self::PENDING_APPROVAL,
        self::IN_PROGRESS,
        self::ON_HOLD,
        'approved',
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }
}
