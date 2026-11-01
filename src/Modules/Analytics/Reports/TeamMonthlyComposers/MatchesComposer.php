<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Analytics\Reports\GoalContributionQuery;
use TT\Modules\Analytics\Reports\MatchesBlockOptions;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Analytics\Reports\PlayerOrder;
use TT\Modules\Analytics\Reports\TeamMatchRecord;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;

/**
 * Results and match statistics (#3516, #4590).
 *
 * The report said a great deal about development and nothing about results,
 * so the score got read off somebody's phone. The data was already recorded;
 * nothing here writes anything new.
 *
 * Three parts, each switchable — see `MatchesBlockOptions`. There is no
 * separate results list: the per-match rows carry date, opponent, home/away
 * and score, so a second table would repeat them.
 */
final class MatchesComposer {

    /** @return array<string,mixed> */
    public static function compose( TeamReportContext $ctx ): array {
        $options    = $ctx->optionsFor( TeamMonthlyReportBlock::MATCHES );
        $activities = $ctx->fixtures();

        $out = [
            // Tournaments are multi-game days (#2686): one score line cannot
            // describe one, so they are left out of everything below. The
            // count is carried so the section can *say* so — a record that
            // quietly disagrees with what the coach remembers is worse than
            // one that explains itself.
            'tournaments_excluded' => ( new ActivitiesRepository() )->tournamentCountInWindow( $ctx->team_id, $ctx->from, $ctx->to ),
            // #4133 — the Summary / Details choice; the parts below follow it.
            'level'                => MatchesBlockOptions::level( $options ),
            'shows'                => [
                'record'  => MatchesBlockOptions::shows( $options, MatchesBlockOptions::RECORD ),
                'scorers' => MatchesBlockOptions::shows( $options, MatchesBlockOptions::SCORERS ),
                'squads'  => MatchesBlockOptions::shows( $options, MatchesBlockOptions::SQUADS ),
            ],
            'record'   => self::record( $activities ),
            'fixtures'      => [],
            'scorers'       => [],
            'scorer_totals' => null,
        ];

        $jerseys = PlayerOrder::jerseys( $ctx->players() );
        $names   = [];
        foreach ( $ctx->players() as $player ) {
            $names[ (int) ( $player->id ?? 0 ) ] = trim( (string) ( $player->first_name ?? '' ) . ' ' . (string) ( $player->last_name ?? '' ) );
        }

        if ( $out['shows']['scorers'] ) {
            $contributions = ( new GoalContributionQuery() )->forTeam( $ctx->team_id, [ 'from' => $ctx->from, 'to' => $ctx->to ] );
            $rows = [];
            foreach ( $contributions as $player_id => $c ) {
                $player_id = (int) $player_id;
                $goals     = (int) $c['goals'];
                $assists   = (int) $c['assists'];
                if ( $goals === 0 && $assists === 0 ) continue;

                $rows[] = [
                    'player_id' => $player_id,
                    'name'      => $names[ $player_id ] ?? '',
                    'goals'     => $goals,
                    'assists'   => $assists,
                ];
            }
            // #4079 — the record counts only scored, non-tournament fixtures,
            // so the attributed count is taken over exactly those. The table
            // above keeps tournament goals: it is a leaderboard.
            $scored = [];
            foreach ( $activities as $a ) {
                if ( $a['outcome'] !== '' ) $scored[] = $a['activity_id'];
            }
            $out['scorers']       = self::rankScorers( PlayerOrder::sort( $rows, $jerseys ) );
            $out['scorer_totals'] = [
                'goals'            => array_sum( array_column( $rows, 'goals' ) ),
                'assists'          => array_sum( array_column( $rows, 'assists' ) ),
                // Reconciled against the record, like the minutes grid footer:
                // "7 of 8 goals attributed" says a scorer was not entered.
                'attributed_goals' => ( new GoalContributionQuery() )->attributedGoalsIn( $ctx->team_id, $scored ),
                'goals_for'        => $out['record']['goals_for'],
            ];
        }

        foreach ( $activities as $a ) {
            $fixture = $a + [ 'squad' => [] ];

            if ( $out['shows']['squads'] ) {
                $minutes = MinutesQuery::squadForActivity( $a['activity_id'] );
                $squad   = [];
                foreach ( $minutes as $player_id => $played ) {
                    $squad[] = [
                        'player_id' => (int) $player_id,
                        'name'      => $names[ (int) $player_id ] ?? '',
                        'minutes'   => (int) $played,
                    ];
                }
                $fixture['squad'] = PlayerOrder::sort( $squad, $jerseys );
            }

            $out['fixtures'][] = $fixture;
        }

        return $out;
    }

    /**
     * The report's record over the fixtures given ({@see TeamMatchRecord}).
     *
     * **A match with no score recorded counts as played and nothing else.** It
     * happens constantly — the match was played, nobody typed the result — and
     * treating it as a goalless draw would make the record quietly wrong,
     * which is worse than an obvious gap. It is counted separately so the
     * section can show the gap.
     *
     * @param list<array{outcome:string, team_score:int|null, opp_score:int|null}> $activities
     * @return array<string,int>
     */
    public static function record( array $activities ): array {
        $tally = TeamMatchRecord::tally( $activities );

        return [
            'played'          => $tally['fixtures'],
            'won'             => $tally['won'],
            'drawn'           => $tally['drawn'],
            'lost'            => $tally['lost'],
            'goals_for'       => $tally['goals_for'],
            'goals_against'   => $tally['goals_against'],
            'goal_difference' => $tally['goal_difference'],
            'without_score'   => $tally['without_score'],
        ];
    }

    /**
     * Scorers ranked by goals, then assists, then shirt order (#4069) — the
     * order a coach reads a scorers table in, on the page, the PDF and REST
     * alike. It replaces the shirt order #3518 gave this list. The rank is
     * shared on a tie and left out for a player without a goal, who is on
     * the list for assists only.
     *
     * @param list<array<string,mixed>> $rows in shirt order
     * @return list<array<string,mixed>>
     */
    private static function rankScorers( array $rows ): array {
        $sorted = TeamReportFigures::stableSort(
            $rows,
            static fn( array $a, array $b ): int => [ (int) $b['goals'], (int) $b['assists'] ] <=> [ (int) $a['goals'], (int) $a['assists'] ]
        );

        $out  = [];
        $rank = 0;
        $last = null;
        foreach ( $sorted as $i => $row ) {
            $key = [ (int) $row['goals'], (int) $row['assists'] ];
            if ( $key !== $last ) $rank = $i + 1;
            $last = $key;

            $row['rank'] = (int) $row['goals'] > 0 ? $rank : null;
            $out[]       = $row;
        }
        return $out;
    }
}
