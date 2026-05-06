<?php
namespace TT\Modules\TeamDevelopment;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\TeamDevelopment\Repositories\PairingsRepository;

/**
 * BlueprintChemistryEngine — FIFA-Ultimate-Team-style pair chemistry on
 * a coach-authored lineup.
 *
 * The classic FIFA model (≤ FIFA 22) draws coloured links between
 * formation-adjacent players and scores each link on shared traits
 * (club / league / nation). A youth football academy has none of those,
 * so the inputs are mapped onto signals we *do* have:
 *
 *   1. Coach-marked pairing — `tt_team_chemistry_pairings` (highest
 *      signal; "always start these two together"). +2 to the link.
 *   2. Same line of play — both slots in the same band (DEF / MID /
 *      ATT / GK), inferred from slot.y. +1.
 *   3. Side coherence — both players sit on a side that matches their
 *      `position_side_preference` (or have no preference recorded).
 *      +1 if both side-compatible, -1 if either is in a wrong-side
 *      slot, 0 otherwise.
 *
 * Resulting per-pair score is clamped 0..3 and bucketed:
 *
 *   - 2.0 ≤ score → green link (strong fit)
 *   - 1.0 ≤ score < 2.0 → amber link (workable)
 *   - score < 1.0 → red link (poor fit)
 *
 * Empty/null pair (one or both slots vacant) → grey neutral link, not
 * scored, not counted toward the team total.
 *
 * Adjacency: each slot connects to its three nearest other slots
 * (Euclidean distance on slot.pos.x/y, deduped). 11 slots produce ~16
 * unique pairs — close to the FIFA UT linkage density and visually
 * legible.
 *
 * Team chemistry: `sum(pair_scores) / (pair_count × 3) × 100`, in 0..100,
 * mirroring FIFA's familiar percentage ceiling. Returns null when no
 * scored pair exists (lineup empty or one player only).
 */
final class BlueprintChemistryEngine {

    public const COLOR_GREEN   = 'green';
    public const COLOR_AMBER   = 'amber';
    public const COLOR_RED     = 'red';
    public const COLOR_NEUTRAL = 'neutral';

    private const NEAREST_K = 3;

    private const COACH_PAIR_BONUS  = 2.0;
    private const SAME_LINE_BONUS   = 1.0;
    private const SIDE_MATCH_BONUS  = 1.0;
    private const SIDE_MISMATCH_PEN = 1.0;

    private PairingsRepository $pairings;

    public function __construct( ?PairingsRepository $pairings = null ) {
        $this->pairings = $pairings ?? new PairingsRepository();
    }

    /**
     * Compute chemistry for a lineup.
     *
     * @param int                                   $team_id
     * @param list<array<string,mixed>>             $slots    Slot definitions (label, pos, side).
     * @param array<string, ?int>                   $lineup   Slot-label → player_id|null.
     *
     * @return array{
     *   team_score: ?int,
     *   pair_count: int,
     *   scored_pair_count: int,
     *   links: list<array{
     *     a_slot:string, b_slot:string,
     *     a_player_id:?int, b_player_id:?int,
     *     score:?float, color:string,
     *     reasons: list<string>,
     *     a_pos: array{x:float,y:float}, b_pos: array{x:float,y:float}
     *   }>
     * }
     */
    public function computeForLineup( int $team_id, array $slots, array $lineup ): array {
        $pairs = self::nearestPairs( $slots );
        if ( empty( $pairs ) ) {
            return [
                'team_score'        => null,
                'pair_count'        => 0,
                'scored_pair_count' => 0,
                'links'             => [],
            ];
        }

        $coach_marks = self::coachMarkedSet( $this->pairings->listForTeam( $team_id ) );
        $slot_index  = self::indexSlots( $slots );
        $player_pref = self::playerSidePreferences( $lineup );

        $links = [];
        $score_total = 0.0;
        $scored = 0;
        foreach ( $pairs as $pair ) {
            [ $a_label, $b_label ] = $pair;
            $a_slot = $slot_index[ $a_label ] ?? null;
            $b_slot = $slot_index[ $b_label ] ?? null;
            if ( $a_slot === null || $b_slot === null ) continue;

            $a_pid = isset( $lineup[ $a_label ] ) ? (int) $lineup[ $a_label ] : 0;
            $b_pid = isset( $lineup[ $b_label ] ) ? (int) $lineup[ $b_label ] : 0;

            if ( $a_pid <= 0 || $b_pid <= 0 ) {
                // One or both slots empty — render as neutral lattice, no score.
                $links[] = [
                    'a_slot'      => $a_label,
                    'b_slot'      => $b_label,
                    'a_player_id' => $a_pid > 0 ? $a_pid : null,
                    'b_player_id' => $b_pid > 0 ? $b_pid : null,
                    'score'       => null,
                    'color'       => self::COLOR_NEUTRAL,
                    'reasons'     => [],
                    'a_pos'       => [ 'x' => (float) $a_slot['x'], 'y' => (float) $a_slot['y'] ],
                    'b_pos'       => [ 'x' => (float) $b_slot['x'], 'y' => (float) $b_slot['y'] ],
                ];
                continue;
            }

            $reasons = [];
            $score = 0.0;

            $coach_key = self::pairKey( $a_pid, $b_pid );
            if ( isset( $coach_marks[ $coach_key ] ) ) {
                $score   += self::COACH_PAIR_BONUS;
                $reasons[] = __( 'Coach-marked pairing', 'talenttrack' );
            }

            if ( self::sameLine( (float) $a_slot['y'], (float) $b_slot['y'] ) ) {
                $score   += self::SAME_LINE_BONUS;
                $reasons[] = __( 'Same line of play', 'talenttrack' );
            }

            $a_compat = self::playerSideCompatibleWithSlot( $player_pref[ $a_pid ] ?? null, (string) $a_slot['side'] );
            $b_compat = self::playerSideCompatibleWithSlot( $player_pref[ $b_pid ] ?? null, (string) $b_slot['side'] );
            if ( $a_compat === false || $b_compat === false ) {
                $score   -= self::SIDE_MISMATCH_PEN;
                $reasons[] = __( 'Side mismatch', 'talenttrack' );
            } elseif ( $a_compat === true && $b_compat === true ) {
                $score   += self::SIDE_MATCH_BONUS;
                $reasons[] = __( 'Side preferences fit slots', 'talenttrack' );
            }

            $score = max( 0.0, min( 3.0, $score ) );
            $score_total += $score;
            $scored++;

            $links[] = [
                'a_slot'      => $a_label,
                'b_slot'      => $b_label,
                'a_player_id' => $a_pid,
                'b_player_id' => $b_pid,
                'score'       => round( $score, 2 ),
                'color'       => self::bucket( $score ),
                'reasons'     => $reasons,
                'a_pos'       => [ 'x' => (float) $a_slot['x'], 'y' => (float) $a_slot['y'] ],
                'b_pos'       => [ 'x' => (float) $b_slot['x'], 'y' => (float) $b_slot['y'] ],
            ];
        }

        $team_score = null;
        if ( $scored > 0 ) {
            $team_score = (int) round( ( $score_total / ( $scored * 3.0 ) ) * 100 );
        }

        return [
            'team_score'        => $team_score,
            'pair_count'        => count( $links ),
            'scored_pair_count' => $scored,
            'links'             => $links,
        ];
    }

    /**
     * Convenience: compute chemistry for a `suggested_xi` shape (the
     * payload `ChemistryAggregator::teamChemistry` produces) so the
     * existing chemistry view can render lines without first
     * reformatting its data.
     *
     * @param int                                                                                       $team_id
     * @param list<array<string,mixed>>                                                                  $slots
     * @param array<string, array{player_id:int, player_name:string, score:float, has_data:bool}>       $suggested
     *
     * @return array<string,mixed>
     */
    public function computeForSuggested( int $team_id, array $slots, array $suggested ): array {
        $lineup = [];
        foreach ( $suggested as $label => $entry ) {
            $lineup[ (string) $label ] = isset( $entry['player_id'] ) ? (int) $entry['player_id'] : null;
        }
        return $this->computeForLineup( $team_id, $slots, $lineup );
    }

    /**
     * Each slot links to its `NEAREST_K` nearest other slots. Pairs
     * deduped (smaller-label-first ordering) so an A↔B link only shows
     * up once.
     *
     * @param list<array<string,mixed>> $slots
     * @return list<array{0:string,1:string}>
     */
    private static function nearestPairs( array $slots ): array {
        $points = [];
        foreach ( $slots as $s ) {
            $label = (string) ( $s['label'] ?? '' );
            if ( $label === '' ) continue;
            $points[ $label ] = [
                'x' => (float) ( $s['pos']['x'] ?? 0.5 ),
                'y' => (float) ( $s['pos']['y'] ?? 0.5 ),
            ];
        }
        $labels = array_keys( $points );
        $seen = [];
        $out = [];
        foreach ( $labels as $a ) {
            $dists = [];
            foreach ( $labels as $b ) {
                if ( $a === $b ) continue;
                $dx = $points[ $a ]['x'] - $points[ $b ]['x'];
                $dy = $points[ $a ]['y'] - $points[ $b ]['y'];
                $dists[ $b ] = sqrt( $dx * $dx + $dy * $dy );
            }
            asort( $dists );
            $top = array_slice( array_keys( $dists ), 0, self::NEAREST_K );
            foreach ( $top as $b ) {
                $key = $a < $b ? $a . '|' . $b : $b . '|' . $a;
                if ( isset( $seen[ $key ] ) ) continue;
                $seen[ $key ] = true;
                $out[] = $a < $b ? [ $a, $b ] : [ $b, $a ];
            }
        }
        return $out;
    }

    /** @return array<string, array{x:float,y:float,side:string}> */
    private static function indexSlots( array $slots ): array {
        $out = [];
        foreach ( $slots as $s ) {
            $label = (string) ( $s['label'] ?? '' );
            if ( $label === '' ) continue;
            $out[ $label ] = [
                'x'    => (float) ( $s['pos']['x'] ?? 0.5 ),
                'y'    => (float) ( $s['pos']['y'] ?? 0.5 ),
                'side' => (string) ( $s['side'] ?? 'center' ),
            ];
        }
        return $out;
    }

    /**
     * @param list<array{player_a_id:int, player_b_id:int}> $pairings
     * @return array<string, true>
     */
    private static function coachMarkedSet( array $pairings ): array {
        $set = [];
        foreach ( $pairings as $p ) {
            $set[ self::pairKey( (int) $p['player_a_id'], (int) $p['player_b_id'] ) ] = true;
        }
        return $set;
    }

    private static function pairKey( int $a, int $b ): string {
        return $a < $b ? $a . '_' . $b : $b . '_' . $a;
    }

    /**
     * Bulk-load player side preferences for the players in this lineup.
     *
     * @param array<string,?int> $lineup
     * @return array<int, ?string>
     */
    private static function playerSidePreferences( array $lineup ): array {
        $ids = [];
        foreach ( $lineup as $pid ) {
            if ( $pid !== null && (int) $pid > 0 ) $ids[ (int) $pid ] = true;
        }
        if ( empty( $ids ) ) return [];

        global $wpdb; $p = $wpdb->prefix;
        $in = implode( ',', array_map( 'intval', array_keys( $ids ) ) );
        $rows = $wpdb->get_results(
            "SELECT id, position_side_preference FROM {$p}tt_players WHERE id IN ($in)"
        );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $pref = (string) ( $r->position_side_preference ?? '' );
            $out[ (int) $r->id ] = $pref !== '' ? $pref : null;
        }
        return $out;
    }

    /**
     * Returns:
     *   - true  : explicit match (player has a preference and it fits the slot)
     *   - false : explicit mismatch (player prefers the opposite side)
     *   - null  : neutral (player has no preference, or the slot is centre)
     */
    private static function playerSideCompatibleWithSlot( ?string $player_pref, string $slot_side ): ?bool {
        if ( $player_pref === null || $player_pref === '' ) return null;
        if ( $slot_side === '' || $slot_side === 'center' ) return null;
        if ( $player_pref === $slot_side ) return true;
        if ( ( $player_pref === 'left' && $slot_side === 'right' ) ||
             ( $player_pref === 'right' && $slot_side === 'left' ) ) {
            return false;
        }
        return null;
    }

    /**
     * Slot.y is normalised 0..1 with 0 = top of the pitch and 1 = bottom.
     * Seeded formations place the home team attacking up the pitch (GK
     * at y=0.95, attackers at y≈0.15..0.25), so the y bands flow
     * top-down: attack, mid, defence, GK.
     */
    private static function sameLine( float $a_y, float $b_y ): bool {
        return self::lineBand( $a_y ) === self::lineBand( $b_y );
    }

    private static function lineBand( float $y ): string {
        if ( $y < 0.30 ) return 'att';
        if ( $y < 0.65 ) return 'mid';
        if ( $y < 0.90 ) return 'def';
        return 'gk';
    }

    private static function bucket( float $score ): string {
        if ( $score >= 2.0 ) return self::COLOR_GREEN;
        if ( $score >= 1.0 ) return self::COLOR_AMBER;
        return self::COLOR_RED;
    }
}
