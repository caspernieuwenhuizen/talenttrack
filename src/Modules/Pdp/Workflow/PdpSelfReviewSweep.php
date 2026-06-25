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

    public static function run(): void {
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
}
