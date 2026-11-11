<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\Repositories\ActivitiesRepository;

/**
 * TeamMatchRecord (#4590) — a team's won / drawn / lost and goals, the one
 * read behind the monthly report's match section and the team statistics tab.
 *
 * Both read fixtures through `ActivitiesRepository::matchesInWindowForTeam()`
 * (game and legacy match, tournaments excluded, cancelled and archived left
 * out) and count them here. A fixture without a score is never a goalless
 * draw: it is counted in `without_score` and in nothing else.
 *
 * #4957 — "Played" is the one rule (`TT\Infrastructure\Query\PlayedMatch`),
 * read per fixture as `played`. Everything the record counts — played, the
 * results, the goals and `without_score` — is counted over the played
 * fixtures only, so a fixture still to come is never a match missing its
 * result. `fixtures` counts every live fixture in the window.
 *
 * @phpstan-type Fixture array{activity_id:int, date:string, title:string, opponent:string, home_away:string, team_score:int|null, opp_score:int|null, outcome:string, played:bool}
 * @phpstan-type Tally array{fixtures:int, played:int, scored:int, won:int, drawn:int, lost:int, goals_for:int, goals_against:int, goal_difference:int, clean_sheets:int, without_score:int}
 */
final class TeamMatchRecord {

    /**
     * Every live fixture of the team in a window, each with `played`.
     *
     * @return list<Fixture>
     */
    public static function fixtures( int $team_id, string $from, string $to ): array {
        return ( new ActivitiesRepository() )->matchesInWindowForTeam( $team_id, $from, $to );
    }

    /**
     * The played fixtures of a list, in their order: the matches a section
     * lists under its record.
     *
     * @param list<Fixture> $fixtures
     * @return list<Fixture>
     */
    public static function played( array $fixtures ): array {
        return array_values( array_filter( $fixtures, static fn( array $f ): bool => $f['played'] ) );
    }

    /**
     * Why the record leaves tournaments out, in words, or '' when none fell
     * in the period. The statistics tab and the Report Studio's match blocks
     * both say it this way (#4572).
     */
    public static function tournamentsNote( int $tournaments ): string {
        if ( $tournaments <= 0 ) return '';
        return sprintf(
            /* translators: %d: number of tournaments in the period */
            _n(
                '%d tournament this period is not included — a tournament is a multi-game day.',
                '%d tournaments this period are not included — a tournament is a multi-game day.',
                $tournaments,
                'talenttrack'
            ),
            $tournaments
        );
    }

    /**
     * The record over the fixtures given.
     *
     * @param list<array{outcome:string, team_score:int|null, opp_score:int|null, played:bool}> $fixtures
     * @return Tally
     */
    public static function tally( array $fixtures ): array {
        $out = [
            'fixtures'        => 0,
            'played'          => 0,
            'scored'          => 0,
            'won'             => 0,
            'drawn'           => 0,
            'lost'            => 0,
            'goals_for'       => 0,
            'goals_against'   => 0,
            'goal_difference' => 0,
            'clean_sheets'    => 0,
            'without_score'   => 0,
        ];

        foreach ( $fixtures as $f ) {
            $out['fixtures']++;
            if ( ! $f['played'] ) continue;
            $out['played']++;
            if ( $f['outcome'] === '' ) {
                $out['without_score']++;
                continue;
            }
            $out['scored']++;
            if ( $f['outcome'] === 'W' ) $out['won']++;
            if ( $f['outcome'] === 'D' ) $out['drawn']++;
            if ( $f['outcome'] === 'L' ) $out['lost']++;
            $out['goals_for']     += (int) $f['team_score'];
            $out['goals_against'] += (int) $f['opp_score'];
            if ( (int) $f['opp_score'] === 0 ) $out['clean_sheets']++;
        }
        $out['goal_difference'] = $out['goals_for'] - $out['goals_against'];

        return $out;
    }
}
