<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Search\ParentSearchService;
use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * Messages (#4673): a parent's own inbox, by subject and by the day it was
 * sent. Not the child's: a message is addressed to a person, and this one
 * is addressed to the reader. Nobody else is served here.
 */
final class MessageSearchProvider extends FamilyRecordSearchProvider {

    public function kind(): string { return 'message'; }

    protected function scopeIds( int $user_id ): array {
        return ParentSearchService::isParentCaller( $user_id ) ? [ $user_id ] : [];
    }

    protected function rows( array $ids, SearchQuery $query, int $limit ): array {
        global $wpdb;
        $dates = self::dateSql( 'DATE(m.created_at)', $query );
        $sql   = "SELECT m.id, m.subject, m.created_at, m.recipient_player_id, m.recipient_user_id
                    FROM {$wpdb->prefix}tt_comms_inbox m
                   WHERE m.club_id = %d
                     AND m.recipient_user_id IN (" . self::placeholders( $ids ) . ")
                     AND {$dates['sql']}
                ORDER BY m.created_at DESC, m.id DESC
                   LIMIT " . (int) $limit;
        $params = array_merge( [ (int) CurrentClub::id() ], $ids, $dates['params'] );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        return array_values( array_filter( is_array( $rows ) ? $rows : [], 'is_object' ) );
    }

    protected function toHit( object $row, int $user_id ): array {
        $r       = (array) $row;
        $id      = (int) ( $r['id'] ?? 0 );
        $subject = (string) ( $r['subject'] ?? '' );
        $date    = substr( (string) ( $r['created_at'] ?? '' ), 0, 10 );
        return [
            'type'         => 'message',
            'id'           => $id,
            'label'        => $subject,
            'sublabel'     => $date !== '' ? \TT\Shared\Dates\TTDate::date( $date ) : '',
            'url'          => $this->meUrl( 'my-messages', [ 'id' => $id ] ),
            'score'        => 0,
            'date'         => $date,
            'player_id'    => (int) ( $r['recipient_player_id'] ?? 0 ),
            'actions'      => [],
            'tt_recipient' => (int) ( $r['recipient_user_id'] ?? 0 ),
            'tt_title'     => $subject,
            'tt_text'      => '',
        ];
    }

    protected function scopeKey( array $hit ): int {
        return (int) ( $hit['tt_recipient'] ?? 0 );
    }
}
