<?php
namespace TT\Modules\MatchExecution\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchPrep\Frontend\FrontendMatchPrepView;

/**
 * PitchLayoutService (#1713) — maps a match-prep lineup to positioned
 * slots for the vertical pitch on the live surface.
 *
 * The lineup stores `slot_number` (1..11) + `player_id` per half. The
 * `slot_number` matches the `num` field of
 * `FrontendMatchPrepView::defaultSlotLayouts()[$shape]`, which carries
 * the position label + x/y percentages (0..100, left→right / top→bottom)
 * — the same coordinate convention the formation templates' `slots_json`
 * uses (just scaled to a percentage). Reusing that single layout table
 * keeps the pitch, the prep view, and the printable team-sheet aligned.
 *
 * Pure mapping — no eligibility or scoring decisions — so it stays
 * SaaS-portable (CLAUDE.md §4).
 */
final class PitchLayoutService {

    private const FALLBACK_SHAPE = '4-3-3';

    /**
     * Positioned starting XI for the first half.
     *
     * @param int                                          $formation_template_id The prep's bound formation template (0 = none).
     * @param array<int,int>                               $slot_to_player        slot_number => player_id for half 1.
     * @param array<int, array{name:string, jersey:?int}>  $player_meta           player_id => display data.
     *
     * @return list<array{
     *   slot:int,
     *   label:string,
     *   x:float,
     *   y:float,
     *   player_id:int,
     *   player_name:string,
     *   jersey:?int
     * }>
     */
    public function positionedXi( int $formation_template_id, array $slot_to_player, array $player_meta ): array {
        $shape   = $this->resolveShape( $formation_template_id );
        $layouts = FrontendMatchPrepView::defaultSlotLayouts();
        $layout  = $layouts[ $shape ] ?? $layouts[ self::FALLBACK_SHAPE ] ?? [];

        $out = [];
        foreach ( $layout as $slot ) {
            $num  = (int) ( $slot['num'] ?? 0 );
            $pid  = (int) ( $slot_to_player[ $num ] ?? 0 );
            $meta = $player_meta[ $pid ] ?? null;
            $out[] = [
                'slot'        => $num,
                'label'       => (string) ( $slot['label'] ?? '' ),
                'x'           => (float) ( $slot['x'] ?? 50 ),
                'y'           => (float) ( $slot['y'] ?? 50 ),
                'player_id'   => $pid,
                'player_name' => ( $pid > 0 && $meta !== null ) ? (string) $meta['name'] : '',
                'jersey'      => ( $pid > 0 && $meta !== null ) ? $meta['jersey'] : null,
            ];
        }
        return $out;
    }

    /**
     * Resolve the formation shape string for a bound template id. Falls
     * back to the default shape when no template is bound or the lookup
     * misses. The formation-templates table is install-global (no
     * club_id column), so no tenancy filter applies here.
     */
    private function resolveShape( int $formation_template_id ): string {
        if ( $formation_template_id <= 0 ) {
            return self::FALLBACK_SHAPE;
        }
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table  = $wpdb->prefix . 'tt_formation_templates';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return self::FALLBACK_SHAPE;
        }
        $shape = (string) $wpdb->get_var( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT formation_shape FROM {$table} WHERE id = %d LIMIT 1",
            $formation_template_id
        ) );
        return $shape !== '' ? $shape : self::FALLBACK_SHAPE;
    }
}
