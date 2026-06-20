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

    public const NOT_STARTED    = 'not_started';
    public const FIRST_HALF     = 'first_half';
    public const HALF_TIME      = 'half_time';
    public const SECOND_HALF    = 'second_half';
    /**
     * #1033 — replaces the prior terminal `FINISHED`. After the coach
     * taps "End match", the execution lands in PENDING_REVIEW: goals,
     * subs, and the score remain editable; the timer is frozen.
     */
    public const PENDING_REVIEW = 'pending_review';
    /**
     * #1033 — new terminal state. Reached via an explicit Finalize
     * action from PENDING_REVIEW. Read-only thereafter.
     */
    public const FINALIZED      = 'finalized';

    /**
     * @deprecated since v4.15.0 (#1033) — the post-match state split
     *             into PENDING_REVIEW (editable) and FINALIZED
     *             (terminal). Kept for one release as a back-compat
     *             alias; the migration in 0NNN_match_execution_state
     *             backfills existing DB rows so the legacy literal
     *             `'finished'` shouldn't appear in storage anymore.
     */
    public const FINISHED       = 'finished';

    /** @var list<string> */
    public const ALL = [
        self::NOT_STARTED,
        self::FIRST_HALF,
        self::HALF_TIME,
        self::SECOND_HALF,
        self::PENDING_REVIEW,
        self::FINALIZED,
    ];

    /**
     * States in which the match is mid-play. Used by the coach-hero
     * "Resume match" lookup and any UI that needs to distinguish a
     * live execution from a startable / post-match one.
     *
     * @var list<string>
     */
    public const LIVE = [
        self::FIRST_HALF,
        self::HALF_TIME,
        self::SECOND_HALF,
    ];

    /**
     * #1033 — states in which the score, goal-event, and substitution
     * endpoints accept writes. Live states stay editable as they
     * always were; PENDING_REVIEW unlocks the same controls for the
     * post-match review window. FINALIZED is read-only.
     *
     * @var list<string>
     */
    public const EDITABLE = [
        self::FIRST_HALF,
        self::HALF_TIME,
        self::SECOND_HALF,
        self::PENDING_REVIEW,
    ];

    public static function isValid( string $value ): bool {
        return in_array( $value, self::ALL, true );
    }

    public static function isLive( string $value ): bool {
        return in_array( $value, self::LIVE, true );
    }

    public static function isEditable( string $value ): bool {
        return in_array( $value, self::EDITABLE, true );
    }

    /**
     * #1520 — post-live states (the match has been played). The detail
     * page surfaces these as "View match" on any day; the LIVE states as
     * "Resume match"; NOT_STARTED only as "Start match", and only on
     * match day.
     *
     * @var list<string>
     */
    public const POST_LIVE = [
        self::PENDING_REVIEW,
        self::FINALIZED,
        self::FINISHED,
    ];

    public static function isPostLive( string $value ): bool {
        return in_array( $value, self::POST_LIVE, true );
    }

    /**
     * #1473 / #1520 — is the given activity date "match day"? Starting a
     * match is gated to the server's current date. Shared by the match
     * execution view's start-lock and the activity detail-page button so
     * the two surfaces can't drift. `$session_date` is the stored
     * `tt_activities.session_date` (date or datetime string).
     */
    public static function isMatchDay( string $session_date ): bool {
        return $session_date !== '' && substr( $session_date, 0, 10 ) === current_time( 'Y-m-d' );
    }
}
