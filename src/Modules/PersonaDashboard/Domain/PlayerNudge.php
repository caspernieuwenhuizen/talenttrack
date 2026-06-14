<?php
namespace TT\Modules\PersonaDashboard\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvaluationsRepository;
use TT\Modules\Threads\ThreadMessagesRepository;

/**
 * PlayerNudge — resolves the "note from your coach" shown by the
 * `coach_nudge` InfoCard (#1385).
 *
 * Source = the most recent of (a) the player-facing feedback on an
 * evaluation (the #1386 `player_feedback` field) and (b) the latest
 * public comment on one of the player's goal threads authored by someone
 * other than the player. Most recent timestamp wins. Keeping the
 * resolution here (not in the widget) keeps the widget a pure renderer.
 */
final class PlayerNudge {

    /**
     * @return array{text:string}|null  null when there's nothing to show.
     */
    public static function latestFor( int $player_id, int $viewer_user_id = 0 ): ?array {
        if ( $player_id <= 0 ) return null;

        $feedback = ( new EvaluationsRepository() )->latestPlayerFeedbackForPlayer( $player_id );
        $comment  = ( new ThreadMessagesRepository() )->latestPublicGoalMessageForPlayer( $player_id, $viewer_user_id );

        $candidates = [];
        if ( $feedback !== null && trim( (string) $feedback->player_feedback ) !== '' ) {
            $candidates[] = [
                'text' => (string) $feedback->player_feedback,
                'ts'   => strtotime( (string) $feedback->eval_date ) ?: 0,
            ];
        }
        if ( $comment !== null && trim( (string) $comment->body ) !== '' ) {
            $candidates[] = [
                'text' => (string) $comment->body,
                'ts'   => strtotime( (string) $comment->created_at ) ?: 0,
            ];
        }
        if ( empty( $candidates ) ) return null;

        usort( $candidates, static fn( $a, $b ): int => $b['ts'] <=> $a['ts'] );
        return [ 'text' => $candidates[0]['text'] ];
    }
}
