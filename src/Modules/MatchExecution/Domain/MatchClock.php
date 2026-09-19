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

    /**
     * Minutes a half may run past its length before it counts as left
     * running. The same stoppage allowance the event endpoints accept
     * (`assertMinuteInRange()`), so a half can never be ended later than
     * the last minute an event may be logged at.
     */
    public const STOPPAGE_MINUTES = 10;

    /** Default half length when the prep carries none. */
    public const DEFAULT_HALF_LENGTH = 35;

    /** The longest a half may run, in seconds: half length + stoppage. */
    public static function limitSeconds( int $half_length ): int {
        if ( $half_length <= 0 ) $half_length = self::DEFAULT_HALF_LENGTH;
        return ( $half_length + self::STOPPAGE_MINUTES ) * 60;
    }

    /**
     * True when a half is live (running or paused) and its clock has passed
     * the limit — a match somebody started and then left, rather than one
     * that is still being played.
     */
    public static function isOverrun( object $execution, int $half_length, ?int $now_utc = null ): bool {
        $state = (string) ( $execution->state ?? '' );
        if ( $state !== MatchExecutionState::FIRST_HALF && $state !== MatchExecutionState::SECOND_HALF ) {
            return false;
        }
        $clock = self::forExecution( $execution, $now_utc );
        return $clock['elapsed_seconds'] > self::limitSeconds( $half_length );
    }

    /**
     * The moment to stamp as a half's end. Never later than the limit:
     * `started + pauses + min( elapsed, limit )`. With `$scheduled` the
     * half ends at exactly its length instead. Null when the half never
     * started, so the caller keeps its own "now".
     *
     * Call it after any open pause has been folded into the half's pause
     * total, so `$now_utc` minus the pauses is the half's real clock.
     */
    public static function endOfHalf( object $execution, int $half, int $half_length, bool $scheduled, ?int $now_utc = null ): ?int {
        $prefix  = $half === 2 ? 'second_half' : 'first_half';
        $started = self::toUnix( $execution->{$prefix . '_started_at'} ?? null );
        if ( $started === null ) return null;
        if ( $half_length <= 0 ) $half_length = self::DEFAULT_HALF_LENGTH;

        $paused  = max( 0, (int) ( $execution->{$prefix . '_pause_seconds'} ?? 0 ) );
        $elapsed = max( 0, ( $now_utc ?? time() ) - $started - $paused );
        $run     = $scheduled ? $half_length * 60 : min( $elapsed, self::limitSeconds( $half_length ) );
        return $started + $paused + $run;
    }

    /**
     * The clock plus what the screen needs to judge it: whether the half
     * has overrun, the limit, when the running half began and who started
     * the match. `created_by` is stamped when the execution row is first
     * written, which is the kick-off.
     *
     * @return array{half:int, elapsed_seconds:int, running:bool, overrun:bool, limit_seconds:int, started_at:?string, started_by:?array{user_id:int, name:string}}
     */
    public static function readout( object $execution, int $half_length, ?int $now_utc = null ): array {
        $clock = self::forExecution( $execution, $now_utc );

        $state      = (string) ( $execution->state ?? '' );
        $prefix     = $state === MatchExecutionState::SECOND_HALF ? 'second_half' : 'first_half';
        $started    = self::toUnix( $execution->{$prefix . '_started_at'} ?? null );
        $creator_id = (int) ( $execution->created_by ?? 0 );
        $started_by = null;
        if ( $creator_id > 0 ) {
            $user       = get_userdata( $creator_id );
            $started_by = [
                'user_id' => $creator_id,
                'name'    => $user ? (string) $user->display_name : '',
            ];
        }

        return $clock + [
            'overrun'       => self::isOverrun( $execution, $half_length, $now_utc ),
            'limit_seconds' => self::limitSeconds( $half_length ),
            'started_at'    => $started !== null ? gmdate( 'Y-m-d\TH:i:s\Z', $started ) : null,
            'started_by'    => $started_by,
        ];
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
