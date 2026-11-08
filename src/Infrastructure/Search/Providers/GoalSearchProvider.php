<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Search\SearchViewer;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * Goals (#4673), for the family: the viewer's own and their children's,
 * where the record's own read allows it
 * (`SearchViewer::playerIdsReading( …, 'goals' )`). Found by title and by
 * due date. Staff are not served here yet.
 */
final class GoalSearchProvider extends FamilyRecordSearchProvider {

    public function kind(): string { return 'goal'; }

    protected function scopeIds( int $user_id ): array {
        return SearchViewer::playerIdsReading( $user_id, 'goals' );
    }

    protected function rows( array $ids, SearchQuery $query, int $limit ): array {
        global $wpdb;
        $dates = self::dateSql( 'g.due_date', $query );
        $sql   = "SELECT g.id, g.player_id, g.title, g.due_date
                    FROM {$wpdb->prefix}tt_goals g
                   WHERE g.club_id = %d
                     AND g.archived_at IS NULL
                     AND g.player_id IN (" . self::placeholders( $ids ) . ")
                     AND {$dates['sql']}
                ORDER BY g.due_date DESC, g.id DESC
                   LIMIT " . (int) $limit;
        $params = array_merge( [ (int) CurrentClub::id() ], $ids, $dates['params'] );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        return array_values( array_filter( is_array( $rows ) ? $rows : [], 'is_object' ) );
    }

    protected function toHit( object $row, int $user_id ): array {
        $r         = (array) $row;
        $player_id = (int) ( $r['player_id'] ?? 0 );
        $title     = (string) ( $r['title'] ?? '' );
        $date      = (string) ( $r['due_date'] ?? '' );
        // A parent of more than one child sees whose goal it is.
        $child     = SearchViewer::childNamesToShow( $user_id )[ $player_id ] ?? '';
        return [
            'type'      => 'goal',
            'id'        => (int) ( $r['id'] ?? 0 ),
            'label'     => $title,
            'sublabel'  => trim( implode( ' · ', array_filter( [ $child, $date !== '' ? \TT\Shared\Dates\TTDate::date( $date ) : '' ] ) ) ),
            'url'       => $this->meUrl( 'my-goals', [ 'player_id' => $player_id ] ),
            'score'     => 0,
            'date'      => $date,
            'player_id' => $player_id,
            'actions'   => [],
            'tt_title'  => $title,
            'tt_text'   => '',
        ];
    }

    protected function scopeKey( array $hit ): int {
        return (int) ( $hit['player_id'] ?? 0 );
    }
}
