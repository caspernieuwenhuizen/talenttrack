<?php
namespace TT\Modules\TeamDevelopment\Chemistry;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ChemistryExplainer (#1017 Phase 6) — turns a LineupChemistryAggregator
 * result into the spec's explainability payload: the strongest and weakest
 * relationships and improvement recommendations. Pure data (player ids +
 * keys); the view resolves names and renders the sentences so the strings
 * stay in one place.
 */
final class ChemistryExplainer {

    /**
     * @param array{pairs: list<array<string,mixed>>} $lineupResult
     *
     * @return array{
     *   strongest: list<array<string,mixed>>,
     *   weakest: list<array<string,mixed>>,
     *   recommendations: list<array<string,mixed>>
     * }
     */
    public function explain( array $lineupResult, int $limit = 3 ): array {
        $pairs = $lineupResult['pairs'] ?? [];
        if ( empty( $pairs ) ) {
            return [ 'strongest' => [], 'weakest' => [], 'recommendations' => [] ];
        }

        // Rank on pairs that actually had data; fall back to all if too few.
        $withData = array_values( array_filter( $pairs, static fn( $p ) => ! empty( $p['has_data'] ) ) );
        $ranked   = count( $withData ) >= 2 ? $withData : array_values( $pairs );

        usort( $ranked, static fn( $a, $b ) => ( (float) $b['score'] ) <=> ( (float) $a['score'] ) );

        $strongest = array_slice( array_map( [ $this, 'slim' ], $ranked ), 0, $limit );

        $weakAsc = $ranked;
        usort( $weakAsc, static fn( $a, $b ) => ( (float) $a['score'] ) <=> ( (float) $b['score'] ) );
        $weakest = array_slice( array_map( [ $this, 'slim' ], $weakAsc ), 0, $limit );

        $recommendations = [];
        foreach ( array_slice( $weakAsc, 0, $limit ) as $p ) {
            $recommendations[] = [
                'a_player_id'       => (int) $p['a_player_id'],
                'b_player_id'       => (int) $p['b_player_id'],
                'weakest_component' => (string) ( $p['weakest_component'] ?? '' ),
                'needs_data'        => empty( $p['has_data'] ),
            ];
        }

        return [
            'strongest'       => $strongest,
            'weakest'         => $weakest,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function slim( array $p ): array {
        return [
            'a_player_id' => (int) $p['a_player_id'],
            'b_player_id' => (int) $p['b_player_id'],
            'score'       => (float) $p['score'],
            'category'    => (string) $p['category'],
        ];
    }
}
