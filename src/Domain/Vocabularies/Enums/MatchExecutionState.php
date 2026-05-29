<?php
/**
 * MatchExecutionState — typed constants for the values stored in
 * `tt_match_execution.state`. Code-only enum (not operator-editable);
 * the five states drive the live-match timer state machine and are
 * never customised via the lookups admin.
 *
 * Lifecycle:
 *
 *     NOT_STARTED -> FIRST_HALF -> HALF_TIME -> SECOND_HALF -> FINISHED
 *
 * The "live" subset (`FIRST_HALF`, `HALF_TIME`, `SECOND_HALF`) is what
 * `MatchExecutionRepository::findLiveForTeams()` filters on; the
 * "startable" subset (`NOT_STARTED` plus NULL) is what
 * `MatchExecutionRepository::findStartableForTeams()` accepts.
 *
 * Per #988's locked decisions (2026-05-28):
 *
 *   - `Vocabularies\Enums\MatchExecutionState` is the single source of
 *     truth for the five state values.
 *   - `MatchExecutionRepository::STATE_*` constants alias the values
 *     here for one release as a backward-compatibility shim; they will
 *     be removed in the next minor.
 *
 * Use the constants in PHP comparisons:
 *
 *     if ( $execution->state === MatchExecutionState::FIRST_HALF ) { ... }
 *     in_array( $execution->state, MatchExecutionState::LIVE, true );
 *
 * SQL string literals stay as literals — DB is the source of truth.
 */

namespace TT\Domain\Vocabularies\Enums;

if ( ! defined( 'ABSPATH' ) ) exit;

final class MatchExecutionState {

    public const NOT_STARTED  = 'not_started';
    public const FIRST_HALF   = 'first_half';
    public const HALF_TIME    = 'half_time';
    public const SECOND_HALF  = 'second_half';
    public const FINISHED     = 'finished';

    /** @var list<string> */
    public const ALL = [
        self::NOT_STARTED,
        self::FIRST_HALF,
        self::HALF_TIME,
        self::SECOND_HALF,
        self::FINISHED,
    ];

    /**
     * States in which the match is mid-play. Used by the coach-hero
     * "Resume match" lookup and any UI that needs to distinguish a
     * live execution from a startable / finished one.
     *
     * @var list<string>
     */
    public const LIVE = [
        self::FIRST_HALF,
        self::HALF_TIME,
        self::SECOND_HALF,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }

    public static function isLive( string $value ): bool {
        return in_array( $value, self::LIVE, true );
    }
}
