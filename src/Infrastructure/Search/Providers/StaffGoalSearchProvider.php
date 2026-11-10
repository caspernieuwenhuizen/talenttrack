<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;

/**
 * Goals for staff (#4936): the goals of the players the viewer reads them
 * for, academy-wide, by team, or a scout's linked players
 * (`sectionScope( …, 'goals' )`). Confirmed by the goal detail's own pair
 * (`GoalAccess::mayRead()`: `canViewPlayer()` and the goals section read).
 *
 * Found by the player's name, the goal's title and description, and the
 * due date. A hit carries an excerpt of the description.
 */
final class StaffGoalSearchProvider extends StaffRecordSearchProvider {

    public function kind(): string { return 'staff_goal'; }

    protected function alias(): string { return 'g'; }

    protected function from(): string {
        global $wpdb;
        return "FROM {$wpdb->prefix}tt_goals g
                JOIN {$wpdb->prefix}tt_players p ON p.id = g.player_id";
    }

    protected function columns(): string {
        return 'g.id, g.player_id, g.title, g.description, g.due_date, p.first_name, p.last_name, p.team_id, p.wp_user_id';
    }

    protected function live(): string {
        return ArchiveRepository::filterClause( 'active', 'g' );
    }

    protected function dateColumn(): string { return 'g.due_date'; }

    protected function tieBreak(): string { return 'g.due_date DESC, g.id DESC'; }

    protected function scope( int $user_id ): ?array {
        return self::sectionScope( $user_id, 'goals' );
    }

    protected function tokenText( string $token, int $user_id ): array {
        return self::anyOf( [ self::likeSql( 'g.title', $token ), self::likeSql( 'g.description', $token ) ] );
    }

    protected function toHit( object $row, int $user_id ): array {
        $r         = (array) $row;
        $player_id = (int) ( $r['player_id'] ?? 0 );
        $team_id   = (int) ( $r['team_id'] ?? 0 );
        $date      = (string) ( $r['due_date'] ?? '' );
        $hit       = [
            'type'      => 'staff_goal',
            'id'        => (int) ( $r['id'] ?? 0 ),
            'label'     => (string) ( $r['title'] ?? '' ),
            'sublabel'  => implode( ' · ', array_filter( [ self::playerName( $row ), $date !== '' ? \TT\Shared\Dates\TTDate::date( $date ) : '' ] ) ),
            'url'       => $this->detailUrl( 'goals', (int) ( $r['id'] ?? 0 ) ),
            'score'     => 0,
            'date'      => $date,
            'player_id' => $player_id,
            'actions'   => [],
            'tt_row'    => [ 'team_id' => $team_id, 'wp_user_id' => (int) ( $r['wp_user_id'] ?? 0 ) ],
        ];
        if ( $team_id > 0 ) $hit['team_id'] = $team_id;
        $excerpt = self::excerpt( (string) ( $r['description'] ?? '' ) );
        if ( $excerpt !== '' ) $hit['excerpt'] = $excerpt;
        return $hit;
    }

    protected function confirmHits( int $user_id, array $hits ): array {
        return self::keepReadable( $user_id, $hits, 'goals' );
    }
}
