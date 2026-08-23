<?php
namespace TT\Modules\Media;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Activities\Repositories\ActivitiesRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Players the tag control offers for a record's media.
 *
 * Lifted out of `FrontendActivitiesManageView` (#2742) because the REST
 * layer needs the same answer: an upload now returns its rendered tile so
 * the grid can show it without a reload, and that tile carries the tag
 * control. Two implementations of "who can be tagged here" would drift the
 * first time a roster rule changed, and the view is the wrong place for a
 * question the API also asks (CLAUDE.md § 4).
 *
 * Only activities offer tagging. A photo on a player is already about that
 * player, and a team photo tags nobody in particular — the roster there
 * would be a list of everyone, which is not a decision worth surfacing.
 */
final class MediaTagRoster {

    /**
     * @return array<int, string> player id => display name
     */
    public static function for( string $entity_type, int $entity_id ): array {
        if ( $entity_type !== MediaEntityType::ACTIVITY || $entity_id <= 0 ) return [];

        $activity = ( new ActivitiesRepository() )->findById( $entity_id );
        if ( ! $activity ) return [];

        return self::forActivity( $activity );
    }

    /**
     * Same answer when the caller already holds the activity row, so a
     * render that has one does not go back to the database for it.
     *
     * @return array<int, string> player id => display name
     */
    public static function forActivity( object $activity ): array {
        $team_id = (int) ( $activity->team_id ?? 0 );
        if ( $team_id <= 0 ) return [];

        $out = [];
        foreach ( QueryHelpers::get_players( $team_id ) as $player ) {
            $out[ (int) $player->id ] = QueryHelpers::player_display_name( $player );
        }

        return $out;
    }
}
