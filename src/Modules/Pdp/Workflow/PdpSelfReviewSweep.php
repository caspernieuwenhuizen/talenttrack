<?php
namespace TT\Modules\Pdp\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\TaskStatus;

/**
 * PdpSelfReviewSweep (#1852) — the daily nudge sweep. Runs on the
 * workflow engine's cron tick (no ad-hoc wp_cron — see §"background
 * work"); for every conversation whose planning window has opened and
 * that hasn't been conducted, it creates exactly one `pdp_self_review`
 * task assigned to the player, due on the talk date.
 *
 * Idempotent: the task's natural key is (template, player's user, the
 * player record, due_at = the talk date). Re-running the sweep — hourly,
 * as the tick fires — never creates a duplicate, because a task already
 * matching that key (in any status, including a completed or skipped
 * one) short-circuits creation.
 *
 * Created tasks are surfaced by the existing My-tasks inbox; completion
 * + auto-resolve are handled by PdpSelfReviewTasks off the conversation
 * PATCH.
 */
class PdpSelfReviewSweep {

    /** The sweep, once for every club. */
    public static function run(): void {
        foreach ( self::clubIds() as $club_id ) {
            self::withClub( $club_id, static function (): void {
                self::runForCurrentClub();
            } );
        }
    }

    private static function runForCurrentClub(): void {
        $today = gmdate( 'Y-m-d', (int) current_time( 'timestamp', true ) );
        $convs = ( new PdpConversationsRepository() )->listEnteringPlanningWindow( $today );
        if ( empty( $convs ) ) return;

        $tasks = new TasksRepository();

        foreach ( $convs as $c ) {
            $player_id = (int) ( $c->player_id ?? 0 );
            if ( $player_id <= 0 ) continue;

            $due_at = (string) ( $c->scheduled_at ?? '' );
            if ( $due_at === '' ) continue;

            $player = QueryHelpers::get_player( $player_id );
            $uid = $player ? (int) ( $player->wp_user_id ?? 0 ) : 0;
            if ( $uid <= 0 ) continue; // No linked account → nobody to nudge.

            // Idempotent: one task per (player, conversation date), ever.
            if ( $tasks->findByNaturalKey( PdpSelfReviewTemplate::KEY, $uid, $player_id, $due_at ) !== null ) {
                continue;
            }

            $tasks->create( [
                'template_key'     => PdpSelfReviewTemplate::KEY,
                'assignee_user_id' => $uid,
                'player_id'        => $player_id,
                'due_at'           => $due_at,
                'status'           => TaskStatus::OPEN,
            ] );
        }
    }

    /**
     * Every club that has config rows, club 1 always included. Mirrors the
     * enumeration `AlertSweepCron` and `AutoPurgeCron` use.
     *
     * @return int[]
     */
    private static function clubIds(): array {
        global $wpdb;
        $ids = $wpdb->get_col( "SELECT DISTINCT club_id FROM {$wpdb->prefix}tt_config" );
        $ids = array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : [] ) ) );
        if ( ! in_array( 1, $ids, true ) ) {
            $ids[] = 1;
        }
        return array_filter( $ids, static function ( $id ) { return $id > 0; } );
    }

    /** Run `$fn` with `tt_current_club_id` pinned to `$club_id`, then restore. */
    private static function withClub( int $club_id, callable $fn ): void {
        $filter = static function () use ( $club_id ) { return $club_id; };
        add_filter( 'tt_current_club_id', $filter, 9999 );
        try {
            $fn();
        } finally {
            remove_filter( 'tt_current_club_id', $filter, 9999 );
        }
    }
}
