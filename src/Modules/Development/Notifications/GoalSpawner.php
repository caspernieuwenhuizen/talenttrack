<?php
namespace TT\Modules\Development\Notifications;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Development\IdeaRepository;
use TT\Modules\Development\IdeaStatus;

/**
 * GoalSpawner — when an idea hits `in-progress` AND has a player_id
 * tagged, automatically spawn a development goal in `tt_goals` linked
 * to the player.
 *
 * The spawn writes the new goal id back onto `tt_dev_ideas.spawned_goal_id`
 * so we don't double-spawn on a re-trigger and so the admin UI can
 * link out to the resulting goal.
 *
 * Idea-without-player promotions (a generic feature request) leave the
 * goal table alone — the staging-only flow is the right thing then.
 */
class GoalSpawner {

    public static function register(): void {
        add_action( 'tt_dev_idea_status_changed', [ self::class, 'maybeSpawn' ], 20, 2 );
    }

    public static function maybeSpawn( int $ideaId, string $status ): void {
        if ( $status !== IdeaStatus::IN_PROGRESS ) return;

        $repo = new IdeaRepository();
        $idea = $repo->find( $ideaId );
        if ( ! $idea ) return;
        if ( ! empty( $idea->spawned_goal_id ) ) return; // already spawned
        $playerId = (int) ( $idea->player_id ?? 0 );
        if ( $playerId <= 0 ) return;

        global $wpdb;
        $ok = $wpdb->insert( $wpdb->prefix . 'tt_goals', [
            'club_id'     => CurrentClub::id(),
            'player_id'   => $playerId,
            'title'       => (string) $idea->title,
            'description' => (string) ( $idea->body ?? '' ),
            'status'      => 'pending',
            'priority'    => 'medium',
            'created_by'  => get_current_user_id(),
        ] );
        if ( $ok === false ) return;

        $repo->update( $ideaId, [ 'spawned_goal_id' => (int) $wpdb->insert_id ] );
    }
}
