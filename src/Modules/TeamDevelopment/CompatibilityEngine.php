<?php
namespace TT\Modules\TeamDevelopment;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Modules\TeamDevelopment\Cache\FitScoreCache;

/**
 * CompatibilityEngine — pure-logic service that scores a player
 * against a slot's role profile. Output is fully traceable: each
 * FitResult carries the per-category breakdown that produced the
 * score so a UI tooltip can show the math.
 *
 * Inputs:
 *   - Player's all-time mean rating per main evaluation category
 *     (technical / tactical / physical / mental). v1 uses all-time;
 *     swapping to rolling-5 is one query change.
 *   - Slot role profile (weights summing to 1.0).
 *   - Player's optional position_side_preference vs slot.side
 *     (+0.2 match, 0 neutral, -0.2 mismatch).
 *
 * Cache: FitScoreCache (per-player). 24h TTL, invalidated on any
 * evaluation save for the player.
 */
class CompatibilityEngine {

    private const SIDE_BONUS    =  0.20;
    private const SIDE_PENALTY  = -0.20;

    private PlayerStatsService $stats;
    private FitScoreCache $cache;

    public function __construct( ?PlayerStatsService $stats = null, ?FitScoreCache $cache = null ) {
        $this->stats = $stats ?? new PlayerStatsService();
        $this->cache = $cache ?? new FitScoreCache();
    }

    /**
     * Score a single player against one slot of a formation template.
     */
    public function fitScore( int $player_id, int $formation_template_id, string $slot_label ): FitResult {
        $cached = $this->cache->getSlot( $player_id, $formation_template_id, $slot_label );
        if ( $cached !== null ) return $cached;

        $slot = self::resolveSlot( $formation_template_id, $slot_label );
        if ( ! $slot ) {
            return new FitResult( 0.0, [], '' );
        }
        $result = $this->compute( $player_id, $slot );
        $this->cache->putSlot( $player_id, $formation_template_id, $slot_label, $result );
        return $result;
    }

    /**
     * Score a player against every slot in a formation template.
     *
     * @return array<string, FitResult> keyed by slot label.
     */
    public function allSlotsFor( int $player_id, int $formation_template_id ): array {
        $cached = $this->cache->getAllSlots( $player_id, $formation_template_id );
        if ( $cached !== null ) return $cached;

        $slots = self::loadFormationSlots( $formation_template_id );
        $out = [];
        foreach ( $slots as $slot ) {
            $label = (string) ( $slot['label'] ?? '' );
            if ( $label === '' ) continue;
            $out[ $label ] = $this->compute( $player_id, $slot );
        }
        $this->cache->putAllSlots( $player_id, $formation_template_id, $out );
        return $out;
    }

    public function invalidateCache( int $player_id ): void {
        $this->cache->invalidate( $player_id );
    }

    /**
     * Style fit — how well a player matches the team's possession /
     * counter / press blend. Independent of formation. Different
     * mapping than slot fit:
     *   - possession axis: technical-heavy
     *   - counter axis: physical-heavy
     *   - press axis: physical + mental
     */
    public function styleFit( int $player_id, int $possession_w, int $counter_w, int $press_w ): float {
        $sum = $possession_w + $counter_w + $press_w;
        if ( $sum <= 0 ) return 0.0;
        $ratings = $this->mainRatings( $player_id );
        $tech = $ratings['technical'];
        $tact = $ratings['tactical'];
        $phys = $ratings['physical'];
        $ment = $ratings['mental'];

        // Per-axis composite. Weights are hard-coded mappings from main
        // categories to play-style traits. Sprint 2 v1; admins may
        // tweak via Sprint 4's config UI.
        $possession_score = ( $tech * 0.55 ) + ( $tact * 0.35 ) + ( $ment * 0.10 );
        $counter_score    = ( $phys * 0.55 ) + ( $tech * 0.20 ) + ( $tact * 0.15 ) + ( $ment * 0.10 );
        $press_score      = ( $phys * 0.45 ) + ( $ment * 0.30 ) + ( $tact * 0.15 ) + ( $tech * 0.10 );

        $weighted = ( $possession_score * $possession_w )
                  + ( $counter_score    * $counter_w )
                  + ( $press_score      * $press_w );
        return round( $weighted / $sum, 2 );
    }

    /** @param array<string, mixed> $slot */
    private function compute( int $player_id, array $slot ): FitResult {
        $weights = is_array( $slot['weights'] ?? null ) ? $slot['weights'] : [];
        $ratings = $this->mainRatings( $player_id );

        $score = 0.0;
        $breakdown = [];
        foreach ( [ 'technical', 'tactical', 'physical', 'mental' ] as $key ) {
            $rating = (float) ( $ratings[ $key ] ?? 0.0 );
            $weight = (float) ( $weights[ $key ] ?? 0.0 );
            $contribution = $rating * $weight;
            $score += $contribution;
            $breakdown[ $key ] = [
                'rating'       => $rating,
                'weight'       => $weight,
                'contribution' => $contribution,
            ];
        }

        $modifier = $this->sideModifier( $player_id, (string) ( $slot['side'] ?? 'center' ) );
        $score = max( 0.0, min( 5.0, $score + $modifier ) );

        $rationale = self::buildRationale( $breakdown, $modifier, (string) ( $slot['label'] ?? '' ) );
        return new FitResult( $score, $breakdown, $rationale, $modifier );
    }

    /**
     * Player's all-time mean per main category. Uses
     * PlayerStatsService::getMainCategoryBreakdown so the source of
     * truth stays consistent with the rate-card view.
     *
     * @return array{technical:float, tactical:float, physical:float, mental:float}
     */
    private function mainRatings( int $player_id ): array {
        $defaults = [ 'technical' => 0.0, 'tactical' => 0.0, 'physical' => 0.0, 'mental' => 0.0 ];
        if ( $player_id <= 0 ) return $defaults;

        $rows = $this->stats->getMainCategoryBreakdown( $player_id );
        foreach ( $rows as $row ) {
            $label = strtolower( (string) ( $row['label'] ?? '' ) );
            if ( isset( $defaults[ $label ] ) && $row['alltime'] !== null ) {
                $defaults[ $label ] = (float) $row['alltime'];
            }
        }
        return $defaults;
    }

    private function sideModifier( int $player_id, string $slot_side ): float {
        if ( $slot_side === 'center' || $slot_side === '' ) return 0.0;
        global $wpdb; $p = $wpdb->prefix;
        $pref = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT position_side_preference FROM {$p}tt_players WHERE id = %d",
            $player_id
        ) );
        if ( $pref === '' ) return 0.0;
        if ( $pref === $slot_side ) return self::SIDE_BONUS;
        if ( ( $pref === 'left' && $slot_side === 'right' ) ||
             ( $pref === 'right' && $slot_side === 'left' ) ) {
            return self::SIDE_PENALTY;
        }
        return 0.0;
    }

    /** @param array<string, array{rating:float, weight:float, contribution:float}> $breakdown */
    private static function buildRationale( array $breakdown, float $modifier, string $slot_label ): string {
        $parts = [];
        foreach ( $breakdown as $key => $b ) {
            $parts[] = sprintf( '%s %.1f × %.2f = %.2f', ucfirst( $key ), $b['rating'], $b['weight'], $b['contribution'] );
        }
        $line = implode( '; ', $parts );
        if ( abs( $modifier ) > 0.001 ) {
            $line .= sprintf( ' (side modifier %+.2f)', $modifier );
        }
        return sprintf( /* translators: %1$s slot label, %2$s breakdown */
            __( 'Fit for %1$s: %2$s', 'talenttrack' ), $slot_label, $line );
    }

    /** @param int $template_id @return array<int, array<string,mixed>> */
    private static function loadFormationSlots( int $template_id ): array {
        global $wpdb; $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT slots_json FROM {$p}tt_formation_templates WHERE id = %d AND archived_at IS NULL",
            $template_id
        ) );
        if ( ! $row ) return [];
        $decoded = json_decode( (string) $row->slots_json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /** @return array<string,mixed>|null */
    private static function resolveSlot( int $template_id, string $slot_label ): ?array {
        foreach ( self::loadFormationSlots( $template_id ) as $slot ) {
            if ( (string) ( $slot['label'] ?? '' ) === $slot_label ) return $slot;
        }
        return null;
    }
}
