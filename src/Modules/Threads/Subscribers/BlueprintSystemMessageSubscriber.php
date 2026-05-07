<?php
namespace TT\Modules\Threads\Subscribers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Threads\Domain\ThreadVisibility;
use TT\Modules\Threads\ThreadMessagesRepository;

/**
 * BlueprintSystemMessageSubscriber (#0068 Phase 3) — writes
 * `is_system=1` messages into a blueprint's discussion thread when
 * the operator transitions its status (draft → shared → locked +
 * Reopen).
 *
 * Per spec decision Q2: per-assignment swaps stay silent — coaches
 * see those via the chemistry refresh, and a system message per drop
 * would be too noisy.
 *
 * Hook:
 *   - tt_team_blueprint_status_changed( $blueprint_id, $status, $user_id )
 *
 * Emitted from `TeamDevelopmentRestController::set_blueprint_status()`.
 */
final class BlueprintSystemMessageSubscriber {

    public static function init(): void {
        add_action( 'tt_team_blueprint_status_changed', [ self::class, 'onStatusChanged' ], 10, 3 );
    }

    public static function onStatusChanged( int $blueprint_id, string $status, int $user_id ): void {
        if ( $blueprint_id <= 0 || $status === '' ) return;

        $body = sprintf(
            /* translators: %s is the new blueprint status (draft / shared / locked) */
            __( 'Status changed to: %s', 'talenttrack' ),
            $status
        );
        ( new ThreadMessagesRepository() )->insert( [
            'thread_type'    => 'blueprint',
            'thread_id'      => $blueprint_id,
            'author_user_id' => $user_id > 0 ? $user_id : (int) get_current_user_id(),
            'body'           => $body,
            'visibility'     => ThreadVisibility::PUBLIC_LEVEL,
            'is_system'      => 1,
        ] );
    }
}
