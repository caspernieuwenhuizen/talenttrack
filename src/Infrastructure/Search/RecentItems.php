<?php
namespace TT\Infrastructure\Search;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\TableExists;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * RecentItems (#4671) — the records a user opened last, in `tt_user_recent`.
 *
 * At most {@see self::KEEP} rows per user and club. Only record kinds are
 * stored (players, teams, people, activities); sections and settings have
 * string keys and are not. Every read goes back through each kind's
 * provider, which scopes and confirms like a search does, so a record whose
 * access was revoked, or which was archived, drops out — and its row is
 * deleted.
 */
final class RecentItems {

    public const KEEP = 20;

    private const TABLE = 'tt_user_recent';

    /** The dashboard views that open one record, and the kind each opens. */
    private const VIEW_KINDS = [ 'players' => 'player', 'teams' => 'team', 'activities' => 'activity' ];

    /**
     * Note that a dashboard page opened a record (`?tt_view=players&id=N`).
     * False for any other view, and as `record()` otherwise.
     */
    public function recordView( int $user_id, string $view, int $id ): bool {
        $kind = self::VIEW_KINDS[ $view ] ?? '';
        return $kind !== '' && $this->record( $user_id, $kind, $id );
    }

    /**
     * Note that a user opened a record. False when the kind holds no
     * records, or the record does not exist or is not one the user may open
     * (the caller answers both the same: not found). Nothing is stored then.
     */
    public function record( int $user_id, string $kind, int $id ): bool {
        if ( $user_id <= 0 || $id <= 0 || ! TableExists::has( self::TABLE ) ) return false;
        $provider = SearchRegistry::get( $kind );
        if ( $provider === null || ! $provider->isRecord() ) return false;
        if ( $provider->hydrate( $user_id, [ $id ] ) === [] ) return false;

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $club  = (int) CurrentClub::id();
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} ( club_id, wp_user_id, entity_type, entity_id, opened_at )
             VALUES ( %d, %d, %s, %d, %s )
             ON DUPLICATE KEY UPDATE opened_at = VALUES( opened_at )",
            $club, $user_id, $kind, $id, current_time( 'mysql', true )
        ) );

        // Keep the newest KEEP: the KEEP-th opened_at is the cutoff. Two
        // statements, no same-table subquery delete (MySQL 5.6).
        $cutoff = $wpdb->get_var( $wpdb->prepare(
            "SELECT opened_at FROM {$table} WHERE club_id = %d AND wp_user_id = %d
              ORDER BY opened_at DESC, id DESC LIMIT 1 OFFSET %d",
            $club, $user_id, self::KEEP - 1
        ) );
        if ( is_string( $cutoff ) && $cutoff !== '' ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE club_id = %d AND wp_user_id = %d AND opened_at < %s",
                $club, $user_id, $cutoff
            ) );
        }
        return true;
    }

    /**
     * The user's recent records, newest first, each re-checked.
     *
     * @return list<array<string,mixed>> search items with `opened_at` (UTC)
     */
    public function forUser( int $user_id ): array {
        if ( $user_id <= 0 || ! TableExists::has( self::TABLE ) ) return [];
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $club  = (int) CurrentClub::id();
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, entity_type, entity_id, opened_at FROM {$table}
              WHERE club_id = %d AND wp_user_id = %d
              ORDER BY opened_at DESC, id DESC LIMIT %d",
            $club, $user_id, self::KEEP
        ) );
        $rows = is_array( $rows ) ? $rows : [];

        $by_kind = [];
        foreach ( $rows as $row ) {
            $by_kind[ (string) $row->entity_type ][] = (int) $row->entity_id;
        }
        $resolved = [];
        foreach ( $by_kind as $kind => $ids ) {
            $provider = SearchRegistry::get( $kind );
            $resolved[ $kind ] = $provider !== null && $provider->isRecord() ? $provider->hydrate( $user_id, $ids ) : [];
        }

        $items = [];
        $gone  = [];
        foreach ( $rows as $row ) {
            $hit = $resolved[ (string) $row->entity_type ][ (int) $row->entity_id ] ?? null;
            if ( $hit === null ) {
                $gone[] = (int) $row->id;
                continue;
            }
            $item              = SearchService::item( $hit );
            $stamp             = strtotime( (string) $row->opened_at . ' UTC' );
            $item['opened_at'] = $stamp !== false ? gmdate( 'c', $stamp ) : '';
            $items[]           = $item;
        }
        if ( $gone !== [] ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE club_id = %d AND wp_user_id = %d AND id IN (" . implode( ',', array_fill( 0, count( $gone ), '%d' ) ) . ')',
                $club, $user_id, ...$gone
            ) );
        }
        return $items;
    }

    /** A deleted account's rows, in every club. */
    public static function forgetUser( int $user_id ): void {
        if ( $user_id <= 0 || ! TableExists::has( self::TABLE ) ) return;
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . self::TABLE, [ 'wp_user_id' => $user_id ], [ '%d' ] );
    }
}
