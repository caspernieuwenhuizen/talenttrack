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
 * The two surfaces word "played" differently, and both are kept: the report
 * counts every fixture in the window, the tab only the scored ones. Both are
 * in the tally, so neither is computed twice.
 *
 * @phpstan-type Fixture array{activity_id:int, date:string, title:string, opponent:string, home_away:string, team_score:int|null, opp_score:int|null, outcome:string}
 * @phpstan-type Tally array{fixtures:int, scored:int, won:int, drawn:int, lost:int, goals_for:int, goals_against:int, goal_difference:int, clean_sheets:int, without_score:int}
 */
final class TeamMatchRecord {

    /**
     * The team's fixtures in a window. `$played_only` leaves out the ones
     * dated after today.
     *
     * @return list<Fixture>
     */
    public static function fixtures( int $team_id, string $from, string $to, bool $played_only = false ): array {
        return ( new ActivitiesRepository() )->matchesInWindowForTeam( $team_id, $from, $to, $played_only );
    }

    /**
     * The record over the fixtures given.
     *
     * @param list<array{outcome:string, team_score:int|null, opp_score:int|null}> $fixtures
     * @return Tally
     */
    public static function tally( array $fixtures ): array {
        $out = [
            'fixtures'        => 0,
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
