<?php
namespace TT\Modules\Threads\Subscribers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Threads\Domain\ThreadVisibility;
use TT\Modules\Threads\ThreadMessagesRepository;

/**
 * GoalSystemMessageSubscriber (#0028) — writes is_system=1 messages
 * when a goal is created or its status changes.
 *
 * Hooks:
 *   - tt_goal_saved($player_id, $goal_id, $data)        — create
 *   - tt_goal_status_changed($goal_id, $status, $user)  — added by
 *     this PR to GoalsRestController::update_status().
 *
 * Title / due-date edits write a system message via the same
 * `tt_goal_status_changed` action when the status is also changing —
 * for v1 we only emit one system message per save.
 */
final class GoalSystemMessageSubscriber {

    public static function init(): void {
        add_action( 'tt_goal_saved',          [ self::class, 'onSaved' ], 10, 3 );
        add_action( 'tt_goal_status_changed', [ self::class, 'onStatusChanged' ], 10, 3 );
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function onSaved( int $player_id, int $goal_id, array $data ): void {
        // Only fire once per goal — at create time. Subsequent saves go
        // through update endpoints which fire tt_goal_status_changed.
        $existing = self::countMessages( $goal_id );
        if ( $existing > 0 ) return;

        $author = (int) get_current_user_id();
        $title  = (string) ( $data['title'] ?? '' );
        $body   = sprintf(
            /* translators: %s is the goal title */
            __( 'Goal created: %s', 'talenttrack' ),
            $title
        );
        ( new ThreadMessagesRepository() )->insert( [
            'thread_type'    => 'goal',
            'thread_id'      => $goal_id,
            'author_user_id' => $author,
            'body'           => $body,
            'visibility'     => ThreadVisibility::PUBLIC_LEVEL,
            'is_system'      => 1,
        ] );
    }

    public static function onStatusChanged( int $goal_id, string $status, int $user_id ): void {
        $body = sprintf(
            /* translators: %s is the new status */
            __( 'Status changed to: %s', 'talenttrack' ),
            $status
        );
        ( new ThreadMessagesRepository() )->insert( [
            'thread_type'    => 'goal',
            'thread_id'      => $goal_id,
            'author_user_id' => $user_id > 0 ? $user_id : (int) get_current_user_id(),
            'body'           => $body,
            'visibility'     => ThreadVisibility::PUBLIC_LEVEL,
            'is_system'      => 1,
        ] );
    }

    private static function countMessages( int $goal_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_thread_messages';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE thread_type = %s AND thread_id = %d",
            'goal', $goal_id
        ) );
    }
}
