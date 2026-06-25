<?php
namespace TT\Modules\TeamDevelopment\Chemistry;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\TeamDevelopment\Chemistry\Scorers\BehaviourScorer;
use TT\Modules\TeamDevelopment\Chemistry\Scorers\CompatibilityScorer;
use TT\Modules\TeamDevelopment\Chemistry\Scorers\ComponentScorer;
use TT\Modules\TeamDevelopment\Chemistry\Scorers\DevelopmentScorer;
use TT\Modules\TeamDevelopment\Chemistry\Scorers\FamiliarityScorer;
use TT\Modules\TeamDevelopment\Chemistry\Scorers\PerformanceScorer;
use TT\Modules\TeamDevelopment\Repositories\ChemistryConfig;

/**
 * PairChemistryEngine (#1017 Phase 3) — the orchestrator that turns two
 * player profiles + their shared-history context into a 0–100 pair
 * chemistry score, by running the five Phase-2 component scorers and
 * combining them with the configurable component weights (ChemistryConfig).
 *
 * This is the new-spec replacement for the per-link scoring in
 * BlueprintChemistryEngine. Phase 4 aggregates pair results into Unit /
 * Lineup / Team chemistry; the live surface only switches over once Phase 7
 * has populated attributes, so this is built and exposed (REST) without
 * displacing the old engine yet.
 */
final class PairChemistryEngine {

    /** @var array<int, ComponentScorer> */
    private array $scorers;
    private ChemistryConfig $config;

    /**
     * @param array<int, ComponentScorer>|null $scorers
     */
    public function __construct( ?array $scorers = null, ?ChemistryConfig $config = null ) {
        $this->scorers = $scorers ?? [
            new CompatibilityScorer(),
            new FamiliarityScorer(),
            new DevelopmentScorer(),
            new BehaviourScorer(),
            new PerformanceScorer(),
        ];
        $this->config = $config ?? new ChemistryConfig();
    }

    public function scorePair(
        PlayerChemistryProfile $a,
        PlayerChemistryProfile $b,
        PairContext $pair
    ): PairResult {
        $weights = $this->config->weights();

        $components = [];
        $total      = 0.0;
        $reasons    = [];
        $has_data   = false;

        foreach ( $this->scorers as $scorer ) {
            $cs  = $scorer->score( $a, $b, $pair );
            $key = $scorer->key();
            $components[ $key ] = $cs;
            $total  += ( ( $weights[ $key ] ?? 0 ) / 100.0 ) * $cs->value;
            if ( $cs->has_data ) $has_data = true;
            foreach ( $cs->reasons as $reason ) {
                $reasons[] = $reason;
            }
        }

        $score = round( $total, 1 );
        return new PairResult(
            $a->player_id,
            $b->player_id,
            $score,
            PairResult::categoryFor( $score ),
            $components,
            array_values( array_unique( $reasons ) ),
            $has_data
        );
    }
}
