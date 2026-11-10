<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Threads\Adapters\PlayerThreadAdapter;
use TT\Modules\Threads\Domain\ThreadAccess;
use TT\Modules\Threads\Domain\ThreadVisibility;
use TT\Modules\Threads\ThreadTypeRegistry;

/**
 * Player notes for staff (#4936): the messages on a player's notes thread
 * (`tt_thread_messages`, thread type `player`), found by the player's name,
 * the note's text and the day it was written.
 *
 * Narrowed by the notes thread's own read (`PlayerThreadAdapter::canRead()`:
 * `tt_view_player_notes`, academy-wide or on the teams the viewer coaches)
 * and by the staff-only rule (`ThreadAccess::canSeePrivate()`): a staff-only
 * note the viewer may not see is neither matched nor counted. Confirmed by
 * the batched forms of the same two checks.
 *
 * The read-only observer, parents and players hold no notes read, so they
 * never get this kind (owner decision on #4687).
 */
final class NoteSearchProvider extends StaffRecordSearchProvider {

    /** @var array<int, array{all:bool, teams:array<int,bool>}> */
    private array $private = [];

    public function kind(): string { return 'note'; }

    protected function alias(): string { return 'm'; }

    protected function from(): string {
        global $wpdb;
        return "FROM {$wpdb->prefix}tt_thread_messages m
                JOIN {$wpdb->prefix}tt_players p ON p.id = m.thread_id
           LEFT JOIN {$wpdb->users} u ON u.ID = m.author_user_id";
    }

    protected function columns(): string {
        return 'm.id, m.thread_id AS player_id, m.body, m.visibility, m.created_at, u.display_name AS author_name, p.first_name, p.last_name, p.team_id, p.wp_user_id';
    }

    protected function live(): string {
        return "m.thread_type = 'player' AND m.deleted_at IS NULL AND m.is_system = 0";
    }

    protected function dateColumn(): string { return 'DATE( m.created_at )'; }

    protected function tieBreak(): string { return 'm.created_at DESC, m.id DESC'; }

    protected function scope( int $user_id ): ?array {
        if ( ! self::adapter() instanceof PlayerThreadAdapter ) return null;
        if ( ! user_can( $user_id, 'tt_view_player_notes' ) ) return null;

        $coached = array_map( static fn( $t ): int => (int) $t->id, QueryHelpers::get_teams_for_coach( $user_id ) );
        if ( QueryHelpers::user_has_global_entity_read( $user_id, 'player_notes' ) ) {
            $parts  = [ '1=1' ];
            $params = [];
        } else {
            if ( $coached === [] ) return null;
            $parts  = [ 'p.team_id IN (' . implode( ',', array_fill( 0, count( $coached ), '%d' ) ) . ')' ];
            $params = $coached;
        }

        // Staff-only notes only where the viewer may see them.
        $reach = $this->privateReach( $user_id, $coached );
        if ( ! $reach['all'] ) {
            $teams = array_keys( array_filter( $reach['teams'] ) );
            $sql   = 'm.visibility = %s';
            $params[] = ThreadVisibility::PUBLIC_LEVEL;
            if ( $teams !== [] ) {
                $sql    = "( {$sql} OR p.team_id IN (" . implode( ',', array_fill( 0, count( $teams ), '%d' ) ) . ') )';
                $params = array_merge( $params, $teams );
            }
            $parts[] = $sql;
        }
        return [ 'sql' => '( ' . implode( ' AND ', $parts ) . ' )', 'params' => $params ];
    }

    protected function tokenText( string $token, int $user_id ): array {
        return self::likeSql( 'm.body', $token );
    }

    protected function toHit( object $row, int $user_id ): array {
        $r         = (array) $row;
        $player_id = (int) ( $r['player_id'] ?? 0 );
        $team_id   = (int) ( $r['team_id'] ?? 0 );
        $date      = substr( (string) ( $r['created_at'] ?? '' ), 0, 10 );
        $hit       = [
            'type'      => 'note',
            'id'        => (int) ( $r['id'] ?? 0 ),
            'label'     => self::playerName( $row ),
            'sublabel'  => implode( ' · ', array_filter( [ $date !== '' ? \TT\Shared\Dates\TTDate::date( $date ) : '', (string) ( $r['author_name'] ?? '' ) ] ) ),
            'url'       => $this->detailUrl( 'players', $player_id, [ 'tab' => 'notes' ] ),
            'score'     => 0,
            'date'      => $date,
            'player_id' => $player_id,
            'actions'   => [],
            'tt_row'    => [ 'team_id' => $team_id, 'visibility' => (string) ( $r['visibility'] ?? '' ) ],
        ];
        if ( $team_id > 0 ) $hit['team_id'] = $team_id;
        $excerpt = self::excerpt( (string) ( $r['body'] ?? '' ) );
        if ( $excerpt !== '' ) $hit['excerpt'] = $excerpt;
        return $hit;
    }

    protected function confirmHits( int $user_id, array $hits ): array {
        $adapter = self::adapter();
        if ( ! $adapter instanceof PlayerThreadAdapter ) return [];

        $team_by_player = [];
        foreach ( $hits as $hit ) $team_by_player[ (int) ( $hit['player_id'] ?? 0 ) ] = (int) ( $hit['tt_row']['team_id'] ?? 0 );
        unset( $team_by_player[0] );
        if ( $team_by_player === [] ) return [];

        $view  = AuthorizationService::canViewPlayers( $user_id, array_keys( $team_by_player ) );
        $read  = $adapter->canReadMany( $user_id, $team_by_player );
        $reach = ThreadAccess::canSeePrivateOnTeams( $user_id, array_values( array_unique( $team_by_player ) ) );

        $out = [];
        foreach ( $hits as $hit ) {
            $pid = (int) ( $hit['player_id'] ?? 0 );
            if ( empty( $view[ $pid ] ) || empty( $read[ $pid ] ) ) continue;
            $public = ( $hit['tt_row']['visibility'] ?? '' ) === ThreadVisibility::PUBLIC_LEVEL;
            if ( ! $public && ! $reach['all'] && empty( $reach['teams'][ (int) ( $hit['tt_row']['team_id'] ?? 0 ) ] ) ) continue;
            $out[] = $hit;
        }
        return $out;
    }

    /**
     * @param list<int> $team_ids
     * @return array{all:bool, teams:array<int,bool>}
     */
    private function privateReach( int $user_id, array $team_ids ): array {
        return $this->private[ $user_id ] ??= ThreadAccess::canSeePrivateOnTeams( $user_id, $team_ids );
    }

    private static function adapter(): ?\TT\Modules\Threads\Domain\ThreadTypeAdapter {
        return class_exists( ThreadTypeRegistry::class ) ? ThreadTypeRegistry::get( 'player' ) : null;
    }
}
