<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DemoRoster — which team each player was in, season by season.
 *
 * The academy's age-group ladder is a conveyor: a squad that is U13 this
 * season was U12 last season and U11 the season before. That is what
 * `tt_player_team_history` records, and until #3402 it was the only place
 * it was recorded — the trainings, evaluations and test sessions of a past
 * season all hung off the player's *current* team, so a player's profile
 * said U12 last season while last season's work belonged to U13.
 *
 * One plan, derived once, answers both: the spells written to the history
 * table and the roster every dated generator writes against. It is pure —
 * no RNG — because the generators that consult it run in different steps
 * of a chunked run, each with its own seeded stream, and a plan that came
 * out differently per step would put the two halves back into conflict.
 */
final class DemoRoster {

    /** Archetype marking a player who left the academy before the current season. */
    public const ARCHETYPE_DEPARTED = 'departed';

    /** Archetype marking a player who joined partway through the window. */
    public const ARCHETYPE_NEW_ARRIVAL = 'new_arrival';

    private DemoCalendar $calendar;

    /** @var list<string> age groups, youngest first */
    private array $ladder;

    /** @var array<string,int> age group => the team that represents that rung */
    private array $team_by_age_group = [];

    /** @var array<int,object> */
    private array $teams_by_id = [];

    /** @var array<int, array<int,int>> player id => season index => team id */
    private array $team_by_player_season = [];

    /** @var array<int, array<int, list<object>>> season index => team id => players */
    private array $roster_by_season_team = [];

    /** @var array<int, list<array{team_id:int, season_index:int, joined_at:string, left_at:?string}>> */
    private array $spells = [];

    /**
     * @param object[] $teams   rows carrying id + age_group
     * @param object[] $players rows carrying id, team_id, archetype, date_joined
     */
    public function __construct( DemoCalendar $calendar, array $teams, array $players ) {
        $this->calendar = $calendar;
        $this->ladder   = self::ladderFor( $teams );

        foreach ( $teams as $team ) {
            $id = (int) ( $team->id ?? 0 );
            if ( $id <= 0 ) continue;
            $this->teams_by_id[ $id ] = $team;

            $age_group = isset( $team->age_group ) ? trim( (string) $team->age_group ) : '';
            if ( $age_group !== '' && ! isset( $this->team_by_age_group[ $age_group ] ) ) {
                $this->team_by_age_group[ $age_group ] = $id;
            }
        }

        $seasons = $this->calendar->seasons();
        foreach ( $players as $player ) {
            $this->plan( $player, $seasons );
        }
    }

    /**
     * The academy's age-group ladder, youngest rung first, in whatever
     * notation the academy uses — `U14`, `JO14`, `O14` and `14` all read as
     * 14, and a rung with no age in its name (`Senior`) sorts last (#3404).
     *
     * @param object[] $teams
     * @return list<string>
     */
    public static function ladderFor( array $teams ): array {
        $rungs = [];
        foreach ( $teams as $team ) {
            $age_group = isset( $team->age_group ) ? trim( (string) $team->age_group ) : '';
            if ( $age_group === '' ) continue;
            $rungs[ $age_group ] = self::rungAge( $age_group );
        }
        asort( $rungs, SORT_NUMERIC );

        return array_keys( $rungs );
    }

    /**
     * The age in an age-group label. Anything without a number sorts last,
     * where a `Senior` catch-all belongs on a ladder.
     */
    public static function rungAge( string $age_group ): int {
        return preg_match( '/(\d+)/', $age_group, $m ) ? (int) $m[1] : PHP_INT_MAX;
    }

    /** @return list<string> */
    public function ladder(): array {
        return $this->ladder;
    }

    /**
     * The spells to write to `tt_player_team_history` for one player,
     * oldest first. The last one of a player still at the academy is
     * open-ended.
     *
     * @return list<array{team_id:int, season_index:int, joined_at:string, left_at:?string}>
     */
    public function spellsFor( int $player_id ): array {
        return $this->spells[ $player_id ] ?? [];
    }

    /** The team a player was in on a given date, or 0 when they were not at the academy. */
    public function teamForPlayerOn( int $player_id, string $date ): int {
        return $this->teamForPlayerInSeason( $player_id, $this->calendar->seasonIndexForDate( $date ) );
    }

    /** The team a player was in for a whole season, or 0 when they were not at the academy. */
    public function teamForPlayerInSeason( int $player_id, int $season_index ): int {
        return (int) ( $this->team_by_player_season[ $player_id ][ $season_index ] ?? 0 );
    }

    /**
     * The players a team had on a given date.
     *
     * @return list<object>
     */
    public function rosterFor( int $team_id, string $date ): array {
        $season = $this->calendar->seasonIndexForDate( $date );
        return $this->roster_by_season_team[ $season ][ $team_id ] ?? [];
    }

    /**
     * Season indices in which this team fielded anyone. A season the team
     * did not exist for yet is one it gets no trainings and no test
     * sessions in, rather than a calendar full of sessions nobody attended.
     *
     * @return list<int>
     */
    public function seasonsWithRoster( int $team_id ): array {
        $out = [];
        foreach ( $this->calendar->seasons() as $season ) {
            if ( ! empty( $this->roster_by_season_team[ $season['index'] ][ $team_id ] ) ) {
                $out[] = (int) $season['index'];
            }
        }
        return $out;
    }

    /** Does this team field anyone on this date? */
    public function teamIsActiveOn( int $team_id, string $date ): bool {
        return $this->rosterFor( $team_id, $date ) !== [];
    }

    /**
     * Work out one player's season-by-season spells and index them both ways.
     *
     * @param array<int, array{index:int, name:string, start_date:string, end_date:string, is_current:bool}> $seasons
     */
    private function plan( object $player, array $seasons ): void {
        $player_id = (int) ( $player->id ?? 0 );
        $team_id   = (int) ( $player->team_id ?? 0 );
        if ( $player_id <= 0 || $team_id <= 0 || $seasons === [] ) return;

        $count     = count( $seasons );
        $archetype = (string) ( $player->archetype ?? '' );

        // A player who has left stops before the current season; everyone
        // else runs to it. Departures are spread across the window's earlier
        // seasons rather than all falling in the same summer — an academy
        // loses a few players every year, and a single mass exit is as false
        // as a roster that never moves at all.
        $last = $count - 1;
        if ( $archetype === self::ARCHETYPE_DEPARTED ) {
            $last = $count - 2 - ( $count > 2 ? $player_id % ( $count - 1 ) : 0 );
        }
        if ( $last < 0 ) return;

        $team  = $this->teams_by_id[ $team_id ] ?? null;
        $group = $team && isset( $team->age_group ) ? trim( (string) $team->age_group ) : '';
        $rung  = $group !== '' ? array_search( $group, $this->ladder, true ) : false;

        // No rung means no conveyor to ride: the player stays in the team
        // they are in for every season the window covers.
        $first = $rung === false ? 0 : max( 0, $last - (int) $rung );

        // #3402 — the squad has to move between seasons or the academy reads
        // as false. A new arrival joins at the start of the current season,
        // or the one before it, rather than all of them arriving together.
        if ( $archetype === self::ARCHETYPE_NEW_ARRIVAL ) {
            $first = max( $first, $last - ( $player_id % 2 ) );
        }

        $planned = [];
        for ( $s = $first; $s <= $last; $s++ ) {
            $spell_team = $team_id;
            if ( $rung !== false ) {
                $spell_rung = (int) $rung - ( $last - $s );
                if ( $spell_rung < 0 ) continue;
                $spell_team = $spell_rung === (int) $rung
                    ? $team_id
                    : (int) ( $this->team_by_age_group[ $this->ladder[ $spell_rung ] ?? '' ] ?? 0 );
            }
            if ( $spell_team <= 0 ) continue;

            $planned[ $s ] = $spell_team;
        }
        if ( $planned === [] ) return;

        $this->team_by_player_season[ $player_id ] = $planned;
        foreach ( $planned as $season_index => $spell_team ) {
            $this->roster_by_season_team[ $season_index ][ $spell_team ][] = $player;
        }

        $this->spells[ $player_id ] = $this->toSpells( $player, $planned, $seasons, $last, $count );
    }

    /**
     * Collapse the per-season plan into history rows, merging consecutive
     * seasons in the same team into one spell.
     *
     * @param array<int,int> $planned season index => team id
     * @param array<int, array{index:int, start_date:string, end_date:string}> $seasons
     * @return list<array{team_id:int, season_index:int, joined_at:string, left_at:?string}>
     */
    private function toSpells( object $player, array $planned, array $seasons, int $last, int $count ): array {
        $first_index = (int) array_key_first( $planned );

        // Someone who was already at the academy when the window opens keeps
        // the join date on their record, so the history does not claim they
        // arrived on the first day the generator happens to cover. The window
        // itself counts too: a run whose history starts in July is generating
        // sessions before the season it belongs to opens.
        $opens = (string) $seasons[ $first_index ]['start_date'];
        if ( $first_index === 0 ) {
            $joined = (string) ( $player->date_joined ?? '' );
            if ( $joined !== '' && $joined < $opens ) $opens = $joined;

            $window = gmdate( 'Y-m-d', $this->calendar->windowStart() );
            if ( $window < $opens ) $opens = $window;
        }

        $spells = [];
        foreach ( $planned as $season_index => $team_id ) {
            $starts = $season_index === $first_index ? $opens : (string) $seasons[ $season_index ]['start_date'];
            $ends   = ( $season_index === $count - 1 && $last === $count - 1 )
                ? null
                : $this->closesAt( $seasons, (int) $season_index );

            $previous = $spells === [] ? null : $spells[ count( $spells ) - 1 ];
            if ( $previous !== null && $previous['team_id'] === $team_id ) {
                $spells[ count( $spells ) - 1 ]['left_at'] = $ends;
                continue;
            }

            $spells[] = [
                'team_id'      => $team_id,
                'season_index' => (int) $season_index,
                'joined_at'    => $starts,
                'left_at'      => $ends,
            ];
        }
        return $spells;
    }

    /**
     * The day a season's spell closes: the day before the next season opens,
     * so the chain has no gap in it.
     *
     * A season runs to the end of June and the next opens in August. Ending
     * a spell on 30 June leaves July belonging to no squad at all — and the
     * generators do write sessions in July, which is how a month of
     * attendance ended up contradicting the history it was supposed to
     * agree with.
     *
     * @param array<int, array{start_date:string, end_date:string}> $seasons
     */
    private function closesAt( array $seasons, int $season_index ): string {
        $next = $seasons[ $season_index + 1 ] ?? null;
        if ( $next === null ) {
            return (string) $seasons[ $season_index ]['end_date'];
        }

        $opens = (int) strtotime( (string) $next['start_date'] . ' 00:00:00 UTC' );
        return $opens > 0
            ? gmdate( 'Y-m-d', $opens - DAY_IN_SECONDS )
            : (string) $seasons[ $season_index ]['end_date'];
    }
}
