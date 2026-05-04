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
     * v3.92.0 — once a roster's data coverage drops below this fraction
     * (i.e. fewer than 40% of its players have any rated main category),
     * we surface a "Not enough evaluations yet" empty-state banner and
     * skip the composite score. The threshold is conservative: rate at
     * least 5 of 12 roster players to start seeing chemistry numbers.
     */
    private const MIN_DATA_COVERAGE = 0.40;

    /**
     * @return array{
     *   composite:?float,
     *   formation_fit:?float,
     *   style_fit:?float,
     *   paired_chemistry:float,
     *   depth_score:?float,
     *   suggested_xi: array<string, array{player_id:int, player_name:string, score:float, has_data:bool}>,
     *   depth: array<string, list<array{player_id:int, player_name:string, score:float, has_data:bool}>>,
     *   data_coverage: float,
     *   has_enough_data: bool,
     *   roster_size: int,
     *   slot_count: int,
     * }
     */
    public function teamChemistry( int $team_id, int $formation_template_id, int $possession_w, int $counter_w, int $press_w ): array {
        $players = QueryHelpers::get_players( $team_id );
        $slots = self::slotsFor( $formation_template_id );
        $slot_count = count( $slots );

        if ( empty( $players ) ) {
            return [
                'composite'        => null,
                'formation_fit'    => null,
                'style_fit'        => null,
                'paired_chemistry' => 0.0,
                'depth_score'      => null,
                'suggested_xi'     => [],
                'depth'            => [],
                'data_coverage'    => 0.0,
                'has_enough_data'  => false,
                'roster_size'      => 0,
                'slot_count'       => $slot_count,
            ];
        }

        // Per (slot, player) fit — pre-computed once per player on the
        // engine's cached path. Track whether each player has any data
        // so the UI can render "?" for not-yet-evaluated players.
        $by_slot = [];
        foreach ( $slots as $slot ) {
            $by_slot[ (string) $slot['label'] ] = [];
        }
        $rated_player_count = 0;
        foreach ( $players as $pl ) {
            $all_slots  = $this->engine->allSlotsFor( (int) $pl->id, $formation_template_id );
            $player_has_data = false;
            foreach ( $all_slots as $label => $result ) {
                if ( $result->hasData ) $player_has_data = true;
                $by_slot[ $label ][] = [
                    'player_id'   => (int) $pl->id,
                    'player_name' => QueryHelpers::player_display_name( $pl ),
                    'score'       => $result->score,
                    'has_data'    => $result->hasData,
                ];
            }
            if ( $player_has_data ) $rated_player_count++;
        }

        $roster_size   = count( $players );
        $data_coverage = $roster_size > 0 ? ( $rated_player_count / $roster_size ) : 0.0;
        $has_enough    = $data_coverage >= self::MIN_DATA_COVERAGE;

        // Suggested XI: best-fit player per slot. Greedy selection that
        // sorts rated players first (so an unrated player only fills a
        // slot when no rated candidate exists), then by score. Crucially
        // — when no unused candidate is left, leave the slot **empty**
        // rather than re-using a player. v1's "fall back to top scorer
        // even if used" was the source of the "same few players appear
        // repeatedly" bug when the roster was smaller than the formation.
        $suggested = [];
        $used = [];
        foreach ( $by_slot as $label => $candidates ) {
            usort( $candidates, static function ( $a, $b ) {
                if ( $a['has_data'] !== $b['has_data'] ) {
                    return $b['has_data'] <=> $a['has_data'];
                }
                return $b['score'] <=> $a['score'];
            } );
            foreach ( $candidates as $c ) {
                if ( isset( $used[ $c['player_id'] ] ) ) continue;
                $suggested[ $label ] = $c;
                $used[ $c['player_id'] ] = true;
                break;
            }
            // No fallback — slots with no unused candidate stay empty.
        }
        $formation_fit = self::meanScoreWithData( $suggested );

        // Depth: top-3 per slot. Sort rated-first so unrated candidates
        // sink below rated ones with even a low score.
        $depth = [];
        foreach ( $by_slot as $label => $candidates ) {
            usort( $candidates, static function ( $a, $b ) {
                if ( $a['has_data'] !== $b['has_data'] ) {
                    return $b['has_data'] <=> $a['has_data'];
                }
                return $b['score'] <=> $a['score'];
            } );
            $depth[ $label ] = array_slice( $candidates, 0, 3 );
        }
        $depth_score = $has_enough ? self::depthScore( $depth ) : null;

        // Style fit: mean across the roster — only computed when we have
        // enough data. Otherwise null so the UI shows "?" instead of a
        // misleading "0.00".
        if ( $has_enough ) {
            $style_total = 0.0; $style_n = 0;
            foreach ( $players as $pl ) {
                $style_total += $this->engine->styleFit( (int) $pl->id, $possession_w, $counter_w, $press_w );
                $style_n++;
            }
            $style_fit = $style_n > 0 ? round( $style_total / $style_n, 2 ) : null;
        } else {
            $style_fit = null;
        }

        // Paired chemistry: each coach-marked pairing where both starters
        // are in the suggested XI contributes +0.05 (capped at +0.5).
        // This stays computable even when the team has no eval data —
        // it's a coach intent signal, not an evaluation read.
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

        // Composite: only computed when we have enough data. The view
        // renders the not-enough-data banner when null.
        $composite = null;
        if ( $has_enough && $formation_fit !== null && $style_fit !== null && $depth_score !== null ) {
            $composite = ( $formation_fit * 0.65 ) + ( $style_fit * 0.20 ) + ( $depth_score * 0.15 ) + $paired_chemistry;
            $composite = round( max( 0.0, min( 5.0, $composite ) ), 2 );
        }

        return [
            'composite'        => $composite,
            'formation_fit'    => $formation_fit !== null ? round( $formation_fit, 2 ) : null,
            'style_fit'        => $style_fit,
            'paired_chemistry' => $paired_chemistry,
            'depth_score'      => $depth_score !== null ? round( $depth_score, 2 ) : null,
            'suggested_xi'     => $suggested,
            'depth'            => $depth,
            'data_coverage'    => round( $data_coverage, 2 ),
            'has_enough_data'  => $has_enough,
            'roster_size'      => $roster_size,
            'slot_count'       => $slot_count,
        ];
    }

    /**
     * v3.92.0 — mean of fit scores, but only over slots whose suggested
     * player has eval data. Returns null when no slot has a rated
     * starter so the caller can branch on that ("Not enough evaluations
     * to compute formation fit yet"). Empty slots (`null` candidate)
     * are skipped — they don't drag the average down.
     *
     * @param array<string, array{player_id:int, player_name:string, score:float, has_data:bool}> $suggested
     */
    private static function meanScoreWithData( array $suggested ): ?float {
        $sum = 0.0; $n = 0;
        foreach ( $suggested as $s ) {
            if ( empty( $s['has_data'] ) ) continue;
            $sum += (float) $s['score'];
            $n++;
        }
        return $n > 0 ? ( $sum / $n ) : null;
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
