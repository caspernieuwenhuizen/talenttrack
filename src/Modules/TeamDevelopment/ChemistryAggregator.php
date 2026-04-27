<?php
namespace TT\Modules\TeamDevelopment;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\TeamDevelopment\Repositories\PairingsRepository;

/**
 * ChemistryAggregator — sprint 4 of #0018. Composes the per-team
 * chemistry score from four ingredients:
 *
 *   1. Formation fit (mean of best-fit-per-slot from CompatibilityEngine)
 *   2. Style fit    (mean across roster against the team blend)
 *   3. Paired chemistry (small bonus per coach-marked pair both rated >=
 *      threshold; reflects the "always start these two together" intent)
 *   4. Depth         (penalty for slots with zero or one capable backup)
 *
 * Output is a `composite` 0-5 score plus the four parts so the UI can
 * surface a stacked-bar breakdown alongside the formation board.
 *
 * No caching here — the aggregator is cheap once each player's per-slot
 * fit is cached in FitScoreCache.
 */
class ChemistryAggregator {

    private CompatibilityEngine $engine;
    private PairingsRepository $pairings;

    public function __construct( ?CompatibilityEngine $engine = null, ?PairingsRepository $pairings = null ) {
        $this->engine   = $engine   ?? new CompatibilityEngine();
        $this->pairings = $pairings ?? new PairingsRepository();
    }

    /**
     * @return array{
     *   composite:float,
     *   formation_fit:float,
     *   style_fit:float,
     *   paired_chemistry:float,
     *   depth_score:float,
     *   suggested_xi: array<string, array{player_id:int, player_name:string, score:float}>,
     *   depth: array<string, list<array{player_id:int, player_name:string, score:float}>>,
     * }
     */
    public function teamChemistry( int $team_id, int $formation_template_id, int $possession_w, int $counter_w, int $press_w ): array {
        $players = QueryHelpers::get_players( $team_id );
        if ( empty( $players ) ) {
            return [
                'composite'        => 0.0,
                'formation_fit'    => 0.0,
                'style_fit'        => 0.0,
                'paired_chemistry' => 0.0,
                'depth_score'      => 0.0,
                'suggested_xi'     => [],
                'depth'            => [],
            ];
        }

        // Per (slot, player) fit — pre-computed once per player on the
        // engine's cached path.
        $slots = self::slotsFor( $formation_template_id );
        $by_slot = [];
        foreach ( $slots as $slot ) {
            $by_slot[ (string) $slot['label'] ] = [];
        }
        foreach ( $players as $pl ) {
            $all_slots = $this->engine->allSlotsFor( (int) $pl->id, $formation_template_id );
            foreach ( $all_slots as $label => $result ) {
                $by_slot[ $label ][] = [
                    'player_id'   => (int) $pl->id,
                    'player_name' => QueryHelpers::player_display_name( $pl ),
                    'score'       => $result->score,
                ];
            }
        }

        // Suggested XI: best-fit player per slot. Greedy selection;
        // a globally-optimal assignment is a v2 ask.
        $suggested = [];
        $used = [];
        foreach ( $by_slot as $label => $candidates ) {
            usort( $candidates, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
            foreach ( $candidates as $c ) {
                if ( isset( $used[ $c['player_id'] ] ) ) continue;
                $suggested[ $label ] = $c;
                $used[ $c['player_id'] ] = true;
                break;
            }
            if ( ! isset( $suggested[ $label ] ) && ! empty( $candidates ) ) {
                // Roster too small to fill all slots without re-using —
                // fall back to the highest-scoring even if used.
                $suggested[ $label ] = $candidates[0];
            }
        }
        $formation_fit = self::meanScore( $suggested );

        // Depth: top-3 per slot, excluding the suggested starter.
        $depth = [];
        foreach ( $by_slot as $label => $candidates ) {
            usort( $candidates, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
            $depth[ $label ] = array_slice( $candidates, 0, 3 );
        }
        $depth_score = self::depthScore( $depth );

        // Style fit: mean across the roster.
        $style_total = 0.0; $style_n = 0;
        foreach ( $players as $pl ) {
            $style_total += $this->engine->styleFit( (int) $pl->id, $possession_w, $counter_w, $press_w );
            $style_n++;
        }
        $style_fit = $style_n > 0 ? round( $style_total / $style_n, 2 ) : 0.0;

        // Paired chemistry: each coach-marked pairing where both starters
        // are in the suggested XI contributes +0.05 (capped at +0.5).
        $paired = $this->pairings->listForTeam( $team_id );
        $starter_ids = array_map( static fn( $s ) => $s['player_id'], $suggested );
        $bonus = 0.0;
        foreach ( $paired as $p ) {
            if ( in_array( (int) $p['player_a_id'], $starter_ids, true )
              && in_array( (int) $p['player_b_id'], $starter_ids, true ) ) {
                $bonus += 0.05;
            }
        }
        $paired_chemistry = round( min( 0.5, $bonus ), 2 );

        // Composite: formation_fit dominant, style_fit as a multiplier
        // (small influence), depth as a soft floor, pairings as bonus.
        $composite = ( $formation_fit * 0.65 ) + ( $style_fit * 0.20 ) + ( $depth_score * 0.15 ) + $paired_chemistry;
        $composite = max( 0.0, min( 5.0, $composite ) );

        return [
            'composite'        => round( $composite, 2 ),
            'formation_fit'    => round( $formation_fit, 2 ),
            'style_fit'        => $style_fit,
            'paired_chemistry' => $paired_chemistry,
            'depth_score'      => round( $depth_score, 2 ),
            'suggested_xi'     => $suggested,
            'depth'            => $depth,
        ];
    }

    /**
     * @param array<string, array{player_id:int, player_name:string, score:float}> $suggested
     */
    private static function meanScore( array $suggested ): float {
        if ( empty( $suggested ) ) return 0.0;
        $sum = 0.0;
        foreach ( $suggested as $s ) $sum += (float) $s['score'];
        return $sum / count( $suggested );
    }

    /**
     * Depth score: per slot, look at #2 + #3 backups. A slot with two
     * backups rated >= 3.0 contributes 5.0; a slot with no backups
     * contributes 0. Mean across all slots.
     *
     * @param array<string, list<array{player_id:int, player_name:string, score:float}>> $depth
     */
    private static function depthScore( array $depth ): float {
        if ( empty( $depth ) ) return 0.0;
        $total = 0.0;
        foreach ( $depth as $slot_depth ) {
            $backups = array_slice( $slot_depth, 1, 2 );
            $count = 0;
            foreach ( $backups as $b ) {
                if ( (float) $b['score'] >= 3.0 ) $count++;
            }
            $total += match ( $count ) {
                2 => 5.0,
                1 => 3.0,
                default => 0.0,
            };
        }
        return $total / count( $depth );
    }

    /** @return list<array<string,mixed>> */
    private static function slotsFor( int $template_id ): array {
        global $wpdb; $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT slots_json FROM {$p}tt_formation_templates WHERE id = %d AND archived_at IS NULL",
            $template_id
        ) );
        if ( ! $row ) return [];
        $decoded = json_decode( (string) $row->slots_json, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
