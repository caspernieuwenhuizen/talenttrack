<?php
namespace TT\Modules\Pdp\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\TaskStatus;

/**
 * PdpSelfReviewTasks (#1852) — keeps a player's self-review nudge in
 * step with their conversation, off the conversation PATCH. The REST
 * controller composes this; the decision logic lives here (§4), so a
 * future SaaS front end gets the same behaviour from the same edit.
 *
 *   - Player saves a (non-empty) reflection → the nudge is **completed**.
 *   - The talk is conducted → the nudge **auto-resolves with no penalty**
 *     (skipped), even if the reflection was never filled in.
 *
 * Never blocks: if there's no matching active task (window not open yet,
 * no linked account, already resolved), these are no-ops.
 */
class PdpSelfReviewTasks {

    /**
     * React to a successful conversation update. `$conv` is the row as it
     * was before the patch; `$patch` is the applied column => value map.
     *
     * @param array<string,mixed> $patch
     */
    public static function syncAfterPatch( object $conv, object $file, array $patch ): void {
        $player_id = (int) ( $file->player_id ?? 0 );
        if ( $player_id <= 0 ) return;

        $player = QueryHelpers::get_player( $player_id );
        $uid = $player ? (int) ( $player->wp_user_id ?? 0 ) : 0;
        if ( $uid <= 0 ) return;

        // due_at is the talk date; honour a same-patch reschedule.
        $due_at = (string) ( $patch['scheduled_at'] ?? $conv->scheduled_at ?? '' );
        if ( $due_at === '' ) return;

        $tasks  = new TasksRepository();
        $active = [ TaskStatus::OPEN, TaskStatus::IN_PROGRESS, TaskStatus::OVERDUE ];

        // Talk conducted → auto-resolve, no penalty, even if empty.
        if ( array_key_exists( 'conducted_at', $patch ) && ! empty( $patch['conducted_at'] ) ) {
            $task = $tasks->findByNaturalKey( PdpSelfReviewTemplate::KEY, $uid, $player_id, $due_at, $active );
            if ( $task !== null ) {
                $tasks->skip( (int) $task['id'] );
            }
            return;
        }

        // Player filled in their reflection → mark the nudge done.
        if ( array_key_exists( 'player_reflection', $patch ) ) {
            $text = trim( wp_strip_all_tags( (string) $patch['player_reflection'] ) );
            if ( $text !== '' ) {
                $task = $tasks->findByNaturalKey( PdpSelfReviewTemplate::KEY, $uid, $player_id, $due_at, $active );
                if ( $task !== null ) {
                    $tasks->complete( (int) $task['id'], [ 'source' => 'reflection_saved' ] );
                }
            }
        }
    }
}
