<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\DemoEvaluationWriter;
use TT\Modules\DemoData\DemoRatingScale;
use TT\Modules\DemoData\DemoRoster;

/**
 * EvaluationGenerator — writes the **round** evaluations into
 * tt_evaluations + tt_eval_ratings.
 *
 * Four a season — start, two mid, end — dated a few days ahead of the PDP
 * conversation that reviews them, so `EvidencePacket::forConversation()`
 * has the round behind every talk. This replaced a flat two-per-week
 * stream, which gave a player 312 evaluations across a three-year window
 * and buried the development story it was meant to tell (#3401).
 *
 * **Match evaluations are not written here** (#3658). They used to be: for
 * each past fixture date this generator rolled a dice per player and wrote
 * a write-up with a random opponent and 45-90 random minutes, for players
 * who had never been in that lineup — because it runs at `run_order` 10,
 * long before `MatchDayGenerator` picks a side at 110. The demo academy
 * then told two contradicting stories about the same Saturday. They now
 * come off the match itself, in `MatchDayGenerator`, for the players it
 * actually fielded.
 *
 * The evaluation-plus-ratings write both generators share lives in
 * `DemoEvaluationWriter`, along with the archetype trajectories.
 */
class EvaluationGenerator implements DependentGeneratorInterface {

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    /** @var object[] */
    private array $teams;

    private int $weeks;

    private DemoCalendar $calendar;

    private DemoRoster $roster;

    public static function category(): string {
        return 'evaluations';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self(
            $ctx->registry,
            $ctx->historicPlayers(),
            $ctx->teams,
            $ctx->weeks(),
            $ctx->calendar(),
            $ctx->roster()
        );
    }

    /**
     * @param object[] $players generated players (with .id, .team_id, .archetype, .wp_user_id)
     * @param object[] $teams   generated teams (with .id, .head_coach_user_id)
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $players,
        array $teams,
        int $weeks,
        ?DemoCalendar $calendar = null,
        ?DemoRoster $roster = null
    ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->teams    = $teams;
        $this->weeks    = max( 1, $weeks );
        $this->calendar = $calendar ?? new DemoCalendar( $this->weeks );
        $this->roster   = $roster ?? new DemoRoster( $this->calendar, $teams, $players );
    }

    public function generate(): int {
        $writer = $this->writer();
        if ( ! $writer->categories() ) {
            throw new \RuntimeException( 'No main evaluation categories found — run the plugin\'s migrations first.' );
        }

        $eval_types = $writer->evalTypes();
        if ( ! $eval_types ) {
            throw new \RuntimeException( 'No evaluation types found — run the plugin\'s migrations first.' );
        }
        $training_id = $eval_types['training'] ?? 0;

        $team_coach = [];
        foreach ( $this->teams as $t ) {
            $team_coach[ (int) $t->id ] = (int) $t->head_coach_user_id;
        }

        $seasons = $this->calendar->seasons();
        $now     = $this->calendar->now();

        $total_evals = 0;
        foreach ( $this->players as $p ) {
            $player_id = (int) ( $p->id ?? 0 );
            if ( $player_id <= 0 ) continue;
            $archetype = (string) ( $p->archetype ?? 'steady_solid' );

            foreach ( $seasons as $season ) {
                $team_id = $this->roster->teamForPlayerInSeason( $player_id, (int) $season['index'] );
                $coach_id = (int) ( $team_coach[ $team_id ] ?? 0 );
                if ( $team_id <= 0 || $coach_id <= 0 ) continue;

                foreach ( $this->calendar->roundDates( $season ) as $round => $when ) {
                    $ts = (int) strtotime( $when );
                    if ( $ts <= 0 || $ts > $now ) continue; // a round that has not come round yet

                    $eval_id = $writer->write( [
                        'club_id'      => CurrentClub::id(),
                        'player_id'    => $player_id,
                        'coach_id'     => $coach_id,
                        'eval_type_id' => (int) $training_id,
                        'eval_date'    => $when,
                        'notes'        => '',
                    ], $player_id, $archetype, [
                        'round'     => $round + 1,
                        'season'    => (string) $season['name'],
                        'team_id'   => $team_id,
                    ] );
                    if ( $eval_id > 0 ) $total_evals++;
                }
            }
        }

        return $total_evals;
    }

    private ?DemoEvaluationWriter $writer = null;

    private function writer(): DemoEvaluationWriter {
        if ( $this->writer === null ) {
            $this->writer = new DemoEvaluationWriter( $this->registry, $this->calendar );
        }
        return $this->writer;
    }

    /**
     * An archetype's rating at window-time `$t`, in the install's own units.
     *
     * Public so a test can assert the property that matters: across a
     * season, an improving archetype moves at least one step of whatever
     * scale the academy has configured (#3401). The curves themselves live
     * on `DemoEvaluationWriter`, which both evaluation writers share.
     */
    public function archetypeRating( string $archetype, float $t, DemoRatingScale $scale, float $climb ): float {
        return $this->writer()->archetypeRating( $archetype, $t, $scale, $climb );
    }
}
