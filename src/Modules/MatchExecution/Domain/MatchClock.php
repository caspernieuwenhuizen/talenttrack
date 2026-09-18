<?php
namespace TT\Modules\MatchExecution\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\MatchExecutionState;

/**
 * MatchClock (#3553) — where the match clock stands, read from the stored
 * execution row rather than from a browser tab.
 *
 * The live screen used to keep the clock in the page alone, so any reload
 * started it again at 00:00, paused, and every minute logged after that was
 * wrong. The row already carries each half's start and end and its pause
 * total; with `clock_paused_at` (migration 0269) it also knows whether the
 * clock is paused right now. That is enough to answer, on any request:
 * which half, how many seconds into it, and whether the clock is running.
 *
 * One answer, three consumers: the view boots the page clock from it, the
 * REST half/pause/resume responses return it, and the coach's dashboard
 * hero labels the live minute with it.
 *
 * All stored times are UTC (`current_time( 'mysql', true )`).
 */
final class MatchClock {

    /**
     * @param object   $execution a `tt_match_execution` row
     * @param int|null $now_utc   unix time to measure against; defaults to now
     * @return array{half:int, elapsed_seconds:int, running:bool}
     */
    public static function forExecution( object $execution, ?int $now_utc = null ): array {
        $now   = $now_utc ?? time();
        $state = (string) ( $execution->state ?? '' );

        if ( $state === MatchExecutionState::FIRST_HALF || $state === MatchExecutionState::SECOND_HALF ) {
            $half      = $state === MatchExecutionState::SECOND_HALF ? 2 : 1;
            $prefix    = $half === 2 ? 'second_half' : 'first_half';
            $paused_at = self::toUnix( $execution->clock_paused_at ?? null );
            $until     = $paused_at ?? $now;
            return [
                'half'            => $half,
                'elapsed_seconds' => self::elapsed( $execution, $prefix, $until ),
                'running'         => $paused_at === null,
            ];
        }

        if ( $state === MatchExecutionState::HALF_TIME ) {
            // The first half, frozen where it ended.
            $ended = self::toUnix( $execution->first_half_ended_at ?? null ) ?? $now;
            return [
                'half'            => 1,
                'elapsed_seconds' => self::elapsed( $execution, 'first_half', $ended ),
                'running'         => false,
            ];
        }

        return [ 'half' => 1, 'elapsed_seconds' => 0, 'running' => false ];
    }

    /** Seconds into a half at `$until`, net of the half's recorded pauses. */
    private static function elapsed( object $execution, string $prefix, int $until ): int {
        $started = self::toUnix( $execution->{$prefix . '_started_at'} ?? null );
        if ( $started === null ) return 0;
        $paused = (int) ( $execution->{$prefix . '_pause_seconds'} ?? 0 );
        return max( 0, $until - $started - $paused );
    }

    /**
     * A stored UTC datetime as unix time; null when empty or unparseable.
     *
     * @param mixed $value
     */
    public static function toUnix( $value ): ?int {
        if ( ! is_string( $value ) || $value === '' || strpos( $value, '0000' ) === 0 ) return null;
        $ts = strtotime( $value . ' UTC' );
        return $ts === false ? null : $ts;
    }
}
