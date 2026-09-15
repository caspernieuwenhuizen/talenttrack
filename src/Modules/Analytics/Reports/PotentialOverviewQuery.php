<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\AllTeamsScope;
use TT\Modules\Players\PlayerStatusModule;
use TT\Modules\Players\Services\PotentialTrajectory;

/**
 * PotentialOverviewQuery (#3412) — every player in a scope with their
 * current potential band.
 *
 * #3385 asked whether a head of development could see an age group's
 * potential, sorted. The answer was no, and not approximately: the band
 * appeared nowhere that showed more than one player, and the one
 * cross-player surface that reads potential — the traffic-light dot —
 * folds it into a 40/25/20/15 composite, so it can be neither sorted nor
 * filtered by.
 *
 * This is the read model behind the answer. The REST controller and the
 * rendered report both call it, so a non-WordPress front end gets the same
 * filtered set for the same caller (CLAUDE.md §4). Nothing here renders;
 * nothing in the view decides.
 *
 * ## Scope
 *
 * Two axes, selectable, because "the U15s" means a squad to one academy
 * and a birth-year cohort to another:
 *
 *   - `team`      — one `tt_teams` row, the operational unit.
 *   - `age_group` — every team carrying the same `tt_teams.age_group`
 *                   label ("U15"), which is what an academy means when an
 *                   age group spans two or three squads.
 *
 * Both resolve to a team set, and the team set is filtered to the teams
 * the caller may read before a single player row is returned — so the
 * scope selector can never widen access.
 *
 * ## Players with nothing recorded are rows, not absences
 *
 * 39 of 64 active players on the demo install have no potential row. A
 * list that silently omitted them would tell a head of development the
 * opposite of the truth, and it is precisely those players the screen
 * exists to find. They come back with `band = ''` and `recorded = false`.
 *
 * Under the #3265 age floor the academy is not *asked* for a band below
 * 13, so those players carry `eligible = false` and are not counted as
 * gaps — a U12 with no band is not a coverage failure.
 *
 * @phpstan-type PotentialRow array{
 *     player_id:int, player_name:string, team_id:int, team_name:string,
 *     age_group:string, date_of_birth:string, eligible:bool,
 *     recorded:bool, band:string, band_label:string, band_rank:int,
 *     set_at:string, set_by:int, set_by_name:string, notes:string,
 *     direction:string, previous_band:string, previous_band_label:string,
 *     revisions:int
 * }
 */
final class PotentialOverviewQuery {

    public const SCOPE_TEAM      = 'team';
    public const SCOPE_AGE_GROUP = 'age_group';

    /** Filter value for "no band recorded" — a real answer, not the absence of one. */
    public const BAND_NONE = 'none';

    /**
     * Rank given to an unrecorded band so band-sorting puts those rows
     * last in both directions rather than mixing them into the middle.
     */
    private const RANK_NONE = 99;

    /** @var array<string,string> sort key => what it orders by */
    public const SORTS = [
        'band' => 'band',
        'name' => 'name',
        'date' => 'date',
        'team' => 'team',
    ];

    /**
     * Teams the caller may read, in the chosen scope.
     *
     * Returned as rows rather than ids because the report needs the names
     * and the age-group labels anyway, and a second query for them would
     * be a second chance to disagree about scope.
     *
     * @return list<array{team_id:int,team_name:string,age_group:string}>
     */
    public function teamsInScope( int $user_id, string $scope, int $team_id, string $age_group ): array {
        $all = $this->readableTeams( $user_id );

        if ( $scope === self::SCOPE_TEAM ) {
            return array_values( array_filter(
                $all,
                static fn( array $t ): bool => $t['team_id'] === $team_id
            ) );
        }

        if ( $age_group === '' ) return [];

        return array_values( array_filter(
            $all,
            static fn( array $t ): bool => $t['age_group'] === $age_group
        ) );
    }

    /**
     * Every team the caller may read, club-scoped.
     *
     * Academy-wide roles get all of them; everyone else gets the teams
     * they are attached to. `player_status` is the entity because that is
     * what this data is — not `teams`, which a persona may hold more or
     * less of than its players' judgements.
     *
     * @return list<array{team_id:int,team_name:string,age_group:string}>
     */
    public function readableTeams( int $user_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id, t.name, t.age_group
               FROM {$wpdb->prefix}tt_teams t
              WHERE t.club_id = %d
                AND " . ArchiveRepository::filterClause( 'active', 't' ) . "
              ORDER BY t.age_group ASC, t.name ASC",
            CurrentClub::id()
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $tid = (int) ( $row->id ?? 0 );
            if ( $tid <= 0 ) continue;
            if ( ! AllTeamsScope::canReadTeamFor( $user_id, $tid, 'player_status' ) ) continue;
            $out[] = [
                'team_id'   => $tid,
                'team_name' => (string) ( $row->name ?? '' ),
                'age_group' => (string) ( $row->age_group ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * The age-group labels the caller can actually choose between.
     *
     * Derived from the teams they may read rather than from the lookup
     * vocabulary, so the selector never offers a cohort that would come
     * back empty for permission reasons — which reads as missing data.
     *
     * @return list<string>
     */
    public function ageGroupsFor( int $user_id ): array {
        $labels = [];
        foreach ( $this->readableTeams( $user_id ) as $team ) {
            if ( $team['age_group'] === '' ) continue;
            $labels[ $team['age_group'] ] = true;
        }
        $out = array_keys( $labels );
        sort( $out );
        return array_values( array_map( 'strval', $out ) );
    }

    /**
     * The report rows.
     *
     * @param list<string> $bands  band codes to keep, plus `none` for the
     *                             unrecorded; empty keeps everything.
     * @return list<PotentialRow>
     */
    public function rows( int $user_id, string $scope, int $team_id, string $age_group, array $bands = [], string $sort = 'band', string $dir = 'asc' ): array {
        $teams = $this->teamsInScope( $user_id, $scope, $team_id, $age_group );
        if ( $teams === [] ) return [];

        /** @var array<int,array{team_id:int,team_name:string,age_group:string}> $by_id */
        $by_id = [];
        foreach ( $teams as $team ) $by_id[ $team['team_id'] ] = $team;

        $players = $this->playersOnTeams( array_keys( $by_id ) );
        if ( $players === [] ) return [];

        $history = $this->historyForPlayers( array_map(
            static fn( array $p ): int => $p['player_id'],
            $players
        ) );

        $rows = [];
        foreach ( $players as $player ) {
            $team   = $by_id[ $player['team_id'] ] ?? [ 'team_name' => '', 'age_group' => '' ];
            $series = PotentialTrajectory::seriesFrom( $history[ $player['player_id'] ] ?? [] );
            $rows[] = $this->composeRow( $player, (string) $team['team_name'], (string) $team['age_group'], $series );
        }

        $rows = $this->applyBandFilter( $rows, $bands );
        return $this->sortRows( $rows, $sort, $dir );
    }

    /**
     * One row, from a player and their decorated potential series.
     *
     * @param array{player_id:int,player_name:string,team_id:int,date_of_birth:string} $player
     * @param list<array<string,mixed>> $series oldest-first, as PotentialTrajectory decorates it
     * @return PotentialRow
     */
    private function composeRow( array $player, string $team_name, string $age_group, array $series ): array {
        $count    = count( $series );
        $current  = $count > 0 ? $series[ $count - 1 ] : null;
        $previous = $count > 1 ? $series[ $count - 2 ] : null;

        $band       = $current !== null ? (string) $current['band'] : '';
        $rank       = $band !== '' ? PotentialTrajectory::rank( $band ) : null;
        $prev_band  = $previous !== null ? (string) $previous['band'] : '';

        return [
            'player_id'           => $player['player_id'],
            'player_name'         => $player['player_name'],
            'team_id'             => $player['team_id'],
            'team_name'           => $team_name,
            'age_group'           => $age_group,
            'date_of_birth'       => $player['date_of_birth'],
            'eligible'            => PlayerStatusModule::potentialAppliesAtBirthdate(
                $player['date_of_birth'] !== '' ? $player['date_of_birth'] : null
            ),
            'recorded'            => $current !== null,
            'band'                => $band,
            'band_label'          => $band !== '' ? PotentialTrajectory::labelFor( $band ) : '',
            'band_rank'           => $rank ?? self::RANK_NONE,
            'set_at'              => $current !== null ? (string) $current['set_at'] : '',
            'set_by'              => $current !== null ? (int) $current['set_by'] : 0,
            'set_by_name'         => $current !== null ? (string) $current['set_by_name'] : '',
            'notes'               => $current !== null ? (string) $current['notes'] : '',
            'direction'           => $current !== null ? (string) $current['direction'] : '',
            'previous_band'       => $prev_band,
            'previous_band_label' => $prev_band !== '' ? PotentialTrajectory::labelFor( $prev_band ) : '',
            'revisions'           => $count,
        ];
    }

    /**
     * Active players on a team set, ordered by name.
     *
     * @param list<int> $team_ids
     * @return list<array{player_id:int,player_name:string,team_id:int,date_of_birth:string}>
     */
    private function playersOnTeams( array $team_ids ): array {
        global $wpdb;

        $ids = $this->idList( $team_ids );
        if ( $ids === '' ) return [];

        // FIND_IN_SET rather than a generated IN() list: `prepare()` wants
        // literal-string SQL, and a run of placeholders built in a loop is
        // not one. One bound parameter, one literal query.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id, p.first_name, p.last_name, p.team_id, p.date_of_birth
               FROM {$wpdb->prefix}tt_players p
              WHERE p.club_id = %d
                AND p.status = 'active'
                AND " . ArchiveRepository::filterClause( 'active', 'p' ) . "
                AND FIND_IN_SET( p.team_id, %s )
              ORDER BY p.last_name ASC, p.first_name ASC",
            CurrentClub::id(),
            $ids
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $pid = (int) ( $row->id ?? 0 );
            if ( $pid <= 0 ) continue;
            $name = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
            $out[] = [
                'player_id'     => $pid,
                'player_name'   => $name !== '' ? $name : '#' . $pid,
                'team_id'       => (int) ( $row->team_id ?? 0 ),
                'date_of_birth' => (string) ( $row->date_of_birth ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * The whole potential history for a player set, newest-first per
     * player — the shape `PotentialTrajectory::seriesFrom()` expects.
     *
     * One query for the cohort rather than one per player: a squad read
     * that called `forPlayer()` sixty times would issue sixty queries to
     * answer a question about one table.
     *
     * @param list<int> $player_ids
     * @return array<int,list<object>>
     */
    private function historyForPlayers( array $player_ids ): array {
        global $wpdb;

        $ids = $this->idList( $player_ids );
        if ( $ids === '' ) return [];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pp.id, pp.player_id, pp.potential_band, pp.set_at, pp.set_by, pp.notes
               FROM {$wpdb->prefix}tt_player_potential pp
              WHERE pp.club_id = %d
                AND FIND_IN_SET( pp.player_id, %s )
              ORDER BY pp.player_id ASC, pp.set_at DESC, pp.id DESC",
            CurrentClub::id(),
            $ids
        ) );

        /** @var array<int,list<object>> $out */
        $out = [];
        foreach ( (array) $rows as $row ) {
            $pid = (int) ( $row->player_id ?? 0 );
            if ( $pid <= 0 ) continue;
            $out[ $pid ][] = $row;
        }
        return $out;
    }

    /**
     * A comma-separated list of positive integers, safe to bind to
     * FIND_IN_SET as one parameter.
     *
     * @param list<int> $ids
     */
    private function idList( array $ids ): string {
        $clean = array_values( array_unique( array_filter(
            array_map( 'intval', $ids ),
            static fn( int $id ): bool => $id > 0
        ) ) );
        return implode( ',', array_map( 'strval', $clean ) );
    }

    /**
     * @param list<PotentialRow> $rows
     * @param list<string> $bands
     * @return list<PotentialRow>
     */
    private function applyBandFilter( array $rows, array $bands ): array {
        $wanted = self::sanitizeBands( $bands );
        if ( $wanted === [] ) return $rows;

        return array_values( array_filter(
            $rows,
            static function ( array $row ) use ( $wanted ): bool {
                $key = $row['recorded'] ? $row['band'] : self::BAND_NONE;
                return in_array( $key, $wanted, true );
            }
        ) );
    }

    /**
     * Drop anything that is not a band this vocabulary knows (plus the
     * `none` pseudo-band). A filter value that survived sanitisation but
     * matches nothing would silently empty the report.
     *
     * @param list<string> $bands
     * @return list<string>
     */
    public static function sanitizeBands( array $bands ): array {
        $allowed = array_merge( PotentialBand::ALL, [ self::BAND_NONE ] );
        return array_values( array_unique( array_filter(
            array_map( 'strval', $bands ),
            static fn( string $b ): bool => in_array( $b, $allowed, true )
        ) ) );
    }

    public static function sanitizeScope( string $scope ): string {
        return $scope === self::SCOPE_AGE_GROUP ? self::SCOPE_AGE_GROUP : self::SCOPE_TEAM;
    }

    public static function sanitizeSort( string $sort ): string {
        return isset( self::SORTS[ $sort ] ) ? $sort : 'band';
    }

    public static function sanitizeDir( string $dir ): string {
        return strtolower( $dir ) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Ordering happens in PHP, not SQL: the band's order is the
     * vocabulary's order, which lives in `PotentialBand::ALL` rather than
     * in the column, and sorting a `VARCHAR` of band codes alphabetically
     * would put `first_team` between `professional_elsewhere` and
     * `recreational`. A squad is tens of rows, not thousands.
     *
     * @param list<PotentialRow> $rows
     * @return list<PotentialRow>
     */
    private function sortRows( array $rows, string $sort, string $dir ): array {
        $sort = self::sanitizeSort( $sort );
        $desc = self::sanitizeDir( $dir ) === 'desc';

        usort( $rows, static function ( array $a, array $b ) use ( $sort ): int {
            switch ( $sort ) {
                case 'name':
                    return strcasecmp( $a['player_name'], $b['player_name'] );
                case 'date':
                    // Never recorded sorts last however the column is read:
                    // "oldest first" is a question about recorded dates.
                    if ( $a['set_at'] === '' || $b['set_at'] === '' ) {
                        return ( $a['set_at'] === '' ? 1 : 0 ) - ( $b['set_at'] === '' ? 1 : 0 );
                    }
                    return strcmp( $a['set_at'], $b['set_at'] );
                case 'team':
                    $t = strcasecmp( $a['team_name'], $b['team_name'] );
                    return $t !== 0 ? $t : strcasecmp( $a['player_name'], $b['player_name'] );
                case 'band':
                default:
                    if ( $a['band_rank'] !== $b['band_rank'] ) {
                        return $a['band_rank'] <=> $b['band_rank'];
                    }
                    return strcasecmp( $a['player_name'], $b['player_name'] );
            }
        } );

        // Reversing after the fact keeps unrecorded rows last in both
        // directions for the band column — flipping the comparator would
        // float the players nobody has assessed to the top of a
        // "lowest band first" read, which is not what that question means.
        if ( $desc ) {
            if ( $sort === 'band' ) {
                $recorded   = array_values( array_filter( $rows, static fn( array $r ): bool => $r['recorded'] ) );
                $unrecorded = array_values( array_filter( $rows, static fn( array $r ): bool => ! $r['recorded'] ) );
                $rows = array_merge( array_reverse( $recorded ), $unrecorded );
            } else {
                $rows = array_reverse( $rows );
            }
        }

        return array_values( $rows );
    }

    /**
     * Headline counts for the report's KPI strip.
     *
     * `missing` counts only players the academy is actually asked about —
     * the #3265 age floor means a U12 without a band is not a gap, and
     * counting them would report a coverage problem that is policy.
     *
     * @param list<PotentialRow> $rows
     * @return array{players:int,recorded:int,missing:int,not_asked:int,coverage:?float}
     */
    public static function summarise( array $rows ): array {
        $players   = count( $rows );
        $recorded  = 0;
        $missing   = 0;
        $not_asked = 0;

        foreach ( $rows as $row ) {
            if ( $row['recorded'] ) { $recorded++; continue; }
            if ( $row['eligible'] ) { $missing++;  continue; }
            $not_asked++;
        }

        $askable  = $recorded + $missing;
        $coverage = $askable > 0 ? round( $recorded / $askable * 100, 1 ) : null;

        return [
            'players'   => $players,
            'recorded'  => $recorded,
            'missing'   => $missing,
            'not_asked' => $not_asked,
            'coverage'  => $coverage,
        ];
    }
}
