<?php
namespace TT\Modules\Pdp\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PdpCycleState — derives "where is this player in the development-talk
 * cycle right now" from the season's seeded conversations and their
 * planning windows (migration 0043). Pure domain logic: no rendering,
 * no DB access. The My PDP surface (#1851) and the development home
 * (#1850) both compose this, so a future SaaS front end derives the
 * same state from the same conversation rows.
 *
 * State is keyed to the next not-conducted conversation and the most
 * recent signed-off one:
 *
 *   POST          — a signed-off talk is still awaiting this viewer's
 *                   acknowledgement (player_ack / parent_ack). Surface
 *                   its outcome + the ack prompt. Actionable now.
 *   REVIEW_WINDOW — the next talk's planning window is open. Surface
 *                   "prepare for your talk" + the self-review. Helpful,
 *                   never required (#1851 decision).
 *   WORKING       — the next talk is still in the future, before its
 *                   window. Lead with the player's goals + the date.
 *   IDLE          — nothing pending (no file, season complete, or every
 *                   talk acknowledged).
 *
 * Optionality is a UI contract, not a state here: nothing in this class
 * blocks on an empty reflection. The view decides what to show; this
 * class only says which moment the player is in.
 */
class PdpCycleState {

    const POST          = 'post';
    const REVIEW_WINDOW = 'review_window';
    const WORKING       = 'working';
    const IDLE          = 'idle';

    const VIEWER_PLAYER = 'player';
    const VIEWER_PARENT = 'parent';

    /** @var string one of the state constants */
    public $state = self::IDLE;

    /** @var object|null the conversation the state is keyed to */
    public $conversation = null;

    /**
     * @var string|null the talk date (YYYY-MM-DD) of the focus
     *                  conversation — conducted_at for POST, otherwise
     *                  scheduled_at. Null when not yet scheduled.
     */
    public $talk_date = null;

    /**
     * Derive the cycle state for a viewer from a season's conversations.
     *
     * @param array<int, object> $convs Conversations for the player's
     *        current-season PDP file, ordered by sequence ASC
     *        (PdpConversationsRepository::listForFile).
     * @param string   $viewer One of VIEWER_PLAYER / VIEWER_PARENT —
     *        decides which ack column gates the POST state.
     * @param int|null $now_ts Unix timestamp for "now" (UTC). Defaults
     *        to current_time('timestamp', true). Injectable for tests.
     */
    public static function derive( array $convs, string $viewer = self::VIEWER_PLAYER, ?int $now_ts = null ): self {
        $out = new self();
        if ( empty( $convs ) ) {
            return $out;
        }

        $now   = $now_ts !== null ? $now_ts : (int) current_time( 'timestamp', true );
        $today = gmdate( 'Y-m-d', $now );
        $ack_col = $viewer === self::VIEWER_PARENT ? 'parent_ack_at' : 'player_ack_at';

        // 1) A signed-off talk this viewer hasn't acknowledged yet is the
        //    most actionable moment — surface its outcome + ack prompt.
        //    Walk newest-first (highest sequence) so the latest talk wins.
        $by_seq_desc = $convs;
        usort( $by_seq_desc, static function ( $a, $b ) {
            return (int) ( $b->sequence ?? 0 ) <=> (int) ( $a->sequence ?? 0 );
        } );
        foreach ( $by_seq_desc as $c ) {
            if ( empty( $c->coach_signoff_at ) ) continue;
            if ( ! empty( $c->{$ack_col} ) ) continue;
            $out->state        = self::POST;
            $out->conversation = $c;
            $out->talk_date    = self::dateOf( $c->conducted_at ?? null ) ?: self::dateOf( $c->scheduled_at ?? null );
            return $out;
        }

        // 2) Otherwise key on the next not-conducted conversation — the
        //    next talk — and whether its planning window has opened.
        $next = null;
        foreach ( $convs as $c ) {
            if ( ! empty( $c->conducted_at ) ) continue;
            $next = $c;
            break;
        }
        if ( $next === null ) {
            // Every talk conducted + acknowledged: nothing pending.
            return $out;
        }

        $out->conversation = $next;
        $out->talk_date    = self::dateOf( $next->scheduled_at ?? null );
        $out->state        = self::windowOpen( $next, $today, $now ) ? self::REVIEW_WINDOW : self::WORKING;
        return $out;
    }

    /**
     * Is the next talk's planning window open as of $today?
     *
     * Primary signal is the seeded `planning_window_start` (migration
     * 0043). When a row predates the backfill and has no window, fall
     * back to the existing "scheduled within 14 days" rule the player
     * surface already uses for the reflection editor, so the two never
     * disagree.
     */
    private static function windowOpen( object $conv, string $today, int $now ): bool {
        $start = self::dateOf( $conv->planning_window_start ?? null );
        if ( $start !== null ) {
            return $today >= $start;
        }
        $scheduled = (string) ( $conv->scheduled_at ?? '' );
        if ( $scheduled === '' ) return false;
        $ts = strtotime( $scheduled . ' UTC' );
        if ( $ts === false ) return false;
        return ( $ts - $now ) <= 14 * DAY_IN_SECONDS;
    }

    /** Normalise a stored datetime/date string to its YYYY-MM-DD part. */
    private static function dateOf( $value ): ?string {
        $value = (string) ( $value ?? '' );
        if ( $value === '' ) return null;
        return substr( $value, 0, 10 );
    }
}
