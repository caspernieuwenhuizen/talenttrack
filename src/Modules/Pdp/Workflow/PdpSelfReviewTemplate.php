<?php
namespace TT\Modules\Pdp\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Resolvers\LambdaResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * PdpSelfReviewTemplate (#1852) — the player's "prepare for your
 * development talk" nudge. When a conversation's planning window opens,
 * PdpSelfReviewSweep creates one task per (player, conversation) due on
 * the talk date, assigned to the player. This template supplies the
 * task's identity, copy and form so the workflow inbox can render it.
 *
 * It is a **nudge, not a gate**: nothing blocks if it's ignored, it is
 * completed when the player saves their reflection, and it auto-resolves
 * with no penalty once the talk is conducted (see PdpSelfReviewTasks).
 *
 * Cadence is owned by the sweep (so due_at can be the talk date, which
 * the engine's offset-based dispatch can't express), hence the manual
 * schedule here.
 */
class PdpSelfReviewTemplate extends TaskTemplate {

    public const KEY = 'pdp_self_review';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Prepare for your development talk', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Add a short self-reflection before your talk. It helps your coach. It is optional, never required.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        // Cadence is owned by PdpSelfReviewSweep on the workflow cron tick.
        return [ 'type' => 'manual' ];
    }

    public function defaultDeadlineOffset(): string {
        // Unused by the sweep (it sets due_at = the talk date); kept for
        // the interface + any manual dispatch.
        return '+14 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new LambdaResolver( static function ( TaskContext $context ): array {
            $player_id = (int) ( $context->player_id ?? 0 );
            if ( $player_id <= 0 ) return [];
            $player = QueryHelpers::get_player( $player_id );
            $uid = $player ? (int) ( $player->wp_user_id ?? 0 ) : 0;
            return $uid > 0 ? [ $uid ] : [];
        } );
    }

    public function formClass(): string {
        return PdpSelfReviewForm::class;
    }

    public function entityLinks(): array {
        return [ 'player_id' ];
    }
}
