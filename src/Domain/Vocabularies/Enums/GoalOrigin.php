<?php
/**
 * GoalOrigin — how a goal came to exist. Code-only enum (not
 * operator-editable), carried in the `goal_set` journey event's payload
 * so the timeline can say who decided a goal without needing a separate
 * event type per writer.
 *
 * Values:
 *
 *   SET           — a person set it: the goal wizard, the goals form, or
 *                   the REST endpoint behind either.
 *   CARRIED_OVER  — the season rollover re-created an open goal in the
 *                   new season (`Pdp\Carryover\SeasonCarryover`).
 *   SPAWNED       — a development idea reaching `in-progress` with a
 *                   player attached spawned it
 *                   (`Development\Notifications\GoalSpawner`).
 *
 * The distinction earns its keep on a rollover: one operation writes a
 * goal for every player in the academy, so without provenance a cohort
 * timeline reads as a wall of identical "Goal set" entries on one date.
 * A payload field expresses that adequately; a fourth `JourneyEventType`
 * would cost renderer work in every consumer to say the same thing.
 *
 * Use the constants in PHP comparisons:
 *
 *     $repo->create( $data, [ 'origin' => GoalOrigin::CARRIED_OVER ] );
 *     if ( $origin === GoalOrigin::SPAWNED ) { ... }
 */

namespace TT\Domain\Vocabularies\Enums;

if ( ! defined( 'ABSPATH' ) ) exit;

final class GoalOrigin {

    public const SET          = 'set';
    public const CARRIED_OVER = 'carried_over';
    public const SPAWNED      = 'spawned';

    /** @var list<string> */
    public const ALL = [
        self::SET,
        self::CARRIED_OVER,
        self::SPAWNED,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }

    /** Anything unrecognised reads as a person having set it. */
    public static function sanitize( string $value ): string {
        return self::isValid( $value ) ? $value : self::SET;
    }
}
