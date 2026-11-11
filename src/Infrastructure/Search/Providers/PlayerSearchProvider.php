<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\PlayerVisibility;
use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Search\SearchViewer;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Teams\TeamRoster;
use TT\Modules\Activities\ActivityAccess;
use TT\Modules\Players\Services\PlayerPhoto;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * Players (#4671). Narrowed by `PlayerVisibility::sqlClause()`, confirmed by
 * `AuthorizationService::canViewPlayers()`. Honours the shirt number and
 * the age group (the player's team's).
 *
 * Each hit carries at most two actions the viewer may use, decided by the
 * same checks the target screens ask, never by role name:
 *
 *   - a viewer who may change evaluations academy-wide: the profile and the
 *     player report;
 *   - one who may evaluate this player (the new-evaluation wizard's
 *     capability and `AuthorizationService::canEvaluatePlayer()`): a new
 *     evaluation, and the goals when `mayReadPlayerSection( …, 'goals' )`
 *     holds, the profile otherwise;
 *   - otherwise the profile, and the report when they may read it.
 *
 * The photo comes from `PlayerPhoto`, the gated media-store URL.
 *
 * #4673 — a player (not staff) also finds their teammates, the players on
 * their own team, as the teammate card allows: the name and a link to that
 * card, nothing else. A parent or a player gets no actions.
 */
final class PlayerSearchProvider extends RecordSearchProvider {

    public function kind(): string { return 'player'; }

    public function patterns(): array {
        return [ SearchQuery::PATTERN_SHIRT, SearchQuery::PATTERN_AGE_GROUP ];
    }

    protected function alias(): string { return 'p'; }

    protected function kindBonus(): int { return 15; }

    protected function tieBreak(): string { return 'p.last_name ASC, p.first_name ASC, p.id ASC'; }

    protected function from(): string {
        global $wpdb;
        return "FROM {$wpdb->prefix}tt_players p
           LEFT JOIN {$wpdb->prefix}tt_teams t ON t.id = p.team_id AND t.club_id = p.club_id";
    }

    protected function columns(): string {
        return 'p.id, p.first_name, p.last_name, p.team_id, p.status, p.wp_user_id, p.photo_media_id, p.photo_url, p.jersey_number, p.preferred_positions, t.name AS team_name';
    }

    protected function scope( int $user_id ): ?array {
        if ( $user_id <= 0 ) return null;
        $clause = PlayerVisibility::sqlClause( $user_id, 'p' );

        // Own scope: the teams the viewer coaches, their children, themselves.
        $own_teams    = ActivityAccess::coachedTeamIds( $user_id );
        $own_players  = ActivityAccess::readablePlayerIds( $user_id );
        $own_parts    = [];
        $own_params   = [];
        if ( $own_teams !== [] ) {
            $in           = self::inList( 'p.team_id', $own_teams );
            $own_parts[]  = $in['sql'];
            $own_params   = array_merge( $own_params, $in['params'] );
        }
        if ( $own_players !== [] ) {
            $in           = self::inList( 'p.id', $own_players );
            $own_parts[]  = $in['sql'];
            $own_params   = array_merge( $own_params, $in['params'] );
        }

        // #4673 — a player also finds their teammates, by name only: the
        // members of their own team's roster, as My Team lists them
        // (`TeamRoster::memberOfClause()`), for roster consistency.
        $sql    = $clause['sql'];
        $params = $clause['params'];
        $team   = self::teammateTeam( $user_id );
        if ( $team > 0 && $sql !== '1=1' ) {
            $sql    = "( {$sql} OR ( " . TeamRoster::memberOfClause( 'p', '%d' ) . ' ) )';
            $params = array_merge( $params, [ $team ] );
        }

        return [
            'sql'        => $sql,
            'params'     => $params,
            'own_sql'    => $own_parts === [] ? '' : '( ' . implode( ' OR ', $own_parts ) . ' )',
            'own_params' => $own_params,
        ];
    }

    /**
     * The team whose players this viewer sees as teammates: their own, when
     * they are a player and not staff. 0 otherwise.
     */
    private static function teammateTeam( int $user_id ): int {
        if ( SearchViewer::isStaff( $user_id ) ) return 0;
        $own = SearchViewer::ownPlayer( $user_id );
        return $own !== null ? (int) ( $own->team_id ?? 0 ) : 0;
    }

    protected function conditions( SearchQuery $query ): array {
        $parts  = [];
        $params = [];
        if ( $query->shirt !== null ) {
            $parts[]  = 'p.jersey_number = %d';
            $params[] = $query->shirt;
        }
        if ( $query->age_group !== null ) {
            global $wpdb;
            $group    = self::ageGroupSql( 'pt', $query->age_group );
            $parts[]  = "EXISTS ( SELECT 1 FROM {$wpdb->prefix}tt_teams pt WHERE pt.id = p.team_id AND pt.club_id = p.club_id AND {$group['sql']} )";
            $params   = array_merge( $params, $group['params'] );
        }
        if ( $query->team_id !== null ) {
            $parts[]  = 'p.team_id = %d';
            $params[] = $query->team_id;
        }
        return [ 'sql' => $parts === [] ? '1=1' : implode( ' AND ', $parts ), 'params' => $params ];
    }

    /** @return array<string,mixed> */
    protected function toHit( object $row, int $user_id ): array {
        $r       = (array) $row;
        $id      = (int) ( $r['id'] ?? 0 );
        $team_id = (int) ( $r['team_id'] ?? 0 );
        $hit     = [
            'type'     => 'player',
            'id'       => $id,
            'label'    => trim( (string) ( $r['first_name'] ?? '' ) . ' ' . (string) ( $r['last_name'] ?? '' ) ),
            'sublabel' => (string) ( $r['team_name'] ?? '' ),
            'url'      => $this->url( $user_id, $id ),
            'score'    => 0,
            'player_id' => $id,
            'actions'  => [],
        ];
        if ( $team_id > 0 ) $hit['team_id'] = $team_id;
        // #4936 — the shirt number and the primary position, from the one
        // reader of the stored positions.
        $shirt = (int) ( $r['jersey_number'] ?? 0 );
        if ( $shirt > 0 ) $hit['shirt_number'] = $shirt;
        $position = \TT\Infrastructure\Players\PlayerPosition::primaryCodeOf( $row );
        if ( $position !== '' ) $hit['position'] = $position;
        // Internal: read by confirm() for the photo and the section check,
        // never part of an item (SearchService::item() lists its keys).
        $hit['tt_row'] = [
            'wp_user_id'     => (int) ( $r['wp_user_id'] ?? 0 ),
            'status'         => (string) ( $r['status'] ?? '' ),
            'photo_media_id' => (int) ( $r['photo_media_id'] ?? 0 ),
            'photo_url'      => (string) ( $r['photo_url'] ?? '' ),
        ];
        return $hit;
    }

    /**
     * Where a player opens: the player page for staff, the player's own
     * development page for the player and for a guardian (who reads the
     * staff page through no grant).
     */
    private function url( int $user_id, int $player_id ): string {
        // #4673 — the matrix bridge grants `tt_view_players` to a guardian
        // too, for their own children, so a family viewer is asked first.
        if ( ! SearchViewer::isFamily( $user_id ) && user_can( $user_id, 'tt_view_players' ) ) return $this->detailUrl( 'players', $player_id );
        static $own = [];
        if ( ! isset( $own[ $user_id ] ) ) {
            $own[ $user_id ] = ( new \TT\Modules\Activities\Repositories\ActivitiesRepository() )->linkedPlayerIdForUser( $user_id );
        }
        $args = [ 'tt_view' => 'my-development' ];
        if ( $own[ $user_id ] !== $player_id ) $args['player_id'] = $player_id;
        return (string) add_query_arg( $args, $this->dashboard() );
    }

    public function confirm( int $user_id, array $hits ): array {
        if ( $hits === [] ) return [];
        $allowed = AuthorizationService::canViewPlayers( $user_id, array_map( static fn( array $h ): int => (int) $h['id'], $hits ) );
        $team    = self::teammateTeam( $user_id );
        $kept    = [];
        $mates   = [];
        foreach ( $hits as $hit ) {
            if ( ! empty( $allowed[ (int) $hit['id'] ] ) ) {
                $kept[] = $hit;
            } elseif ( $team > 0 && (int) ( $hit['team_id'] ?? 0 ) === $team && ( $hit['tt_row']['status'] ?? '' ) === 'active' ) {
                // The roster's own rule: an active member of the viewer's team.
                // Archived and binned rows never reach here (`baseWhere()`).
                $mates[] = $this->asTeammate( $hit );
            }
        }
        $kept = $this->withPhotos( $this->withActions( $user_id, $kept ) );
        if ( $mates === [] ) return $kept;

        // Back in the ranked order the hits came in.
        $order = array_flip( array_map( static fn( array $h ): int => (int) $h['id'], $hits ) );
        $all   = array_merge( $kept, $mates );
        usort( $all, static fn( array $a, array $b ): int => $order[ (int) $a['id'] ] <=> $order[ (int) $b['id'] ] );
        return $all;
    }

    /**
     * A teammate as a player sees one: the name and a link to the teammate
     * card. No team, no photo, no shirt number or position, no actions.
     *
     * @param array<string,mixed> $hit
     * @return array<string,mixed>
     */
    private function asTeammate( array $hit ): array {
        $hit['sublabel'] = '';
        $hit['url']      = (string) add_query_arg( [ 'tt_view' => 'teammate', 'player_id' => (int) $hit['id'] ], $this->dashboard() ); /* tt-xview-ok */
        $hit['actions']  = [];
        unset( $hit['tt_row'], $hit['photo_url'], $hit['team_id'], $hit['shirt_number'], $hit['position'] );
        return $hit;
    }

    /**
     * The photo through the private media store (`PlayerPhoto`), the media
     * rows of the whole list read in one statement.
     *
     * @param list<array<string,mixed>> $hits
     * @return list<array<string,mixed>>
     */
    private function withPhotos( array $hits ): array {
        $players = [];
        foreach ( $hits as $hit ) {
            $row = (array) ( $hit['tt_row'] ?? [] );
            $players[ (int) $hit['id'] ] = (object) [
                'photo_media_id' => (int) ( $row['photo_media_id'] ?? 0 ),
                'photo_url'      => (string) ( $row['photo_url'] ?? '' ),
            ];
        }
        $urls = $players === [] ? [] : PlayerPhoto::urls( $players );
        foreach ( $hits as $i => $hit ) {
            unset( $hits[ $i ]['tt_row'] );
            $url = $urls[ (int) $hit['id'] ] ?? '';
            if ( $url !== '' ) $hits[ $i ]['photo_url'] = $url;
        }
        return $hits;
    }

    /**
     * @param list<array<string,mixed>> $hits
     * @return list<array<string,mixed>>
     */
    private function withActions( int $user_id, array $hits ): array {
        // #4673 — a parent or a player gets no quick actions.
        if ( $hits === [] || SearchViewer::isFamily( $user_id ) ) return $hits;
        $gate        = \TT\Modules\Authorization\MatrixGate::class;
        $academywide = $gate::can( $user_id, 'evaluations', $gate::CHANGE, $gate::SCOPE_GLOBAL );
        $ids         = array_map( static fn( array $h ): int => (int) $h['id'], $hits );
        $reports     = class_exists( '\\TT\\Modules\\Analytics\\Reports\\PlayerReportAccess' )
            ? \TT\Modules\Analytics\Reports\PlayerReportAccess::canReadMany( $user_id, $ids )['read']
            : [];

        // A new evaluation and the goals are offered on the checks the target
        // screens ask: the wizard's capability and the evaluation rule for
        // the player, and the goals tab's section read.
        $evaluate = [];
        $goals    = [];
        if ( ! $academywide && AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_evaluations' ) ) {
            $team_by_player = [];
            foreach ( $hits as $hit ) $team_by_player[ (int) $hit['id'] ] = (int) ( $hit['team_id'] ?? 0 );
            $evaluate = array_filter( AuthorizationService::canEvaluatePlayers( $user_id, $team_by_player ) );
            $readers  = [];
            foreach ( $hits as $hit ) {
                $id = (int) $hit['id'];
                if ( ! isset( $evaluate[ $id ] ) ) continue;
                $readers[ $id ] = [ 'team_id' => (int) ( $hit['team_id'] ?? 0 ), 'wp_user_id' => (int) ( $hit['tt_row']['wp_user_id'] ?? 0 ) ];
            }
            $goals = $readers === [] ? [] : AuthorizationService::mayReadPlayerSections( $user_id, $readers, 'goals' );
        }

        $wizard = null;
        foreach ( $hits as $i => $hit ) {
            $id      = (int) $hit['id'];
            $profile = [ 'key' => 'profile', 'label' => __( 'Profile', 'talenttrack' ), 'url' => (string) $hit['url'] ];
            $report  = ! empty( $reports[ $id ] )
                ? [ 'key' => 'report', 'label' => __( 'Player report', 'talenttrack' ), 'url' => (string) add_query_arg( [ 'tt_view' => 'standard-report', 'slug' => 'player-report', 'player_id' => $id ], $this->dashboard() ) /* tt-xview-ok */ ]
                : null;

            if ( $academywide ) {
                $actions = array_filter( [ $profile, $report ] );
            } else {
                if ( isset( $evaluate[ $id ] ) ) {
                    // The entry point is resolved once; each player only adds its id.
                    $wizard ??= \TT\Shared\Wizards\WizardEntryPoint::urlFor( 'new-evaluation', (string) add_query_arg( 'tt_view', 'evaluations', $this->dashboard() ), [ 'mode' => 'player' ] );
                    $actions = [
                        [ 'key' => 'new_evaluation', 'label' => __( 'New evaluation', 'talenttrack' ), 'url' => (string) add_query_arg( 'player_id', $id, $wizard ) ],
                        ! empty( $goals[ $id ] )
                            ? [ 'key' => 'goals', 'label' => __( 'Goals', 'talenttrack' ), 'url' => (string) add_query_arg( 'tab', 'goals', (string) $hit['url'] ) ]
                            : $profile,
                    ];
                } else {
                    $actions = array_filter( [ $profile, $report ] );
                }
            }
            $hits[ $i ]['actions'] = array_slice( $actions, 0, 2 );
        }
        return $hits;
    }
}
