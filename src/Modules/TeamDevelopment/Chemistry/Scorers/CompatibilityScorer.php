<?php
namespace TT\Modules\TeamDevelopment\Chemistry\Scorers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\TeamDevelopment\Chemistry\ComponentScore;
use TT\Modules\TeamDevelopment\Chemistry\PairContext;
use TT\Modules\TeamDevelopment\Chemistry\PlayerChemistryProfile;

/**
 * CompatibilityScorer — spec component 4.1 (groups physical / technical /
 * tactical / mental + footedness).
 *
 * v1 formula: the pair's combined competence in the core attribute groups
 * (the mean of each player's mean across physical/technical/tactical/mental)
 * nudged by footedness complementarity — a left+right or either-both pairing
 * fits wide partnerships, so +5; two same-single-footed players, −3. Clamped
 * 0–100. Neutral when neither player has any core attribute recorded.
 *
 * Tuning hook (future): role/position complementarity from preferred_positions.
 */
final class CompatibilityScorer implements ComponentScorer {

    private const CORE_GROUPS = [ 'physical', 'technical', 'tactical', 'mental' ];

    public function key(): string { return 'compatibility'; }

    public function score( PlayerChemistryProfile $a, PlayerChemistryProfile $b, PairContext $pair ): ComponentScore {
        $am = $a->meanOfGroups( self::CORE_GROUPS );
        $bm = $b->meanOfGroups( self::CORE_GROUPS );

        if ( $am === null && $bm === null ) {
            return ComponentScore::neutral( __( 'No attributes recorded yet', 'talenttrack' ) );
        }
        // If only one is recorded, lean on it rather than dropping to neutral.
        $base = $am !== null && $bm !== null ? ( $am + $bm ) / 2 : ( $am ?? $bm );

        $reasons = [];
        $foot_adj = 0.0;
        if ( $a->foot !== '' && $b->foot !== '' ) {
            $complement = ( $a->foot === 'both' || $b->foot === 'both' )
                || ( $a->foot !== $b->foot );
            if ( $complement ) {
                $foot_adj = 5.0;
                $reasons[] = __( 'Complementary footedness', 'talenttrack' );
            } else {
                $foot_adj = -3.0;
            }
        }

        if ( (float) $base >= 70.0 ) {
            $reasons[] = __( 'Both strong in core attributes', 'talenttrack' );
        }

        return ComponentScore::clamp( (float) $base + $foot_adj, true, $reasons );
    }
}
